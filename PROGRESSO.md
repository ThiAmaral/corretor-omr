# Acompanhamento da execução do plano

Registro do que foi feito em cada fase do [PLANO_DE_FASES.md](PLANO_DE_FASES.md),
com data, resultado medido e pendências. As caixas do plano são marcadas conforme
os itens ficam prontos; aqui ficam os detalhes.

Legenda: ✅ feito · 🔄 em andamento · ⏳ pendente · ⚠️ bloqueado / depende de terceiros

---

## Fase 0: preparação

| Item | Situação | Observação |
|---|---|---|
| Repositório git + GitHub privado | ✅ | `origin` = github.com/ThiAmaral/corretor-omr, commit inicial `c8225dc` |
| Juntar folhas AMA reais (em branco de outros cadernos, preenchidas, fotos, scans) | ⚠️ | Depende da escola. Por enquanto só existe a folha em branco do M0801 |
| Entender o código do QR e o número abaixo da grade | ⚠️ | Aguardando o professor orientador (ver "Perguntas em aberto" no plano) |

A Fase 1 começou sem as fotos reais: o leitor está sendo validado com folhas AMA
**geradas** a partir do PDF em branco. O critério "95% das fotos reais" fica
pendente até a Fase 0 entregar as fotos.

---

## Fase 1: leitor da folha AMA com detecção automática do formato

Início: 2026-10-11.

**Linha de base (antes de qualquer mudança):**

| Conjunto | Leituras corretas | Fracas para revisão |
|---|---|---|
| `exemplos/provas_7A` (formato antigo) | 235/235 (100%) | 5/5 |
| `exemplos/provas_7B` (formato antigo) | 194/194 (100%) | 6/6 |
| Folha AMA em branco | leitor acha 4 de 104 bolinhas | — |

### Itens

| # | Item | Situação | Observação |
|---|---|---|---|
| 1.1 | `alinhar_por_marcadores()` (4 quadrados pretos) | ⏳ | |
| 1.2 | `ler_qr()` (código do caderno) | ⏳ | |
| 1.3 | Bolinhas com tamanho proporcional aos marcadores | ⏳ | |
| 1.4 | Blocos de questões (`detectar_layout()`, `organizar_grade()` por blocos) | ⏳ | |
| 1.5 | Novo formato de `Layout` (`blocos`, `caderno`), compatível com o antigo | ⏳ | |
| 1.6 | Conferência do caderno (folha × prova) | ⏳ | |
| 1.7 | Confiança da leitura | ⏳ | |
| 1.8 | `omr_cli.py detectar <imagem>` | ⏳ | |
| 1.9 | `gerar_folhas_teste.py` gera folhas AMA com cara de foto | ⏳ | |
| 1.10 | `validar_leitura.py` mede caderno e layout | ⏳ | |
| 1.11 | `web/index.php` sem a validação "divisível por colunas" | ⏳ | |
| 1.12 | Testes `pytest` de casos de borda em `worker/testes/` | ⏳ | |
| 1.13 | Validação com fotos reais (≥ 95%) | ⚠️ | Depende da Fase 0 |

### Diário

- **2026-10-11** — Linha de base medida. Folha AMA renderizada a partir do PDF
  (A4, 200 dpi) para estudar a geometria.
