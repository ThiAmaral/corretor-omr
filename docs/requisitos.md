# Requisitos do Corretor de provas

Levantamento dos requisitos funcionais e não funcionais do sistema, com a
situação de cada um em **2026-10-20**. Serve de base para avaliar o que o
sistema já atende, o que falta e o que precisa melhorar, e para o capítulo de
requisitos e de resultados do TCC.

Fontes: o [plano de fases](../PLANO_DE_FASES.md) (pedidos originais, decisões e
critérios de pronto de cada fase), o [registro de execução](../PROGRESSO.md)
(testes e medições), os pedidos feitos durante o desenvolvimento (2026-10-18) e
o próprio código, conferido requisito por requisito.

## Sumário

1. [Como ler este documento](#1-como-ler-este-documento)
2. [Contexto, atores e componentes](#2-contexto-atores-e-componentes)
3. [Requisitos funcionais](#3-requisitos-funcionais)
4. [Requisitos não funcionais](#4-requisitos-não-funcionais)
5. [Rastreabilidade dos pedidos](#5-rastreabilidade-dos-pedidos)
6. [Resumo da situação](#6-resumo-da-situação)
7. [Lacunas e melhorias, por prioridade](#7-lacunas-e-melhorias-por-prioridade)
8. [Como verificar no piloto](#8-como-verificar-no-piloto)
9. [Perguntas em aberto e dependências](#9-perguntas-em-aberto-e-dependências)

---

## 1. Como ler este documento

Cada requisito tem um identificador (**RF** para funcional, **RNF** para não
funcional), uma prioridade e uma situação. A coluna **Evidência** diz onde o
requisito foi conferido: um teste automatizado, um teste de ponta a ponta
registrado no PROGRESSO ou o arquivo do código.

**Prioridade**

| Prioridade | Significado |
|---|---|
| **Essencial** | Sem ele o sistema não resolve o problema do TCC ou não pode ser usado na escola |
| **Importante** | Faz diferença no uso real; a falta dele tem contorno, mas incomoda ou traz risco |
| **Desejável** | Melhora a experiência ou a manutenção; pode ficar para depois do piloto |

**Situação**

| Situação | Significado |
|---|---|
| **Atende** | Implementado e conferido com o banco, o Storage e o login reais ou com testes automatizados |
| **Atende (simulado)** | Implementado e testado só com fotos geradas, no navegador ou com o login simulado. Falta a prova real (fotos reais, celular de verdade, rede da escola) |
| **Parcial** | Atende só uma parte do requisito; a parte que falta está na observação |
| **Não atende** | Não implementado |
| **Depende de terceiros** | Não depende de código: falta material, decisão ou conta de fora |

Os números citados (tempos, porcentagens) vêm dos testes registrados no
PROGRESSO.md. Os conjuntos de teste usados são:

- **8A:** 20 fotos geradas a partir da folha AMA em branco do caderno M0801,
  com perspectiva, giro até 8°, sombra e desfoque (`exemplos/ama/geradas_8A`).
- **Difíceis:** conjuntos de 30 fotos geradas com papel curvado, sombra dura,
  desfoque forte e baixa resolução (Fase 5; não versionados, refeitos pelo
  gerador com as opções registradas).
- **Modelo antigo:** folhas das turmas 7A e 7B do protótipo (`exemplos/provas_7A`,
  `exemplos/provas_7B`).

Ainda **não há nenhuma foto real** de folha AMA preenchida (Fase 0). Por isso
todos os números de precisão da leitura são de fotos simuladas.

---

## 2. Contexto, atores e componentes

**Problema.** Os professores de uma escola não conseguem corrigir e analisar o
simulado da AMA (Avaliação de Monitoramento da Aprendizagem, SEDU-ES / CAEd) a
tempo de revisar com a turma, antes da prova oficial, as questões em que ela
teve mais dificuldade.

**Objetivo do sistema.** Corrigir as folhas de resposta pela foto e mostrar, por
turma, quais questões precisam de revisão e por quê.

### Atores

| Ator | O que faz | Observação |
|---|---|---|
| **Professor** | Cadastra a prova e o gabarito, revisa e aprova o gabarito, escaneia as folhas da turma, confere as leituras duvidosas, vê os relatórios | Usa o aplicativo e o painel web |
| **Coordenação** | Cadastra os professores no Supabase; vê os relatórios em tela grande e imprime para a reunião pedagógica | Hoje não tem perfil próprio no sistema: entra como professor (ver RF-01.6) |
| **Aluno** | Preenche a folha de resposta em papel | Não usa o sistema e não é identificado |
| **Supabase** | Guarda o banco (PostgreSQL), as imagens (Storage) e as contas (Auth) | Serviço externo, plano Free, região São Paulo |

### Componentes

| Componente | Tecnologia | Papel |
|---|---|---|
| Aplicativo | Expo SDK 57 / React Native, TypeScript (`mobile/`) | Login, cadastro e revisão do gabarito, escaneamento, recorte da folha no celular, resultado |
| Painel web | PHP puro (`web/`) | As mesmas operações pelo navegador, relatório completo, impressão e CSV |
| API REST | PHP, controlador frontal (`web/api/`, rotas em `web/src/api.php`) | Usada pelo aplicativo; chama as mesmas funções do painel (`web/src/servicos.php`) |
| Leitor óptico | Python / OpenCV (`worker/omr.py`, `worker/omr_cli.py`) | Só lê a folha: formato, caderno, marcações e qualidade da foto. Não conhece o banco |
| Banco, Storage e login | Supabase | Tabelas `provas`, `questoes` e `folhas` com RLS sem políticas; bucket privado `anotadas`; Auth por e-mail e senha |

Regra de arquitetura: o PHP é dono do banco e da regra de correção; o Python só
devolve qual bolinha foi marcada; o aplicativo nunca grava direto no banco.

---

## 3. Requisitos funcionais

### RF-01 Acesso ao sistema

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-01.1 | O professor entra no **aplicativo** com e-mail e senha cadastrados no Supabase Auth | Essencial | Atende | Fase 6: login real pelo `testar_api.ps1` (prova 10). Fase 7: login real no Android com Expo Go (2026-10-12) |
| RF-01.2 | O professor entra no **painel web** com o mesmo e-mail e senha | Essencial | Atende | Fase 6: `login.php`; senha errada mostra "E-mail ou senha incorretos."; volta à página pedida |
| RF-01.3 | O sistema continua logado entre aberturas do app e renova a sessão sozinho | Importante | Atende (simulado) | `autoRefreshToken`, sessão no aparelho (`expo-sqlite/localStorage`). No painel, token renovado antes de vencer. Conferir no aparelho (roteiro, passo 3) |
| RF-01.4 | O professor sai do sistema (app e painel) | Importante | Atende | Fase 7 (app web): volta ao login e apaga a sessão. Painel: `sair.php` só por POST, avisa o Supabase |
| RF-01.5 | Só entra quem foi cadastrado pela coordenação; não existe cadastro aberto | Essencial | Parcial | O sistema não tem tela de cadastro. Mas o cadastro público do Supabase (`disable_signup`) estava **ligado** no teste de 2026-10-12 e precisa ser desligado no painel do Supabase |
| RF-01.6 | Perfis diferentes para professor e coordenação, e cada professor vê só as próprias provas (ou as da escola, conforme a regra definida) | Importante | Não atende | Todo usuário logado vê e altera todas as provas. A autoria é gravada (`provas.criado_por`), mas não é usada para restringir nada |

### RF-02 Provas

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-02.1 | Cadastrar uma prova informando só o ano letivo, a edição do simulado e o gabarito; o nome é opcional | Essencial | Atende | Fase 3 (projeto real): sem formato, colunas nem áreas. App e painel |
| RF-02.2 | Sugerir o nome da prova pelo caderno do QR (ex.: "AMA – Matemática 8º ano (M0801)") | Desejável | Atende | Fase 3, teste de `sugerir_nome_prova()` em `regras.php` |
| RF-02.3 | A prova nasce como **rascunho** e só recebe folhas depois de aprovada | Essencial | Atende | Fase 3: envio antes da aprovação recusado (409 na API) |
| RF-02.4 | Listar as provas e filtrar por ano e edição | Importante | Atende | Fase 2 (filtros), Fase 7 (lista no app) |
| RF-02.5 | Descartar um rascunho, apagando a prova e a imagem do gabarito | Importante | Atende | Fase 3 (projeto real); app pede confirmação; apaga também as fotos guardadas no celular |
| RF-02.6 | Editar gabarito, disciplinas e dificuldade de uma prova já aprovada, com o relatório recalculado sem reenviar as folhas | Importante | Atende | Fase 3: trocar a resposta da questão 1 recalculou 63,1% → 61,0%. Numa prova ativa não se apaga resposta nem disciplina |
| RF-02.7 | O período da prova é a edição do simulado (1ª, 2ª...), com a quantidade configurável; outras escolas podem usar bimestre, trimestre ou semestre | Importante | Atende | `PERIODO_ESCOLA` e `EDICOES_SIMULADO`; teste em `regras.php` |
| RF-02.8 | Apagar uma prova já aprovada, com as folhas e as imagens | Importante | Não atende | Só o rascunho pode ser descartado. Prova ativa e as imagens no Storage só saem pelo painel do Supabase |

### RF-03 Gabarito oficial

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-03.1 | Informar o gabarito por **foto** de uma folha AMA preenchida pelo professor | Essencial | Atende (simulado) | Fase 3 e Fase 7: caderno, 26 questões e respostas lidas do `gabarito.jpg` gerado |
| RF-03.2 | Informar o gabarito **digitando** as letras em ordem | Essencial | Atende | Fase 3 (projeto real). O campo do app ignora espaços e números e conta as questões |
| RF-03.3 | Com o gabarito digitado, o formato e o caderno vêm da **primeira folha de aluno** lida; folhas com outro número de questões ou outro caderno são recusadas | Essencial | Atende | Fase 3: folha de 20 questões recusada numa prova de 26 |
| RF-03.4 | Mostrar o formato detectado ("Caderno M0801 · 26 questões em 4 blocos (7, 7, 7, 5) · alternativas A–D") | Importante | Atende | Fase 3, painel e app |
| RF-03.5 | Revisão do gabarito em cartões: resposta editável, disciplina, dificuldade (Fácil, Médio, Difícil) e habilidade opcional (ex.: D07) | Essencial | Atende | Painel (`gabarito.php`) e app (`revisar.tsx`) |
| RF-03.6 | Leitura duvidosa do gabarito em destaque (marcação fraca ou dupla); fraca abaixo do limiar fica sem resposta | Essencial | Atende | Fase 3: questões 12, 13, 18 e 24 de uma foto com marcações fracas |
| RF-03.7 | Mostrar a leitura desenhada sobre a imagem do gabarito, com a questão escolhida em destaque | Importante | Atende | App: círculos sobre o recorte, conferidos em captura de tela (2026-10-18). Painel: imagem anotada |
| RF-03.8 | Disciplina automática pelo caderno de uma disciplina só (ex.: `M` = Matemática) | Importante | Parcial | Só o `M` está confirmado. `L` = Língua Portuguesa é sugestão. O significado do código depende do orientador (seção 9) |
| RF-03.9 | Prova de várias disciplinas: escolher a disciplina por **intervalo de questões**, com uma faixa mostrando o que já tem disciplina | Essencial | Atende | Fase 3 (projeto real), painel (`gabarito.js`) e app ("Marcar várias questões") |
| RF-03.10 | Aplicar a dificuldade a um intervalo de questões de uma vez | Importante | Atende | Painel e app |
| RF-03.11 | Disciplinas de um catálogo fixo, não de texto livre | Importante | Atende | `disciplinas()`: 8 disciplinas. Nomes antigos já gravados continuam aceitos |
| RF-03.12 | Aprovar o gabarito só com resposta e disciplina em todas as questões; erros agrupados ("Falta a disciplina das questões 1 a 26") | Essencial | Atende | Fase 3 e Fase 7 (422 do servidor mostrado no app) |
| RF-03.13 | Salvar a revisão incompleta e continuar depois | Importante | Atende | Rascunho pode ser salvo incompleto |
| RF-03.14 | Exigir a dificuldade de todas as questões na aprovação | Desejável | Não atende | Decisão em aberto: hoje é opcional, e o relatório avisa quando falta |

### RF-04 Leitura óptica da folha

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-04.1 | Ler a folha AMA pelos **4 quadrados pretos** dos cantos da área de respostas, corrigindo perspectiva e inclinação | Essencial | Atende (simulado) | Fase 1: 20/20 da 8A, 30/30 do conjunto com giro até 15° |
| RF-04.2 | Descobrir sozinho o formato: blocos de questões (ex.: 7, 7, 7, 5), número de questões e alternativas | Essencial | Atende (simulado) | Fase 1: formato certo em 20/20 e 30/30. Folha em branco: 104/104 bolinhas |
| RF-04.3 | Ler o **QR code** para identificar o caderno e o lado de cima da folha | Essencial | Atende (simulado) | Caderno lido em 20/20 da 8A (Fase 5) e 21/21 no recorte do app (Fase 7) |
| RF-04.4 | Recusar folha de **outro caderno** ("Esta folha é do caderno X, e a prova é do caderno Y") | Essencial | Atende | Fase 1 e testes `pytest` (caderno errado) |
| RF-04.5 | Recusar folha de **outro formato** (blocos ou alternativas diferentes) | Essencial | Atende | `conferir_layout()`; folha do modelo antigo recusada numa prova AMA |
| RF-04.6 | Ler a folha **de cabeça para baixo** ou deitada | Importante | Atende (simulado) | Pelo QR; sem QR, pela regra "bloco menor à direita". `pytest` (virada, deitada, sem QR e virada) |
| RF-04.7 | Ler com **um marcador coberto** (estima o 4º canto) | Importante | Atende (simulado) | 78/80 lidas certo; as outras 2 recusadas com pedido de nova foto |
| RF-04.8 | Classificar cada questão em marcada, em branco ou **marcação dupla**; branco e dupla contam como erro | Essencial | Atende | Teste `corrigir` em `regras.php` |
| RF-04.9 | Mandar para revisão as **marcações fracas** (preenchimento entre 0,25 e 0,65) | Essencial | Atende (simulado) | 12/12 marcações fracas da 8A e 23/23 do conjunto de 30 fotos |
| RF-04.10 | Medir a **qualidade da foto** e recusar foto escura, pequena demais ou desfocada com mensagem que diz o que fazer | Essencial | Atende (simulado) | Fase 5: abaixo de ~30% de brilho, "escura demais"; 500–950 px, "pouca resolução"; desfoque forte, "foto tremida ou fora de foco" |
| RF-04.11 | Informar a **confiança** da leitura (alta ou baixa, com os motivos) | Importante | Parcial | O leitor devolve `confianca`, mas ela **não aparece** para o professor no painel nem no app; só as questões duvidosas aparecem |
| RF-04.12 | Devolver a posição de cada bolinha, para o app desenhar a leitura sobre a imagem | Importante | Atende | Fase 5 e Fase 7 (círculos sobre o recorte) |
| RF-04.13 | Continuar lendo o **modelo antigo** do protótipo (moldura, várias disciplinas) | Desejável | Atende | 7A 235/235 e 7B 194/194 respostas certas. Só pelo painel web: o app lê só a folha AMA |
| RF-04.14 | Ler PDF com várias folhas (uma por página) | Desejável | Atende | Painel: PDF da 7A com 12 páginas |
| RF-04.15 | Ler as folhas AMA de **outros cadernos** (Língua Portuguesa, outros anos, todas as disciplinas) | Essencial | Depende de terceiros | Nenhuma folha desses cadernos foi vista. O leitor não supõe 26 questões nem 4 blocos, mas supõe 4 marcadores, QR acima da área e numeração descendo cada bloco. A faixa do QR do app foi medida na M0801 |

### RF-05 Envio das folhas dos alunos

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-05.1 | Escolher a turma e escanear as folhas uma a uma com a câmera aberta | Essencial | Atende (simulado) | Fase 7, pelo navegador (galeria no lugar da câmera). A câmera ao vivo falta conferir no aparelho (roteiro 15–17) |
| RF-05.2 | Mostrar **na hora** a nota da folha e as questões a conferir | Essencial | Atende (simulado) | App web: 19/26 com a questão 25 a conferir; 3 a 5 s por folha |
| RF-05.3 | **Confirmar ou corrigir** as duvidosas e passar para a próxima; confirmar grava a folha como conferida | Essencial | Atende (simulado) | "Salvar e próxima" / "Confirmar e próxima"; desde 2026-10-18, confirmar sem mudança também marca a folha como revisada |
| RF-05.4 | Contador de folhas lidas da turma | Importante | Atende | Vem do servidor (`folhas_da_turma`) |
| RF-05.5 | Avisar **antes da foto** quando o QR visto pela câmera é de outro caderno | Importante | Atende (simulado) | Cantoneiras ficam vermelhas e aparece a mensagem; o servidor também recusa |
| RF-05.6 | Folha recusada pelo app não é gravada; o professor tira outra foto | Essencial | Atende | API com `guardarFalhas = false`; contador não muda |
| RF-05.7 | Entrar a foto pela **galeria** (folha já fotografada) | Desejável | Atende | App, gabarito e folhas |
| RF-05.8 | **Scanner de documento** do sistema (ML Kit / VisionKit) | Desejável | Parcial | Código pronto (botão "Scanner" quando o módulo existe), mas só roda num development build, que ainda não foi gerado (item 7.12) |
| RF-05.9 | **Recortar a folha no celular** e enviar só a área de respostas e a faixa do QR | Essencial | Atende (simulado) | `mobile/src/lib/recorte.ts`. App web: enviados só `area.jpg` (~110 KB) e `qr.jpg` (~13 KB). Detalhes em RNF-02 |
| RF-05.10 | Avisar **antes do envio** quando o celular não acha os 4 quadrados ou a folha está longe demais | Importante | Atende (simulado) | Etapas "Procurando os 4 quadrados pretos..." e "Lendo a folha..."; nada é enviado |
| RF-05.11 | Painel web: enviar várias fotos ou PDFs de uma turma de uma vez | Importante | Atende | Até 100 arquivos por envio, 20 MB cada; as não lidas ficam na lista "Folhas que não puderam ser lidas" com o motivo |
| RF-05.12 | Impedir que a **mesma folha** seja contada duas vezes (foto repetida) | Importante | Não atende | Uma folha fotografada duas vezes entra como dois alunos e distorce o acerto da turma |
| RF-05.13 | Informar quantos alunos a turma tem e mostrar quantas folhas faltam | Desejável | Não atende | O contador mostra só as lidas |
| RF-05.14 | Apagar uma folha enviada por engano (outra turma, foto repetida) | Importante | Não atende | Não há rota nem botão; só pelo banco |
| RF-05.15 | Nome da turma padronizado (sem diferença entre maiúsculas e minúsculas) | Desejável | Parcial | O app converte para maiúsculas; o painel aceita "8a" e "8A" como turmas diferentes |
| RF-05.16 | Fila de envio quando não há rede (escanear e mandar depois) | Importante | Não atende | Sem conexão, a folha é recusada com "Sem conexão com o servidor" e precisa ser fotografada de novo. Relevante se o Wi-Fi da escola for ruim |

### RF-06 Revisão das folhas

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-06.1 | Abrir uma folha lida e ver a imagem anotada (área das bolinhas com a leitura, QR em cima) | Essencial | Atende | Painel (`folha.php`) e app (`folha/[id].tsx`); URL assinada de 5 min |
| RF-06.2 | Corrigir a resposta de qualquer questão (letra, em branco ou dupla) e gravar a folha como revisada | Essencial | Atende | Fase 2 e Fase 6 (`PUT /api/folhas/{id}`, revisão parcial ou completa) |
| RF-06.3 | Ver o nível de preenchimento das alternativas nas questões duvidosas | Desejável | Atende | Painel mostra as porcentagens; API devolve `niveis` |
| RF-06.4 | Destacar na lista as folhas com questões a conferir | Importante | Atende | Selo "Revisar" no painel; "a conferir" no app |

### RF-07 Relatórios

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-07.1 | Acerto geral por turma | Essencial | Atende | 63,1% da 8A, igual ao leitor direto (Fases 2 a 7) |
| RF-07.2 | Acerto por disciplina e por nível de dificuldade | Essencial | Atende | Fase 4 (projeto real): Fácil 64,4%, Médio 60,6%, Difícil 64,4% |
| RF-07.3 | **Prioridades de revisão**: questões abaixo do limite de reforço, primeiro as fáceis e médias, depois as sem nível, por último as difíceis; em cada grupo, do menor acerto para o maior | Essencial | Atende | Fase 4 e testes em `regras.php`. Mesmas prioridades (17, 11, 9, 12, 13, 26, 21, 25) no painel, na API e no app |
| RF-07.4 | Mostrar o **erro comum** (alternativa errada mais marcada) e o motivo da prioridade | Essencial | Atende | Fase 4 |
| RF-07.5 | Agrupar por **habilidade** (descritor) as que têm acerto baixo | Importante | Atende | Fase 4: D20 50,0%, D12 54,0% |
| RF-07.6 | **Mapa de questões** com selo de dificuldade, filtrável por disciplina e dificuldade | Importante | Atende | Painel. No app não há mapa (só as prioridades) |
| RF-07.7 | **Dificuldade marcada × observada** (acima de 70% fácil, abaixo de 30% difícil), com concordância e divergentes | Importante | Atende | Painel e API. No app não aparece |
| RF-07.8 | **Precisão da leitura**: lidas com certeza, duvidosas, faixa do acerto conforme as duvidosas não conferidas, correções do professor e questões com mais dúvidas | Importante | Atende | 2026-10-18: painel, app, API e CSV; 3 testes em `regras.php`. Prova 21: 95,8% com certeza, acerto 62,9% (62,5% a 66,5%) |
| RF-07.9 | Relatório de uma turma ou de todas somadas | Importante | Atende | Painel e API (`?turma=`); app por turma |
| RF-07.10 | **Imprimir** o relatório para a reunião pedagógica | Importante | Atende | Fase 4: PDF de 4 páginas, sem formulários |
| RF-07.11 | Exportar **CSV** por questão e por turma, que abre no Excel em português | Importante | Atende | Fase 4 e 2026-10-18 (colunas de precisão) |
| RF-07.12 | Lista de alunos para reforço (abaixo do limite) | Importante | Parcial | Mostra **quantos** alunos estão abaixo do limite, não quais. Coerente com a decisão de não identificar o aluno |
| RF-07.13 | Comparar edições do simulado ou turmas ao longo do tempo | Desejável | Não atende | Cada prova tem o seu relatório |

### RF-08 Fotos guardadas no celular

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-08.1 | Guardar a foto original de cada folha lida e do gabarito numa pasta privada do app, fora da galeria | Importante | Atende (simulado) | `fotosLocais.ts`. Não roda no navegador; conferir no aparelho (roteiro 21a) |
| RF-08.2 | Mostrar na tela da prova quantas fotos estão guardadas e apagar todas com confirmação | Importante | Atende (simulado) | Conferir no aparelho (roteiro 21b) |
| RF-08.3 | Ver a foto original guardada de uma folha | Desejável | Não atende | A foto fica guardada, mas não há tela para abri-la |

### RF-09 Configuração

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-09.1 | Limites configuráveis sem mudar o código: reforço (60%), fácil (70%) e difícil (30%) | Importante | Atende | Variáveis de ambiente `LIMITE_REFORCO`, `LIMITE_FACIL`, `LIMITE_DIFICIL` |
| RF-09.2 | Novos modelos de caderno (disciplina automática) e novas disciplinas | Desejável | Parcial | Possível só editando `modelos_de_prova()` e `disciplinas()` no código; não há tela |
| RF-09.3 | Endereço do servidor e chaves do app configuráveis | Essencial | Atende | `mobile/.env` (URL da API, URL e chave publishable do Supabase) |

### RF-10 API para o aplicativo

| ID | Requisito | Prioridade | Situação | Evidência / observação |
|---|---|---|---|---|
| RF-10.1 | API REST em JSON com as operações do aplicativo (12 rotas) | Essencial | Atende | `web/src/api.php`; coleção do Postman (`docs/api.postman.json`) e `testar_api.ps1` |
| RF-10.2 | Erros em JSON (`{"erro": "..."}`) com o código HTTP certo (400, 401, 404, 405, 409, 413, 415, 422, 500) | Importante | Atende | Fase 6, casos de erro conferidos um a um |
| RF-10.3 | Painel e API usam as **mesmas funções** (uma regra só) | Essencial | Atende | `web/src/servicos.php`; mesmos números no painel, na API e no app |

---

## 4. Requisitos não funcionais

### RNF-01 Precisão e confiabilidade da leitura

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-01.1 | Bolinhas lidas corretamente em fotos **reais** de celular | ≥ 99% (critério da Fase 5) | Depende de terceiros | Só há fotos simuladas: 8A 100% (546/546), difíceis de 99,2% a 100%. Sem nenhuma foto real ainda |
| RNF-01.2 | Fotos reais de folhas AMA lidas (não recusadas) | ≥ 95% (critério da Fase 1) | Depende de terceiros | Mesmo motivo. Simulado: 20/20 e 30/30 |
| RNF-01.3 | **Nenhuma resposta errada sem aviso**: o que não dá para ler com segurança vai para revisão ou é recusado | 0 erros sem aviso | Atende (simulado) | Fase 5, em todos os conjuntos difíceis, inclusive "além do limite" |
| RNF-01.4 | Calibrar os limiares (`LIMIAR_MARCADA` 0,50, `ZONA_DUVIDA` 0,25–0,65) e os de qualidade com fotos reais | Feito com fotos reais | Depende de terceiros | Calibrados só em simulação |
| RNF-01.5 | Medir e mostrar a **margem de erro** da análise | Faixa do acerto e taxa de correções no relatório | Atende | RF-07.8. A taxa de erro "escondida" (correção em leitura sem dúvida) só ganha valor real com folhas conferidas inteiras |
| RNF-01.6 | A regra de correção é única e testada: branco e dupla contam como erro | Testes automatizados | Atende | `regras.php` (51 testes) |
| RNF-01.7 | O recorte feito no celular não muda o resultado da leitura | Mesmas respostas da foto inteira | Atende (simulado) | 546/546 respostas iguais e 12/12 fracas; marcadores em TypeScript e Python iguais em 21/21 fotos (diferença < 0,5 px) |

### RNF-02 Privacidade e proteção de dados (LGPD)

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-02.1 | O sistema não guarda o nome do aluno em lugar nenhum | Nenhum dado pessoal do aluno no banco ou no Storage | Atende | Banco sem campo de aluno; imagem guardada cortada nas bolinhas (conferência visual, 2026-10-18) |
| RNF-02.2 | A **foto da folha não sai do celular**: só a área de respostas e a faixa do QR são enviadas | Nenhuma foto inteira chega ao servidor pelo app | Atende (simulado) | App web: só os dois recortes no envio; servidor recusa foto inteira (`nao_recorte`). Conferir no aparelho |
| RNF-02.3 | O servidor não guarda o que recebe; tudo fica numa pasta temporária apagada no fim da requisição | Nenhum arquivo no disco depois da requisição | Atende | Fase 2: nenhum arquivo deixado no disco |
| RNF-02.4 | O nome original do arquivo enviado nunca é gravado | Nome genérico `turma_folhaNN` | Atende | Teste `nome_generico` em `regras.php`; conferido no banco (2026-10-18) |
| RNF-02.5 | O **número impresso abaixo da grade** (ex.: `6357256532`) não sai do celular, se identificar o aluno | Tapado antes do envio, se for identificador | Parcial | Vai no recorte da área enviado, mas não fica na imagem guardada. O significado do número é pergunta em aberto (seção 9) |
| RNF-02.6 | O **painel web** também não recebe a folha inteira | Recorte no navegador antes do envio | Não atende | Decisão de 2026-10-18: o painel continua recebendo a folha inteira, só para uso no computador da escola |
| RNF-02.7 | **Prazo de guarda** e eliminação dos dados (provas, folhas, imagens) | Prazo definido e rotina de eliminação | Não atende | Não há prazo nem rotina; a política de privacidade tem o campo em aberto. Ver também RF-02.8 e RF-05.14 |
| RNF-02.8 | Política de privacidade completa, exigida pelas lojas | Publicada, com escola, base legal e prazo | Parcial | Rascunho em `docs/politica_de_privacidade.md`. Faltam os dados da escola, a base legal e o prazo. Duas frases precisam de ajuste: diz que a conexão é cifrada (HTTPS ainda não existe, RNF-03.8) e deixa em aberto se as fotos ficam na galeria (não ficam: pasta privada do app) |
| RNF-02.9 | Fotos guardadas no celular não saem do aparelho, nem pelo backup automático do sistema | Fora da galeria **e** fora do backup na nuvem | Não atende | Ficam fora da galeria (pasta privada `Paths.document`), mas essa pasta entra por padrão no backup do iCloud (iPhone) e no backup automático do Google Drive (Android): o `app.json` não desliga o backup (`android.allowBackup`) nem exclui a pasta no iOS. Ou seja, as fotos inteiras podem ir para a nuvem da conta do professor |

### RNF-03 Segurança

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-03.1 | Toda operação exige login (app, API e painel) | 401 sem token; painel redireciona ao login | Atende | Fase 6: sem token e token falso dão 401 |
| RNF-03.2 | O token do app é validado no Supabase, com cache curto que nunca grava o token | Cache ≤ 5 min, só o hash no disco | Atende | `web/src/auth.php` |
| RNF-03.3 | A chave secret do Supabase e a senha do banco ficam só no servidor | Fora do app e do git | Atende | `.env` fora do git; o app usa só a chave publishable |
| RNF-03.4 | O banco não pode ser lido pela API pública do Supabase | RLS ligado e sem políticas | Atende | Fase 2 (projeto real): 3 tabelas com RLS e 0 políticas |
| RNF-03.5 | Imagens em bucket privado, mostradas por URL assinada de curta duração | URL de 5 min | Atende | Fase 2: sem assinatura, HTTP 400; com URL assinada, 200 |
| RNF-03.6 | Proteção contra CSRF nos formulários do painel; cookie de sessão `HttpOnly`, `SameSite=Lax` e `Secure` com HTTPS | 403 sem o token CSRF | Atende | Fase 6 |
| RNF-03.7 | Uploads com tamanho máximo e tipo conferido pelo conteúdo, não pela extensão | 20 MB; JPEG, PNG, TIFF ou PDF (recorte do app: JPEG ou PNG) | Atende | Fase 6: texto renomeado para `.jpg` dá 415 |
| RNF-03.8 | **HTTPS** entre o app e o servidor | Obrigatório fora da rede local (o iOS exige) | Não atende | Depende da hospedagem, ainda não escolhida. Hoje o app fala com o computador por HTTP na rede local |
| RNF-03.9 | Autorização por perfil e por autoria (ver RF-01.6) | Professor não altera prova de outro, se a escola quiser essa regra | Não atende | Qualquer usuário logado altera qualquer prova |
| RNF-03.10 | Cadastro público desligado no Supabase Auth | `disable_signup: true` | Parcial | Estava ligado em 2026-10-12; desligar no painel do Supabase |
| RNF-03.11 | Senha do banco trocada depois de ter aparecido numa conversa com o assistente | Senha nova | Parcial | Registrado como pendência da Fase 2; não há registro de que foi trocada |
| RNF-03.12 | Tempo máximo para a leitura de uma folha no servidor | Leitor interrompido se travar | Não atende | O PHP chama o Python sem limite de tempo próprio (`proc_open`); um arquivo problemático pode prender a requisição |
| RNF-03.13 | Mensagens de erro sem detalhe técnico para o usuário; detalhe só no log | 500 com mensagem genérica | Atende | Fase 6 |

### RNF-04 Desempenho

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-04.1 | Tempo por folha no escaneamento, da foto até a nota na tela | Meta a definir; sugestão: ≤ 5 s com boa rede | Atende (simulado) | App web no computador: 3 a 5 s por folha (recorte + envio + leitura + Storage). Falta medir num celular comum e na rede da escola |
| RNF-04.2 | Tempo da leitura no servidor | ≤ 2 s por folha | Atende (simulado) | ~0,2 s com QR legível; ~2,4 s sem QR (Fase 5). Com o Storage em São Paulo, ~1 s por folha |
| RNF-04.3 | Tempo do recorte no celular | ≤ 2 s | Atende (simulado) | No Node, achar os marcadores leva ~0,04 a 0,15 s; o recorte inteiro no navegador ~2 a 4 s. Falta medir no celular |
| RNF-04.4 | Corrigir uma turma inteira mais rápido que a correção manual | Comparação no piloto | Depende de terceiros | Critério da Fase 8 |
| RNF-04.5 | O professor vê em menos de **1 minuto** quais questões revisar e por quê | < 1 min | Depende de terceiros | Critério da Fase 4. O relatório abre direto nas prioridades, mas o tempo não foi medido com professores |
| RNF-04.6 | Fotos reduzidas antes do envio | Lado menor ~1600 px | Atende | Fase 7. Com o recorte, o envio caiu para ~125 KB por folha |

### RNF-05 Usabilidade

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-05.1 | Um professor **sem treinamento** cadastra uma prova e corrige uma turma inteira só pelo celular | Critério da Fase 7 | Depende de terceiros | Roteiro de teste pronto (`docs/roteiro_teste_app.md`); falta o teste com professores |
| RNF-05.2 | Mensagens em português que dizem o que fazer ("Aproxime o celular...", "Fotografe num lugar mais iluminado...") | Toda recusa com orientação | Atende | Fases 5 e 7 |
| RNF-05.3 | Tirar a foto sem precisar encaixar a folha num contorno | Folha um pouco torta, de qualquer lado, ocupando de ~30% da largura da foto para cima | Atende (simulado) | 2026-10-18: cantoneiras discretas; folha a 42% da largura lida; a 30%, aviso para aproximar |
| RNF-05.4 | Painel utilizável no celular | Sem rolagem horizontal a 375 px | Atende | Fases 3, 4 e 6 |
| RNF-05.5 | Satisfação dos professores | Questionário SUS no piloto | Depende de terceiros | Fase 8 |
| RNF-05.6 | Acessibilidade: rótulos para leitor de tela, contraste e tamanho de toque | Rótulos nos botões e imagens | Parcial | O app tem rótulos de acessibilidade nos principais botões e imagens; não foi feito teste com leitor de tela (TalkBack/VoiceOver) |
| RNF-05.7 | Ícone e tela de abertura próprios do app | Identidade da escola/sistema | Não atende | Ainda são os padrões do Expo |
| RNF-05.8 | Textos das permissões em português, explicando o uso (exigência da Apple) | Câmera e galeria | Atende | `app.json`. O texto da galeria tinha um acento gravado fora do UTF-8 e foi corrigido em 2026-10-20 |

### RNF-06 Compatibilidade e portabilidade

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-06.1 | Aplicativo para **Android e iPhone** com um código só | Android e iOS | Parcial | Android com Expo Go testado parcialmente (2026-10-12, antes do recorte); iPhone não testado. `scanFromURLAsync` no iPhone não foi testado |
| RNF-06.2 | Funcionar no **Expo Go** (sem build próprio) para os testes | Recorte sem módulo nativo novo | Atende (simulado) | `jpeg-js` e `marcadores.ts` são JavaScript puro |
| RNF-06.3 | Instalação para teste pelas lojas (TestFlight e teste interno do Google Play) | Fase 8 | Depende de terceiros | Contas de desenvolvedor (US$ 99/ano Apple, US$ 25 Google) |
| RNF-06.4 | Servidor roda com Docker e no Windows sem Docker | Os dois | Parcial | Sem Docker (`iniciar.cmd`): usado em todos os testes. Docker: configurado, **nunca testado** (não instalado na máquina) |
| RNF-06.5 | Painel nos navegadores atuais | Chrome, Edge, Firefox | Parcial | Testado no Edge e no navegador do Claude (Chromium). Firefox e Safari não testados |

### RNF-07 Disponibilidade e operação

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-07.1 | Servidor acessível pelos celulares da escola | Hospedagem com HTTPS, ou computador da escola na rede local | Depende de terceiros | Hoje: o computador do Thiago na rede local (porta 8001). Hospedagem fora do escopo do plano |
| RNF-07.2 | Supabase ativo nos dias de uso | Projeto não pausado | Parcial | O plano Free pausa depois de 7 dias sem uso; conferir antes do piloto e da apresentação |
| RNF-07.3 | Cópia de segurança dos dados | Rotina de backup definida | Não atende | Não há rotina definida pelo projeto |
| RNF-07.4 | Funcionamento com a rede instável da escola | Envio que sobrevive a quedas | Não atende | Ver RF-05.16 |
| RNF-07.5 | Dados de teste separados dos dados do piloto | Banco limpo antes do piloto | Parcial | O projeto real tem as provas de teste das Fases 2 a 7 (nomes começando com "TESTE" e rascunhos criados nos testes), com as imagens no Storage; não há projeto `-dev` separado |

### RNF-08 Capacidade e custo

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-08.1 | Caber no plano gratuito do Supabase durante o TCC | 500 MB de banco e 1 GB de Storage | Atende | Uma imagem por folha (~75 a 90 KB). Estimativa: mais de 10 mil folhas no Storage gratuito |
| RNF-08.2 | Uma turma de 40 alunos sem travar o envio | Envio por folha, sem lote longo | Atende | O app manda uma folha por vez; o lote longo (~40 s) só acontece no painel |
| RNF-08.3 | Custo de publicação conhecido | Contas das lojas | Depende de terceiros | Apple US$ 99/ano; Google US$ 25 uma vez |

### RNF-09 Manutenibilidade

| ID | Requisito | Meta | Situação | Evidência / observação |
|---|---|---|---|---|
| RNF-09.1 | Arquitetura em camadas: app → API PHP → leitor Python; Python não conhece o banco | Separação mantida | Atende | Conferida no código |
| RNF-09.2 | Testes automatizados do leitor e das regras | Rodam sem banco | Atende | `pytest worker/testes` (33) e `php web/testes/regras.php` (51) |
| RNF-09.3 | Teste de ponta a ponta da API repetível | Script e coleção | Atende | `ferramentas/testar_api.ps1` (todos os passos ok em 2026-10-18) e Postman |
| RNF-09.4 | Testes automatizados do aplicativo | Ao menos as funções do recorte | Não atende | O app tem só `tsc`, `expo lint` e `expo-doctor`. A comparação dos marcadores TypeScript × Python foi feita com um script fora do repositório |
| RNF-09.5 | O recorte do app e o espelho em Python ficam iguais | Teste que falha se divergirem | Parcial | As constantes (faixa do QR, margens, área mínima) estão duplicadas em `marcadores.ts` e `omr.py`, com comentário. Sem teste automático, uma mudança só num lado passa despercebida |
| RNF-09.6 | Banco versionado por migrações | Toda mudança vira migração | Atende | `supabase/migrations/`. Pendente: migração para remover a coluna `folhas.imagem` e o bucket `folhas`, sem uso desde 2026-10-18 |
| RNF-09.7 | Documentação de instalação, uso, API e decisões | README, PROGRESSO, roteiro de teste | Atende | README principal, README do app, PROGRESSO.md, roteiro de teste e diagramas em Mermaid |
| RNF-09.8 | Diagramas do TCC no Visual Paradigm (casos de uso, atividades, DER, sequência, componentes) | Pedido do orientador | Parcial | Rascunhos de sequência e componentes em Mermaid (`docs/modelagem/fase6_diagramas.md`); os do Visual Paradigm estão com o Thiago |
| RNF-09.9 | Dependências do app nas versões indicadas pelo SDK | `expo-doctor` sem avisos | Parcial | 4 pacotes do Expo com versão de correção mais nova (2026-10-18); resolver com `npx expo install --fix` |

---

## 5. Rastreabilidade dos pedidos

Cada pedido do plano e do desenvolvimento, com os requisitos que o atendem.

| Pedido | Requisitos | Situação geral |
|---|---|---|
| 1. Reconhecer sozinho o formato da folha (questões, colunas, numeração, alternativas) | RF-04.2, RF-04.3, RF-03.4 | Atende (simulado) |
| 2. Áreas sem texto por extenso; simulado com todas as disciplinas ou de uma disciplina | RF-03.8, RF-03.9, RF-03.11 | Atende; disciplina automática só para o `M` |
| 3. Nível de dificuldade por questão | RF-03.5, RF-03.10, RF-07.2, RF-07.7 | Atende |
| 4. Revisão do gabarito antes do uso, com a dificuldade marcada nela | RF-02.3, RF-03.5 a RF-03.13 | Atende |
| 5. Aplicativo iOS/Android para fotografar o gabarito, aprovar e escanear | RF-05, RNF-06.1 | Atende no navegador; falta o aparelho |
| 6. Banco no Supabase | RNF-03.4, RNF-03.5, RNF-08.1 | Atende |
| Decisão: o nome do aluno não é relevante; a turma é informada no envio | RNF-02.1, RF-05.1 | Atende |
| Decisão: gabarito por foto **ou** digitado, terminando na mesma revisão | RF-03.1, RF-03.2, RF-03.3 | Atende |
| 2026-10-18: fotos das provas não vão para a internet; no máximo o recorte das bolinhas, sem o nome, e o QR | RNF-02.2 a RNF-02.6, RF-05.9 | Atende no app (simulado); painel fica para uso local |
| 2026-10-18: câmera sem marcação estática | RNF-05.3, RF-05.10 | Atende (simulado); marcação ao vivo pendente (7.20) |
| 2026-10-18: taxa de imprecisão da detecção e margem de erro da análise | RF-07.8, RNF-01.5 | Atende |
| Problema do TCC: saber a tempo quais questões revisar com a turma | RF-07.3, RF-07.4, RNF-04.5 | Atende; o tempo com professores falta medir |

---

## 6. Resumo da situação

| | Atende | Atende (simulado) | Parcial | Não atende | Depende de terceiros | Total |
|---|---|---|---|---|---|---|
| Funcionais | 51 | 17 | 7 | 9 | 1 | 85 |
| Não funcionais | 24 | 8 | 13 | 10 | 10 | 65 |
| **Total** | **75** | **25** | **20** | **19** | **11** | **150** |

Leitura do resumo:

- **O fluxo principal está completo.** Cadastrar a prova, revisar e aprovar o
  gabarito, escanear a turma, conferir as duvidosas e ver as prioridades de
  revisão funcionam no painel, na API e no app. **Nenhum requisito funcional
  essencial está sem implementação.** Dos 12 essenciais fora de "Atende", 10
  estão testados só em simulação (fotos geradas ou navegador), 1 é uma
  configuração no painel do Supabase (RF-01.5) e 1 depende de folhas de outros
  cadernos (RF-04.15).
- **O que mais pesa é a falta de prova real**, não de código. Os requisitos de
  leitura e do app estão testados com fotos geradas e no navegador. Falta
  ver o sistema com fotos reais de folhas AMA e num celular de verdade.
- **As lacunas de código se concentram em três temas:** gestão dos dados
  enviados (apagar folha, apagar prova, folha repetida, prazo de guarda),
  permissões (perfis e autoria) e operação fora do computador local (HTTPS,
  hospedagem, rede instável, backup).

---

## 7. Lacunas e melhorias, por prioridade

### Antes do piloto (bloqueiam ou distorcem o uso real)

| # | Lacuna | Requisitos | Ação proposta |
|---|---|---|---|
| 1 | Nenhuma foto real de folha AMA | RNF-01.1, RNF-01.2, RNF-01.4, RF-04.15 | Juntar pelo menos 10 folhas reais preenchidas (de preferência de 2 ou 3 cadernos), fotografar com 2 ou 3 celulares, rodar `validar_leitura.py` e ajustar os limiares |
| 2 | App não testado de ponta a ponta num celular depois do recorte | RF-05, RF-08, RNF-04.1, RNF-04.3, RNF-06.1 | Rodar o roteiro de teste num Android e num iPhone, com o login real, e medir o tempo por folha |
| 3 | Folha repetida conta duas vezes | RF-05.12 | Avisar quando uma folha nova tem as mesmas respostas (e os mesmos níveis) de outra da mesma turma, e deixar o professor descartar |
| 4 | Não dá para apagar uma folha enviada por engano | RF-05.14 | Botão e rota para apagar uma folha (com a imagem no Storage) |
| 5a | Fotos guardadas no celular podem ir para o backup na nuvem (iCloud / Google Drive) | RNF-02.9 | Desligar o backup do app no Android (`android.allowBackup: false` no `app.json`) e, no iPhone, guardar as fotos numa pasta excluída do backup; conferir num aparelho |
| 5 | Cadastro público do Supabase ligado | RF-01.5, RNF-03.10 | Desligar "Allow new users to sign up" no painel do Supabase |
| 6 | Senha do banco possivelmente exposta | RNF-03.11 | Trocar a senha do banco e atualizar o `.env` |
| 7 | Onde o servidor vai rodar durante o piloto | RNF-03.8, RNF-07.1 | Decidir: computador da escola na rede local (sem HTTPS, só Android ou iPhone em modo de desenvolvimento) ou hospedagem com HTTPS |
| 8 | Dados de teste no projeto real | RNF-07.5 | Apagar as provas de teste e as imagens delas no Storage, ou criar um projeto separado para o piloto |

### Importantes (melhoram o uso e a confiança)

| # | Lacuna | Requisitos | Ação proposta |
|---|---|---|---|
| 9 | Confiança baixa da leitura não aparece para o professor | RF-04.11 | Mostrar os motivos (sem QR, marcador estimado, foto um pouco desfocada) na nota da folha e na lista |
| 10 | Sem fila de envio quando a rede cai | RF-05.16, RNF-07.4 | Guardar o recorte no celular e reenviar quando a conexão voltar |
| 11 | Perfis e autoria | RF-01.6, RNF-03.9 | Definir com a escola: todos veem tudo, ou cada professor só as suas provas e a coordenação todas |
| 12 | Prazo de guarda e limpeza dos dados; política de privacidade incompleta | RNF-02.7, RNF-02.8, RF-02.8 | Definir o prazo com a escola; apagar prova aprovada pelo sistema; completar a política e corrigir as duas frases desatualizadas |
| 13 | Número abaixo da grade vai no recorte | RNF-02.5 | Confirmar o significado; se identificar o aluno, tapar a faixa dele no celular antes do envio |
| 14 | Leitor sem tempo máximo | RNF-03.12 | Limitar o tempo do processo do Python e devolver erro claro |
| 15 | Sem backup | RNF-07.3 | Exportação periódica do banco (por exemplo `pg_dump` semanal) |
| 16 | Turmas "8a" e "8A" separadas no painel | RF-05.15 | Padronizar a turma em maiúsculas também no painel |

### Desejáveis (depois do piloto)

| # | Lacuna | Requisitos | Ação proposta |
|---|---|---|---|
| 17 | Marcação ao vivo dos 4 quadrados e foto automática | RF-05.8, item 7.20 | Development build com processamento dos quadros da câmera |
| 18 | Recorte também no painel web | RNF-02.6 | Recortar no navegador antes do envio, como o app |
| 19 | Testes automatizados do app e da paridade TypeScript × Python | RNF-09.4, RNF-09.5 | Levar para o repositório o teste que compara os marcadores nas 21 fotos e rodar junto com o `pytest` |
| 20 | Mapa de questões e dificuldade marcada × observada no app | RF-07.6, RF-07.7 | Levar as duas seções ao resultado da turma no app |
| 21 | Exigir dificuldade na aprovação | RF-03.14 | Decidir com o orientador; é uma linha em `validar_revisao()` |
| 22 | Número de alunos da turma e folhas faltando | RF-05.13 | Campo opcional na turma |
| 23 | Ver a foto original guardada no celular | RF-08.3 | Abrir a foto local na tela da folha |
| 24 | Coluna e bucket sem uso | RNF-09.6 | Migração removendo `folhas.imagem` e o bucket `folhas` |
| 25 | Ícone e tela de abertura | RNF-05.7, Fase 8 | Preparar antes do envio às lojas. Os textos das permissões de câmera e de galeria já existem no `app.json` |
| 26 | Docker nunca testado | RNF-06.4 | Testar `docker compose up` numa máquina com Docker |
| 27 | Pacotes do Expo desatualizados | RNF-09.9 | `npx expo install --fix` e repetir `tsc`, lint e `expo-doctor` |

---

## 8. Como verificar no piloto

Medições para o capítulo de resultados do TCC, ligadas aos requisitos que
dependem de uso real.

| Medida | Requisitos | Como medir |
|---|---|---|
| Precisão da leitura com fotos reais | RNF-01.1, RNF-01.2 | Folhas reais com as respostas conhecidas (`respostas_esperadas.json`), lidas pelo `validar_leitura.py`: % de bolinhas certas, % de folhas lidas, % de fracas enviadas para revisão |
| Precisão observada no uso | RNF-01.5 | Seção "Precisão da leitura" do relatório: duvidosas, correções nas duvidosas e nas leituras sem dúvida. Para estimar o erro escondido, conferir **inteiras** algumas folhas de uma turma ("Ver todas" no app ou a folha no painel) |
| Tempo por folha e por turma | RNF-04.1, RNF-04.4 | Cronômetro do roteiro de teste (passo 17), comparado com a correção manual da mesma turma |
| Tempo até saber o que revisar | RNF-04.5 | Do abrir o relatório da turma até o professor dizer as 3 primeiras questões a revisar |
| Uso sem treinamento | RNF-05.1 | Professor que não participou do desenvolvimento segue só o app; anotar onde travou |
| Satisfação | RNF-05.5 | Questionário SUS (10 perguntas) ao fim do piloto |

---

## 9. Perguntas em aberto e dependências

| Pergunta ou dependência | Com quem | Requisitos afetados |
|---|---|---|
| O que significam as partes do código do QR (`2265`, a letra da disciplina, `08`, `01`)? Qual letra é cada disciplina? | Professor orientador | RF-03.8, RF-04.15 |
| O número abaixo da grade (`6357256532`) muda de folha para folha? Identifica o aluno? | Professor orientador | RNF-02.5 |
| Folhas AMA reais: em branco de outros cadernos e preenchidas, fotografadas e escaneadas | Escola | RNF-01.1 a RNF-01.4, RF-04.15 |
| As faixas de 70% e 30% da dificuldade observada podem ser citadas no TCC? | Professor orientador | RF-07.7 |
| Base legal, prazo de guarda e responsável pelos dados | Escola | RNF-02.7, RNF-02.8 |
| Regra de acesso: todos os professores veem todas as provas? | Escola | RF-01.6, RNF-03.9 |
| Hospedagem do servidor (com HTTPS) ou computador da escola | Thiago e escola | RNF-03.8, RNF-07.1 |
| Contas de desenvolvedor Apple e Google | Thiago | RNF-06.3, RNF-08.3 |
