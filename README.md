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
  api/      API REST em JSON para o aplicativo (controlador frontal)
  src/      Operações do sistema (servicos.php), login (auth.php) e rotas da API (api.php)
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
4. Cadastre os professores. O sistema só abre com login, e só entra quem foi
   cadastrado pela coordenação:
   - **Authentication → Sign In / Providers**: desligue **Allow new users to
     sign up** (ninguém cria conta sozinho);
   - **Authentication → Users → Add user → Create new user**: e-mail e senha
     do professor, com **Auto Confirm User** marcado. Ou **Send invitation**,
     para o professor escolher a senha pelo e-mail.

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
php -S localhost:8000 -t web web/src/roteador.php
```

O `web/src/roteador.php` faz o que o Apache faz no Docker: manda `/api/...`
para a API e esconde as pastas `src/` e `testes/`.

## Ano letivo e filtros

Cada prova é cadastrada com o ano letivo e o período. A divisão do ano segue a
variável `PERIODO_ESCOLA` (`bimestre`, `trimestre` ou `semestre`; padrão
`bimestre`), definida no `docker-compose.yml`. A lista de provas pode ser
filtrada por ano e período. Para criar outros filtros (turma, série), acrescente
uma entrada em `filtros_de_provas()` no `web/lib.php`; o formulário e a consulta
se ajustam sozinhos.

## Testar o fluxo completo

Entre com o e-mail e a senha de um professor cadastrado no Supabase. O
cadastro não pede mais o formato da folha nem as áreas. A prova nasce como
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

## API (para o aplicativo)

O aplicativo faz login direto no Supabase Auth (`signInWithPassword`, com a URL
e a chave **publishable**) e manda o token em toda chamada:
`Authorization: Bearer <token>`. O PHP confere o token com o Supabase
(`GET /auth/v1/user`) e guarda a resposta por 5 minutos. A chave secret e a
`DATABASE_URL` nunca saem do servidor.

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/config` | Períodos, disciplinas, dificuldades, limites e o professor do token |
| GET | `/api/provas?ano=&periodo=` | Lista de provas |
| POST | `/api/provas` | `multipart`: `ano`, `periodo`, `nome` (opcional) e a foto em `gabarito_imagem` ou as letras em `gabarito_digitado`. Devolve o rascunho e a leitura da foto, com as coordenadas das bolinhas |
| GET | `/api/provas/{id}` | Prova, questões, catálogo de disciplinas e a imagem do gabarito (URL que vale 5 min) |
| DELETE | `/api/provas/{id}` | Descarta um rascunho |
| PUT | `/api/provas/{id}/questoes` | JSON `{"nome": ..., "questoes": [{"numero": 3, "resposta": "B", "disciplina": "Matemática", "dificuldade": "facil", "habilidade": "D07"}]}`. Só o que mudou; `null` apaga |
| POST | `/api/provas/{id}/aprovar` | Aprova o rascunho (aceita o mesmo JSON do PUT) |
| GET | `/api/provas/{id}/folhas?turma=` | Folhas lidas, com nota, e as recusadas |
| POST | `/api/provas/{id}/folhas` | `multipart`: `turma` e a foto em `folha` (ou várias em `folhas[]`). Cada página volta com nota, acertos, questões a revisar e a leitura (coordenadas das bolinhas), ou com `erro` e `codigo`. Folha recusada não é gravada |
| GET | `/api/provas/{id}/relatorio?turma=` | Resumo por turma (disciplina e dificuldade), prioridades de revisão, habilidades com acerto baixo, estatísticas por questão e dificuldade marcada × observada |
| GET | `/api/folhas/{id}` | Folha, gabarito, níveis de preenchimento e a imagem anotada (URL que vale 5 min) |
| PUT | `/api/folhas/{id}` | Revisão manual: `{"respostas": {"12": "B", "13": null}}` (só as que mudaram) ou a lista completa. `MULTIPLA` = marcação dupla |

Erros saem como `{"erro": "mensagem para o professor"}`, às vezes com mais
campos (`erros` na aprovação, `codigo` na leitura da foto). Códigos: 400
requisição malformada, 401 sem login ou token vencido, 403 CSRF (só no painel),
404 não encontrado, 405 método errado, 409 a situação da prova não permite (ex.:
enviar folha antes de aprovar), 413 arquivo grande demais (20 MB por arquivo),
415 não é JPEG, PNG, TIFF nem PDF (o tipo é lido do conteúdo, não da extensão),
422 dado inválido, 500 erro no servidor (detalhe só no log).

Exemplo com `curl`, depois de obter o token:

```bash
curl -H "Authorization: Bearer $TOKEN" -F turma=8A -F folha=@exemplos/ama/geradas_8A/8A_aluno01.jpg http://localhost:8000/api/provas/4/folhas
```

Para testar o fluxo todo com um professor de verdade (login no Supabase,
cadastro por foto, revisão, aprovação, 20 folhas, relatório e revisão de uma
folha), com o sistema rodando:

```bash
powershell -ExecutionPolicy Bypass -File ferramentas/testar_api.ps1 -Email professor@escola.br
```

O script pede a senha sem mostrá-la. A mesma sequência está na coleção do
Postman em [docs/api.postman.json](docs/api.postman.json).

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

Fotos mais difíceis, para testar a leitura de celular: `--curvatura 0.015`
(papel curvado ou dobrado), `--sombra-dura 0.5` (metade das fotos com sombra
de borda nítida), `--desfoque 1 3` (faixa do desfoque) e `--resolucao 1200 2400`
(faixa do lado maior da foto, em px):

```bash
python ferramentas/gerar_folhas_teste.py exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf   --turma 8B --alunos 30 --curvatura 0.015 --sombra-dura 0.5 --desfoque 1 3   --resolucao 1200 2400 --saida exemplos/ama/dificeis_8B
```

Gerar mais folhas do modelo antigo (inclusive mais tortas):

```bash
python ferramentas/gerar_folhas_teste.py exemplos/gabarito_oficial.png \
  --layout exemplos/layout_linguagens_matematica.json \
  --turma 8C --alunos 30 --rotacao 5 --saida exemplos/provas_8C --pdf
```

Testes automatizados do leitor (casos de borda: folha virada, sem QR, sem um
marcador, em branco, toda marcada, caderno errado, foto desfocada, pequena
demais ou escura, papel curvado):

```bash
pip install -r worker/requirements-dev.txt
python -m pytest worker/testes -q
```

## Como a leitura funciona

1. Divide a imagem pelo próprio fundo, para tirar sombras e a cor da mesa.
2. Procura os 4 quadrados pretos dos cantos da área de respostas (folha AMA).
   Eles precisam ser isolados (papel branco em volta), o que exclui os "olhos"
   do QR code quando a foto está borrada. Se só 3 aparecerem, estima o 4º.
3. Mede a qualidade da foto: brilho do papel, tamanho dos marcadores em pixels
   e desfoque (largura da borda borrada dos marcadores ÷ lado deles). Foto
   escura ou pequena demais é recusada na hora; desfocada recebe uma mensagem
   própria se a leitura falhar.
4. Lê o QR code na faixa acima da área de respostas: ele dá o código do
   caderno e diz qual é o lado de cima (folha virada ou deitada). Sem QR, a
   folha virada é reconhecida pelos blocos (o bloco menor fica à direita).
   Se o QR não sai de primeira, tenta de novo com mais nitidez.
5. Endireita a área entre os marcadores. Sem marcadores, procura a moldura do
   modelo antigo e, se não achar, segue com a imagem inteira.
6. Detecta as bolinhas pelo formato (círculos de tamanho parecido com o dos
   marcadores) e descarta caixinhas quadradas e letras.
7. Descobre o formato: colunas de bolinhas separadas por espaços pequenos são
   alternativas; espaços grandes separam blocos de questões. Em cada bloco, as
   questões descem de cima para baixo e são agrupadas com a inclinação do
   próprio bloco (papel curvado). Bolinhas não encontradas em uma questão têm a
   posição estimada pela grade; uma linha inteira que falte no meio do bloco é
   recolocada pelo espaço entre as vizinhas.
8. Mede o escurecimento de cada bolinha, calibrado pela própria folha
   (bolinha vazia típica = 0, totalmente preenchida = 1).
   Acima de 0,50 conta como marcada; entre 0,25 e 0,65 vai para revisão.
9. Devolve a confiança da leitura (`alta` ou `baixa`, com os motivos), para
   pedir outra foto quando faltar QR, marcador ou bolinhas, ou quando a foto
   estiver um pouco desfocada.

### O que o leitor devolve

Para cada página, `omr_cli.py ler` devolve um objeto JSON. Se a folha foi lida
(`ok: true`):

| Campo | Conteúdo |
|---|---|
| `caderno`, `layout` | Código do QR e formato detectado (blocos e alternativas) |
| `respostas` | Por questão: `resposta`, `situacao` (`marcada`, `branco`, `multipla`), `duvidosa`, `niveis` (escurecimento de cada alternativa) e `bolinhas` (centro de cada alternativa na foto, de 0 a 1, em fração da largura e da altura) |
| `raio_bolinha` | Raio típico das bolinhas, em fração da largura da foto |
| `confianca` | `nivel` (`alta`/`baixa`) e `motivos` |
| `qualidade` | `lado_marcador` (px), `desfoque`, `brilho` e `problema` (`null`, `pouca_resolucao` ou `desfocada`) |

Se não foi lida (`ok: false`), vêm `erro` (a mensagem para o professor) e
`codigo`: `escura`, `pouca_resolucao`, `desfocada`, `sem_marcadores`, `grade`,
`formato_diferente`, `caderno_diferente` ou `arquivo`.

## Limitações conhecidas

- Os blocos detectados precisam bater com o formato da prova, e o caderno do
  QR com o da prova; se não baterem, a folha aparece em "não puderam ser lidas"
  com o motivo.
- As folhas de teste são simulações. Antes de usar com a escola, rode o
  validador com fotos reais de folhas AMA preenchidas e ajuste `LIMIAR_MARCADA`
  e `ZONA_DUVIDA` em `worker/omr.py` se necessário. O mesmo vale para os limites
  de qualidade (`DESFOQUE_MAXIMO`, `LADO_MINIMO`, `BRILHO_MINIMO`).
- Sem QR legível, a leitura fica mais lenta (~2 s por folha, contra ~0,2 s),
  por causa das novas tentativas com mais nitidez.
- Fora do computador local, o servidor precisa de **HTTPS**: o iOS só aceita
  conexões seguras, e a senha e o token passam por elas. A hospedagem ainda
  não foi escolhida.
- Pelo painel web, o nome do arquivo enviado fica guardado. Se a escola
  digitalizar as folhas com o nome do aluno no arquivo, ele aparece na lista de
  folhas; o app manda nomes genéricos.
- Cada questão tem uma disciplina só (tabela `questoes`).
- A letra `L` do caderno como Língua Portuguesa ainda não foi confirmada; por
  enquanto é só uma sugestão, que o professor confere na revisão.
- No modelo impresso atual, a 4ª bolinha das questões 15, 17 e 19 está rotulada
  como "A". A leitura é por posição, então não afeta a correção, mas vale
  corrigir o arquivo da folha.
