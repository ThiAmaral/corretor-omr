# Plano de evolução do Corretor de Provas

Contexto: o protótipo será a base de um TCC. O problema a resolver é que os
professores de uma escola têm dificuldade em corrigir e avaliar o simulado da
prova da SEDU a tempo de revisar com a turma, antes da prova oficial, as questões
em que ela teve mais dificuldade.

Mudanças pedidas:

1. O sistema deve reconhecer sozinho o formato da folha: número de questões,
   colunas, sentido da numeração e alternativas.
2. As áreas da prova não devem mais ser digitadas por extenso (`Linguagens: 1-10`).
   A escola usa dois formatos de simulado: um com todas as disciplinas e outro
   só de Português ou só de Matemática.
3. Cada questão deve ter um nível de dificuldade (fácil, médio ou difícil).
4. O gabarito oficial deve passar por uma revisão do professor antes de ser
   usado, e é nessa revisão que se marca a dificuldade.
5. Deve existir um aplicativo iOS/Android para fotografar o gabarito oficial,
   aprovar a leitura e escanear as folhas dos alunos.
6. O banco de dados passa a ser o **Supabase**.

Decisões já tomadas:

- **Backend:** PHP puro, sem Laravel.
- **Aluno:** o nome do aluno não é relevante. A turma continua sendo informada
  no envio das folhas.
- **Fora do escopo do plano:** prazo, Wi-Fi da escola e hospedagem.
- **Gabarito oficial:** o professor **fotografa** o gabarito e revisa a leitura,
  ou **digita** as respostas. As duas formas terminam na mesma tela de revisão.
- **Prova com todas as disciplinas:** o professor informa, na revisão do
  gabarito, quais questões são de cada disciplina. Não há modelo pré-cadastrado
  para esse formato.

---

## A folha real: AMA 2ª edição

Modelo de referência: [exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf](exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf),
caderno **M0801** (Matemática, 8º ano do Ensino Fundamental), da Avaliação de
Monitoramento da Aprendizagem (SEDU-ES / CAEd).

### Diferenças para o modelo usado no protótipo

| Característica | Protótipo (`exemplos/gabarito_oficial.png`) | Folha AMA |
|---|---|---|
| Questões | 20 | 26 |
| Blocos de questões | 2 colunas iguais (10 + 10) | **4 blocos desiguais (7 + 7 + 7 + 5)** |
| Alinhamento | Moldura retangular em volta | **4 quadrados pretos** nos cantos da área de respostas |
| Identificação | Nenhuma | **QR code** com o código do caderno (`2265M0801`) e um número abaixo da grade (`6357256532`) |
| Letras | Dentro das bolinhas | Acima de cada bloco (A B C D); bolinhas vazias |
| Numeração | Desce a 1ª coluna e depois a 2ª | Desce cada bloco: 01–07, 08–14, 15–21, 22–26 |
| Cabeçalho | — | Campos escritos à mão de nome e turma, ignorados pelo sistema |

### Teste do leitor atual nesta folha

Rodei o leitor atual na folha AMA:

- **QR code:** foi lido corretamente pelo próprio OpenCV (`2265M0801`).
- **Bolinhas:** o leitor encontrou só **4 das 104**.
- **Moldura:** não foi encontrada, porque a folha não tem moldura.
- **Layout:** mesmo que as bolinhas fossem achadas, o layout atual exige que o
  número de questões seja divisível pelo de colunas (26 ÷ 4 não é) e que todas
  as linhas tenham o mesmo número de bolinhas. As duas últimas linhas da AMA
  têm 12 bolinhas, e não 16.

**Conclusão:** o leitor precisa ser adaptado à AMA antes de qualquer outra
coisa. A boa notícia é que a folha já tem o que a Fase 0 do plano anterior
pretendia acrescentar:

- os **marcadores** dos cantos, para alinhar a foto com precisão;
- o **QR code**, para identificar a prova sozinho.

**Não é preciso alterar a folha.**

---

## Decisão de tecnologia

### Backend: PHP puro

O aplicativo conversa com o servidor por HTTP/JSON, então a linguagem do backend
não muda nada para o iOS ou o Android. Os motivos para manter o PHP:

- A regra de correção, os relatórios e os filtros já funcionam em PHP.
  Reescrever gasta tempo de TCC sem trazer nenhuma funcionalidade nova.
- A divisão "PHP corrige e guarda, Python só lê a folha" é um bom argumento de
  arquitetura no texto do TCC, e continua valendo.

Para continuar sem Composer e sem framework:

- **API:** fica num controlador frontal em `web/api/index.php`, que chama
  funções do `lib.php`.
- **Chamadas ao Supabase** (login e Storage): são feitas com a extensão `curl`,
  que já vem na imagem Docker do PHP.

### Banco de dados: Supabase

O Supabase é um **PostgreSQL na nuvem** com serviços prontos em volta. O projeto
usa três deles:

| Serviço do Supabase | Uso no projeto | Substitui |
|---|---|---|
| **Database** (PostgreSQL) | Provas, questões e folhas | O SQLite (`armazenamento/corretor.db`) |
| **Auth** | Login dos professores, no app e no painel web | Uma tabela de usuários e tokens feitos à mão |
| **Storage** | Fotos das folhas e imagens anotadas | A pasta `armazenamento/provas/` e o volume do Docker |

Regra de arquitetura: **o app não grava direto nas tabelas.** Ele usa o Supabase
só para fazer login, e todo o resto passa pela API PHP. Assim a regra de correção
continua em um lugar só, e o Python continua sem conhecer o banco. Para garantir
isso, as tabelas ficam com **RLS ligado e sem políticas**: a API pública do
Supabase não consegue ler nem gravar nelas, e só o PHP, que conecta direto no
PostgreSQL, tem acesso.

### Aplicativo: React Native com Expo (TypeScript)

- **Um código só para iOS e Android.**
- **Publicar no iOS sem Mac:** o EAS Build da Expo compila e envia para a App
  Store pela nuvem, o que é decisivo para quem desenvolve no Windows.
- **Linguagem:** JavaScript/TypeScript fica mais perto do que você já faz na web.
- **Login:** o Supabase tem biblioteca oficial para React Native
  (`@supabase/supabase-js`).
- **Câmera:** existem bibliotecas prontas de "scanner de documento" que usam o
  ML Kit (Android) e o VisionKit (iOS). Elas detectam as bordas da folha,
  corrigem a perspectiva e entregam uma imagem recortada.

### O que continua igual

- **Leitor óptico:** continua em Python/OpenCV, no servidor. O celular só tira a
  foto e envia.
- **RabbitMQ:** fica opcional. Para uma foto por vez, a leitura síncrona leva
  cerca de 1 s e basta.

---

## Fase 0: preparação

**Objetivo:** ter controle de versão e material real para testar.

- [x] Rodar `git init`, subir um repositório privado no GitHub e fazer o
      primeiro commit do estado atual.
- [ ] **Juntar folhas AMA reais.** Guardar em `exemplos/ama/`, com um
      `respostas_esperadas.json` preenchido à mão para as folhas preenchidas.
  - folhas **em branco** de outros cadernos: Língua Portuguesa, outros anos e o
    formato com **todas as disciplinas**, para confirmar que a folha de
    respostas segue o mesmo padrão (marcadores, QR e blocos);
  - folhas **preenchidas**, cada uma **fotografada com 2 ou 3 celulares** (luz
    boa, luz ruim, um pouco torta);
  - algumas folhas preenchidas **escaneadas**.
- [ ] **Entender o código do QR** e o número abaixo da grade:
  - o que significam o `2265` e o `01` em `2265M0801`;
  - qual letra corresponde a cada disciplina (o `M` é Matemática; Português
    seria `L`?);
  - se o número `6357256532` muda de uma folha para outra (é um identificador
    da folha?) ou é igual para todo o caderno.

**Pronto quando:** o projeto está no git e há pelo menos 10 fotos reais de folhas
AMA preenchidas, com as respostas conhecidas.

---

## Fase 1: leitor da folha AMA com detecção automática do formato

> **Situação (2026-10-11):** implementada e validada com fotos **geradas**. Falta
> só o critério das fotos reais, que depende da Fase 0. Detalhes, números e
> pendências em [PROGRESSO.md](PROGRESSO.md#fase-1-leitor-da-folha-ama-com-detecção-automática-do-formato).

**Objetivo:** ler a folha AMA por foto e descobrir sozinho o caderno, o número
de questões, os blocos e as alternativas, sem nada digitado.

### Como ler ([worker/omr.py](worker/omr.py))

1. **Alinhamento pelos 4 marcadores:**
   - procurar os quatro quadrados pretos (contornos quadrados, cheios e de
     tamanho parecido) perto dos cantos da área de respostas;
   - aplicar a transformação de perspectiva para um retângulo de tamanho fixo.
     Isso corrige a inclinação e a perspectiva da foto de uma vez, e só a área
     de respostas fica na imagem;
   - **substitui** `localizar_folha()`. A moldura antiga continua como
     alternativa, para os exemplos do protótipo.
2. **QR code:** ler com `cv2.QRCodeDetector()`, que já funcionou no teste, para
   obter o **código do caderno**. Dele saem a disciplina e o ano; o significado
   exato fica confirmado na Fase 0.
3. **Bolinhas:**
   - depois do alinhamento, o tamanho das bolinhas é previsível. Ajustar
     `d_min` e `d_max` de `detectar_bolhas()` em proporção à distância entre os
     marcadores; hoje eles são fixos e eliminam as bolinhas da AMA, que são
     menores;
   - descartar as linhas tracejadas entre as questões e os quadrinhos cinzas
     dos números.
4. **Blocos de questões** (no lugar de "colunas iguais"):
   - agrupar as bolinhas na horizontal: espaços pequenos separam alternativas;
     espaços grandes separam blocos;
   - dentro de cada bloco, ordenar as questões de cima para baixo. A AMA tem
     blocos de 7, 7, 7 e 5;
   - **alternativas** = bolinhas por questão. As letras são A, B, C, D, na
     ordem impressa acima dos blocos;
   - **questões** = soma das questões de todos os blocos;
   - **numeração:** desce o 1º bloco, depois o 2º, e assim por diante. Na AMA é
     sempre assim, então **não precisa de OCR**.
5. **Layout por caderno:**
   - o layout detectado no gabarito fica guardado com o código do caderno;
   - em cada folha de aluno, o código do QR é comparado com o da prova. Se for
     diferente, a folha é recusada com a mensagem "Esta folha é do caderno X, e
     a prova é do caderno Y";
   - isso evita misturar provas de Matemática e Português.
6. **Confiança:** devolver se os 4 marcadores e o QR foram achados e se o número
   de bolinhas é coerente. Com confiança baixa, pedir outra foto.

### Novo formato do layout

O formato antigo continua aceito: `questoes` + `colunas` é convertido em blocos
iguais.

```json
{
  "caderno": "2265M0801",
  "alternativas": ["A", "B", "C", "D"],
  "blocos": [7, 7, 7, 5],
  "questoes": 26
}
```

### Mudanças no código

- **`omr.py`:**
  - novas funções `alinhar_por_marcadores()`, `ler_qr()` e `detectar_layout()`;
  - `organizar_grade()` passa a trabalhar com blocos;
  - `Layout` ganha `blocos` e deixa de exigir que as questões sejam divisíveis
    pelas colunas.
- **`omr_cli.py`:** novo comando `detectar <imagem>`, que imprime o caderno, o
  layout e a leitura em JSON.
- **`gerar_folhas_teste.py`:** passa a gerar folhas AMA preenchidas a partir do
  PDF em branco, simulando foto de celular (perspectiva, sombra, desfoque).
- **`validar_leitura.py`:** passa a medir também se o caderno e o layout
  detectados estão certos.
- **`web/index.php`:** a validação "questões divisíveis por colunas" sai. Na
  Fase 3, o formulário inteiro de formato deixa de existir.

**Pronto quando:** o leitor lê corretamente as folhas AMA geradas e pelo menos
95% das fotos reais da Fase 0, identificando o caderno pelo QR.

---

## Fase 2: banco de dados no Supabase

> **Situação (2026-10-11):** concluída. Projeto criado, migração aplicada e
> fluxo completo testado no Supabase real. Só falta testar o Docker. Detalhes em [PROGRESSO.md](PROGRESSO.md#fase-2-banco-de-dados-no-supabase).

**Objetivo:** trocar o SQLite pelo PostgreSQL do Supabase e guardar as imagens
no Supabase Storage, sem mudar nada do que o professor vê.

Esta fase vem **antes** da revisão do gabarito (Fase 3) de propósito: as tabelas
novas já nascem no PostgreSQL, e não é preciso fazer a migração duas vezes.

Se o professor pediu o diagrama do banco no Visual Paradigm, desenhe o DER
**antes da etapa 2.2**, a partir do esquema abaixo.

### 2.1 Criar o projeto no Supabase (passo a passo)

1. Acesse https://supabase.com e clique em **Start your project**. Entre com a
   conta do GitHub.
2. Crie uma **organização** no plano **Free**.
3. Clique em **New project** e preencha:
   - **Name:** `corretor-provas`;
   - **Database Password:** gere uma senha forte e **guarde num gerenciador de
     senhas**. Ela é a senha do PostgreSQL e não aparece de novo;
   - **Region:** *South America (São Paulo)*.
4. Espere alguns minutos até o projeto ficar pronto.
5. **String de conexão:** clique no botão **Connect**, no topo do painel, e copie
   a do **Session pooler**, exatamente como aparece. O formato é parecido com
   `postgresql://postgres.<id-do-projeto>:<senha>@aws-0-sa-east-1.pooler.supabase.com:5432/postgres`.
   - Use o *Session pooler*, e não a conexão direta: a direta só funciona em
     IPv6, o que muitas redes e o Docker no Windows não têm.
   - Não use o *Transaction pooler* (porta 6543): ele não aceita bem os
     *prepared statements* que o PDO usa.
6. **Chaves da API:** em **Project Settings → API Keys**, anote a URL do projeto
   (`https://<id-do-projeto>.supabase.co`) e as duas chaves:
   - a **publishable** (antiga *anon*), que pode ir no app;
   - a **secret** (antiga *service_role*), que **só pode ficar no servidor**.
     Quem tiver essa chave ignora todas as regras de acesso.
7. **Arquivo `.env`:** crie um `.env` na raiz do projeto, **fora do git**
   (acrescente `.env` ao `.gitignore`):

   ```
   DATABASE_URL=postgresql://postgres.<id>:<senha>@aws-0-sa-east-1.pooler.supabase.com:5432/postgres
   SUPABASE_URL=https://<id>.supabase.co
   SUPABASE_SECRET_KEY=<chave secret>
   ```

   No `docker-compose.yml`, acrescente `env_file: .env` ao serviço `corretor`.
   Commite também um `.env.exemplo` com os mesmos nomes e sem os valores.

**Cuidados do plano Free:**
- **Pausa:** o projeto é pausado depois de **7 dias sem uso**. Antes da
  apresentação do TCC, entre no painel e confirme que ele está ativo.
- **Limites:** 500 MB de banco e 1 GB de Storage. Guardando só a área de
  respostas recortada (cerca de 150 KB por folha), cabem milhares de folhas.
- **Desenvolvimento:** dá para ter 2 projetos gratuitos. Vale criar um
  `corretor-provas-dev` para testar sem mexer nos dados do piloto.

### 2.2 Criar as tabelas

Instale a **Supabase CLI** (`npm install -D supabase` ou `scoop install
supabase`). Depois, na raiz do projeto, prepare a pasta de migrações:

```bash
npx supabase init
npx supabase login
npx supabase link --project-ref <id-do-projeto>
npx supabase migration new esquema_inicial
```

A CLI cria um arquivo vazio em `supabase/migrations/<data>_esquema_inicial.sql`.
Escreva nele o esquema:

```sql
create table provas (
    id         bigint generated always as identity primary key,
    nome       text not null,
    ano        int not null,
    periodo    int,
    caderno    text,                         -- código lido do QR, ex.: 2265M0801
    modelo     text,
    status     text not null default 'rascunho' check (status in ('rascunho', 'ativa')),
    layout     jsonb not null,               -- só o que o leitor precisa: alternativas e blocos
    criado_por uuid references auth.users(id),
    criado_em  timestamptz not null default now()
);
create index on provas (caderno);

create table questoes (
    prova_id    bigint not null references provas(id) on delete cascade,
    numero      int not null,
    resposta    text not null,
    disciplina  text,
    dificuldade text check (dificuldade in ('facil', 'medio', 'dificil')),
    habilidade  text,                        -- opcional: descritor da matriz de referência, ex.: D07
    primary key (prova_id, numero)
);

create table folhas (
    id           bigint generated always as identity primary key,
    prova_id     bigint not null references provas(id) on delete cascade,
    turma        text not null,
    arquivo      text not null,               -- nome original enviado
    pagina       int not null,
    imagem       text,                        -- caminho no Storage (só a área de respostas)
    imagem_debug text,                        -- caminho no Storage
    respostas    jsonb,
    niveis       jsonb,
    revisar      jsonb not null default '[]',
    revisada     boolean not null default false,
    erro         text,
    criado_em    timestamptz not null default now()
);
create index on folhas (prova_id, turma);

-- Bloqueia a API pública do Supabase: só o PHP, conectado direto no PostgreSQL, acessa.
alter table provas   enable row level security;
alter table questoes enable row level security;
alter table folhas   enable row level security;
```

Por fim, envie o esquema para o projeto:

```bash
npx supabase db push
```

Toda mudança futura de banco vira um **novo arquivo de migração**, versionado no
git.

### 2.3 Adaptar o PHP

- **Extensões:** o PHP precisa da `pdo_pgsql` e da `curl`.
  - **Docker:** acrescentar ao `Dockerfile`
    `apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql`. A `curl`
    já vem na imagem.
  - **Sem Docker ([iniciar.cmd](iniciar.cmd)):** ativar `extension=pdo_pgsql` e
    `extension=curl` no `php.ini`.
- **`db()` em [web/lib.php](web/lib.php):** montar o DSN a partir da
  `DATABASE_URL` (`pgsql:host=...;port=5432;dbname=postgres;sslmode=require`) e
  **apagar** o `CREATE TABLE IF NOT EXISTS` e as migrações com `PRAGMA`. O esquema
  agora vem das migrações da CLI.
- **Diferenças entre SQLite e PostgreSQL que aparecem no código atual:**

  | Onde | Hoje (SQLite) | No PostgreSQL |
  |---|---|---|
  | `index.php`, cadastro | `lastInsertId()` | `INSERT ... RETURNING id` |
  | `folha.php`, salvar revisão | `revisada = 1` | `revisada = true` |
  | `index.php`, lista de provas | `ORDER BY p.periodo DESC` põe os vazios no fim | Acrescentar `NULLS LAST` |
  | `lib.php`, `buscar_prova()` | Lê `provas.gabarito` | Ler as respostas da tabela `questoes` |
  | `respostas`, `niveis`, `layout` | Texto com JSON | `jsonb`; o `json_encode` e o `json_decode` continuam iguais |

- **Imagens no Storage:**
  - Dois *buckets* **privados**: `folhas` e `anotadas`. *(Feito: a própria
    migração cria os dois; não é preciso criar no painel.)*
  - **Recorte:** guardar **só a área de respostas**, já recortada pelos
    marcadores na Fase 1. Assim o nome escrito à mão no cabeçalho nunca é
    armazenado. Isso protege os dados do aluno (LGPD) e economiza espaço.
  - **Envio:** depois da leitura, o PHP envia os arquivos com a chave secret,
    usando `curl` (`POST {SUPABASE_URL}/storage/v1/object/<bucket>/<caminho>`),
    e apaga a cópia local.
  - **Python:** continua recebendo um arquivo temporário no disco e **não muda**.
  - [web/imagem.php](web/imagem.php) passa a gerar uma **URL assinada**, que
    vale por poucos minutos, e redireciona para ela.
- **Dados atuais:** os dados do SQLite são de teste. O mais simples é
  **começar com o banco vazio**.

**Pronto quando:** o fluxo atual (cadastrar prova, enviar folhas, ver relatório,
revisar folha) funciona igual, com os dados no Supabase e nenhum arquivo em
`armazenamento/`.

---

## Fase 3: revisão do gabarito oficial, áreas e dificuldade

> **Situação (2026-10-11):** concluída. Os três cenários do "pronto quando"
> foram testados no Supabase real. Detalhes em [PROGRESSO.md](PROGRESSO.md#fase-3-revisão-do-gabarito-oficial-áreas-e-dificuldade).

**Objetivo:** o professor envia a foto do gabarito, confere o que foi lido,
ajusta as respostas e marca a dificuldade de cada questão, tudo em uma tela só.

### Novo fluxo de cadastro (substitui o formulário atual de `index.php`)

1. **Envio:** o professor informa o ano letivo e o período e escolhe uma das
   duas formas de informar o gabarito oficial. Não há mais campos de questões,
   colunas ou alternativas.
   - **Foto (forma principal):** o professor pinta as respostas certas numa folha
     AMA em branco e fotografa. O sistema lê o QR, o formato (Fase 1) e as
     respostas.
   - **Digitação:** o professor digita as letras em ordem, por exemplo
     `CBDAABCD...`. Como não há foto, o formato e o caderno vêm da **primeira
     folha de aluno** lida, que precisa ter o mesmo número de questões que o
     gabarito digitado. Se o número não bater, o sistema avisa.
2. **Rascunho:**
   - com a foto, o sistema sugere o nome da prova (por exemplo "AMA 2ª edição:
     Matemática 8º ano (M0801)") e o modelo;
   - grava a prova com `status = 'rascunho'` e uma linha em `questoes` para cada
     resposta, lida ou digitada.
3. **Tela de revisão do gabarito** (nova, `gabarito.php`):
   - **Imagem anotada** da área de respostas, com a resposta lida em cada
     questão.
   - **Formato detectado**: "Caderno M0801, 26 questões em 4 blocos, A–D".
   - **Grade de questões em cartões.** Cada cartão traz:
     - a resposta lida, editável, em destaque se a leitura foi duvidosa;
     - a disciplina, já preenchida pelo modelo;
     - a dificuldade, com três botões: Fácil, Médio e Difícil;
     - opcionalmente, a habilidade ou descritor (por exemplo D07).
   - **Atalhos para várias questões:** selecionar um intervalo clicando na
     primeira e na última questão e aplicar a dificuldade ou a disciplina de
     uma vez.
4. **Aprovação:** o botão "Aprovar gabarito" muda o status para `ativa`. Só
   provas ativas recebem folhas de alunos.
5. **Novas regras:** gabarito duvidoso não bloqueia mais o cadastro, porque vai
   para a revisão.

### Áreas sem texto por extenso

- **Modelos de prova:** criar `modelos_de_prova()` no `lib.php`, no mesmo
  estilo de `filtros_de_provas()`. O modelo é escolhido pelo código do caderno:
  - **Caderno de uma disciplina** (por exemplo `M0801`): todas as questões
    recebem essa disciplina automaticamente, e **o professor não precisa
    marcar nada**. A letra do código (`M`, `L`...) indica a disciplina.
  - **Simulado com todas as disciplinas, ou caderno desconhecido:** o professor
    informa as disciplinas na tela de revisão. Ele seleciona um intervalo de
    questões (clica na primeira e na última, por exemplo 1 e 13) e escolhe a
    disciplina numa lista; depois repete para o próximo intervalo.
    - Uma faixa colorida acima da grade mostra quais questões já têm disciplina.
    - A prova não pode ser aprovada com questões sem disciplina.
- **Disciplinas:** catálogo fixo (Língua Portuguesa, Matemática, Ciências,
  História, Geografia...) em vez de nomes livres.
- **Página da prova:** o `textarea` de áreas sai da página e dá lugar a um link
  "Editar gabarito e dificuldade", que abre a mesma tela de revisão.

**Pronto quando:**
- dá para cadastrar a prova M0801 só com a foto do gabarito, revisar, marcar a
  dificuldade e aprovar, sem digitar o formato nem as áreas;
- dá para cadastrar a mesma prova digitando o gabarito;
- dá para cadastrar uma prova de várias disciplinas, informando os intervalos na
  tela de revisão.

---

## Fase 4: relatórios por dificuldade

> **Situação (2026-10-12):** concluída. Testada no projeto real com as provas de
> teste das Fases 2 e 3. Detalhes em [PROGRESSO.md](PROGRESSO.md#fase-4-relatórios-por-dificuldade).

**Objetivo:** responder à pergunta central do TCC: em quais questões a turma
precisa de revisão antes da prova oficial?

- [x] **Tabela de turmas:** colunas de acerto por nível (Fácil, Médio e Difícil),
      ao lado das colunas por disciplina.
- [x] **Lista "Prioridades de revisão"** por turma, ordenada por importância:
  - **Primeiro:** questões **fáceis ou médias com acerto baixo**. São conteúdos
    que a turma deveria dominar.
  - **Junto com elas:** o **erro comum** que já existe (a alternativa errada
    mais marcada), para o professor entender a confusão.
  - **Com habilidade cadastrada:** agrupar também por habilidade/descritor.
- [x] **Mapa de questões:** mostrar um selo de dificuldade em cada bolinha e
      permitir filtrar por disciplina e por dificuldade.
- [x] **Dificuldade prevista × observada:**
  - Calcular o índice de dificuldade (a proporção de acerto) e classificar a
    dificuldade observada. Usar faixas usuais, por exemplo acima de 70% = fácil
    e abaixo de 30% = difícil, configuráveis como o `LIMITE_REFORCO`.
  - Comparar com o nível que o professor marcou e destacar as questões em que
    os dois divergem.
  - Isso rende uma análise interessante para o texto do TCC.
- [x] **Exportação:** CSV e uma versão para impressão (CSS `@media print`) do
      relatório da turma, para levar à reunião pedagógica.

**Pronto quando:** o professor abre a turma e vê em menos de um minuto quais
questões revisar e por quê.

---

## Fase 5: leitura confiável de fotos de celular

**Objetivo:** fotos de celular trazem sombra, perspectiva forte, desfoque e
papel curvado, problemas que o scanner não tem. Os marcadores da AMA resolvem a
perspectiva; esta fase cuida do resto.

- [ ] **Iluminação:** antes do limiar, dividir a imagem pelo seu próprio fundo
      desfocado. Isso remove sombras e gradientes de luz.
- [ ] **Qualidade da foto:** medir o desfoque (variância do Laplaciano) e a
      resolução, e verificar se os 4 marcadores e o QR foram achados. Devolver
      mensagens claras como "foto tremida, tente de novo" ou "enquadre os 4
      quadrados pretos", em vez de "encontrei 7 linhas".
- [ ] **Papel curvado:** se as fotos reais mostrarem folhas dobradas, alinhar
      cada bloco separadamente pelas próprias bolinhas, depois do alinhamento
      geral pelos marcadores.
- [ ] **Calibração:** rodar `validar_leitura.py` com as fotos reais e ajustar
      `LIMIAR_MARCADA` e `ZONA_DUVIDA`.
- [ ] **Resposta para o app:** devolver as **coordenadas das bolinhas**
      (normalizadas de 0 a 1) junto com a leitura, para o app desenhar as
      marcações sobre a própria foto.

**Pronto quando:** pelo menos 99% das bolinhas das fotos reais são lidas
corretamente, e as marcações fracas vão para revisão.

---

## Fase 6: API REST e login

**Objetivo:** o celular precisa falar com o servidor. Hoje o sistema só roda em
`localhost`, sem login.

### Organização do código (PHP puro)

- **Lógica separada do HTML:** tirar a lógica que hoje está misturada ao HTML de
  `index.php`, `prova.php` e `folha.php` e passar para funções no `lib.php` (ou
  em arquivos dentro de `web/src/`, incluídos com `require`):
  `cadastrar_prova()`, `aprovar_gabarito()`, `ler_folha_aluno()`,
  `relatorio_prova()`.
- **Controlador frontal** em `web/api/index.php`:
  - lê o método e a rota (`$_SERVER['REQUEST_METHOD']`, `PATH_INFO`);
  - escolhe a função com um `match`;
  - devolve JSON com `json_encode`;
  - erros saem como JSON `{"erro": "..."}` com o código HTTP certo (400, 401,
    404, 422).
- **Uma regra só:** as páginas web e a API chamam as mesmas funções.

### Login com Supabase Auth

- **Cadastro dos professores:** no painel do Supabase, em
  **Authentication → Users → Add user**, ou por convite por e-mail. Desligar o
  cadastro público nas configurações de *Sign In / Providers*, para que só
  professores convidados entrem.
- **No app:** o login é feito com `supabase.auth.signInWithPassword()`, que
  devolve um token (JWT). O app manda esse token em toda chamada à API
  (`Authorization: Bearer <token>`).
- **No PHP, sem bibliotecas:** validar o token chamando
  `GET {SUPABASE_URL}/auth/v1/user` com `curl`:
  - resposta 200: a requisição é aceita e o `id` do professor vem na resposta;
  - outra resposta: a API devolve 401;
  - para não repetir a chamada a cada requisição, guardar o resultado em cache
    por alguns minutos (um arquivo temporário, indexado pelo hash do token).
- **No painel web:** o formulário de login chama
  `POST {SUPABASE_URL}/auth/v1/token?grant_type=password` com `curl` e guarda o
  token na sessão PHP. Acrescentar proteção CSRF aos formulários com um token
  por sessão.
- **Autoria:** o `id` do professor é gravado em `provas.criado_por`.

### Endpoints (`web/api/`)

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/provas` | Lista com os filtros de ano e período |
| POST | `/api/provas` | Foto do gabarito; devolve o rascunho com caderno, formato e leitura |
| PUT | `/api/provas/{id}/questoes` | Salva a revisão: respostas, disciplinas, dificuldades |
| POST | `/api/provas/{id}/aprovar` | Ativa a prova |
| POST | `/api/provas/{id}/folhas` | Foto de uma folha e a turma; devolve a leitura e os acertos |
| PUT | `/api/folhas/{id}` | Revisão manual de uma folha |
| GET | `/api/provas/{id}/relatorio?turma=` | Resumo, prioridades de revisão e acerto por questão |

O login não precisa de rota própria, porque o app fala direto com o Supabase
Auth.

### Segurança

- [ ] **Uploads:** limitar o tamanho e validar o tipo real do arquivo
      (`finfo_file`), não só a extensão.
- [ ] **Chaves:** a chave secret do Supabase e a `DATABASE_URL` ficam só nas
      variáveis de ambiente do servidor; nunca no app nem no git.
- [ ] **HTTPS:** o servidor precisa ser publicado com HTTPS, porque o iOS só
      aceita conexões seguras.
- [ ] **Privacidade:** escrever a política de privacidade, que as lojas exigem.
      Como só a área de respostas é guardada, o sistema não armazena o nome do
      aluno.

**Pronto quando:** dá para fazer todo o fluxo da Fase 3 e enviar folhas pela API
(testado com Postman ou `curl`), com login.

---

## Fase 7: aplicativo mobile (Expo / React Native)

**Objetivo:** o professor faz tudo pelo celular. Tela por tela:

1. **Login** com Supabase Auth.
2. **Minhas provas:** lista com os filtros de ano e período.
3. **Nova prova:**
   - **fotografar o gabarito oficial**, com o botão "Digitar respostas" como
     alternativa. Com foto, o app identifica o caderno pelo QR e sugere o nome;
   - **revisar o gabarito**: é a mesma tela da Fase 3, adaptada ao celular, com
     as marcações desenhadas sobre a foto e um cartão por questão com a
     resposta, a disciplina e a dificuldade. Na prova de várias disciplinas, o
     professor seleciona os intervalos de questões e escolhe a disciplina de
     cada um;
   - **aprovar.**
4. **Escanear turma:**
   - escolher a turma;
   - a câmera fica aberta: a cada folha o app envia a foto e mostra na hora a
     nota e as questões duvidosas;
   - se a folha for de outro caderno, o app avisa na hora;
   - o professor **confirma ou corrige** e passa para a próxima;
   - um contador mostra quantas folhas da turma já foram lidas.
5. **Resultado da turma:** acerto geral, por disciplina e por dificuldade, e a
   lista de prioridades de revisão (Fase 4).

### Detalhes técnicos

- **Supabase no app:** usar `@supabase/supabase-js` só com a URL e a chave
  **publishable**, e só para login. Todo o resto passa pela API PHP.
- **Câmera:** o scanner de documento usa uma biblioteca de ML Kit/VisionKit
  compatível com o Expo, por exemplo
  `react-native-document-scanner-plugin`, com um *development build* do Expo.
- **Enquadramento:** mostrar uma moldura-guia na câmera pedindo para enquadrar
  os 4 quadrados pretos.
- **Envio das fotos:** reduzir a foto para cerca de 1600 px de largura antes de
  enviar.
- **Painel web:** continua existindo, para relatórios em tela grande e para a
  coordenação.

**Pronto quando:** um professor sem treinamento cadastra uma prova e corrige uma
turma inteira só pelo celular.

---

## Fase 8: publicação e avaliação com os professores

- [ ] **Contas de desenvolvedor:**
  - Apple Developer: US$ 99 por ano;
  - Google Play Console: US$ 25, pagos uma vez.
- [ ] **Distribuição para testes:**
  - iOS: TestFlight;
  - Android: teste interno do Google Play.
  - Para o TCC isso já costuma bastar; a publicação aberta na loja é opcional.
- [ ] **Permissões:** a Apple exige um texto explicando por que o app usa a
      câmera.
- [ ] **Supabase ativo:** conferir que o projeto não está pausado antes do piloto
      e da apresentação.
- [ ] **Piloto com professores da escola** em uma aplicação real da AMA. Medir:
  - o tempo para corrigir uma turma, comparado com a correção manual;
  - a precisão da leitura, ou seja, quantas correções manuais foram necessárias;
  - a satisfação dos professores, com um questionário curto, por exemplo SUS.
- [ ] Esses números viram o capítulo de resultados do TCC.

---

## Testes

Cada fase tem um critério de pronto. Esta seção diz como verificá-lo de forma
repetível.

| O que | Como | Quando rodar |
|---|---|---|
| **Leitor (Python)** | `validar_leitura.py` sobre as folhas AMA geradas e as fotos reais da Fase 0. Mede: caderno lido, layout detectado, % de bolinhas certas, % de marcações fracas enviadas para revisão | A cada mudança em `omr.py` |
| **Leitor, casos de borda** | Testes com `pytest` em `worker/testes/`: foto sem um dos marcadores, QR ilegível, folha de cabeça para baixo, folha em branco, folha toda marcada | A cada mudança em `omr.py` |
| **Regra de correção (PHP)** | Um script `web/testes/correcao.php`, sem biblioteca, com `assert()`. Cobre `corrigir()`: branco e dupla contam como erro. Cobre também os resumos por disciplina e dificuldade e as prioridades de revisão | A cada mudança em `lib.php` |
| **API** | Uma coleção do Postman (exportada para `docs/api.postman.json`) com o fluxo completo: login, cadastrar prova, revisar, aprovar, enviar folha e relatório | Antes de cada versão do app |
| **App** | Roteiro de teste manual em `docs/roteiro_teste_app.md`: cadastrar a M0801, escanear 5 folhas, corrigir uma leitura e conferir o relatório. Rodar em um Android e um iPhone | Antes de cada envio ao TestFlight / Play |

Os exemplos antigos (`exemplos/provas_7A`, `provas_7B`) continuam como testes do
formato antigo, para garantir que a mudança para blocos não quebrou nada.

---

## Documentação do TCC (Visual Paradigm)

Diagramas a fazer no Visual Paradigm, a pedido do professor orientador. Eles
entram no texto do TCC e nos slides da apresentação.

- **Arquivos:** salvar o `.vpp` e as imagens exportadas em `docs/modelagem/`.
- **Para os slides:** exportar em PNG de alta resolução, ou em SVG, e fazer uma
  versão simplificada dos diagramas grandes, só com o essencial.

| Diagrama | Conteúdo | Fazer na |
|---|---|---|
| **Casos de uso** | Professor: cadastrar prova, revisar gabarito, marcar dificuldade, escanear turma, revisar folha, ver prioridades de revisão. Coordenação: ver relatórios | Antes da Fase 1 |
| **Atividades** | Fluxo completo: fotografar gabarito → revisar → aprovar → escanear turma → relatório → revisão em sala | Antes da Fase 1 |
| **DER** | Tabelas `provas`, `questoes` e `folhas` e a ligação com `auth.users` do Supabase | Fase 2, antes da 2.2 |
| **Sequência** | Foto da folha → app → API PHP → leitor Python → correção → Supabase → resposta ao app | Fase 6 |
| **Componentes / implantação** | App (Expo), servidor (PHP + Python em Docker) e Supabase (Database, Auth, Storage) | Fase 6 |

---

## Resumo da ordem e das dependências

```
Fase 0 ─┬─> Fase 1 (leitor AMA) ────────┐
        ├─> Fase 2 (Supabase) ──────────┴─> Fase 3 (revisão/áreas/dificuldade) ─> Fase 4 (relatórios)
        └─> Fase 5 (fotos de celular) ─────────────┐
                                    Fase 3 + 5 ─> Fase 6 (API + login) ─> Fase 7 (app) ─> Fase 8 (piloto)
```

- **Prioridade:** a Fase 1 é a mais urgente. Hoje o sistema não lê a folha real.
- **Em paralelo:** as Fases 1, 2 e 5 não dependem umas das outras. A 1 e a 5
  mexem só no Python; a 2, só no PHP e no banco.
- **Sem o app:** as Fases 1 a 4 já entregam valor pelo navegador. Se faltar tempo
  para o app, o sistema continua utilizável.

## Perguntas em aberto

Não impede começar a Fase 1.

1. **Código do QR** (aguardando o professor orientador): o que significam as
   partes de `2265M0801` (`2265`, a letra da disciplina, `08`, `01`)? O número
   abaixo da grade (`6357256532`) muda em cada folha?
   - Enquanto isso, a Fase 1 usa o código inteiro só para conferir se a folha
     do aluno é do mesmo caderno da prova.
   - A disciplina automática da Fase 3 espera essa resposta. Até lá, o
     professor escolhe a disciplina na revisão do gabarito.
