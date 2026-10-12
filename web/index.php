<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$professor = iniciar_pagina();
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $fotos = upload_normalizado($_FILES['gabarito_imagem'] ?? []);
        $nova = cadastrar_prova($_POST, $fotos, $professor['id']);
        redirecionar("gabarito.php?id={$nova['id']}");
    } catch (Throwable $ex) {
        $erro = $ex->getMessage();
    }
}

[$provas, $filtros, $escolhidos] = listar_provas($_GET);
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
  <?= campo_csrf() ?>
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
