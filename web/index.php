<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provaId = null;
    try {
        $nome = trim($_POST['nome'] ?? '');
        $questoes = (int)($_POST['questoes'] ?? 0);
        $colunas = (int)($_POST['colunas'] ?? 0);
        $alternativas = array_values(array_filter(array_map('trim', explode(',', strtoupper($_POST['alternativas'] ?? '')))));
        $ano = (int)($_POST['ano'] ?? 0);
        $periodo = (int)($_POST['periodo'] ?? 0);

        if ($nome === '') {
            throw new InvalidArgumentException('Dê um nome para a prova.');
        }
        if ($ano < 2000 || $ano > 2100) {
            throw new InvalidArgumentException('Informe o ano letivo com quatro dígitos, por exemplo ' . date('Y') . '.');
        }
        if (!isset(periodos()[$periodo])) {
            throw new InvalidArgumentException('Escolha o ' . PERIODO_ESCOLA . ' da prova.');
        }
        if ($questoes < 1 || $colunas < 1 || $questoes % $colunas !== 0) {
            throw new InvalidArgumentException('O número de questões precisa ser divisível pelo número de colunas.');
        }
        if (count($alternativas) < 2) {
            throw new InvalidArgumentException('Informe ao menos duas alternativas, separadas por vírgula.');
        }

        $layout = [
            'nome' => $nome,
            'questoes' => $questoes,
            'alternativas' => $alternativas,
            'colunas' => $colunas,
            'ordem' => ($_POST['ordem'] ?? '') === 'linhas' ? 'linhas' : 'colunas',
            'areas' => ler_areas($_POST['areas'] ?? '', $questoes),
        ];

        db()->prepare('INSERT INTO provas (nome, layout, gabarito, ano, periodo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nome, '{}', '[]', $ano, $periodo]);
        $provaId = (int)db()->lastInsertId();
        salvar_layout($provaId, $layout);

        $digitado = preg_replace('/[^A-Z]/', '', strtoupper($_POST['gabarito_digitado'] ?? ''));
        $imagem = upload_normalizado($_FILES['gabarito_imagem'] ?? []);

        if ($digitado !== '') {
            $gabarito = str_split($digitado);
            if (count($gabarito) !== $questoes) {
                throw new InvalidArgumentException("O gabarito digitado tem " . count($gabarito) . " respostas; a prova tem $questoes.");
            }
            $invalidas = array_diff($gabarito, $alternativas);
            if ($invalidas) {
                throw new InvalidArgumentException('O gabarito usa alternativas que não existem: ' . implode(', ', array_unique($invalidas)));
            }
        } elseif ($imagem) {
            $pasta = ARMAZENAMENTO . "/provas/$provaId";
            $arquivo = guardar_upload($imagem[0], $pasta);
            $leitura = ler_folhas([$arquivo], caminho_layout($provaId), "$pasta/debug")[0];
            if (!$leitura['ok']) {
                throw new RuntimeException('Não foi possível ler o gabarito: ' . $leitura['erro']);
            }
            $problemas = [];
            foreach ($leitura['respostas'] as $r) {
                if ($r['situacao'] !== 'marcada' || $r['duvidosa']) {
                    $problemas[] = $r['questao'];
                }
            }
            if ($problemas) {
                throw new RuntimeException('O gabarito precisa ter uma marcação nítida por questão. Confira as questões '
                    . implode(', ', $problemas) . ' ou digite o gabarito.');
            }
            $gabarito = respostas_da_leitura($leitura);
        } else {
            throw new InvalidArgumentException('Envie a imagem do gabarito ou digite as respostas.');
        }

        db()->prepare('UPDATE provas SET gabarito = ? WHERE id = ?')->execute([json_encode($gabarito), $provaId]);
        redirecionar("prova.php?id=$provaId");
    } catch (Throwable $ex) {
        if ($provaId) {
            db()->prepare('DELETE FROM provas WHERE id = ?')->execute([$provaId]);
        }
        $erro = $ex->getMessage();
    }
}

$filtros = filtros_de_provas();
[$where, $params, $escolhidos] = aplicar_filtros($filtros, $_GET);

$st = db()->prepare("
    SELECT p.id, p.nome, p.ano, p.periodo, p.criado_em,
           COUNT(f.id) AS folhas,
           COUNT(DISTINCT f.turma) AS turmas
    FROM provas p LEFT JOIN folhas f ON f.prova_id = p.id AND f.erro IS NULL
    WHERE $where
    GROUP BY p.id ORDER BY p.ano DESC, p.periodo DESC, p.id DESC
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
      <td><a href="prova.php?id=<?= $p['id'] ?>"><?= e($p['nome']) ?></a></td>
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
<p class="vazio">Cadastre a primeira prova com o formulário abaixo. Depois é só enviar as folhas dos alunos de cada turma.</p>
<?php endif; ?>

<h2 id="nova">Nova prova</h2>
<?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="formulario">
  <label>Nome da prova
    <input name="nome" required value="<?= e($_POST['nome'] ?? '') ?>" placeholder="Teste seletivo 7º ano">
  </label>

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
    <legend>Formato da folha de respostas</legend>
    <div class="linha">
      <label>Questões <input type="number" name="questoes" min="1" value="<?= e($_POST['questoes'] ?? '20') ?>"></label>
      <label>Colunas <input type="number" name="colunas" min="1" value="<?= e($_POST['colunas'] ?? '2') ?>"></label>
      <label>Alternativas <input name="alternativas" value="<?= e($_POST['alternativas'] ?? 'A,B,C,D') ?>"></label>
    </div>
    <label>Numeração
      <select name="ordem">
        <option value="colunas">Desce a primeira coluna (1 a 10), depois a segunda</option>
        <option value="linhas" <?= ($_POST['ordem'] ?? '') === 'linhas' ? 'selected' : '' ?>>Segue as linhas (1 e 2 lado a lado)</option>
      </select>
    </label>
  </fieldset>

  <label>Áreas da prova <span class="dica">Uma por linha. Pode deixar em branco e preencher depois.</span>
    <textarea name="areas" rows="3" placeholder="Linguagens: 1-10&#10;Matemática: 11-20"><?= e($_POST['areas'] ?? '') ?></textarea>
  </label>

  <fieldset>
    <legend>Gabarito oficial</legend>
    <label>Imagem ou PDF do gabarito preenchido
      <input type="file" name="gabarito_imagem" accept=".png,.jpg,.jpeg,.pdf,.tif,.tiff">
    </label>
    <label>Ou digite as respostas em ordem
      <input name="gabarito_digitado" value="<?= e($_POST['gabarito_digitado'] ?? '') ?>" placeholder="CCADCBCABADDBBCDCCCC" autocomplete="off">
    </label>
  </fieldset>

  <button type="submit">Cadastrar prova</button>
</form>

<?php rodape();
