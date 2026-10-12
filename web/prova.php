<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$prova = buscar_prova((int)($_GET['id'] ?? 0));
$id = (int)$prova['id'];
if ($prova['status'] === 'rascunho') {
    // Só prova com gabarito aprovado recebe folhas e tem relatório.
    redirecionar("gabarito.php?id=$id");
}
$layout = $prova['layout'];
$gabarito = $prova['gabarito'];
$erro = null;
$aviso = null;
if (isset($_GET['lidas'])) {
    $aviso = (int)$_GET['lidas'] . ' folha(s) lida(s) para a turma ' . ($_GET['turma'] ?? '') . '.'
        . (!empty($_GET['falhas']) ? ' ' . (int)$_GET['falhas'] . ' não puderam ser lidas; veja o motivo no fim da página.' : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $turma = trim($_POST['turma'] ?? '');
        if ($turma === '') {
            throw new InvalidArgumentException('Informe a turma das folhas enviadas.');
        }
        $uploads = upload_normalizado($_FILES['folhas'] ?? []);
        if (!$uploads) {
            throw new InvalidArgumentException('Selecione ao menos um arquivo.');
        }
        $caminhos = [];
        $nomeOriginal = [];
        foreach ($uploads as $u) {
            $c = guardar_upload($u);
            $caminhos[] = $c;
            $nomeOriginal[$c] = $u['nome'];
        }

        // As fotos enviadas ficam só na pasta temporária. No Storage vão a área de
        // respostas recortada (sem o nome do aluno) e a imagem anotada pelo leitor.
        // Gabarito digitado: sem formato ainda, cada folha é lida com o formato
        // detectado, e a primeira que servir define o formato e o caderno da prova.
        $semFormato = !formato_conhecido($layout);
        $leituras = ler_folhas($caminhos, $layout);
        if ($semFormato) {
            foreach ($leituras as &$l) {
                if ($l['ok'] && ($motivo = conferir_formato_da_folha($layout, $l, $gabarito)) !== null) {
                    $l = ['ok' => false, 'erro' => $motivo] + $l;
                }
            }
            unset($l);
        }
        $ins = db()->prepare('INSERT INTO folhas (prova_id, turma, arquivo, pagina, imagem, imagem_debug, respostas, niveis, revisar, erro)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $enviadas = ['folhas' => [], 'anotadas' => []];
        $ok = $falhas = 0;
        try {
            db()->beginTransaction();
            foreach ($leituras as $l) {
                $nome = $nomeOriginal[$l['arquivo']] ?? basename($l['arquivo']);
                if ($l['ok']) {
                    $ok++;
                    $prefixo = "$id/" . bin2hex(random_bytes(8));
                    $imagem = $imagemDebug = null;
                    if (isset($l['imagem_recorte'])) {
                        $imagem = $enviadas['folhas'][] = storage_enviar('folhas', "{$prefixo}_area.jpg", $l['imagem_recorte']);
                    }
                    if (isset($l['imagem_debug'])) {
                        $imagemDebug = $enviadas['anotadas'][] = storage_enviar('anotadas', "{$prefixo}_anotada.jpg", $l['imagem_debug']);
                    }
                    $ins->execute([
                        $id, $turma, $nome, $l['pagina'], $imagem, $imagemDebug,
                        json_encode(respostas_da_leitura($l)),
                        json_encode(array_column($l['respostas'], 'niveis')),
                        json_encode(questoes_para_revisar($l)),
                        null,
                    ]);
                } else {
                    $falhas++;
                    $ins->execute([$id, $turma, $nome, $l['pagina'], null, null, null, null, '[]', $l['erro']]);
                }
            }
            if ($semFormato && formato_conhecido($layout)) {
                $modelo = modelo_do_caderno($layout['caderno'] ?? null);
                db()->prepare('UPDATE provas SET layout = ?, caderno = ?, modelo = ? WHERE id = ?')
                    ->execute([json_encode(layout_do_leitor($layout), JSON_UNESCAPED_UNICODE),
                        $layout['caderno'] ?? null, $modelo['chave'] ?? null, $id]);
            }
            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            foreach ($enviadas as $bucket => $lista) {
                try {
                    storage_apagar($bucket, $lista);
                } catch (Throwable) {
                    // O erro original é o que importa para o professor.
                }
            }
            throw $ex;
        }
        redirecionar("prova.php?id=$id&turma=" . urlencode($turma) . "&lidas=$ok&falhas=$falhas");
    } catch (Throwable $ex) {
        $erro = $ex->getMessage();
    }
}

/* ------------------------------------------------------------ Relatório */

$questoes = $prova['questoes'];
[$lidas, $falhas] = carregar_folhas($prova);
$resumo = resumo_por_turma($questoes, $lidas);
$turmas = array_map('strval', array_keys($resumo)); // turma "7" vira chave inteira no array
$filtro = in_array($_GET['turma'] ?? '', $turmas, true) ? $_GET['turma'] : '';
$visiveis = $filtro !== '' ? array_values(array_filter($lidas, fn($f) => $f['turma'] === $filtro)) : $lidas;
$disciplinas = array_values(array_unique(array_map('disciplina_da_questao', $questoes)));
$niveis = niveis_da_prova($questoes);

// Por questão, respeitando o filtro de turma
$estatisticas = estatisticas_das_questoes($questoes, $visiveis);
$prioridades = prioridades_de_revisao($estatisticas);
$habilidades = array_values(array_filter(acerto_por_habilidade($estatisticas),
    fn($h) => $h['taxa'] !== null && $h['taxa'] < LIMITE_REFORCO));
$matriz = matriz_de_dificuldade($estatisticas);
$comparadas = array_sum(array_map('array_sum', $matriz));
$divergentes = array_values(array_filter($estatisticas, fn($e) => $e['diverge']));

// Filtros do mapa de questões
$filtroDisciplina = in_array($_GET['disciplina'] ?? '', $disciplinas, true) ? $_GET['disciplina'] : '';
$filtroDificuldade = array_key_exists($_GET['dificuldade'] ?? '', DIFICULDADES + ['sem' => 1]) ? $_GET['dificuldade'] : '';
$mapa = filtrar_questoes($estatisticas, $filtroDisciplina, $filtroDificuldade);

$grupos = [
    0 => ['Conteúdo que a turma deveria dominar', 'Marcadas como fáceis ou médias, com acerto baixo.'],
    1 => ['Sem nível de dificuldade', 'Marque a dificuldade para saber se o acerto baixo era esperado.'],
    2 => ['Difíceis', 'Acerto baixo já era esperado; retome se houver tempo.'],
];

function classe_taxa(?float $t): string
{
    if ($t === null) {
        return '';
    }
    return $t < LIMITE_REFORCO ? 'baixa' : 'boa';
}

/** Selo com a dificuldade marcada (F, M, D); vazio sem nível. */
function selo_dificuldade(?string $nivel, bool $curto = false): string
{
    if ($nivel === null) {
        return '';
    }
    $rotulo = DIFICULDADES[$nivel];
    return '<span class="nivel ' . $nivel . '" title="' . e($rotulo) . '">' . e($curto ? mb_substr($rotulo, 0, 1) : $rotulo) . '</span>';
}

/** Link da própria página trocando só alguns parâmetros (turma, filtros do mapa). */
function link_relatorio(int $id, array $params, string $ancora = ''): string
{
    $params = array_filter(['id' => $id] + $params, fn($v) => $v !== '' && $v !== null);
    return 'prova.php?' . http_build_query($params) . ($ancora ? "#$ancora" : '');
}

$alvo = $filtro !== '' ? 'na turma ' . $filtro : (count($turmas) > 1 ? 'em todas as turmas' : '');

cabecalho($prova['nome'], 'index.php');
?>

<p class="meta">
  <?php if (formato_conhecido($layout)): ?>
    <?= e(descrever_formato($layout, count($gabarito))) ?>.
  <?php else: ?>
    <?= count($gabarito) ?> questões. O formato da folha será reconhecido na primeira folha enviada.
  <?php endif; ?>
  Gabarito: <span class="gabarito"><?= e(implode('', $gabarito)) ?></span>.
  Reforço indicado abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto.
  <a class="nao-imprimir" href="gabarito.php?id=<?= $id ?>">Editar gabarito e dificuldade</a>
</p>
<p class="so-impressao meta">Relatório <?= $filtro !== '' ? 'da turma ' . e($filtro) : (count($turmas) > 1 ? 'de todas as turmas' : '') ?> gerado em <?= date('d/m/Y') ?> às <?= date('H:i') ?>, com <?= count($visiveis) ?> folha(s).</p>

<?php if ($aviso): ?><p class="aviso"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

<section class="envio nao-imprimir">
  <h2>Enviar folhas de uma turma</h2>
  <form method="post" enctype="multipart/form-data" class="formulario linha-envio">
    <label>Turma
      <input name="turma" list="turmas" required placeholder="7A" value="<?= e($_POST['turma'] ?? $filtro) ?>">
      <datalist id="turmas"><?php foreach ($turmas as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
    </label>
    <label class="arquivos">Folhas digitalizadas <span class="dica">Imagens ou PDF com uma folha por página</span>
      <input type="file" name="folhas[]" multiple required accept=".png,.jpg,.jpeg,.pdf,.tif,.tiff">
    </label>
    <button type="submit">Ler e corrigir</button>
  </form>
</section>

<?php if ($lidas): ?>
<section>
  <div class="titulo-secao">
    <h2>Turmas</h2>
    <p class="acoes-relatorio nao-imprimir">
      <button type="button" class="secundario" onclick="window.print()">Imprimir relatório<?= $filtro !== '' ? ' da ' . e($filtro) : '' ?></button>
      <a href="exportar.php?id=<?= $id ?>&amp;tipo=questoes<?= $filtro !== '' ? '&amp;turma=' . urlencode($filtro) : '' ?>">CSV por questão</a>
      <a href="exportar.php?id=<?= $id ?>&amp;tipo=turmas">CSV por turma</a>
    </p>
  </div>
  <table class="tabela">
    <thead>
      <?php if ($niveis): ?>
      <tr class="grupos">
        <th colspan="3"></th>
        <th colspan="<?= count($disciplinas) ?>" class="num inicio-grupo">Por disciplina</th>
        <th colspan="<?= count($niveis) ?>" class="num inicio-grupo">Por dificuldade</th>
        <th colspan="2"></th>
      </tr>
      <?php endif; ?>
      <tr>
        <th>Turma</th><th class="num">Folhas</th><th class="num">Acerto</th>
        <?php foreach ($disciplinas as $i => $d): ?><th class="num<?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= e($d) ?></th><?php endforeach; ?>
        <?php foreach ($niveis as $i => $n): ?><th class="num<?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= e(DIFICULDADES[$n]) ?></th><?php endforeach; ?>
        <th class="num">Para reforço</th><th class="num">A revisar</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($turmas as $t): $r = $resumo[$t]; $tx = pct($r['acertos'], $r['total']); ?>
      <tr class="<?= $filtro === $t ? 'ativa' : '' ?>">
        <td><a href="<?= e(link_relatorio($id, ['turma' => $t], 'prioridades')) ?>"><?= e($t) ?></a></td>
        <td class="num"><?= $r['folhas'] ?></td>
        <td class="num <?= classe_taxa($tx) ?>"><?= fmt_pct($tx) ?></td>
        <?php foreach ($disciplinas as $i => $d): $v = $r['disciplinas'][$d] ?? [0, 0]; $ta = pct($v[0], $v[1]); ?>
          <td class="num <?= classe_taxa($ta) ?><?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= fmt_pct($ta) ?></td>
        <?php endforeach; ?>
        <?php foreach ($niveis as $i => $n): $v = $r['niveis'][$n] ?? [0, 0]; $ta = pct($v[0], $v[1]); ?>
          <td class="num <?= classe_taxa($ta) ?><?= $i === 0 ? ' inicio-grupo' : '' ?>"><?= fmt_pct($ta) ?></td>
        <?php endforeach; ?>
        <td class="num"><?= $r['reforco'] ?></td>
        <td class="num"><?= $r['revisar'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($filtro !== ''): ?><p class="nao-imprimir"><a href="<?= e(link_relatorio($id, [])) ?>">Mostrar todas as turmas</a></p><?php endif; ?>
</section>

<section id="prioridades">
  <h2>Prioridades de revisão<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <?php if ($filtro === '' && count($turmas) > 1): ?>
    <p class="dica nao-imprimir">Somando todas as turmas. Clique numa turma na tabela acima para ver as prioridades dela.</p>
  <?php endif; ?>
  <?php if (!$niveis): ?>
    <p class="aviso atencao nao-imprimir">Nenhuma questão tem dificuldade marcada. <a href="gabarito.php?id=<?= $id ?>">Marque Fácil, Médio ou Difícil</a> para separar o que a turma deveria dominar do que já era esperado errar.</p>
  <?php endif; ?>

  <?php if (!$prioridades): ?>
    <p class="vazio">Nenhuma questão ficou abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto<?= $alvo !== '' ? ' ' . e($alvo) : '' ?>.</p>
  <?php else: ?>
    <p class="dica">Questões abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto, da mais urgente para a menos urgente.</p>
    <?php $grupoAtual = null; $posicao = 0; ?>
    <?php foreach ($prioridades as $p): ?>
      <?php if ($p['grupo'] !== $grupoAtual): ?>
        <?php if ($grupoAtual !== null): ?></ol><?php endif; $grupoAtual = $p['grupo']; ?>
        <?php if ($niveis): // sem nenhum nível marcado, o aviso acima já explica ?>
        <h3 class="grupo-prioridade"><?= e($grupos[$grupoAtual][0]) ?> <span class="dica"><?= e($grupos[$grupoAtual][1]) ?></span></h3>
        <?php endif; ?>
        <ol class="prioridades" start="<?= $posicao + 1 ?>">
      <?php endif; $posicao++; ?>
        <li class="grupo-<?= $p['grupo'] ?>">
          <span class="questao">Questão <?= $p['numero'] ?></span>
          <span class="etiquetas">
            <?= selo_dificuldade($p['dificuldade']) ?>
            <span class="selo"><?= e($p['disciplina']) ?></span>
            <?php if ($p['habilidade']): ?><span class="selo">Habilidade <?= e($p['habilidade']) ?></span><?php endif; ?>
          </span>
          <span class="barra" style="--nivel: <?= (int)round($p['taxa']) ?>%" aria-hidden="true"></span>
          <strong class="taxa baixa"><?= fmt_pct($p['taxa']) ?></strong>
          <span class="motivo"><?= e($p['motivo']) ?> Certa: <?= e($p['certa']) ?>.</span>
        </li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($habilidades): ?>
    <h3>Habilidades com acerto baixo</h3>
    <table class="tabela compacta">
      <thead><tr><th>Habilidade</th><th>Questões</th><th class="num">Acerto</th></tr></thead>
      <tbody>
      <?php foreach ($habilidades as $h): ?>
        <tr><td><?= e($h['habilidade']) ?></td><td><?= e(listar_numeros($h['questoes'])) ?></td><td class="num baixa"><?= fmt_pct($h['taxa']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section id="mapa">
  <h2>Acerto por questão<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <form method="get" action="prova.php#mapa" class="filtros nao-imprimir">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($filtro !== ''): ?><input type="hidden" name="turma" value="<?= e($filtro) ?>"><?php endif; ?>
    <?php if (count($disciplinas) > 1): ?>
    <label>Disciplina
      <select name="disciplina">
        <option value="">Todas</option>
        <?php foreach ($disciplinas as $d): ?><option <?= $d === $filtroDisciplina ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Dificuldade
      <select name="dificuldade">
        <option value="">Todas</option>
        <?php foreach (DIFICULDADES + ['sem' => 'Sem nível'] as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= $chave === $filtroDificuldade ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="secundario">Filtrar</button>
    <?php if ($filtroDisciplina !== '' || $filtroDificuldade !== ''): ?>
      <a class="limpar" href="<?= e(link_relatorio($id, ['turma' => $filtro], 'mapa')) ?>">Limpar filtros</a>
    <?php endif; ?>
  </form>
  <p class="dica">Cada bolinha é uma questão. Quanto mais cheia, mais alunos acertaram. Em vermelho, abaixo do limite de reforço. A letra no canto é a dificuldade marcada.</p>
  <?php if (!$mapa): ?>
    <p class="vazio">Nenhuma questão com esses filtros.</p>
  <?php endif; ?>
  <ol class="mapa">
  <?php foreach ($mapa as $q): $nivel = (int)round($q['taxa'] ?? 0); ?>
    <li class="<?= classe_taxa($q['taxa']) ?>" style="--nivel: <?= $nivel ?>%"
        title="<?= e($q['disciplina'] . ($q['habilidade'] ? ' · ' . $q['habilidade'] : '') . ($q['dificuldade'] ? ' · ' . DIFICULDADES[$q['dificuldade']] : '')) ?>">
      <span class="bolinha"><span><?= $q['numero'] ?></span><?= selo_dificuldade($q['dificuldade'], true) ?></span>
      <strong><?= fmt_pct($q['taxa']) ?></strong>
      <small>Certa: <?= e($q['certa']) ?><?php if ($q['distrator']): ?><br>Erro comum: <?= e($q['distrator']) ?> (<?= $q['distrator_qtd'] ?>)<?php endif; ?></small>
    </li>
  <?php endforeach; ?>
  </ol>
</section>

<?php if ($comparadas): ?>
<section id="dificuldade">
  <h2>Dificuldade marcada × observada<?= $alvo !== '' ? ' ' . e($alvo) : '' ?></h2>
  <p class="dica">A dificuldade observada vem da proporção de acerto: acima de <?= fmt_pct(LIMITE_FACIL) ?> é fácil, abaixo de <?= fmt_pct(LIMITE_DIFICIL) ?> é difícil, e médio entre as duas.</p>
  <?php $iguais = array_sum(array_map(fn($n) => $matriz[$n][$n], array_keys(DIFICULDADES))); ?>
  <p><strong><?= $iguais ?> de <?= $comparadas ?></strong> questões com dificuldade marcada ficaram no nível previsto (<?= fmt_pct(pct($iguais, $comparadas)) ?>).</p>
  <div class="lado-a-lado">
    <table class="tabela compacta matriz">
      <caption>Questões por nível</caption>
      <thead>
        <tr><th>Marcada ↓ · Observada →</th><?php foreach (DIFICULDADES as $rotulo): ?><th class="num"><?= e($rotulo) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php foreach (DIFICULDADES as $marcada => $rotulo): ?>
        <tr>
          <th><?= e($rotulo) ?></th>
          <?php foreach (array_keys(DIFICULDADES) as $observada): $n = $matriz[$marcada][$observada]; ?>
            <td class="num<?= $marcada === $observada ? ' diagonal' : ($n ? ' diverge' : '') ?>"><?= $n ?: '' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($divergentes): ?>
    <table class="tabela compacta">
      <caption>Questões em que divergem</caption>
      <thead><tr><th class="num">Questão</th><th>Disciplina</th><th>Marcada</th><th>Observada</th><th class="num">Acerto</th></tr></thead>
      <tbody>
      <?php foreach ($divergentes as $q): ?>
        <tr>
          <td class="num"><?= $q['numero'] ?></td>
          <td><?= e($q['disciplina']) ?></td>
          <td><?= selo_dificuldade($q['dificuldade']) ?></td>
          <td><?= selo_dificuldade($q['observada']) ?></td>
          <td class="num <?= classe_taxa($q['taxa']) ?>"><?= fmt_pct($q['taxa']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="nao-imprimir">
  <h2>Folhas<?= $filtro !== '' ? ' da turma ' . e($filtro) : '' ?></h2>
  <table class="tabela">
    <thead><tr><th>Turma</th><th>Arquivo</th><th class="num">Acertos</th><th class="num">Taxa</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($visiveis as $f): ?>
      <tr>
        <td><?= e($f['turma']) ?></td>
        <td><a href="folha.php?id=<?= $f['id'] ?>"><?= e($f['arquivo']) ?><?= $f['pagina'] > 1 || str_ends_with(strtolower($f['arquivo']), '.pdf') ? ', pág. ' . $f['pagina'] : '' ?></a></td>
        <td class="num"><?= $f['n_acertos'] ?>/<?= count($gabarito) ?></td>
        <td class="num <?= classe_taxa($f['taxa']) ?>"><?= fmt_pct($f['taxa']) ?></td>
        <td>
          <?php if ($f['revisar']): ?>
            <a class="selo atencao" href="folha.php?id=<?= $f['id'] ?>">Revisar questões <?= e(implode(', ', $f['revisar'])) ?></a>
          <?php elseif ($f['revisada']): ?>
            <span class="selo">Revisada</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($falhas): ?>
<section class="nao-imprimir">
  <h2>Folhas que não puderam ser lidas</h2>
  <table class="tabela">
    <thead><tr><th>Turma</th><th>Arquivo</th><th>Motivo</th></tr></thead>
    <tbody>
    <?php foreach ($falhas as $f): ?>
      <tr><td><?= e($f['turma']) ?></td><td><?= e($f['arquivo']) ?>, pág. <?= $f['pagina'] ?></td><td><?= e($f['erro']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php rodape();
