<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$st = db()->prepare('SELECT * FROM folhas WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$folha = $st->fetch();
if (!$folha || $folha['erro'] !== null) {
    http_response_code(404);
    exit('Folha não encontrada.');
}
$prova = buscar_prova((int)$folha['prova_id']);
$alts = $prova['layout']['alternativas'];
$gabarito = $prova['gabarito'];
$respostas = json_decode($folha['respostas'], true);
$niveis = json_decode($folha['niveis'] ?? '[]', true);
$revisar = json_decode($folha['revisar'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novas = [];
    foreach ($gabarito as $i => $_) {
        $v = $_POST['q'][$i] ?? '';
        $novas[] = in_array($v, $alts, true) || $v === 'MULTIPLA' ? $v : null;
    }
    db()->prepare("UPDATE folhas SET respostas = ?, revisar = '[]', revisada = true WHERE id = ?")
        ->execute([json_encode($novas), $folha['id']]);
    redirecionar('prova.php?id=' . $prova['id'] . '&turma=' . urlencode($folha['turma']));
}

$acertos = corrigir($gabarito, $respostas);
cabecalho('Folha de ' . $folha['turma'], 'prova.php?id=' . $prova['id'] . '&turma=' . urlencode($folha['turma']));
?>
<p class="meta"><?= e($folha['arquivo']) ?>, página <?= (int)$folha['pagina'] ?>.
  <?= count(array_filter($acertos)) ?> de <?= count($gabarito) ?> acertos.
  <?= $revisar ? 'Confira as questões destacadas e salve.' : 'Você pode corrigir qualquer leitura e salvar.' ?></p>

<div class="revisao">
  <?php if ($folha['imagem_debug']): ?>
    <figure>
      <img src="imagem.php?folha=<?= (int)$folha['id'] ?>" alt="Folha lida, com as marcações identificadas">
      <figcaption>Verde: marcação lida. Laranja: leitura duvidosa.</figcaption>
    </figure>
  <?php endif; ?>

  <form method="post" class="formulario respostas">
    <table class="tabela">
      <thead><tr><th>Questão</th><th>Resposta lida</th><th>Gabarito</th></tr></thead>
      <tbody>
      <?php foreach ($gabarito as $i => $certa): $n = $i + 1; $r = $respostas[$i]; ?>
        <tr class="<?= in_array($n, $revisar, true) ? 'destaque' : '' ?>">
          <td><?= $n ?></td>
          <td>
            <select name="q[<?= $i ?>]" aria-label="Resposta da questão <?= $n ?>">
              <option value="" <?= $r === null ? 'selected' : '' ?>>Em branco</option>
              <?php foreach ($alts as $a): ?>
                <option value="<?= e($a) ?>" <?= $r === $a ? 'selected' : '' ?>><?= e($a) ?></option>
              <?php endforeach; ?>
              <option value="MULTIPLA" <?= $r === 'MULTIPLA' ? 'selected' : '' ?>>Marcação dupla</option>
            </select>
            <?php if (in_array($n, $revisar, true) && isset($niveis[$i])): ?>
              <span class="dica">Preenchimento: <?= e(implode(', ', array_map(
                  fn($a, $v) => $a . ' ' . round($v * 100) . '%', $alts, $niveis[$i]))) ?></span>
            <?php endif; ?>
          </td>
          <td class="<?= $acertos[$i] ? 'boa' : 'baixa' ?>"><?= e($certa) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit">Salvar revisão</button>
  </form>
</div>
<?php rodape();
