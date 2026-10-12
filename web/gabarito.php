<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Revisão do gabarito oficial. O professor confere a leitura da foto (ou o que
 * digitou), escolhe a disciplina e a dificuldade de cada questão e aprova.
 * A mesma tela edita o gabarito de uma prova já ativa.
 */

iniciar_pagina();
$prova = buscar_prova((int)($_GET['id'] ?? 0));
$id = (int)$prova['id'];
$rascunho = $prova['status'] === 'rascunho';
$erros = [];
$aviso = isset($_GET['salvo']) ? 'Rascunho salvo. Você pode voltar e aprovar quando terminar.' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';
    try {
        if ($acao === 'descartar' && $rascunho) {
            descartar_rascunho($prova);
            redirecionar('index.php');
        }
        $nome = (string)($_POST['nome'] ?? '');
        $aprovar = $acao === 'aprovar';
        [$questoes, $erros] = aprovar_gabarito($prova, $nome, (array)($_POST['q'] ?? []), $aprovar);
        if (!$erros) {
            redirecionar($rascunho && !$aprovar ? "gabarito.php?id=$id&salvo=1" : "prova.php?id=$id");
        }
        // Com erro, a tela volta com o que o professor escolheu, sem perder nada.
        $prova['nome'] = trim($nome);
        $prova['questoes'] = $questoes;
    } catch (Throwable $ex) {
        $erros[] = $ex->getMessage();
    }
}

$layout = $prova['layout'];
$alts = $layout['alternativas'];
$questoes = $prova['questoes'];
$catalogo = disciplinas_da_prova($questoes);
$modelo = modelo_do_caderno($prova['caderno']);
$duvidosas = array_column(array_filter($questoes, fn($q) => $q['duvidosa']), 'numero');

// Cor de cada disciplina na faixa e nos cartões (mesma ordem do catálogo).
$paleta = ['#2f6fae', '#b4572f', '#2e7650', '#7a4fa3', '#a8326e', '#5d6b1f', '#1f7a7a', '#8a5300', '#4b5563'];
$cores = [];
foreach ($catalogo as $i => $d) {
    $cores[$d] = $paleta[$i % count($paleta)];
}

cabecalho($rascunho ? 'Revisar gabarito' : 'Gabarito e dificuldade', $rascunho ? 'index.php' : "prova.php?id=$id");
?>

<p class="meta">
  <?php if (formato_conhecido($layout)): ?>
    <?= e(descrever_formato($layout, count($questoes))) ?>.
  <?php else: ?>
    <?= count($questoes) ?> questões digitadas. O formato da folha e o caderno serão reconhecidos na primeira folha de aluno enviada.
  <?php endif; ?>
  <?php if ($modelo): ?>Modelo <?= e($modelo['rotulo']) ?>: todas as questões já vêm com a disciplina <?= e($modelo['disciplina']) ?>.<?php endif; ?>
</p>

<?php if ($rascunho): ?>
  <p class="aviso">Confira cada resposta, escolha a dificuldade e aprove o gabarito. Só depois da aprovação a prova recebe as folhas dos alunos.</p>
<?php else: ?>
  <p class="dica">Esta prova já está ativa. Mudar uma resposta recalcula na hora as notas das folhas já lidas.</p>
<?php endif; ?>
<?php if ($aviso): ?><p class="aviso"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($erros): ?>
  <div class="erro" role="alert"><?php foreach ($erros as $m): ?><p><?= e($m) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($duvidosas): ?>
  <p class="aviso atencao">A leitura das questões <?= e(listar_numeros($duvidosas)) ?> ficou duvidosa (marcação fraca, em branco ou dupla). Elas estão destacadas abaixo.</p>
<?php endif; ?>

<form method="post" class="revisao-gabarito<?= $prova['imagem_gabarito'] ? '' : ' sem-imagem' ?>" id="revisao">
  <?= campo_csrf() ?>
  <?php if ($prova['imagem_gabarito']): ?>
    <figure>
      <img src="imagem.php?prova=<?= $id ?>" alt="Foto do gabarito lida, com a resposta encontrada em cada questão">
      <figcaption>Verde: marcação lida. Laranja: leitura duvidosa.</figcaption>
    </figure>
  <?php endif; ?>

  <div class="painel">
    <label class="campo-nome">Nome da prova
      <input name="nome" required value="<?= e($prova['nome']) ?>">
    </label>

    <div class="faixa-disciplinas">
      <p class="rotulo">Disciplinas</p>
      <ol class="faixa" aria-label="Disciplina de cada questão">
        <?php foreach ($questoes as $q): ?>
          <li data-numero="<?= $q['numero'] ?>" title="Questão <?= $q['numero'] ?>: <?= e($q['disciplina'] ?? 'sem disciplina') ?>"
              style="<?= $q['disciplina'] ? '--cor: ' . e($cores[$q['disciplina']]) : '' ?>"></li>
        <?php endforeach; ?>
      </ol>
      <ul class="legenda"></ul>
    </div>

    <div class="selecao" hidden>
      <p class="dica">Para aplicar a várias questões de uma vez, clique no número da primeira e depois no da última.</p>
      <p class="selecionadas" aria-live="polite">Nenhuma questão selecionada.</p>
      <div class="acoes">
        <button type="button" class="secundario" data-acao="todas">Selecionar todas</button>
        <label>Disciplina
          <select data-campo="disciplina">
            <?php foreach ($catalogo as $d): ?><option><?= e($d) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button type="button" data-acao="disciplina">Aplicar disciplina</button>
        <span class="grupo" role="group" aria-label="Aplicar dificuldade">
          <?php foreach (DIFICULDADES as $chave => $rotulo): ?>
            <button type="button" class="secundario" data-dificuldade="<?= $chave ?>"><?= e($rotulo) ?></button>
          <?php endforeach; ?>
        </span>
        <button type="button" class="link" data-acao="limpar">Limpar seleção</button>
      </div>
    </div>

    <div class="cartoes">
      <?php foreach ($questoes as $i => $q): $n = $q['numero']; ?>
        <fieldset class="cartao<?= $q['duvidosa'] ? ' duvidosa' : '' ?>" data-indice="<?= $i ?>" data-numero="<?= $n ?>"
                  style="<?= $q['disciplina'] ? '--cor: ' . e($cores[$q['disciplina']]) : '' ?>">
          <legend>
            <button type="button" class="numero" aria-pressed="false" title="Selecionar a partir desta questão">
              <span class="sr">Questão</span> <?= sprintf('%02d', $n) ?>
            </button>
            <?php if ($q['duvidosa']): ?><span class="selo atencao">Confira</span><?php endif; ?>
          </legend>

          <div class="opcoes resposta" role="radiogroup" aria-label="Resposta da questão <?= $n ?>">
            <?php foreach ($alts as $a): ?>
              <label><input type="radio" name="q[<?= $i ?>][resposta]" value="<?= e($a) ?>" <?= $q['resposta'] === $a ? 'checked' : '' ?>><span><?= e($a) ?></span></label>
            <?php endforeach; ?>
          </div>

          <label class="campo">Disciplina
            <select name="q[<?= $i ?>][disciplina]">
              <option value="">Escolha…</option>
              <?php foreach ($catalogo as $d): ?>
                <option <?= $q['disciplina'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <div class="opcoes dificuldade" role="radiogroup" aria-label="Dificuldade da questão <?= $n ?>">
            <?php foreach (DIFICULDADES as $chave => $rotulo): ?>
              <label><input type="radio" name="q[<?= $i ?>][dificuldade]" value="<?= $chave ?>" <?= $q['dificuldade'] === $chave ? 'checked' : '' ?>><span><?= e($rotulo) ?></span></label>
            <?php endforeach; ?>
          </div>

          <label class="campo habilidade">Habilidade <span class="dica">opcional</span>
            <input name="q[<?= $i ?>][habilidade]" value="<?= e($q['habilidade']) ?>" maxlength="20" placeholder="D07" autocomplete="off">
          </label>
        </fieldset>
      <?php endforeach; ?>
    </div>

    <div class="rodape-revisao">
      <?php if ($rascunho): ?>
        <button type="submit" name="acao" value="aprovar">Aprovar gabarito</button>
        <button type="submit" name="acao" value="salvar" class="secundario" formnovalidate>Salvar rascunho</button>
      <?php else: ?>
        <button type="submit" name="acao" value="salvar">Salvar alterações</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($rascunho): ?>
<form method="post" class="descartar" data-confirmar="Descartar este rascunho? A leitura do gabarito será apagada.">
  <?= campo_csrf() ?>
  <input type="hidden" name="acao" value="descartar">
  <button type="submit" class="link perigo">Descartar rascunho</button>
</form>
<?php endif; ?>

<script>window.CORES_DISCIPLINAS = <?= json_encode($cores, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="gabarito.js"></script>
<?php rodape();
