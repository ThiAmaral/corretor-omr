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
| 2. Supabase | ⏳ | |
| 3. Revisão do gabarito, áreas e dificuldade | ⏳ | |
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
