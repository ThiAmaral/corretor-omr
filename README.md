# Corretor de provas — protótipo local

Lê folhas de resposta digitalizadas ou fotografadas, compara com o gabarito
oficial e mostra a taxa de acerto por turma, por área e por questão. Lê a folha
da AMA (SEDU-ES / CAEd) e o modelo antigo do protótipo, e descobre sozinho o
formato da folha (caderno, blocos de questões e alternativas). Nesta fase o sistema não
identifica o aluno: a turma é informada no momento do envio das folhas.

## Estrutura

```
web/        Backend + telas em PHP (cadastro da prova, envio, relatórios, revisão)
worker/     Leitor óptico em Python/OpenCV (só lê a folha; não conhece o banco)
ferramentas/ Gerador de folhas de teste e validador de precisão
exemplos/   Gabarito real, layouts e folhas fictícias das turmas 7A e 7B;
            em exemplos/ama, a folha AMA em branco e fotos geradas a partir dela
```

O andamento do plano de evolução fica em [PROGRESSO.md](PROGRESSO.md).

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

### 3. Rodar

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
| Apagar todas as provas e folhas      | `docker compose down -v` (não tem volta)          |

As provas e folhas enviadas ficam num volume do Docker (`dados`), separado do
código: sobrevivem a `down`, `up` e `git pull`. Só o `-v` apaga.

## Rodar sem Docker

Requisitos: PHP 8.1+ com `pdo_sqlite`, Python 3.10+ e `poppler-utils` (para PDF).

```bash
pip install -r worker/requirements.txt
php -S localhost:8000 -t web
```

Os dados ficam em `armazenamento/` (banco SQLite, uploads e imagens anotadas).

## Ano letivo e filtros

Cada prova é cadastrada com o ano letivo e o período. A divisão do ano segue a
variável `PERIODO_ESCOLA` (`bimestre`, `trimestre` ou `semestre`; padrão
`bimestre`), definida no `docker-compose.yml`. A lista de provas pode ser
filtrada por ano e período. Para criar outros filtros (turma, série), acrescente
uma entrada em `filtros_de_provas()` no `web/lib.php`; o formulário e a consulta
se ajustam sozinhos.

## Testar o fluxo completo

### Com a folha AMA

1. Em **Nova prova**, use 26 questões, 4 colunas, alternativas `A,B,C,D`
   (as colunas podem ter tamanhos diferentes: a AMA tem 7, 7, 7 e 5).
2. Envie `exemplos/ama/geradas_8A/gabarito.jpg` como gabarito. O sistema confere
   o formato e guarda o código do caderno lido no QR (`2265M0801`).
3. Na página da prova, envie as fotos `exemplos/ama/geradas_8A/8A_aluno*.jpg`
   com a turma `8A`. Folhas de outro caderno são recusadas.

### Com o modelo antigo

1. Em **Nova prova**, use 20 questões, 2 colunas, alternativas `A,B,C,D`.
2. Envie `exemplos/gabarito_oficial.png` como gabarito (ou digite `CCADCBCABADDBBCDCCCC`).
3. Áreas: deixe em branco ou use `Linguagens: 1-10` e `Matemática: 11-20`.
4. Na página da prova, envie as folhas de `exemplos/provas_7A` com a turma `7A`
   (ou o PDF `turma_7A.pdf`, uma folha por página) e depois as de `provas_7B`.
5. Folhas com marcação fraca ou dupla aparecem com o selo "Revisar". Abra,
   confira a imagem anotada e salve.

As áreas podem ser alteradas a qualquer momento (por exemplo, `Matemática: 1-20`)
e os relatórios são recalculados sem reenviar as folhas.

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

- Os blocos detectados precisam bater com o layout cadastrado, e o caderno do
  QR com o da prova; se não baterem, a folha aparece em "não puderam ser lidas"
  com o motivo.
- As folhas de teste são simulações. Antes de usar com a escola, rode o
  validador com fotos reais de folhas AMA preenchidas e ajuste `LIMIAR_MARCADA`
  e `ZONA_DUVIDA` em `worker/omr.py` se necessário.
- Sem autenticação nem proteção CSRF: é um protótipo para uso local.
- No modelo impresso atual, a 4ª bolinha das questões 15, 17 e 19 está rotulada
  como "A". A leitura é por posição, então não afeta a correção, mas vale
  corrigir o arquivo da folha.
