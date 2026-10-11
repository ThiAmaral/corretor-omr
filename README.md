# Corretor de provas — protótipo local

Lê folhas de resposta digitalizadas, compara com o gabarito oficial e mostra a
taxa de acerto por turma, por área e por questão. Nesta fase o sistema não
identifica o aluno: a turma é informada no momento do envio das folhas.

## Estrutura

```
web/        Backend + telas em PHP (cadastro da prova, envio, relatórios, revisão)
worker/     Leitor óptico em Python/OpenCV (só lê a folha; não conhece o banco)
ferramentas/ Gerador de folhas de teste e validador de precisão
exemplos/   Gabarito real, layouts e folhas fictícias das turmas 7A e 7B
```

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

```bash
python worker/omr_cli.py corrigir \
  --gabarito exemplos/gabarito_oficial.png \
  --provas exemplos/provas_7A --turma 7A \
  --layout exemplos/layout_linguagens_matematica.json \
  --debug /tmp/anotadas
```

Medir a precisão da leitura contra as respostas conhecidas:

```bash
python ferramentas/validar_leitura.py exemplos/provas_7A --layout exemplos/layout_linguagens_matematica.json
```

Gerar mais folhas fictícias (inclusive mais tortas):

```bash
python ferramentas/gerar_folhas_teste.py exemplos/gabarito_oficial.png \
  --layout exemplos/layout_linguagens_matematica.json \
  --turma 8C --alunos 30 --rotacao 5 --saida exemplos/provas_8C --pdf
```

## Como a leitura funciona

1. Procura a moldura retangular da folha e corrige a perspectiva. Se a moldura
   estiver cortada no scan, segue com a imagem inteira.
2. Detecta as bolinhas pelo formato (círculos de tamanho parecido) e descarta
   caixinhas quadradas e letras dos títulos.
3. Estima a inclinação pelas linhas de bolinhas e monta a grade
   linhas x colunas x alternativas, conforme o layout da prova.
4. Mede o escurecimento de cada bolinha, calibrado pela própria folha
   (bolinha vazia típica = 0, totalmente preenchida = 1).
   Acima de 0,50 conta como marcada; entre 0,25 e 0,65 vai para revisão.

## Limitações conhecidas

- O número de linhas e de bolinhas por linha precisa bater com o layout
  cadastrado; se não bater, a folha aparece em "não puderam ser lidas" com o motivo.
- As folhas de teste são simulações feitas a partir do gabarito real. Antes de
  usar com a escola, rode o validador com algumas folhas reais digitalizadas
  pelo scanner que vai ser usado e ajuste `LIMIAR_MARCADA` e `ZONA_DUVIDA` em
  `worker/omr.py` se necessário.
- Sem autenticação nem proteção CSRF: é um protótipo para uso local.
- No modelo impresso atual, a 4ª bolinha das questões 15, 17 e 19 está rotulada
  como "A". A leitura é por posição, então não afeta a correção, mas vale
  corrigir o arquivo da folha.
