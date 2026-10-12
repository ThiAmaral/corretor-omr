# Corretor de provas — protótipo local

Lê folhas de resposta digitalizadas ou fotografadas, compara com o gabarito
oficial e mostra a taxa de acerto por turma, por disciplina, por nível de
dificuldade e por questão, com a lista de questões que cada turma precisa revisar. Lê a folha
da AMA (SEDU-ES / CAEd) e o modelo antigo do protótipo, e descobre sozinho o
formato da folha (caderno, blocos de questões e alternativas). Nesta fase o sistema não
identifica o aluno: a turma é informada no momento do envio das folhas.

## Estrutura

```
web/        Backend + telas em PHP (cadastro da prova, envio, relatórios, revisão)
worker/     Leitor óptico em Python/OpenCV (só lê a folha; não conhece o banco)
supabase/   Migrações do banco (PostgreSQL no Supabase), aplicadas com a Supabase CLI
ferramentas/ Gerador de folhas de teste e validador de precisão
exemplos/   Gabarito real, layouts e folhas fictícias das turmas 7A e 7B;
            em exemplos/ama, a folha AMA em branco e fotos geradas a partir dela
```

O andamento do plano de evolução fica em [PROGRESSO.md](PROGRESSO.md).

Os dados ficam no **Supabase**: provas, questões e folhas no PostgreSQL, e as
imagens (só a área de respostas recortada e a imagem anotada pelo leitor) no
Supabase Storage, em buckets privados. A foto enviada não é guardada; o nome
escrito à mão no cabeçalho nunca sai do servidor.

A divisão já segue a arquitetura combinada: o PHP é dono do banco e da regra
de correção; o Python só devolve "qual bolinha foi marcada". Hoje o PHP chama o
Python direto pela linha de comando (`ler_folhas()` em `web/lib.php`). Na
versão com RabbitMQ, essa função passa a publicar uma mensagem e um consumidor
grava o resultado; o JSON trocado é o mesmo.

## Rodar com Docker (recomendado)

O Docker já traz PHP, Python, OpenCV e Poppler; não é preciso instalar nenhum
deles no computador.

### 1. Instalar (uma vez por computador)

- **Git**: baixe em https://git-scm.com e instale com as opções padrão.
- **Docker Desktop**: baixe em https://www.docker.com/products/docker-desktop.
  No Windows ele pede para ativar o WSL2 e reiniciar o computador. Depois abra
  o Docker Desktop e espere aparecer "Engine running".

### 2. Clonar o projeto

No terminal, dentro da pasta onde o projeto deve ficar:

```bash
git clone https://github.com/ThiAmaral/corretor-omr.git
cd corretor-omr
```

Como o repositório é privado, na primeira vez o Git abre uma janela para entrar
na conta do GitHub. A conta precisa ter sido convidada como colaboradora.

### 3. Configurar o Supabase (uma vez)

1. Crie o projeto no Supabase seguindo a etapa 2.1 do
   [PLANO_DE_FASES.md](PLANO_DE_FASES.md#21-criar-o-projeto-no-supabase-passo-a-passo).
2. Copie o `.env.exemplo` para `.env` e preencha a `DATABASE_URL` (Session
   pooler), a `SUPABASE_URL` e a `SUPABASE_SECRET_KEY`. O `.env` não vai para o git.
3. Crie as tabelas e os buckets aplicando as migrações (precisa do Node.js):

   ```bash
   npx supabase login
   npx supabase link --project-ref <id-do-projeto>
   npx supabase db push
   ```

### 4. Rodar

```bash
docker compose up --build
```

A primeira vez demora alguns minutos (baixa e monta a imagem). Quando o texto
parar de rolar, abra http://localhost:8080.

### Dia a dia

| Quero…                               | Comando                                          |
|--------------------------------------|--------------------------------------------------|
| Parar o sistema                      | `Ctrl+C` no terminal, ou `docker compose down`    |
| Ligar de novo                        | `docker compose up`                              |
| Atualizar para a versão do GitHub    | `git pull` e depois `docker compose up --build`  |
| Mudar o banco                        | `npx supabase migration new <nome>`, escrever o SQL e `npx supabase db push` |

Os dados ficam no Supabase, e não no container: sobrevivem a `down`, `up` e
`git pull`. No plano Free, o projeto é pausado depois de 7 dias sem uso; antes
de uma apresentação, entre no painel e confirme que ele está ativo.

## Rodar sem Docker

Requisitos: PHP 8.1+ com `pdo_pgsql`, `curl` e `openssl`, Python 3.10+ e
`poppler-utils` (para PDF). O `.env` é o mesmo do Docker.

No Windows, dois cliques no `iniciar.cmd`: ele liga as extensões do PHP na
linha de comando, sem mexer no `php.ini`. Em outros sistemas:

```bash
pip install -r worker/requirements.txt
php -S localhost:8000 -t web
```

## Ano letivo e filtros

Cada prova é cadastrada com o ano letivo e o período. A divisão do ano segue a
variável `PERIODO_ESCOLA` (`bimestre`, `trimestre` ou `semestre`; padrão
`bimestre`), definida no `docker-compose.yml`. A lista de provas pode ser
filtrada por ano e período. Para criar outros filtros (turma, série), acrescente
uma entrada em `filtros_de_provas()` no `web/lib.php`; o formulário e a consulta
se ajustam sozinhos.

## Testar o fluxo completo

O cadastro não pede mais o formato da folha nem as áreas. A prova nasce como
**rascunho**, passa pela tela **Revisar gabarito** e só recebe folhas depois de
aprovada.

### Com a folha AMA (gabarito por foto)

1. Em **Nova prova**, informe o ano e o período e envie
   `exemplos/ama/geradas_8A/gabarito.jpg`. O sistema lê o caderno pelo QR
   (`2265M0801`), o formato (26 questões em blocos de 7, 7, 7 e 5) e as
   respostas, e sugere o nome "AMA – Matemática 8º ano (M0801)".
2. Na revisão, todas as questões já vêm com a disciplina Matemática (modelo
   reconhecido pelo caderno). Confira as respostas, marque a dificuldade e, se
   quiser, a habilidade (ex.: `D07`). Para aplicar a várias questões de uma vez,
   clique no número da primeira e no da última. Depois, **Aprovar gabarito**.
3. Na página da prova, envie as fotos `exemplos/ama/geradas_8A/8A_aluno*.jpg`
   com a turma `8A`. Folhas de outro caderno são recusadas.

### Com o gabarito digitado

Em **Nova prova**, digite `AACBBBAADAAABBABDBDCABDCCB`. Sem foto, o formato e o
caderno vêm da primeira folha de aluno enviada; folhas com outro número de
questões ou de outro caderno são recusadas com o motivo.

### Com o modelo antigo (várias disciplinas)

1. Envie `exemplos/gabarito_oficial.png` como gabarito (ou digite `CCADCBCABADDBBCDCCCC`).
2. Como não há caderno conhecido, escolha as disciplinas na revisão: clique na
   questão 1 e na 10 e aplique Língua Portuguesa; depois 11 e 20, Matemática. A
   faixa colorida mostra o que já tem disciplina, e a prova não pode ser
   aprovada com questões sem disciplina.
3. Na página da prova, envie as folhas de `exemplos/provas_7A` com a turma `7A`
   (ou o PDF `turma_7A.pdf`, uma folha por página) e depois as de `provas_7B`.
4. Folhas com marcação fraca ou dupla aparecem com o selo "Revisar". Abra,
   confira a imagem anotada e salve.

Gabarito, disciplinas e dificuldade podem ser alterados depois em **Editar
gabarito e dificuldade**, na página da prova; os relatórios são recalculados sem
reenviar as folhas. Novos modelos de caderno (disciplina automática) entram em
`modelos_de_prova()` no `web/lib.php`, e o catálogo de disciplinas em
`disciplinas()`.

## Relatórios

Na página da prova, clique numa turma para ver:

- **Turmas:** acerto geral, por disciplina e por nível (Fácil, Médio, Difícil).
- **Prioridades de revisão:** questões abaixo do `LIMITE_REFORCO`, da mais
  urgente para a menos urgente. Primeiro as marcadas como fáceis ou médias
  (conteúdo que a turma deveria dominar), depois as sem nível e por último as
  difíceis; em cada grupo, do menor acerto para o maior. Cada uma traz o motivo
  e o erro comum (a alternativa errada mais marcada). Questões com habilidade
  cadastrada (ex.: `D07`) também aparecem agrupadas por habilidade.
- **Acerto por questão:** o mapa de bolinhas com a dificuldade marcada no canto,
  filtrável por disciplina e por dificuldade.
- **Dificuldade marcada × observada:** a observada vem da proporção de acerto
  (acima de `LIMITE_FACIL`, 70%, é fácil; abaixo de `LIMITE_DIFICIL`, 30%, é
  difícil). A tabela mostra em quantas questões o nível previsto se confirmou e
  lista as que divergem.

**Imprimir relatório** gera uma versão sem formulários nem a lista de folhas,
para a reunião pedagógica. **CSV por questão** (da turma escolhida) e **CSV por
turma** abrem no Excel em português. Os três limites são variáveis de ambiente,
como no `docker-compose.yml`.

Testes das regras do PHP (correção, revisão do gabarito, modelos de caderno,
formato vindo da 1ª folha, resumos, prioridades e dificuldade observada), sem banco:

```bash
php web/testes/regras.php
```

## Testar só o leitor (sem PHP)

Descobrir o formato de uma folha (caderno, blocos, alternativas):

```bash
python worker/omr_cli.py detectar exemplos/ama/geradas_8A/gabarito.jpg
```

Corrigir uma turma usando o formato detectado no gabarito:

```bash
python worker/omr_cli.py corrigir --gabarito exemplos/ama/geradas_8A/gabarito.jpg --provas exemplos/ama/geradas_8A --turma 8A
```

Com um layout cadastrado (modelo antigo):

```bash
python worker/omr_cli.py corrigir \
  --gabarito exemplos/gabarito_oficial.png \
  --provas exemplos/provas_7A --turma 7A \
  --layout exemplos/layout_linguagens_matematica.json \
  --debug /tmp/anotadas
```

Medir a precisão da leitura contra as respostas conhecidas (sem `--layout`, o
formato é detectado e o validador mede também caderno e blocos):

```bash
python ferramentas/validar_leitura.py exemplos/ama/geradas_8A
python ferramentas/validar_leitura.py exemplos/provas_7A --layout exemplos/layout_linguagens_matematica.json
```

Gerar fotos fictícias da AMA a partir do PDF em branco (perspectiva, sombra,
desfoque; `--invertidas 0.3` deixa 30% de cabeça para baixo):

```bash
python ferramentas/gerar_folhas_teste.py exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf --turma 8B --alunos 30 --saida exemplos/ama/geradas_8B
```

Gerar mais folhas do modelo antigo (inclusive mais tortas):

```bash
python ferramentas/gerar_folhas_teste.py exemplos/gabarito_oficial.png \
  --layout exemplos/layout_linguagens_matematica.json \
  --turma 8C --alunos 30 --rotacao 5 --saida exemplos/provas_8C --pdf
```

Testes automatizados do leitor (casos de borda: folha virada, sem QR, sem um
marcador, em branco, toda marcada, caderno errado):

```bash
pip install -r worker/requirements-dev.txt
python -m pytest worker/testes -q
```

## Como a leitura funciona

1. Divide a imagem pelo próprio fundo, para tirar sombras e a cor da mesa.
2. Procura os 4 quadrados pretos dos cantos da área de respostas (folha AMA).
   Se só 3 aparecerem, estima o 4º.
3. Lê o QR code na faixa acima da área de respostas: ele dá o código do
   caderno e diz qual é o lado de cima (folha virada ou deitada). Sem QR, a
   folha virada é reconhecida pelos blocos (o bloco menor fica à direita).
4. Endireita a área entre os marcadores. Sem marcadores, procura a moldura do
   modelo antigo e, se não achar, segue com a imagem inteira.
5. Detecta as bolinhas pelo formato (círculos de tamanho parecido com o dos
   marcadores) e descarta caixinhas quadradas e letras.
6. Descobre o formato: colunas de bolinhas separadas por espaços pequenos são
   alternativas; espaços grandes separam blocos de questões. Em cada bloco, as
   questões descem de cima para baixo. Bolinhas não encontradas em uma questão
   têm a posição estimada pela grade.
7. Mede o escurecimento de cada bolinha, calibrado pela própria folha
   (bolinha vazia típica = 0, totalmente preenchida = 1).
   Acima de 0,50 conta como marcada; entre 0,25 e 0,65 vai para revisão.
8. Devolve a confiança da leitura (`alta` ou `baixa`, com os motivos), para
   pedir outra foto quando faltar QR, marcador ou bolinhas.

## Limitações conhecidas

- Os blocos detectados precisam bater com o formato da prova, e o caderno do
  QR com o da prova; se não baterem, a folha aparece em "não puderam ser lidas"
  com o motivo.
- As folhas de teste são simulações. Antes de usar com a escola, rode o
  validador com fotos reais de folhas AMA preenchidas e ajuste `LIMIAR_MARCADA`
  e `ZONA_DUVIDA` em `worker/omr.py` se necessário.
- Sem autenticação nem proteção CSRF: é um protótipo para uso local. O login
  com Supabase Auth chega na Fase 6.
- Cada questão tem uma disciplina só (tabela `questoes`).
- A letra `L` do caderno como Língua Portuguesa ainda não foi confirmada; por
  enquanto é só uma sugestão, que o professor confere na revisão.
- No modelo impresso atual, a 4ª bolinha das questões 15, 17 e 19 está rotulada
  como "A". A leitura é por posição, então não afeta a correção, mas vale
  corrigir o arquivo da folha.
