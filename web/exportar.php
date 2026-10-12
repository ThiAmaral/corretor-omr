<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Relatório da prova em CSV, para abrir no Excel ou no LibreOffice em português:
 * separador ";", vírgula decimal e BOM para o UTF-8 ser reconhecido.
 *   exportar.php?id=4&tipo=questoes[&turma=8A]  uma linha por questão (prioridade, acerto, erro comum)
 *   exportar.php?id=4&tipo=turmas               uma linha por turma (acerto por disciplina e por nível)
 */

iniciar_pagina();
$prova = buscar_prova((int)($_GET['id'] ?? 0));
if ($prova['status'] === 'rascunho') {
    throw new ErroDoUsuario('Prova sem gabarito aprovado.', 404);
}
$tipo = $_GET['tipo'] ?? 'questoes';
if (!in_array($tipo, ['questoes', 'turmas'], true)) {
    throw new ErroDoUsuario('Tipo de exportação inválido.', 400);
}
$questoes = $prova['questoes'];
[$lidas] = carregar_folhas($prova);
$turma = (string)($_GET['turma'] ?? '');
if ($turma !== '') {
    $lidas = array_values(array_filter($lidas, fn($f) => $f['turma'] === $turma));
}

function numero_csv(?float $v): string
{
    return $v === null ? '' : number_format($v, 1, ',', '');
}

$linhas = [];
if ($tipo === 'questoes') {
    $estatisticas = estatisticas_das_questoes($questoes, $lidas);
    $posicao = [];
    foreach (prioridades_de_revisao($estatisticas) as $i => $p) {
        $posicao[$p['numero']] = $i + 1;
    }
    $linhas[] = ['Questão', 'Disciplina', 'Habilidade', 'Gabarito', 'Dificuldade marcada', 'Alunos', 'Acertos',
        'Acerto (%)', 'Dificuldade observada', 'Diverge', 'Erro comum', 'Alunos no erro comum',
        'Em branco ou dupla', 'Prioridade de revisão'];
    foreach ($estatisticas as $e) {
        $linhas[] = [$e['numero'], $e['disciplina'], $e['habilidade'] ?? '', $e['certa'],
            DIFICULDADES[$e['dificuldade']] ?? '', $e['alunos'], $e['acertos'], numero_csv($e['taxa']),
            DIFICULDADES[$e['observada']] ?? '', $e['diverge'] ? 'sim' : '', $e['distrator'] ?? '',
            $e['distrator'] !== null ? $e['distrator_qtd'] : '', $e['brancos'], $posicao[$e['numero']] ?? ''];
    }
} else {
    $disciplinas = array_values(array_unique(array_map('disciplina_da_questao', $questoes)));
    $niveis = niveis_da_prova($questoes);
    $linhas[] = array_merge(['Turma', 'Folhas', 'Acerto (%)'],
        array_map(fn($d) => "$d (%)", $disciplinas),
        array_map(fn($n) => DIFICULDADES[$n] . ' (%)', $niveis),
        ['Para reforço', 'A revisar']);
    foreach (resumo_por_turma($questoes, $lidas) as $t => $r) {
        $linhas[] = array_merge([(string)$t, $r['folhas'], numero_csv(pct($r['acertos'], $r['total']))],
            array_map(fn($d) => numero_csv(pct(...($r['disciplinas'][$d] ?? [0, 0]))), $disciplinas),
            array_map(fn($n) => numero_csv(pct(...($r['niveis'][$n] ?? [0, 0]))), $niveis),
            [$r['reforco'], $r['revisar']]);
    }
}

$nome = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $prova['nome']) ?: 'prova');
$arquivo = trim($nome, '_') . ($turma !== '' ? '_' . preg_replace('/[^A-Za-z0-9]+/', '', $turma) : '') . "_$tipo.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF");
foreach ($linhas as $l) {
    fputcsv($saida, $l, ';', '"', '');
}
fclose($saida);
