<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$prova = buscar_prova((int)($_GET['id'] ?? 0));
$id = (int)$prova['id'];
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
        if (($_POST['acao'] ?? '') === 'areas') {
            salvar_areas($id, ler_areas($_POST['areas'] ?? '', count($gabarito)));
            redirecionar("prova.php?id=$id");
        }

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
        $leituras = ler_folhas($caminhos, $layout);
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

$todas = db()->prepare('SELECT * FROM folhas WHERE prova_id = ? ORDER BY turma, id');
$todas->execute([$id]);
$todas = $todas->fetchAll();

$lidas = [];
$falhas = [];
foreach ($todas as $f) {
    if ($f['erro'] !== null) {
        $falhas[] = $f;
        continue;
    }
    $f['respostas'] = json_decode($f['respostas'], true);
    $f['revisar'] = json_decode($f['revisar'], true);
    $f['acertos'] = corrigir($gabarito, $f['respostas']);
    $f['n_acertos'] = count(array_filter($f['acertos']));
    $f['taxa'] = pct($f['n_acertos'], count($gabarito));
    $lidas[] = $f;
}

$turmas = array_values(array_unique(array_column($lidas, 'turma')));
sort($turmas);
$filtro = $_GET['turma'] ?? '';
$visiveis = $filtro !== '' ? array_values(array_filter($lidas, fn($f) => $f['turma'] === $filtro)) : $lidas;
$areas = areas_efetivas($layout);

// Resumo por turma e por área
$resumo = [];
foreach ($lidas as $f) {
    $t = $f['turma'];
    $resumo[$t] ??= ['folhas' => 0, 'acertos' => 0, 'total' => 0, 'reforco' => 0, 'revisar' => 0, 'areas' => []];
    $resumo[$t]['folhas']++;
    $resumo[$t]['acertos'] += $f['n_acertos'];
    $resumo[$t]['total'] += count($gabarito);
    $resumo[$t]['reforco'] += $f['taxa'] < LIMITE_REFORCO ? 1 : 0;
    $resumo[$t]['revisar'] += $f['revisar'] ? 1 : 0;
    foreach ($areas as $a) {
        $trecho = array_slice($f['acertos'], $a['de'] - 1, $a['ate'] - $a['de'] + 1);
        $resumo[$t]['areas'][$a['nome']][0] = ($resumo[$t]['areas'][$a['nome']][0] ?? 0) + count(array_filter($trecho));
        $resumo[$t]['areas'][$a['nome']][1] = ($resumo[$t]['areas'][$a['nome']][1] ?? 0) + count($trecho);
    }
}

// Por questão (respeita o filtro de turma)
$porQuestao = [];
foreach ($gabarito as $i => $certa) {
    $acertos = 0;
    $erradas = [];
    foreach ($visiveis as $f) {
        $r = $f['respostas'][$i];
        if ($r === $certa) {
            $acertos++;
        } elseif ($r !== null && $r !== 'MULTIPLA') {
            $erradas[$r] = ($erradas[$r] ?? 0) + 1;
        }
    }
    arsort($erradas);
    $porQuestao[] = [
        'num' => $i + 1,
        'certa' => $certa,
        'taxa' => pct($acertos, count($visiveis)),
        'distrator' => $erradas ? array_key_first($erradas) : null,
        'distrator_qtd' => $erradas ? reset($erradas) : 0,
    ];
}

function area_da_questao(array $areas, int $n): string
{
    foreach ($areas as $a) {
        if ($n >= $a['de'] && $n <= $a['ate']) {
            return $a['nome'];
        }
    }
    return '';
}

function classe_taxa(?float $t): string
{
    if ($t === null) {
        return '';
    }
    return $t < LIMITE_REFORCO ? 'baixa' : 'boa';
}

cabecalho($prova['nome'], 'index.php');
?>

<p class="meta">
  <?php if (!empty($layout['caderno'])): ?>Caderno <?= e($layout['caderno']) ?>. <?php endif; ?>
  <?= (int)$layout['questoes'] ?> questões, alternativas <?= e(implode(', ', $layout['alternativas'])) ?>.
  Gabarito: <span class="gabarito"><?= e(implode('', $gabarito)) ?></span>.
  Reforço indicado abaixo de <?= fmt_pct(LIMITE_REFORCO) ?> de acerto.
</p>

<?php if ($aviso): ?><p class="aviso"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

<section class="envio">
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
  <h2>Turmas</h2>
  <table class="tabela">
    <thead>
      <tr>
        <th>Turma</th><th class="num">Folhas</th><th class="num">Acerto</th>
        <?php foreach ($areas as $a): ?><th class="num"><?= e($a['nome']) ?></th><?php endforeach; ?>
        <th class="num">Para reforço</th><th class="num">A revisar</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($turmas as $t): $r = $resumo[$t]; $tx = pct($r['acertos'], $r['total']); ?>
      <tr class="<?= $filtro === $t ? 'ativa' : '' ?>">
        <td><a href="?id=<?= $id ?>&turma=<?= urlencode($t) ?>"><?= e($t) ?></a></td>
        <td class="num"><?= $r['folhas'] ?></td>
        <td class="num <?= classe_taxa($tx) ?>"><?= fmt_pct($tx) ?></td>
        <?php foreach ($areas as $a): $v = $r['areas'][$a['nome']] ?? [0, 0]; $ta = pct($v[0], $v[1]); ?>
          <td class="num <?= classe_taxa($ta) ?>"><?= fmt_pct($ta) ?></td>
        <?php endforeach; ?>
        <td class="num"><?= $r['reforco'] ?></td>
        <td class="num"><?= $r['revisar'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($filtro !== ''): ?><p><a href="?id=<?= $id ?>">Mostrar todas as turmas</a></p><?php endif; ?>
</section>

<section>
  <h2>Acerto por questão<?= $filtro !== '' ? ' na turma ' . e($filtro) : '' ?></h2>
  <p class="dica">Cada bolinha é uma questão. Quanto mais cheia, mais alunos acertaram. Em vermelho, abaixo do limite de reforço.</p>
  <ol class="mapa">
  <?php foreach ($porQuestao as $q): $nivel = (int)round($q['taxa'] ?? 0); ?>
    <li class="<?= classe_taxa($q['taxa']) ?>" style="--nivel: <?= $nivel ?>%"
        title="<?= e(area_da_questao($areas, $q['num'])) ?>">
      <span class="bolinha"><span><?= $q['num'] ?></span></span>
      <strong><?= fmt_pct($q['taxa']) ?></strong>
      <small>Certa: <?= e($q['certa']) ?><?php if ($q['distrator']): ?><br>Erro comum: <?= e($q['distrator']) ?> (<?= $q['distrator_qtd'] ?>)<?php endif; ?></small>
    </li>
  <?php endforeach; ?>
  </ol>
</section>

<section>
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
<section>
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

<section>
  <h2>Áreas da prova</h2>
  <p class="dica">Alterar as áreas recalcula todos os relatórios na hora; as folhas não precisam ser enviadas de novo.</p>
  <form method="post" class="formulario">
    <input type="hidden" name="acao" value="areas">
    <textarea name="areas" rows="3" placeholder="Matemática: 1-20"><?= e(areas_texto($layout)) ?></textarea>
    <button type="submit">Salvar áreas</button>
  </form>
</section>

<?php rodape();
