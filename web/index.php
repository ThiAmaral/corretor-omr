<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ano = (int)($_POST['ano'] ?? 0);
        $periodo = (int)($_POST['periodo'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            throw new InvalidArgumentException('Informe o ano letivo com quatro dígitos, por exemplo ' . date('Y') . '.');
        }
        if (!isset(periodos()[$periodo])) {
            throw new InvalidArgumentException('Escolha o ' . PERIODO_ESCOLA . ' da prova.');
        }

        $digitado = trim($_POST['gabarito_digitado'] ?? '');
        $imagem = upload_normalizado($_FILES['gabarito_imagem'] ?? []);
        $imagemGabarito = null;

        if ($imagem) {
            // A foto do gabarito só é lida; fica guardada apenas a área de respostas anotada.
            $leitura = ler_folhas([guardar_upload($imagem[0])])[0];
            if (!$leitura['ok']) {
                throw new RuntimeException('Não foi possível ler o gabarito: ' . $leitura['erro']
                    . ' Tire outra foto ou digite as respostas.');
            }
            $layout = $leitura['layout'];
            $modelo = modelo_do_caderno($layout['caderno'] ?? null);
            $questoes = questoes_da_leitura($leitura, $modelo['disciplina'] ?? null);
            if (isset($leitura['imagem_debug'])) {
                $imagemGabarito = storage_enviar('anotadas', 'gabaritos/' . bin2hex(random_bytes(8)) . '.jpg',
                    $leitura['imagem_debug']);
            }
        } elseif ($digitado !== '') {
            // Sem foto, o formato e o caderno vêm da primeira folha de aluno lida.
            $questoes = questoes_digitadas($digitado);
            $layout = ['questoes' => count($questoes), 'alternativas' => alternativas_digitadas($questoes)];
        } else {
            throw new InvalidArgumentException('Envie a foto do gabarito ou digite as respostas.');
        }

        $nome = trim($_POST['nome'] ?? '') ?: sugerir_nome_prova($layout['caderno'] ?? null);
        try {
            $provaId = criar_rascunho($nome, $ano, $periodo, $layout, $questoes, $imagemGabarito);
        } catch (Throwable $ex) {
            if ($imagemGabarito) {
                try {
                    storage_apagar('anotadas', [$imagemGabarito]);
                } catch (Throwable) {
                    // O erro original é o que importa para o professor.
                }
            }
            throw $ex;
        }
        redirecionar("gabarito.php?id=$provaId");
    } catch (Throwable $ex) {
        $erro = $ex->getMessage();
    }
}

$filtros = filtros_de_provas();
[$where, $params, $escolhidos] = aplicar_filtros($filtros, $_GET);

$st = db()->prepare("
    SELECT p.id, p.nome, p.ano, p.periodo, p.status, p.criado_em,
           COUNT(f.id) AS folhas,
           COUNT(DISTINCT f.turma) AS turmas
    FROM provas p LEFT JOIN folhas f ON f.prova_id = p.id AND f.erro IS NULL
    WHERE $where
    GROUP BY p.id ORDER BY p.ano DESC, p.periodo DESC NULLS LAST, p.id DESC
");
$st->execute($params);
$provas = $st->fetchAll();
$temProvas = (bool)db()->query('SELECT 1 FROM provas LIMIT 1')->fetchColumn();

cabecalho('Provas');
?>

<?php if ($temProvas): ?>
<form method="get" class="filtros" role="search" aria-label="Filtrar provas">
  <?php foreach ($filtros as $chave => $f): ?>
    <label><?= e($f['rotulo']) ?>
      <select name="<?= e($chave) ?>">
        <option value="">Todos</option>
        <?php foreach ($f['opcoes'] as $valor => $rotulo): ?>
          <option value="<?= e((string)$valor) ?>" <?= ($escolhidos[$chave] ?? null) === (string)$valor ? 'selected' : '' ?>><?= e((string)$rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endforeach; ?>
  <button type="submit">Filtrar</button>
  <?php if ($escolhidos): ?><a class="limpar" href="index.php">Limpar filtros</a><?php endif; ?>
</form>
<?php endif; ?>

<?php if ($provas): ?>
<table class="tabela">
  <thead><tr><th>Prova</th><th>Ano letivo</th><th class="num">Turmas</th><th class="num">Folhas lidas</th><th>Criada em</th></tr></thead>
  <tbody>
  <?php foreach ($provas as $p): ?>
    <tr>
      <td>
        <?php if ($p['status'] === 'rascunho'): ?>
          <a href="gabarito.php?id=<?= $p['id'] ?>"><?= e($p['nome']) ?></a>
          <a class="selo atencao" href="gabarito.php?id=<?= $p['id'] ?>">Gabarito a revisar</a>
        <?php else: ?>
          <a href="prova.php?id=<?= $p['id'] ?>"><?= e($p['nome']) ?></a>
        <?php endif; ?>
      </td>
      <td><?= e(($p['ano'] ?? '–') . ' · ' . nome_periodo($p['periodo'])) ?></td>
      <td class="num"><?= $p['turmas'] ?></td>
      <td class="num"><?= $p['folhas'] ?></td>
      <td><?= e(date('d/m/Y', strtotime($p['criado_em']))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php elseif ($temProvas): ?>
<p class="vazio">Nenhuma prova encontrada com esses filtros. <a href="index.php">Ver todas</a></p>
<?php else: ?>
<p class="vazio">Cadastre a primeira prova com o formulário abaixo: envie a foto do gabarito oficial, confira a leitura e aprove. Depois é só enviar as folhas dos alunos de cada turma.</p>
<?php endif; ?>

<h2 id="nova">Nova prova</h2>
<?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="formulario">
  <div class="linha">
    <label>Ano letivo
      <input type="number" name="ano" min="2000" max="2100" required value="<?= e($_POST['ano'] ?? date('Y')) ?>">
    </label>
    <label><?= e(ucfirst(PERIODO_ESCOLA)) ?>
      <select name="periodo">
        <?php $periodoEscolhido = (int)($_POST['periodo'] ?? periodo_atual()); ?>
        <?php foreach (periodos() as $n => $rotulo): ?>
          <option value="<?= $n ?>" <?= $n === $periodoEscolhido ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <fieldset>
    <legend>Gabarito oficial</legend>
    <label>Foto ou PDF de uma folha de respostas com o gabarito pintado
      <span class="dica">O sistema lê o caderno pelo QR code, o formato da folha e as respostas.</span>
      <input type="file" name="gabarito_imagem" accept=".png,.jpg,.jpeg,.pdf,.tif,.tiff">
    </label>
    <label>Ou digite as respostas em ordem
      <span class="dica">Sem foto, o formato da folha é reconhecido na primeira folha de aluno enviada.</span>
      <input name="gabarito_digitado" value="<?= e($_POST['gabarito_digitado'] ?? '') ?>" placeholder="CBDAABCDDCBA" autocomplete="off" spellcheck="false">
    </label>
  </fieldset>

  <label>Nome da prova <span class="dica">Opcional. Com a foto, o nome é sugerido pelo caderno; dá para trocar na revisão.</span>
    <input name="nome" value="<?= e($_POST['nome'] ?? '') ?>" placeholder="AMA – Matemática 8º ano (M0801)">
  </label>

  <button type="submit">Ler gabarito e revisar</button>
</form>

<?php rodape();
