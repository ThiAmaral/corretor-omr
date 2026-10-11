<?php
declare(strict_types=1);

/*
 * Testes das regras do PHP que não dependem do banco nem do Storage.
 * Rodar na raiz do projeto:  php web/testes/regras.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('zend.assertions', '1');
ini_set('assert.exception', '1');
require dirname(__DIR__) . '/lib.php';

$testes = 0;
function teste(string $nome, callable $corpo): void
{
    global $testes;
    try {
        $corpo();
        $testes++;
    } catch (Throwable $ex) {
        fwrite(STDERR, "FALHOU: $nome\n  " . $ex->getMessage() . "\n");
        exit(1);
    }
}

function igual(mixed $esperado, mixed $obtido): void
{
    if ($esperado !== $obtido) {
        throw new AssertionError('esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
    }
}

/* ------------------------------------------------------------ Correção */

teste('corrigir: branco e dupla contam como erro', function () {
    igual([true, false, false, false], corrigir(['A', 'B', 'C', 'D'], ['A', null, 'MULTIPLA', 'A']));
});

/* ------------------------------------------------- Modelos de caderno */

teste('caderno de Matemática vira disciplina automática', function () {
    $m = modelo_do_caderno('2265M0801');
    igual('ama_matematica', $m['chave']);
    igual('Matemática', $m['disciplina']);
    igual(8, $m['ano_escolar']);
    igual('AMA – Matemática 8º ano (M0801)', sugerir_nome_prova('2265M0801'));
});

teste('caderno de Língua Portuguesa', function () {
    igual('Língua Portuguesa', modelo_do_caderno('2265L0902')['disciplina']);
});

teste('caderno desconhecido ou ausente fica sem modelo', function () {
    igual(null, modelo_do_caderno('2265X0801'));
    igual(null, modelo_do_caderno(null));
    igual('Prova do caderno ABC', sugerir_nome_prova('ABC'));
    igual('Prova de ' . date('d/m/Y'), sugerir_nome_prova(null));
});

/* ------------------------------------------------- Rascunho do gabarito */

teste('leitura da foto: branco e dupla ficam sem resposta, fraca fica em destaque', function () {
    $leitura = ['respostas' => [
        ['questao' => 1, 'situacao' => 'marcada', 'resposta' => 'C', 'duvidosa' => false],
        ['questao' => 2, 'situacao' => 'marcada', 'resposta' => 'A', 'duvidosa' => true],
        ['questao' => 3, 'situacao' => 'branco', 'resposta' => null, 'duvidosa' => false],
        ['questao' => 4, 'situacao' => 'multipla', 'resposta' => null, 'duvidosa' => false],
    ]];
    $q = questoes_da_leitura($leitura, 'Matemática');
    igual(['C', 'A', null, null], array_column($q, 'resposta'));
    igual([false, true, true, true], array_column($q, 'duvidosa'));
    igual(array_fill(0, 4, 'Matemática'), array_column($q, 'disciplina'));
});

teste('gabarito digitado ignora espaços e minúsculas', function () {
    $q = questoes_digitadas('cb da-a');
    igual(['C', 'B', 'D', 'A', 'A'], array_column($q, 'resposta'));
    igual(['A', 'B', 'C', 'D'], alternativas_digitadas($q));
    igual(['A', 'B', 'C', 'D', 'E'], alternativas_digitadas(questoes_digitadas('ABE')));
});

teste('gabarito digitado vazio ou com letra além do E é recusado', function () {
    foreach (['', '  ', 'ABF'] as $texto) {
        try {
            questoes_digitadas($texto);
            throw new AssertionError("aceitou \"$texto\"");
        } catch (InvalidArgumentException) {
        }
    }
});

/* --------------------------------------------------- Revisão e aprovação */

function prova_exemplo(): array
{
    $questoes = [];
    foreach (['A', null, 'C'] as $i => $r) {
        $questoes[] = ['numero' => $i + 1, 'resposta' => $r, 'disciplina' => null, 'dificuldade' => null,
            'habilidade' => null, 'duvidosa' => $r === null];
    }
    return ['layout' => ['alternativas' => ['A', 'B', 'C', 'D']], 'questoes' => $questoes];
}

teste('rascunho pode ser salvo incompleto', function () {
    [$q, $erros] = validar_revisao([['resposta' => 'A'], ['resposta' => ''], ['resposta' => 'c']], prova_exemplo(), false);
    igual([], $erros);
    igual(['A', null, 'C'], array_column($q, 'resposta'));
    igual([false, true, false], array_column($q, 'duvidosa'));
});

teste('aprovação exige resposta e disciplina em todas as questões', function () {
    [, $erros] = validar_revisao([
        ['resposta' => 'A', 'disciplina' => 'Matemática'],
        ['resposta' => '', 'disciplina' => ''],
        ['resposta' => 'C', 'disciplina' => ''],
    ], prova_exemplo(), true);
    igual(['Falta a resposta da questão 2.', 'Falta a disciplina das questões 2, 3.'], $erros);
});

teste('aprovação completa passa, com dificuldade e habilidade', function () {
    [$q, $erros] = validar_revisao([
        ['resposta' => 'A', 'disciplina' => 'Matemática', 'dificuldade' => 'facil', 'habilidade' => ' d07 '],
        ['resposta' => 'B', 'disciplina' => 'Matemática', 'dificuldade' => 'dificil'],
        ['resposta' => 'C', 'disciplina' => 'Língua Portuguesa', 'dificuldade' => 'xyz'],
    ], prova_exemplo(), true);
    igual([], $erros);
    igual(['facil', 'dificil', null], array_column($q, 'dificuldade'));
    igual(['D07', null, null], array_column($q, 'habilidade'));
    igual([false, false, false], array_column($q, 'duvidosa'));
});

teste('alternativa e disciplina fora da lista são recusadas', function () {
    [, $erros] = validar_revisao([
        ['resposta' => 'E', 'disciplina' => 'Matemática'],
        ['resposta' => 'B', 'disciplina' => 'Astrologia'],
        ['resposta' => 'C', 'disciplina' => 'Matemática'],
    ], prova_exemplo(), false);
    igual(['A resposta da questão 1 precisa ser uma das alternativas (A, B, C, D).',
        'A disciplina da questão 2 não está na lista.'], $erros);
});

teste('disciplina antiga gravada na prova continua aceita', function () {
    $prova = prova_exemplo();
    $prova['questoes'][0]['disciplina'] = 'Linguagens';
    [$q, $erros] = validar_revisao([['resposta' => 'A', 'disciplina' => 'Linguagens']], $prova, false);
    igual([], $erros);
    igual('Linguagens', $q[0]['disciplina']);
});

teste('listar_numeros junta intervalos', function () {
    igual('1 a 3, 5, 7, 8 e 10', listar_numeros([10, 1, 2, 3, 5, 7, 8]));
    igual('4', listar_numeros([4]));
});

teste('faixas de disciplina remontadas das questões', function () {
    $q = [['numero' => 1, 'disciplina' => 'LP'], ['numero' => 2, 'disciplina' => 'LP'],
        ['numero' => 3, 'disciplina' => null], ['numero' => 4, 'disciplina' => 'MAT']];
    igual([['nome' => 'LP', 'de' => 1, 'ate' => 2], ['nome' => 'MAT', 'de' => 4, 'ate' => 4]], areas_das_questoes($q));
});

/* --------------------------------- Formato vindo da 1ª folha (gabarito digitado) */

function leitura_ama(string $caderno = '2265M0801', array $blocos = [7, 7, 7, 5]): array
{
    return ['caderno' => $caderno, 'layout' => ['caderno' => $caderno, 'alternativas' => ['A', 'B', 'C', 'D'],
        'blocos' => $blocos, 'questoes' => array_sum($blocos)]];
}

teste('primeira folha define formato e caderno', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D']];
    igual(null, conferir_formato_da_folha($layout, leitura_ama(), array_fill(0, 26, 'A')));
    igual('2265M0801', $layout['caderno']);
    igual([7, 7, 7, 5], $layout['blocos']);
    igual(true, formato_conhecido($layout));
    igual('Caderno 2265M0801 · 26 questões em 4 blocos (7, 7, 7, 5) · alternativas A–D', descrever_formato($layout, 26));
});

teste('folhas seguintes precisam ser do mesmo caderno', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D']];
    $gab = array_fill(0, 26, 'B');
    conferir_formato_da_folha($layout, leitura_ama(), $gab);
    igual(null, conferir_formato_da_folha($layout, leitura_ama(), $gab));
    igual('Esta folha é do caderno 2265L0801, e a prova é do caderno 2265M0801.',
        conferir_formato_da_folha($layout, leitura_ama('2265L0801'), $gab));
});

teste('número de questões diferente do digitado é recusado', function () {
    $layout = ['questoes' => 20, 'alternativas' => ['A', 'B', 'C', 'D']];
    igual('Esta folha tem 26 questões, e o gabarito da prova tem 20.',
        conferir_formato_da_folha($layout, leitura_ama(), array_fill(0, 20, 'A')));
    igual(false, formato_conhecido($layout));
});

teste('gabarito com alternativa que a folha não tem é recusado', function () {
    $layout = ['questoes' => 26, 'alternativas' => ['A', 'B', 'C', 'D', 'E']];
    $gab = array_fill(0, 26, 'A');
    $gab[3] = 'E';
    igual('O gabarito usa a alternativa E, que não existe nesta folha (A, B, C, D).',
        conferir_formato_da_folha($layout, leitura_ama(), $gab));
});

echo "$testes testes passaram.\n";
