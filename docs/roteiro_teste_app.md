# Roteiro de teste do aplicativo

Teste manual do app (Fase 7), para rodar num Android e num iPhone antes de cada
envio ao TestFlight / teste interno do Google Play. Leva cerca de 20 minutos.
Anote o resultado de cada passo (✅ / ❌ e o que aconteceu) na tabela do fim.

## Antes de começar

- Servidor rodando e aberto para a rede (`iniciar.cmd`) ou publicado.
- App instalado (Expo Go ou development build) com o `mobile/.env` apontando
  para o servidor.
- Uma conta de professor no Supabase Auth.
- Material impresso: o gabarito oficial da **M0801** e 5 folhas de alunos
  preenchidas, sendo pelo menos uma com uma marcação fraca ou dupla. Se não
  houver folhas reais, imprima `exemplos/ama/geradas_8A/gabarito.jpg` e
  `8A_aluno01.jpg` a `8A_aluno05.jpg`.
- Uma folha de **outro caderno** (ou do modelo antigo, `exemplos/gabarito_oficial.png`).
- Um cronômetro.

Use nomes de teste (`TESTE ...`) e uma turma de teste (`9T`), para apagar
depois.

## Passos

| # | Passo | Resultado esperado |
|---|---|---|
| 1 | Abrir o app e entrar com senha errada | "E-mail ou senha incorretos." |
| 2 | Entrar com a senha certa | "Minhas provas", com o e-mail no topo |
| 3 | Fechar o app e abrir de novo | Continua logado |
| 4 | Trocar o ano e a edição nos filtros | A lista muda; "Todas" mostra todas do ano |
| 5 | **Nova prova** → conferir a edição | Aparecem "1ª edição" e "2ª edição", com a edição atual marcada |
| 6 | Nome `TESTE app <data>` → **Fotografar o gabarito** | Pede a câmera (1ª vez); aparecem só 4 cantos finos perto da borda e "Folha inteira na tela, bem iluminada. Pode estar um pouco torta." |
| 7 | Apontar para o gabarito | Aparece "Caderno 2265M0801" em verde |
| 8 | Tirar a foto com a folha um pouco torta (cronometrar até a revisão abrir) | "Procurando os 4 quadrados pretos..." e depois "Lendo o gabarito..."; em poucos segundos abre "Revisar gabarito" com o caderno e "26 questões em 4 blocos" |
| 9 | Conferir o recorte na revisão | Só a área de respostas (sem o nome); um círculo verde sobre cada bolinha marcada; "Ver a área de respostas inteira" amplia |
| 10 | Tocar numa questão e trocar a resposta | O círculo muda de lugar; o cartão aberto fica azul |
| 11 | **Marcar várias questões de uma vez**: 1 a 9 Fácil, 10 a 18 Médio, 19 a 26 Difícil | "Aplicado às questões ..."; cada cartão mostra a dificuldade |
| 12 | Abrir a questão 1 e escrever a habilidade `D07` | Aparece no resumo do cartão |
| 13 | **Salvar**, sair da tela e voltar pela lista | As mudanças continuam lá |
| 14 | **Aprovar gabarito** | Abre a tela da prova, sem turmas corrigidas |
| 15 | Turma `9T` → **Escanear** | Câmera aberta com "0 folhas lidas" |
| 16 | Fotografar a folha do outro caderno | Aviso em vermelho antes da foto (se o QR for lido) ou "Folha de outra prova" depois; o contador não muda. Com o modelo antigo: "Não encontrei os 4 quadrados pretos..." |
| 17 | Fotografar as 5 folhas, uma por vez (cronometrar a turma toda) | Cada uma mostra a nota (ex.: 18/26) e as questões a conferir; o contador sobe |
| 17a | Fotografar uma folha de cabeça para baixo | Lida normalmente, com a mesma nota |
| 17b | Fotografar com a folha longe (ocupando metade da tela) e depois bem longe | Metade da tela: lida. Bem longe: aviso na hora para aproximar, sem enviar nada |
| 18 | Na folha com marcação fraca, corrigir a questão e **Salvar e próxima** | A correção é aceita e a câmera volta |
| 19 | Numa folha, tocar **Ver todas** | Mostra as 26 questões, com certo/errado |
| 20 | **Concluir e ver resultado** | Acerto geral, por dificuldade, por disciplina e as prioridades |
| 21 | **Ver as 5 folhas** → abrir a corrigida | "Já foi revisada"; imagem anotada: o QR em cima e as bolinhas, sem nome nem turma |
| 21a | Voltar à tela da prova | "Fotos neste celular" com o número de folhas lidas + o gabarito; a galeria do celular não tem essas fotos |
| 21b | **Apagar as fotos deste celular** → confirmar | A seção some; as notas continuam |
| 22 | Desligar o Wi-Fi e puxar a lista para atualizar | "Sem conexão com o servidor..." sem fechar o app |
| 23 | **Sair** | Volta ao login |
| 24 | Abrir o mesmo resultado no painel web (`prova.php`) | Os mesmos números do app |

## Resultado

| Aparelho | Sistema | Data | Passos com ❌ | Tempo do gabarito (passo 8) | Tempo da turma (passo 17) | Observações |
|---|---|---|---|---|---|---|
| | Android | | | | | |
| | iOS | | | | | |

## Depois do teste

Descartar ou apagar a prova `TESTE app ...` e as folhas da turma `9T` (painel
do Supabase ou SQL), para não misturar com os dados do piloto.
