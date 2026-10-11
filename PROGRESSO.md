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
| 3. Revisão do gabarito, áreas e dificuldade | ✅ (teste local) | Testada num PostgreSQL local; falta aplicar a migração nova no projeto real e repetir o teste lá |
| 4. Relatórios por dificuldade | ⏳ | |
| 5. Fotos de celular | ⏳ | Parte já adiantada na Fase 1 (ver abaixo) |
| 6. API e login | ⏳ | |
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

Testada como a Fase 2: PostgreSQL local (PGlite) com as duas migrações
aplicadas e o Storage simulado, com o navegador e com `curl`. **A migração nova
ainda não foi aplicada no projeto real** (ver pendências).

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 3.1 | Migração `20261011232850_revisao_do_gabarito.sql` | ✅ local · ⏳ projeto real | `provas.imagem_gabarito`, `questoes.duvidosa`, `questoes.resposta` pode ficar vazia no rascunho |
| 3.2 | Cadastro novo em `index.php` | ✅ | Só ano, período e gabarito (foto **ou** digitado); nome opcional. Sem questões, colunas, numeração nem áreas. Grava `status = 'rascunho'` e abre a revisão |
| 3.3 | Catálogo de disciplinas e `modelos_de_prova()` | ✅ | `disciplinas()` (8 disciplinas fixas). Caderno `…M0801` → Matemática, 8º ano, todas as questões preenchidas. `L` → Língua Portuguesa como **sugestão** (não confirmado). Caderno desconhecido: o professor escolhe |
| 3.4 | Tela `gabarito.php` | ✅ | Imagem anotada do gabarito (`imagem.php?prova=`), formato detectado, nome sugerido editável, um cartão por questão: resposta (botões A–D), disciplina (lista), dificuldade (Fácil/Médio/Difícil), habilidade opcional |
| 3.5 | Seleção de intervalo e faixa colorida | ✅ | `gabarito.js`: clique na primeira e na última questão (no cartão ou na faixa), aplica disciplina ou dificuldade; "Selecionar todas". Sem JavaScript, a tela funciona questão por questão |
| 3.6 | Aprovação | ✅ | `rascunho` → `ativa`. Exige resposta e disciplina em todas as questões (dificuldade é opcional). Rascunho pode ser salvo incompleto ou descartado. `prova.php` de rascunho redireciona para a revisão |
| 3.7 | Gabarito digitado | ✅ | Formato e caderno vêm da 1ª folha boa; folhas com outro número de questões, outro caderno ou alternativa inexistente são recusadas com o motivo |
| 3.8 | `prova.php` | ✅ | Sai o `textarea` de áreas; entra o link "Editar gabarito e dificuldade" (mesma tela). Numa prova ativa, a revisão não deixa apagar resposta nem disciplina |
| 3.9 | Testes PHP | ✅ | `php web/testes/regras.php`: 18 testes (correção, modelos de caderno, rascunho, aprovação, formato pela 1ª folha) |
| 3.10 | Teste de ponta a ponta | ✅ local | Ver abaixo |

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

- **Aplicar a migração no projeto real** (`npx supabase db push`) e repetir os
  três cenários lá. Sem ela, o PHP novo falha no projeto real (colunas
  `imagem_gabarito` e `duvidosa` não existem).
- **Provas de teste da Fase 2** (ids 1 e 2 do projeto real) continuam com
  `status = 'ativa'`; seguem funcionando, com as áreas antigas ("Linguagens")
  aceitas na revisão.
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
