# Acompanhamento da execução do plano

Registro do que foi feito em cada fase do [PLANO_DE_FASES.md](PLANO_DE_FASES.md),
com data, resultado medido e pendências. As caixas do plano são marcadas conforme
os itens ficam prontos; aqui ficam os detalhes.

Legenda: ✅ feito · 🔄 em andamento · ⏳ pendente · ⚠️ bloqueado / depende de terceiros

## Visão geral

| Fase | Situação | Observação |
|---|---|---|
| 0. Preparação | 🔄 | Git pronto; faltam as folhas AMA reais e o significado do QR |
| 1. Leitor AMA com detecção do formato | ✅ (com fotos geradas) | Falta validar com fotos reais (depende da Fase 0) |
| 2. Supabase | ✅ | Testado no projeto real (`xcmyknrojawmolnffltf`). Só o Docker não foi testado (não instalado nesta máquina) |
| 3. Revisão do gabarito, áreas e dificuldade | ✅ | Testada no projeto real (migração aplicada pelo Thiago) |
| 4. Relatórios por dificuldade | ✅ | Testada no projeto real com as provas de teste (2026-10-12) |
| 5. Fotos de celular | ✅ (com fotos simuladas) | Falta calibrar com fotos reais (depende da Fase 0) |
| 6. API e login | ✅ | Testada com o login real do Supabase (2026-10-12). HTTPS depende da hospedagem |
| 7. App | ⏳ | |
| 8. Piloto | ⏳ | |

---

## Fase 0: preparação

| Item | Situação | Observação |
|---|---|---|
| Repositório git + GitHub privado | ✅ | `origin` = github.com/ThiAmaral/corretor-omr, commit inicial `c8225dc` |
| Juntar folhas AMA reais (em branco de outros cadernos, preenchidas, fotos, scans) | ⚠️ | Depende da escola. Por enquanto só existe a folha em branco do M0801 |
| Entender o código do QR e o número abaixo da grade | ⚠️ | Aguardando o professor orientador (ver "Perguntas em aberto" no plano) |

A Fase 1 foi feita sem as fotos reais: o leitor foi validado com folhas AMA
**geradas** a partir do PDF em branco. O critério "95% das fotos reais" fica
pendente até a Fase 0 entregar as fotos.

---

## Fase 1: leitor da folha AMA com detecção automática do formato

Início e término da implementação: 2026-10-11. Commits `8915d92`, `b78e568` e
o commit de testes/documentação logo depois.

### Resultados

Medidos com `ferramentas/validar_leitura.py` (sem `--layout`, ou seja, com o
formato detectado sozinho, salvo no modelo antigo).

| Conjunto | Antes | Depois |
|---|---|---|
| Folha AMA em branco (scan do PDF) | 4 de 104 bolinhas achadas | 104/104; caderno, blocos `[7, 7, 7, 5]` e 4 alternativas detectados |
| `exemplos/ama/geradas_8A` — 20 fotos simuladas (perspectiva, giro até 8°, sombra, desfoque) | não lia | **20/20 folhas lidas**, 508/508 respostas certas (100%), 12/12 marcações fracas para revisão, layout certo 20/20, caderno (QR) 19/20 |
| Conjunto difícil — 30 fotos, giro até 15°, 30% de cabeça para baixo (gerado na hora, não versionado) | não lia | **30/30 lidas**, 757/757 certas (100%), 23/23 fracas para revisão, layout 30/30, caderno 27/30 |
| Fotos de `geradas_8A` com **um marcador coberto** (80 casos) | — | 78/80 lidas certo; as 2 restantes são recusadas com pedido de nova foto |
| `exemplos/provas_7A` (modelo antigo) | 235/235 | 235/235 (100%) |
| `exemplos/provas_7B` (modelo antigo) | 194/194 | 194/194 (100%) |
| Testes `pytest` (`worker/testes`) | — | 18/18 passando |

Tempo de leitura: cerca de 0,2 s por foto (20 fotos em ~4,5 s, num notebook).

Onde o QR falha (1 a 3 por conjunto), a folha é lida mesmo assim, com
confiança **baixa** e o motivo "Não consegui ler o QR code". Em geral é por
causa do desfoque forte da simulação somado ao QR já borrado do PDF de origem.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 1.1 | `alinhar_por_marcadores()` (4 quadrados pretos) | ✅ | Com `normalizar_iluminacao()` antes e `localizar_marcadores()` separado. Estima o 4º marcador se só 3 aparecerem |
| 1.2 | `ler_qr()` (código do caderno) | ✅ | Também `ler_qr_acima()`: lê o QR no recorte endireitado acima da área, bem mais confiável em foto. O QR define o lado de cima |
| 1.3 | Bolinhas com tamanho proporcional aos marcadores | ✅ | Diâmetro aceito: 0,45 a 1,6 × lado do marcador |
| 1.4 | Blocos de questões (`detectar_layout()`, `organizar_grade()` por blocos) | ✅ | Espaços pequenos = alternativas, grandes = blocos. Bolinhas faltantes estimadas pela grade. Tolera área cisalhada |
| 1.5 | Novo formato de `Layout` (`blocos`, `caderno`), compatível com o antigo | ✅ | `questoes` + `colunas` vira blocos (26 em 4 → `[7, 7, 7, 5]`) |
| 1.6 | Conferência do caderno (folha × prova) | ✅ | "Esta folha é do caderno X, e a prova é do caderno Y". O PHP guarda o caderno lido no gabarito |
| 1.7 | Confiança da leitura | ✅ | `confianca: {nivel, motivos}`: sem marcador/moldura, sem QR, marcador estimado, bolinhas estimadas |
| 1.8 | `omr_cli.py detectar <imagem>` | ✅ | `--layout` também ficou opcional em `ler` e `corrigir` |
| 1.9 | `gerar_folhas_teste.py` gera folhas AMA com cara de foto | ✅ | Recebe o PDF; gera `gabarito.jpg` + alunos; `--invertidas` para fotos de cabeça para baixo |
| 1.10 | `validar_leitura.py` mede caderno e layout | ✅ | Também folhas lidas e confiança |
| 1.11 | `web/index.php` sem a validação "divisível por colunas" | ✅ | E guarda `blocos`/`caderno` do gabarito no layout; `prova.php` mostra o caderno |
| 1.12 | Testes `pytest` de casos de borda em `worker/testes/` | ✅ | Virada, deitada, sem QR, sem QR e virada, sem cada um dos marcadores, caderno errado, layout errado, sem folha, em branco, toda marcada, modelo antigo |
| 1.13 | Validação com fotos reais (≥ 95%) | ⚠️ | Depende da Fase 0 |

### Mudanças fora do previsto

- **Correção de bug antigo:** no Windows sem Docker, a saída do Python não era
  UTF-8, e qualquer mensagem com acento quebrava o JSON lido pelo PHP ("Resposta
  inválida do leitor óptico"). O `omr_cli.py` agora força UTF-8.
- **Calibração:** o "nível de bolinha vazia" passou a ter teto (`BASE_MAXIMA =
  0,50`). Sem isso, uma folha com todas as bolinhas marcadas era lida como em
  branco.
- **Adiantado da Fase 5:** normalização da iluminação (dividir pelo fundo) e
  tolerância a cisalhamento no agrupamento das bolinhas.
- **Novo campo** `girada` no resultado, e `requirements-dev.txt` com o `pytest`.

### Pendências e riscos

- **Fotos reais (Fase 0).** Os limiares (`LIMIAR_MARCADA`, `ZONA_DUVIDA`) foram
  calibrados em simulação. Com as fotos reais, rodar o validador e ajustar.
- **Formatos que ainda não vimos.** A folha com todas as disciplinas e a de
  Língua Portuguesa ainda não foram testadas. O leitor não supõe 26 questões
  nem 4 blocos, mas supõe: 4 marcadores nos cantos, QR acima da área de
  respostas e numeração descendo cada bloco.
- **Folha virada sem QR** é reconhecida pela regra "o bloco menor fica à
  direita". Se um caderno tiver todos os blocos do mesmo tamanho e o QR não
  for lido, não há como saber o lado de cima. Nesse caso a confiança já sai
  baixa.
- **Confiança baixa ainda não aparece no painel web.** O PHP recebe o campo,
  mas a tela de envio só mostra erro/ok. Mostrar isso faz parte das Fases 3/6.

### Diário

- **2026-10-11** — Linha de base medida. Folha AMA renderizada a partir do PDF
  (A4, 200 dpi) para estudar a geometria.
- **2026-10-11** — Reescrita do `omr.py`: marcadores, QR, blocos, layout novo,
  confiança. A folha em branco passou a ser lida inteira na primeira versão.
  Um falso positivo (quadradinhos do campo "NOME" do modelo antigo vistos como
  marcadores) foi corrigido exigindo quadrado preto por dentro e isolado.
- **2026-10-11** — Gerador de fotos AMA. A primeira rodada leu só 6/20: o
  limiar global falhava com mesa escura e sombra, e o QR quase nunca era lido.
  Resolvido com normalização da iluminação, caixa girada para os marcadores e
  leitura do QR no recorte endireitado: 20/20.
- **2026-10-11** — Casos de um marcador coberto: estimativa do 4º canto,
  agrupamento tolerante a cisalhamento e mensagem de erro que orienta a tirar
  outra foto. Fluxo web testado de ponta a ponta (cadastro com foto do gabarito,
  envio de 3 fotos AMA + 1 folha de outro formato, recusada).
- **2026-10-11** — Testes `pytest` (18), README e plano atualizados.

---

## Fase 2: banco de dados no Supabase

Início: 2026-10-11.

O código foi adaptado e testado **sem** um projeto real no Supabase: o banco de
teste foi um PostgreSQL local (PGlite, o PostgreSQL 18 compilado para
WebAssembly, rodando em `npx pglite-server`) com a migração aplicada, e o
Storage foi simulado por um servidor PHP que responde às mesmas rotas da API do
Supabase Storage. Nada disso foi versionado; é só para teste.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 2.1 | Criar o projeto no Supabase, `.env` com as chaves | ✅ | Projeto `xcmyknrojawmolnffltf` (São Paulo), criado pelo Thiago em 2026-10-11, que também preencheu a senha e a chave secret no `.env` (fora do git) |
| 2.2 | Migração `supabase/migrations/20261011220051_esquema_inicial.sql` | ✅ | `supabase init` + `migration new`. Tabelas `provas`, `questoes`, `folhas`, RLS ligado e sem políticas. Também cria os buckets `folhas` e `anotadas` |
| 2.2 | Aplicar no projeto (`npx supabase link` + `db push`) | ✅ | 2026-10-11, pelo Thiago: `20261011220051_esquema_inicial.sql` aplicada sem erro. Session pooler em `aws-1-sa-east-1` |
| 2.3 | `pdo_pgsql` no Docker e no `iniciar.cmd` | ✅ | `iniciar.cmd` liga `pdo_pgsql`, `curl` e `openssl` com `-d`, sem editar o `php.ini`; recusa subir sem `.env` |
| 2.3 | `db()` lê a `DATABASE_URL`; sem `CREATE TABLE`/`PRAGMA` | ✅ | `.env` lido pelo PHP quando roda sem Docker; no Docker, `env_file` |
| 2.3 | Diferenças SQLite → PostgreSQL | ✅ | `RETURNING id`, `revisada = true`, `NULLS LAST`, gabarito na tabela `questoes`, JSON em `jsonb` |
| 2.3 | Imagens no Storage | ✅ | Só a área de respostas recortada (`folhas`) e a anotada (`anotadas`), em JPEG. A foto enviada e o gabarito ficam numa pasta temporária apagada no fim da requisição |
| 2.3 | `imagem.php` com URL assinada | ✅ | `imagem.php?folha=<id>` busca o caminho no banco e redireciona para uma URL que vale 5 minutos |
| 2.3 | Começar com o banco vazio | ✅ | Os dados do SQLite eram de teste; nada foi migrado. A pasta `armazenamento/` deixa de ser usada |
| — | Teste do fluxo com o projeto real | ✅ | Ver "Teste no projeto real" abaixo |

### Resultados do teste local

| Teste | Resultado |
|---|---|
| Migração aplicada no PostgreSQL 18 | 3 tabelas e 2 buckets criados, sem erro |
| Cadastro da prova AMA com foto do gabarito | Caderno `2265M0801` e blocos `[7, 7, 7, 5]` gravados no `layout`; 26 linhas em `questoes` |
| Envio das 20 fotos de `geradas_8A` | 20/20 lidas; 40 envios ao Storage; acerto da turma **63,1%**, igual ao `omr_cli.py corrigir` |
| Folha do modelo antigo enviada na prova AMA | Recusada com o motivo, sem imagem guardada |
| Modelo antigo: gabarito digitado, PDF da 7A + 10 fotos da 7B | 12 + 10 lidas; acerto e áreas iguais ao `omr_cli.py` (61,25% na 7A aparece como 61,3% no PHP e 61,2% no Python: só arredondamento) |
| Revisão de uma folha | `revisada = true`, `revisar = []` |
| Imagem anotada | `imagem.php` redireciona (302) para a URL assinada; id inexistente dá 404 |
| Trocar as áreas | Disciplinas regravadas nas questões; colunas do relatório mudam na hora |
| Áreas sobrepostas | Recusadas com mensagem |
| Filtros por ano e período | Funcionam; período vazio vai para o fim (`NULLS LAST`) |
| Storage recusando o envio (chave errada) | Mensagem "O Supabase Storage recusou a operação (401)"; nenhuma folha gravada no banco |
| Arquivos no disco depois das requisições | Nenhum: pasta temporária apagada, `armazenamento/` não é tocada |
| `pytest worker/testes` | 18/18 |

### Teste no projeto real (2026-10-11)

Feito com o PHP sem Docker, lendo o `.env`, como o `iniciar.cmd` faz.

| Teste | Resultado |
|---|---|
| Conexão pelo Session pooler (`aws-1-sa-east-1`) | OK |
| Tabelas `provas`, `questoes`, `folhas` | RLS ligado, 0 políticas |
| Buckets `folhas` e `anotadas` | Privados. Acesso sem assinatura: HTTP 400; com URL assinada: HTTP 200 |
| Prova AMA: gabarito por foto + 20 fotos da 8A | 20/20 lidas, **63,1%** (igual ao teste local); folha de outro formato recusada |
| Modelo antigo: gabarito digitado + PDF da 7A + 10 fotos da 7B | 12 + 10 lidas; 7A 61,3% (Linguagens 63,3%, Matemática 59,2%), 7B 61,5% (60,0%, 63,0%), iguais ao teste local |
| Imagem anotada na revisão | Redireciona para a URL assinada e abre (JPEG, ~78 KB); id inexistente dá 404 |
| Revisão de folha, troca de áreas, filtro por ano | OK |
| Arquivos deixados no disco | Nenhum |

Tempo: **20 fotos em ~21 s** (~1 s por folha: leitura + 2 envios ao Storage em
São Paulo), contra ~9 s com o Storage local. O cadastro com foto do gabarito
leva ~1,5 s.

Ficaram no projeto as duas provas de teste ("TESTE Fase 2 – …", ids 1 e 2) e
as imagens das suas 42 folhas no Storage.

Espaço no Storage por folha AMA: cerca de **58 KB** (recorte) + **74 KB**
(anotada). No plano Free (1 GB), cabem umas **7 mil folhas**. No modelo antigo
são ~130 + ~140 KB.

### Mudanças fora do previsto

- **Imagem anotada em JPEG.** Em PNG ela tinha ~600 KB por folha, e o Storage
  gratuito encheria com ~1.500 folhas. O `omr_cli.py --debug` agora grava
  `.jpg` (qualidade 80).
- **O Python mudou um pouco**, ao contrário do previsto: com `--debug`, o
  `omr_cli.py` também grava o recorte limpo da área de respostas
  (`imagem_recorte`), que é o que vai para o bucket `folhas`. O `omr.py` não mudou.
- **Recorte pela região das bolinhas** (`recortar_bolinhas()` no `omr_cli.py`),
  nas duas imagens. No modelo antigo, a área dentro da moldura incluía nome
  completo, data de nascimento e assinatura; agora nenhum dos dois modelos
  guarda dados pessoais.
- **Prepared statements emulados no PDO** (`ATTR_EMULATE_PREPARES`). Os poolers
  do Supabase podem trocar a conexão entre as requisições; preparar no PHP
  funciona com o Session e com o Transaction pooler.
- **Chave secret no cabeçalho `apikey`**, como manda a documentação atual do
  Supabase para as chaves novas (`sb_secret_...`). Se for usada a chave antiga
  (JWT `service_role`), ela vai também no `Authorization: Bearer`.
- **Cadastro sem rascunho no banco:** antes, a prova era gravada e apagada se o
  gabarito falhasse. Agora o gabarito é lido primeiro e a prova e as questões
  são gravadas juntas, numa transação.
- **Certificados no Windows:** o PHP do winget não traz a lista de
  certificados, e o `curl` recusava o HTTPS do Supabase. No Windows, o
  `storage()` usa o repositório de certificados do próprio sistema
  (`CURLSSLOPT_NATIVE_CA`); a verificação continua ligada. No Docker não muda nada.
- **Áreas sem sobreposição:** como cada questão guarda uma disciplina só,
  `ler_areas()` recusa áreas que usam as mesmas questões.

### Pendências e riscos

- **Senha do banco exposta na conversa com o assistente.** Trocar em Project
  Settings → Database → Reset database password e atualizar o `.env`.
- **Provas de teste no projeto real** (ids 1 e 2). Apagar antes do piloto,
  junto com as imagens no Storage (o `on delete cascade` apaga as linhas, mas
  não os arquivos dos buckets).
- **Docker não foi testado nesta máquina** (o Docker não está instalado). O
  `Dockerfile` ganhou só `libpq-dev` + `docker-php-ext-install pdo_pgsql`.
- **Latência:** ~1 s por folha no projeto real. Uma turma de 40 fica perto de
  40 s numa requisição só; se incomodar, os envios podem ser feitos em paralelo
  (`curl_multi`) ou ir para a fila da versão com RabbitMQ.
- **DER no Visual Paradigm**, se o orientador pedir: o esquema está na migração.

### Diário

- **2026-10-11** — `supabase init` e migração do esquema inicial (com os
  buckets). Testada num PostgreSQL local.
- **2026-10-11** — `lib.php` passa a usar a `DATABASE_URL`, o Storage via `curl`
  e uma pasta temporária por requisição. `index.php`, `prova.php`, `folha.php`
  e `imagem.php` adaptados.
- **2026-10-11** — Projeto `xcmyknrojawmolnffltf` criado, `supabase link` e
  `db push` feitos pelo Thiago; migração aplicada no projeto real.
- **2026-10-11** — Fluxo testado de ponta a ponta (AMA e modelo antigo) contra o
  PostgreSQL local e o Storage simulado; números iguais aos do leitor direto.
  Imagem anotada trocada de PNG para JPEG. Dockerfile, `docker-compose.yml`,
  `.env.exemplo`, `iniciar.cmd` e README atualizados.
- **2026-10-11** — `.env` completo (senha e chave secret preenchidas pelo
  Thiago, sem os marcadores `[ ]` e `< >` dos exemplos). Conexão, RLS e buckets
  conferidos. Corrigida a verificação de certificado do `curl` no Windows.
  Fluxo completo testado no projeto real: mesmos números do teste local.

---

## Fase 3: revisão do gabarito oficial, áreas e dificuldade

Início e término da implementação: 2026-10-11.

Testada primeiro como a Fase 2 (PostgreSQL local com PGlite e Storage
simulado) e depois no projeto real, com o navegador e com `curl`.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 3.1 | Migração `20261011232850_revisao_do_gabarito.sql` | ✅ | Aplicada no projeto real pelo Thiago (2026-10-11). `provas.imagem_gabarito`, `questoes.duvidosa`, `questoes.resposta` pode ficar vazia no rascunho |
| 3.2 | Cadastro novo em `index.php` | ✅ | Só ano, período e gabarito (foto **ou** digitado); nome opcional. Sem questões, colunas, numeração nem áreas. Grava `status = 'rascunho'` e abre a revisão |
| 3.3 | Catálogo de disciplinas e `modelos_de_prova()` | ✅ | `disciplinas()` (8 disciplinas fixas). Caderno `…M0801` → Matemática, 8º ano, todas as questões preenchidas. `L` → Língua Portuguesa como **sugestão** (não confirmado). Caderno desconhecido: o professor escolhe |
| 3.4 | Tela `gabarito.php` | ✅ | Imagem anotada do gabarito (`imagem.php?prova=`), formato detectado, nome sugerido editável, um cartão por questão: resposta (botões A–D), disciplina (lista), dificuldade (Fácil/Médio/Difícil), habilidade opcional |
| 3.5 | Seleção de intervalo e faixa colorida | ✅ | `gabarito.js`: clique na primeira e na última questão (no cartão ou na faixa), aplica disciplina ou dificuldade; "Selecionar todas". Sem JavaScript, a tela funciona questão por questão |
| 3.6 | Aprovação | ✅ | `rascunho` → `ativa`. Exige resposta e disciplina em todas as questões (dificuldade é opcional). Rascunho pode ser salvo incompleto ou descartado. `prova.php` de rascunho redireciona para a revisão |
| 3.7 | Gabarito digitado | ✅ | Formato e caderno vêm da 1ª folha boa; folhas com outro número de questões, outro caderno ou alternativa inexistente são recusadas com o motivo |
| 3.8 | `prova.php` | ✅ | Sai o `textarea` de áreas; entra o link "Editar gabarito e dificuldade" (mesma tela). Numa prova ativa, a revisão não deixa apagar resposta nem disciplina |
| 3.9 | Testes PHP | ✅ | `php web/testes/regras.php`: 19 testes (correção, modelos de caderno, rascunho, aprovação, formato pela 1ª folha) |
| 3.10 | Teste de ponta a ponta | ✅ | Local e no projeto real; ver abaixo |

### Resultados do teste local

| Cenário | Resultado |
|---|---|
| M0801 só com a foto do gabarito | Caderno, blocos `[7, 7, 7, 5]` e nome "AMA – Matemática 8º ano (M0801)" sugeridos; 26 questões já com Matemática; gabarito lido igual ao esperado. Dificuldade marcada por intervalo (1–7 Fácil, 21–26 Difícil), habilidade D07, aprovada. 20 fotos da 8A: **63,1%**, igual à Fase 2; folha do modelo antigo recusada |
| M0801 com o gabarito digitado | Rascunho sem formato; envio de folhas bloqueado antes da aprovação; aprovação recusada sem disciplina ("Falta a disciplina das questões 1 a 26"). Depois de aprovada: folha de 20 questões recusada ("Esta folha tem 20 questões, e o gabarito da prova tem 26"), a 1ª folha AMA definiu caderno `2265M0801` e blocos; **63,1%**; num 2º envio, folha de outro formato recusada |
| Várias disciplinas (modelo antigo, sem QR) | Nenhuma disciplina pré-preenchida; 1–10 Língua Portuguesa e 20→11 Matemática (seleção ao contrário também funciona). Aprovação com 11–20 vazias recusada sem perder o que foi escolhido. 7A (PDF) **61,3%** (63,3% / 59,2%) e 7B **61,5%** (60,0% / 63,0%), iguais à Fase 2 |
| Foto de gabarito com marcações fracas e branco (`8A_aluno02`) | Questões 12, 13, 18 e 24 em destaque; 12, 18 e 24 sem resposta (exigida na aprovação) |
| Descartar rascunho | Linhas apagadas e imagem do gabarito removida do Storage |
| Editar prova ativa | Trocar a resposta da questão 1 recalculou o relatório na hora (63,1% → 61,0%); apagar uma disciplina foi recusado |
| Celular (375 px) | Sem rolagem horizontal; cartões em uma coluna; barra de seleção deixa de ser fixa |
| `pytest worker/testes` | 18/18 |

### Teste no projeto real (2026-10-11)

Conferido antes, só com leitura, que as colunas `provas.imagem_gabarito` e
`questoes.duvidosa` existiam no banco.

| Cenário | Resultado |
|---|---|
| M0801 por foto ("TESTE Fase 3 - AMA foto", id 4) | Caderno, modelo Matemática e imagem do gabarito no Storage (abre pela URL assinada). Aprovada; 8A **63,1%**; folha do modelo antigo recusada |
| M0801 digitada ("TESTE Fase 3 – AMA digitado", id 5) | Caderno `2265M0801`, modelo e blocos gravados pela 1ª folha; 8A **63,1%**; folha de 20 questões recusada |
| Várias disciplinas ("TESTE Fase 3 – várias disciplinas", id 6) | 7A **61,3%** (63,3% / 59,2%), 7B **61,5%** (60,0% / 63,0%) |
| Descartar rascunho | OK (id 3, criado por engano durante o teste) |

Tempo: 21 fotos em 33 a 40 s (leitura + 2 envios ao Storage por folha), um
pouco acima dos ~21 s da Fase 2; a variação parece ser da rede.

**Erro encontrado e corrigido:** um nome enviado em Windows-1252 (o `curl` do
Git Bash) não era UTF-8 válido, e o PDO acabava gravando a prova de forma que
as questões davam violação de chave estrangeira. O navegador sempre manda UTF-8,
mas o app da Fase 6 é outro cliente. Agora o `lib.php` converte qualquer texto
recebido em `$_GET`/`$_POST` para UTF-8 (`utf8_valido()`), com teste.

### Mudanças fora do previsto

- **Rascunho só depois da leitura:** o plano dizia gravar o rascunho e depois
  revisar; isso foi mantido, mas a foto é lida antes de gravar. Se a foto não
  puder ser lida, nada é gravado e o professor recebe o motivo.
- **Questão duvidosa no rascunho:** marcação fraca abaixo do limiar vem do
  leitor como "em branco duvidosa", então fica sem resposta e em destaque.
- **Imagem do gabarito** vai para o bucket `anotadas`, em `gabaritos/<aleatório>.jpg`.
- **Erros de validação agrupados** ("Falta a disciplina das questões 1 a 26"),
  em vez de uma linha por questão.
- **Removidos** `ler_areas()`, `areas_texto()`, `salvar_areas()` e
  `criar_prova()`, que só serviam ao formulário antigo.

### Pendências e riscos

- **Provas de teste no projeto real:** ids 1 e 2 (Fase 2) e 4, 5 e 6 (Fase 3),
  com as imagens no Storage. Apagar antes do piloto (o banco apaga em cascata,
  mas os arquivos dos buckets não). As da Fase 2 continuam funcionando, com as
  áreas antigas ("Linguagens") aceitas na revisão.
- **Letra do caderno para Língua Portuguesa** e o significado do resto do código
  (pergunta em aberto da Fase 0). Até lá, só o `M` é confirmado.
- **Dificuldade é opcional** na aprovação. Se a Fase 4 depender dela em todas as
  questões, dá para exigir com uma linha em `validar_revisao()`.
- **Confiança baixa da leitura do gabarito** (ex.: QR não lido) ainda não é
  mostrada na revisão; aparece só como "caderno ausente" no formato.

### Diário

- **2026-10-11** — Fase iniciada. Conferido que o leitor detecta sozinho o
  formato também do modelo antigo (blocos `[10, 10]`, 7B com 61,5%, igual ao
  layout manual): o formulário de formato pode sair sem perder o modelo antigo.
- **2026-10-11** — Migração nova; `lib.php` com catálogo de disciplinas,
  modelos por caderno, rascunho, validação da revisão e formato pela 1ª folha.
  `index.php` reescrito; `gabarito.php` + `gabarito.js` novos; `prova.php` e
  `imagem.php` adaptados. Testes PHP (18).
- **2026-10-11** — Teste de ponta a ponta local nos três cenários do "pronto
  quando" e nos casos de borda. Ajustes: erros agrupados e barra de seleção não
  fixa no celular.
- **2026-10-11** — Migração aplicada no projeto real pelo Thiago. Três cenários
  repetidos lá, com os mesmos números. Corrigido o texto recebido fora de UTF-8.

---

## Fase 4: relatórios por dificuldade

Início: 2026-10-12.

Objetivo: o professor abre a turma e vê em menos de um minuto quais questões
revisar e por quê.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 4.1 | Cálculos do relatório no `lib.php` (funções puras, testáveis) | ✅ | `carregar_folhas()`, `resumo_por_turma()`, `estatisticas_das_questoes()`, `prioridades_de_revisao()`, `acerto_por_habilidade()`, `matriz_de_dificuldade()`, `filtrar_questoes()`. Faixas `LIMITE_FACIL` (70) e `LIMITE_DIFICIL` (30) configuráveis como o `LIMITE_REFORCO` |
| 4.2 | Tabela de turmas com acerto por nível (Fácil, Médio, Difícil) | ✅ | Colunas agrupadas "Por disciplina" e "Por dificuldade"; só aparecem os níveis usados na prova |
| 4.3 | Lista "Prioridades de revisão" por turma, com erro comum e agrupada por habilidade | ✅ | Logo abaixo da tabela; clicar na turma leva direto a ela (`#prioridades`). Grupos "Conteúdo que a turma deveria dominar", "Sem nível" e "Difíceis", cada questão com o motivo em uma frase. Tabela "Habilidades com acerto baixo". Sem turma escolhida, soma todas |
| 4.4 | Mapa de questões com selo de dificuldade e filtros por disciplina e dificuldade | ✅ | Letra F/M/D no canto da bolinha. Filtros por GET (funcionam sem JavaScript); o de disciplina só aparece com mais de uma |
| 4.5 | Dificuldade prevista × observada (faixas configuráveis) | ✅ | Matriz 3×3 (marcada × observada), concordância em % e tabela das questões divergentes |
| 4.6 | Exportação CSV e versão para impressão | ✅ | `exportar.php?tipo=questoes` (por turma ou todas) e `tipo=turmas`: `;`, vírgula decimal e BOM, para o Excel em português. Impressão por `@media print`, com data e turma no topo |
| 4.7 | Testes PHP dos resumos e das prioridades | ✅ | 10 testes novos (29 no total), passando |
| 4.8 | Teste de ponta a ponta (no projeto real) | ✅ | Ver abaixo. A Fase 4 só lê o banco, então não precisou do PostgreSQL local |

### Teste no projeto real (2026-10-12)

PHP sem Docker lendo o `.env`, como o `iniciar.cmd`. Para ter os três níveis,
a dificuldade da prova 5 ("TESTE Fase 3 – AMA digitado") foi marcada pela tela
de revisão: 1–9 Fácil, 10–18 Médio, 19–26 Difícil; habilidades D07 (1–4), D12
(9–13) e D20 (17, 21, 25). Foi a única escrita no banco.

| Cenário | Resultado |
|---|---|
| Prova 5, turma 8A | Acerto 63,1% (igual às Fases 2 e 3); Fácil 64,4%, Médio 60,6%, Difícil 64,4%. Prioridades: 17, 11, 9, 12, 13 (fáceis/médias) e 26, 21, 25 (difíceis). Habilidades D20 50,0% e D12 54,0% |
| Marcada × observada (prova 5) | 7 de 26 no nível previsto (26,9%); 19 divergentes. As folhas geradas têm acerto parecido em todas as questões (40–85%), por isso quase tudo cai em "médio" |
| Prova 4 (só 1–7 com nível) | Todas as prioridades no grupo "Sem nível"; 0 de 7 no nível previsto |
| Prova 6 (várias disciplinas, sem nível) | 7A 61,3% (63,3% / 59,2%), 7B 61,5% (60,0% / 63,0%), iguais à Fase 3. Aviso para marcar a dificuldade; filtro de disciplina (Matemática → questões 11 a 20) |
| Provas 1 e 2 (Fase 2, áreas antigas) | Abrem sem erro nem aviso do PHP |
| CSV | Por questão da 8A e por turma da prova 6 abrem com acentos e vírgula decimal; tipo inválido dá 400 e prova inexistente, 404 |
| Impressão (PDF pelo Edge sem interface) | 4 páginas: cabeçalho com turma e data, tabela, prioridades, mapa e comparação, com cores; sem formulário nem lista de folhas |
| Celular (375 px) | Sem rolagem horizontal da página; cada prioridade em três linhas. As tabelas largas rolam dentro da seção, como antes |
| `php web/testes/regras.php` | 29/29 |

### Mudanças fora do previsto

- **Fuso horário:** o PHP estava em UTC; o `lib.php` agora usa
  `America/Sao_Paulo`, para a data do relatório impresso (e a "Criada em" da
  lista de provas) saírem no horário local.
- **Turma no filtro é conferida** com as turmas da prova; valor desconhecido
  mostra todas.
- **Sem nenhum nível marcado,** a página mostra um aviso com link para a revisão
  do gabarito, e as prioridades ficam só por acerto.
- **`.claude/launch.json`** criado para subir o PHP no painel do Claude
  (mesmas opções do `iniciar.cmd`); não vai para o git.

### Pendências e riscos

- **Faixas de 70% e 30%:** são as usuais da Teoria Clássica dos Testes, mas
  convém o orientador confirmar antes de citar no TCC. Mudam por
  `LIMITE_FACIL` e `LIMITE_DIFICIL`, sem mexer no código.
- **Folhas geradas não testam bem a comparação:** o acerto delas é parecido em
  todas as questões. Com as folhas reais (Fase 0) a análise fica significativa.
- **Dificuldade continua opcional** na aprovação. Com o relatório pronto, dá para
  decidir se passa a ser exigida (uma linha em `validar_revisao()`).
- **Prova 5 de teste** agora tem dificuldade e habilidades marcadas; ela continua
  na lista de provas de teste a apagar antes do piloto.

### Diário

- **2026-10-12** — Fase iniciada. Lidos o `prova.php` (relatório atual: turmas
  por disciplina, mapa de questões com erro comum, folhas) e o `lib.php`.
- **2026-10-12** — Cálculos do relatório movidos para o `lib.php` como funções
  sem banco, com 10 testes novos. Regra das prioridades: acerto abaixo do
  `LIMITE_REFORCO`; primeiro fáceis e médias, depois sem nível, por último
  difíceis; dentro do grupo, menor acerto primeiro.
- **2026-10-12** — `prova.php` reescrito com as funções novas: tabela por
  nível, prioridades (com habilidades), mapa com selo e filtros, comparação
  marcada × observada. Novo `exportar.php` (CSV) e estilos de impressão.
- **2026-10-12** — Teste no projeto real (provas 1, 2, 4, 5 e 6). Ajustes: selo
  do mapa sem cor (especificidade do CSS), título de grupo repetido quando não
  há nível, selo cortado na quebra de página da impressão. README e
  `docker-compose.yml` com os limites novos. Fase concluída.

---

## Fase 5: leitura confiável de fotos de celular

Início: 2026-10-12.

Objetivo: fotos de celular com sombra, perspectiva, desfoque e papel curvado
são lidas corretamente, e as que não dão para ler voltam com uma mensagem que
diz ao professor o que fazer.

As fotos reais da Fase 0 ainda não chegaram. Por isso a fase é feita com fotos
**simuladas** mais difíceis que as da Fase 1 (desfoque forte, baixa resolução,
sombra dura, papel curvado). A calibração com fotos reais fica pendente.

### Resultados

Medidos com `ferramentas/validar_leitura.py`, formato detectado sozinho. Os
conjuntos difíceis têm 30 fotos cada e não são versionados; para refazê-los,
rodar `gerar_folhas_teste.py` sobre o PDF da AMA com `--alunos 30` e:

| Conjunto | Opções do gerador | Antes da Fase 5 | Depois |
|---|---|---|---|
| Papel curvado | `--curvatura 0.015 --semente 1` | 96,3%; formato certo 27/30 | **100%**; 30/30 |
| Sombra dura | `--sombra-dura 1 --semente 2` | 99,3%; caderno 28/30 | **99,6%** (3 bolinhas vazias mandadas para revisão); caderno 30/30 |
| Desfoque forte (sigma 2–4) | `--desfoque 2 4 --semente 3` | 17,8%; 13/30 lidas, várias com o formato errado | **99,2%**; 30/30 lidas, formato certo (os 6 "erros" são letras certas mandadas para revisão); caderno 1 → 6/30 |
| Baixa resolução (900–1500 px) | `--resolucao 900 1500 --semente 4` | 59,2%; 20/30 lidas | **100%**; 30/30; caderno 30/30 |
| Além do limite: desfoque sigma 3,5–7 | `--desfoque 3.5 7 --semente 5` | — | 9/30 lidas, sem erro; 21 recusadas (20 com "foto tremida ou fora de foco") |
| Além do limite: 500–950 px | `--resolucao 500 950 --semente 6` | — | 10/30 lidas, sem erro; 20 recusadas com "pouca resolução" |
| `geradas_8A` (20 fotos da Fase 1) | — | 100%; caderno 19/20 | 100%; caderno 20/20 |
| `geradas_8A` escurecidas (50% a 15% do brilho) | — | — | 0 erros; abaixo de ~30% recusadas com "escura demais" |
| `provas_7A` / `provas_7B` (modelo antigo) | — | 100% / 100% | 100% / 100% |
| `pytest` (`worker/testes`) | — | 18/18 | 27/27 |

**Nenhuma resposta errada sem aviso** em todos esses conjuntos: o que não dá
para ler com segurança é recusado com uma mensagem que diz o que fazer.

Tempo: ~0,2 s por foto com QR legível; ~2,4 s quando o QR não é lido.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 5.1 | Iluminação: dividir a imagem pelo fundo desfocado | ✅ | Feito na Fase 1 (`normalizar_iluminacao()`). Com sombra dura, o fundo passou a ser suavizado pela metade para acompanhar a borda da sombra: 99,3% → 99,6% (as diferenças eram bolinhas vazias mandadas para revisão, não erros) |
| 5.2 | Qualidade da foto (desfoque, resolução, marcadores, QR) com mensagens claras | ✅ | `avaliar_qualidade()`: desfoque pela borda dos marcadores (sigma ÷ lado, não depende da resolução), lado do marcador em px e brilho do papel. Escura ou pequena demais: recusada. Desfocada: mensagem própria quando a leitura falha, confiança baixa quando dá certo. `ErroLeitura.codigo` vai no JSON do CLI, para o app. QR com nova tentativa mais nítida |
| 5.3 | Papel curvado: alinhar cada bloco pelas próprias bolinhas | ✅ | As linhas (questões) são agrupadas dentro de cada bloco, com a inclinação do próprio bloco. Conjunto curvado: 96,3% → 100% |
| 5.4 | Calibração de `LIMIAR_MARCADA` e `ZONA_DUVIDA` com fotos reais | ⚠️ | Depende da Fase 0 |
| 5.5 | Coordenadas das bolinhas (0 a 1) na resposta, para o app | ✅ | `respostas[i].bolinhas` = centro de cada alternativa na foto recebida (fração da largura e da altura) e `raio_bolinha` (fração da largura). Conferido desenhando sobre as fotos, inclusive de cabeça para baixo e no modelo antigo |

### Mudanças fora do previsto

- **Marcadores errados em foto borrada ou pequena:** o maior problema achado
  não estava na lista do plano. Os "olhos" do QR, borrados, passavam por
  marcadores, e a folha era lida com o formato errado (respostas trocadas sem
  aviso). Resolvido com um teste de isolamento mais rigoroso.
- **Linha de questão perdida:** uma linha com poucas bolinhas achadas era
  descartada e as questões seguintes mudavam de número. Agora é recolocada.
- **Foto escura é recusada,** não só sinalizada: com pouca luz o leitor chegava
  a trocar respostas.
- **QR com nova tentativa** (mais nitidez) quando não sai de primeira.
- **Gerador de fotos** com opções de foto difícil, e `ErroLeitura.codigo`.

### Pendências e riscos

- **Fotos reais (Fase 0).** Todos os limites foram calibrados em simulação:
  `LIMIAR_MARCADA`, `ZONA_DUVIDA` (item 5.4) e os de qualidade
  (`DESFOQUE_MAXIMO`, `LADO_MINIMO`, `LADO_BOM`, `BRILHO_MINIMO`). O critério
  "99% das bolinhas das fotos reais" só pode ser conferido com elas.
- **O desfoque simulado é gaussiano.** Foto tremida de verdade borra numa
  direção só; a medida pela borda dos marcadores deve funcionar (pega o lado
  mais nítido pelo quartil de baixo), mas convém conferir com fotos reais.
- **Folha sem QR legível é ~10× mais lenta** (~2,4 s). Numa turma de 30 fotos
  todas borradas, ~1 minuto. Se incomodar, dá para tentar o realce só na região
  esperada do QR.
- **Confiança e qualidade ainda não aparecem no painel web.** O PHP mostra a
  mensagem de erro das folhas recusadas (já mais clara), mas não os avisos de
  leitura com confiança baixa. Fica para a Fase 6 (API) e 7 (app), que vão usar
  `qualidade`, `codigo` e as coordenadas.

### Diário

- **2026-10-12** — Fase iniciada. Lidos `omr.py`, `omr_cli.py`, o gerador de
  fotos e o validador.
- **2026-10-12** — Gerador com fotos mais difíceis: `--curvatura`, `--sombra-dura`,
  `--desfoque` e `--resolucao`. Linha de base em 4 conjuntos de 30 fotos (não
  versionados): curvado 96,3%, sombra dura 99,3%, desfoque forte 17,8%, baixa
  resolução 59,2%. O pior: folhas **lidas com o formato errado** (ex.: blocos
  `[5, 4]`), ou seja, respostas trocadas sem aviso.
- **2026-10-12** — Causa no desfoque: os 3 "olhos" do QR code, borrados, viram
  quadrados cheios e um deles era escolhido como marcador. Agora candidatos em
  grupo (outro a menos de 4 lados) são descartados. Desfoque 17,8% → 78,9%
  (24/30 lidas, todas com o formato certo), baixa resolução 59,2% → 89,4%.
- **2026-10-12** — Papel curvado: linhas agrupadas por bloco. Curvado 100%,
  30/30 com o formato certo. Conjuntos antigos sem mudança (`geradas_8A`,
  `provas_7A`, `provas_7B`: 100%).
- **2026-10-12** — Ainda havia marcadores errados quando só **um** olho do QR
  vira candidato (foto pequena ou borrada). O teste de "isolado" passou a olhar
  um anel mais largo em volta do candidato (até um lado além da borda, no
  máximo 8% escuro): no marcador real o anel tem 0% de escuro; no olho do QR,
  17–20%. Desfoque 78,9% → 99,2% (30/30 lidas; os 6 erros são letras certas
  mandadas para revisão), baixa resolução 89,4% → 100% (30/30). `pytest` 18/18.
- **2026-10-12** — Qualidade da foto. Os índices globais (variância do
  Laplaciano) dependem da resolução e não separavam fotos boas de ruins. A
  medida adotada é a largura da borda borrada dos marcadores dividida pelo lado
  deles: até 0,062 em todas as fotos boas; a partir de ~0,067 as fotos borradas
  deixam de ser lidas. Limites: `DESFOQUE_MAXIMO = 0,07`, `LADO_MINIMO = 10` px,
  `LADO_BOM = 16` px, `BRILHO_MINIMO = 60`. Dois conjuntos "além do limite"
  (desfoque sigma 3,5–7; foto de 500–950 px): 21 de 30 e 20 de 30 recusadas,
  quase todas com a mensagem certa (`desfocada` / `pouca_resolucao`) em vez de
  "não encontrei os 4 quadrados".
- **2026-10-12** — Fotos escuras (brilho a 50%, 40%, 30%, 25%, 15%, com ruído):
  apareceram **respostas trocadas sem aviso**. Causa: uma linha do bloco com
  poucas bolinhas achadas era descartada, e as questões seguintes mudavam de
  número. Agora, se o vão entre duas linhas é o dobro do normal, a linha que
  falta é recolocada com as bolinhas estimadas. Foto escura passou a ser
  recusada (como a pequena demais). Resultado: 0 respostas erradas em todos os
  níveis; abaixo de ~30% de brilho a foto é recusada com "escura demais".
- **2026-10-12** — Conferência geral: nenhuma resposta errada sem aviso nos 6
  conjuntos difíceis; `geradas_8A`, `provas_7A` e `provas_7B` em 100%, sem alerta
  de qualidade falso; `pytest` 18/18.
- **2026-10-12** — Coordenadas das bolinhas na foto recebida (5.5), pela inversa
  da matriz que endireita a área. `ErroLeitura` ganhou `codigo`, e o
  `omr_cli.py` devolve esse código junto com a mensagem.
- **2026-10-12** — Sombra dura: fundo estimado com metade da suavização (5 → 3
  bolinhas vazias mandadas para revisão; nenhum outro conjunto mudou).
- **2026-10-12** — QR em foto ruim: se não sai de primeira, tenta de novo com
  mais nitidez. Caderno lido: sombra 28 → 30/30, baixa resolução 28 → 30/30,
  `geradas_8A` 19 → 20/20, borradas 1 → 6/30. Custo: com QR, ~0,2 s por folha,
  como antes; sem QR, ~2,4 s. Testada a versão mais barata (só na escala
  reduzida: 1,5 s), mas ela perde leituras (borradas 6 → 2), então ficou a
  completa.
- **2026-10-12** — 9 testes `pytest` novos (27 no total, todos passando):
  qualidade da foto boa, QR borrado não vira marcador, foto muito desfocada,
  pequena demais e escura recusadas com o código certo, papel curvado, linha
  sem bolinhas achadas recolocada, coordenadas na foto (normal e de cabeça para
  baixo). O teste do QR borrado falha no `omr.py` anterior.
- **2026-10-12** — README (etapas da leitura, formato da resposta, códigos de
  erro, opções novas do gerador) e plano atualizados. Fase concluída com fotos
  simuladas; 5.4 aguarda as fotos reais.


---

## Fase 6: API REST e login

Início: 2026-10-12.

Objetivo: o celular fala com o servidor. Todo o fluxo da Fase 3 e o envio de
folhas funcionam pela API, com login de professor pelo Supabase Auth.

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 6.1 | Lógica tirada do HTML para funções | ✅ | `web/src/servicos.php`: `listar_provas()`, `cadastrar_prova()`, `aprovar_gabarito()` (salva e aprova), `descartar_rascunho()`, `ler_folhas_aluno()`, `buscar_folha()`, `revisar_folha()`, `relatorio_prova()`. As páginas e a API chamam as mesmas funções |
| 6.2 | Erros com código HTTP em vez de `exit` | ✅ | `ErroDoUsuario` (mensagem + código + detalhes). Na página vira mensagem; na API, `{"erro": ...}` com 400, 401, 403, 404, 405, 409, 413, 415, 422. Erro interno: 500 com mensagem genérica e detalhe só no log |
| 6.3 | Controlador frontal `web/api/index.php` e as rotas do plano | ✅ | Rotas em `web/src/api.php`. Além das 7 do plano: `GET /api/config`, `GET /api/provas/{id}`, `DELETE /api/provas/{id}` (descartar rascunho), `GET /api/provas/{id}/folhas`, `GET /api/folhas/{id}`. Sem Docker, `web/src/roteador.php` faz o papel do Apache |
| 6.4 | Validação do token do Supabase no PHP, com cache | ✅ | `GET /auth/v1/user` com `curl`; cache de 5 min num arquivo com o hash do token (nunca o token), sem passar do vencimento. Token vencido ou malformado é recusado sem chamar o Supabase |
| 6.5 | Login no painel web (sessão PHP) e CSRF nos formulários | ✅ | `login.php` e `sair.php` (POST). Token na sessão, renovado sozinho antes de vencer. CSRF em todos os formulários POST, trocado no login. Volta para a página pedida, só dentro do site |
| 6.6 | Autoria: `provas.criado_por` | ✅ | Gravado no cadastro (página e API), com o id real do professor (prova 9) |
| 6.7 | Uploads: tamanho máximo e tipo real (`finfo`) | ✅ | 20 MB por arquivo; JPEG, PNG, TIFF e PDF pelo conteúdo; a extensão gravada vem do tipo real |
| 6.8 | Chaves só no servidor | ✅ | A chave secret e a `DATABASE_URL` só no `.env` (fora do git). O app (Fase 7) usa só a URL e a chave publishable, que entrou como opcional no `.env.exemplo` |
| 6.9 | HTTPS | ⏳ | Depende da hospedagem (fora do escopo do plano). O cookie da sessão já sai com `Secure` quando a página é HTTPS |
| 6.10 | Política de privacidade | ✅ (rascunho) | [docs/politica_de_privacidade.md](docs/politica_de_privacidade.md). Faltam os dados da escola, a base legal e o prazo de guarda |
| 6.11 | Testes PHP novos e coleção da API (`docs/api.postman.json`) | ✅ | 16 testes novos (45 no total), passando. Coleção do Postman com 14 pedidos e o script `ferramentas/testar_api.ps1` (mesmo fluxo, com tabela de resultado) |
| 6.14 | Diagramas de sequência e de componentes | 🔄 | Rascunho em Mermaid em [docs/modelagem/fase6_diagramas.md](docs/modelagem/fase6_diagramas.md); a versão do Visual Paradigm é com o Thiago |
| 6.12 | Professor cadastrado no Supabase e cadastro público desligado | ✅ | Thiago cadastrou o próprio usuário (`6aa1b615-…`) em 2026-10-12. Cadastro público: conferir no painel |
| 6.13 | Teste de ponta a ponta pela API, com login | ✅ | Script do Thiago com o login real: fluxo completo (prova 10). Antes, 18/18 com o Auth simulado e o id real (prova 9) |

### Teste local (Auth simulado, banco e Storage reais)

Não há professor no Supabase Auth ainda, e o assistente não deve digitar senha
num serviço de fora. Por isso o login foi testado com um **Auth simulado** em
`localhost:8011` (não versionado): responde `/auth/v1/token`, `/auth/v1/user` e
`/auth/v1/logout` com tokens de teste e repassa `/storage/v1/...` ao Supabase
real. O banco é o real.

| Cenário | Resultado |
|---|---|
| Sem token, token qualquer, rota inexistente, método errado | 401, 401, 404, 405 (com `Allow`) |
| `src/` e `testes/` pelo navegador | 404 |
| `GET /api/provas?ano=2026` | 4 provas, com turmas e folhas |
| `GET /api/provas/5/relatorio?turma=8A` | 63,1%; Fácil 64,4%, Médio 60,6%, Difícil 64,4%; prioridades 17, 11, 9, 12, 13, 26, 21, 25: **iguais à Fase 4** |
| `POST /api/provas/4/folhas` (turma `9Z-API`), 1 foto | Nota, questões a revisar (12, 13, 18) e coordenadas das bolinhas; ~2,2 s |
| Mesmo envio com 3 arquivos | 2 lidas; a do modelo antigo volta com `codigo: pouca_resolucao` e não é gravada |
| Arquivo de texto com nome `.jpg`, sem turma, sem arquivo, prova inexistente | 415, 422, 422, 404 |
| `GET /api/folhas/108` | Imagem anotada por URL assinada: 200, JPEG de 76 KB |
| `PUT /api/folhas/108` só com 3 questões | Revisada, `revisar = []`; letra inválida 422, JSON quebrado 400, lista incompleta 422 |
| `POST /api/provas` (cadastro) | Validações 422 certas. Gravar dá 500 por causa da chave estrangeira `criado_por` → `auth.users`: o professor simulado não existe no banco. Esperado; depende de 6.12 |
| Painel: página pedida sem login | Vai para `login.php?volta=...`; senha errada mostra "E-mail ou senha incorretos."; certa volta para a página |
| CSRF | Login, revisão de folha e sair sem o token ou com token errado: 403 |
| Sair | Só por POST; avisa o Supabase (`/logout`); depois disso as páginas pedem login |
| `?volta=//outro.site/x` | Vai para `index.php` |
| Token da sessão perto de vencer | Renovado sozinho pelo refresh token |
| Celular (375 px) | E-mail e "Sair" descem para a 2ª linha do topo; sem rolagem horizontal |
| `php web/testes/regras.php` | 45/45 |

Ficaram no banco 3 folhas da turma `9Z-API` na prova 4 (de teste), uma delas
revisada pela API.

### Teste com o professor real (2026-10-12)

**1ª rodada do `testar_api.ps1`, pelo Thiago, contra a porta 8000:** login no
Supabase Auth real, 401 sem token e com token falso e `GET /api/config` ok. O
cadastro deu 500. Causa: havia **dois** `php -S` na porta 8000, o do
`iniciar.cmd` e um antigo, aberto por outra conversa do Claude (sem o roteador
e sem `OMR_PYTHON`). Nesse segundo, o PHP chamava `python3`, que no Windows é o
atalho da Microsoft Store, e o leitor falhava. Correção: no Windows, o padrão
passou a ser `python` (`lib.php`); `OMR_PYTHON` continua mandando quando existe.

**2ª rodada, pelo assistente:** servidor de teste na 8010, **sem**
`OMR_PYTHON`, e Auth simulado devolvendo o id real do Thiago, para a chave
estrangeira `criado_por` valer de verdade. **18/18 passos**:

| Passo | Resultado |
|---|---|
| Cadastro pela foto | 201, prova 9 ("TESTE Fase 6 - API …"), caderno `2265M0801`, 26 questões, `criado_por` = id do Thiago, leitura com coordenadas e confiança alta |
| Folha antes de aprovar | 409 |
| Revisão (1–9 Fácil, 10–18 Médio, 19–26 Difícil; D07 em 1–4) | 200; resposta "Z" recusada com 422 |
| Aprovação | 200, `ativa`; aprovar de novo, 409 |
| 20 fotos da 8A, uma por vez | 20/20, contador 20, **1,3 s por foto** |
| Folha do modelo antigo | Recusada (`pouca_resolucao`), não gravada |
| Relatório da 8A | **63,1%**; Fácil 64,4%, Médio 60,6%, Difícil 64,4%; prioridades 17, 11, 9, 12, 13, 26, 21, 25 (iguais às Fases 4 e 6) |
| Folha: detalhe, imagem e revisão | 200, imagem 200, revisada |

Ficou no banco a prova 9, com 20 folhas, criada pelo Thiago.

**3ª rodada, pelo Thiago, com o login real** (depois de encerrar o servidor
duplicado, PID 22692): fluxo completo. A prova 10 ("TESTE Fase 6 - API 12/10
12:49") ficou ativa, com `criado_por` do Thiago, 20 folhas lidas, 1 revisada e
as mesmas prioridades (17, 11, 9, 12, 13, 26, 21, 25). O acerto da 8A aparece
como 62,9% porque o último passo do script marca a questão 1 de uma folha como
dupla; quando o script conferiu o relatório, antes da revisão, era 63,1%.

### Diário

- **2026-10-12** — Fase iniciada. Lidos o plano, `lib.php`, `index.php`,
  `gabarito.php`, `prova.php`, `folha.php`, `imagem.php` e `exportar.php`.
  O projeto real ainda não tem nenhum usuário no Supabase Auth.
- **2026-10-12** — Lógica das páginas movida para `web/src/servicos.php`;
  `ErroDoUsuario` com código HTTP; `buscar_prova()` não usa mais `exit`.
  `storage()` passou a usar `supabase_http()`, que também serve ao Auth.
- **2026-10-12** — Login: `web/src/auth.php` (token do app com cache, sessão
  web com renovação, CSRF), `login.php`, `sair.php`. Todas as páginas chamam
  `iniciar_pagina()`. Uploads conferidos pelo conteúdo (`finfo`) e pelo tamanho.
- **2026-10-12** — API: `web/src/api.php` (rotas, formato JSON, revisão parcial
  de gabarito e de folha) e `web/api/index.php`. Roteador para o `php -S`
  (`iniciar.cmd`) e configuração do Apache no `Dockerfile` (não testada: sem
  Docker nesta máquina). 16 testes novos.
- **2026-10-12** — Teste local com Auth simulado. Único erro achado foi do
  ambiente de teste (o servidor de teste não tinha o `OMR_PYTHON`). O CSRF passou
  a ser trocado no login.
- **2026-10-12** — README (login dos professores, roteador, seção da API),
  coleção do Postman, `ferramentas/testar_api.ps1` (testado até o cadastro com o
  Auth simulado), rascunho da política de privacidade e diagramas em Mermaid.
- **2026-10-12** — Thiago cadastrou o próprio usuário no Supabase Auth e
  rodou o script: login real ok, cadastro com 500 por um servidor duplicado na
  porta 8000 que chamava `python3`. Padrão do Python no Windows trocado para
  `python`. Script repetido pelo assistente com o id real: 18/18.
- **2026-10-12** — Servidor duplicado encerrado; Thiago rodou o script com o
  login real e o fluxo passou inteiro (prova 10). **Fase concluída.** Ficam:
  HTTPS (hospedagem), dados da escola na política de privacidade, diagramas no
  Visual Paradigm e conferir no painel se o cadastro público está desligado.
