"""
Leitura óptica de folhas de resposta (OMR).

Este módulo só LÊ a folha: devolve qual alternativa foi marcada em cada
questão. Comparar com o gabarito e calcular notas é responsabilidade do
backend (PHP), para que a regra de correção fique em um lugar só.

Etapas de ler_folha():
  1. Localiza a moldura retangular da folha e corrige perspectiva/inclinação.
  2. Detecta as bolinhas (círculos vazios e preenchidos).
  3. Organiza as bolinhas em grade: linhas x colunas x alternativas.
  4. Mede o quanto cada bolinha está escurecida e decide a marcação.
"""
from __future__ import annotations

import math
import os
from dataclasses import dataclass, field

import cv2
import numpy as np

LARGURA_NORMALIZADA = 1000  # px; toda folha é redimensionada para esta largura

# Limiares sobre o nível calibrado (0 = bolinha vazia típica, 1 = toda preenchida)
LIMIAR_MARCADA = 0.50
ZONA_DUVIDA = (0.25, 0.65)  # leituras nesta faixa vão para revisão manual


class ErroLeitura(Exception):
    """Folha que não pôde ser lida (ex.: grade de bolinhas não encontrada)."""


@dataclass
class Layout:
    questoes: int = 20
    alternativas: list[str] = field(default_factory=lambda: ["A", "B", "C", "D"])
    colunas: int = 2
    ordem: str = "colunas"  # "colunas": 1..10 desce a 1a coluna; "linhas": 1,2 na mesma linha

    @classmethod
    def de_dict(cls, d: dict) -> "Layout":
        alts = d.get("alternativas", ["A", "B", "C", "D"])
        if isinstance(alts, str):
            alts = [a.strip() for a in alts.split(",") if a.strip()]
        lay = cls(
            questoes=int(d.get("questoes", 20)),
            alternativas=list(alts),
            colunas=int(d.get("colunas", 2)),
            ordem=d.get("ordem", "colunas"),
        )
        if lay.questoes % lay.colunas != 0:
            raise ValueError("O número de questões precisa ser divisível pelo número de colunas.")
        return lay

    @property
    def linhas(self) -> int:
        return self.questoes // self.colunas


# ---------------------------------------------------------------------------
# Entrada: imagens ou PDF
# ---------------------------------------------------------------------------

def carregar_paginas(caminho: str) -> list[np.ndarray]:
    """Devolve uma lista de imagens BGR. PDFs geram uma imagem por página."""
    ext = os.path.splitext(caminho)[1].lower()
    if ext == ".pdf":
        try:
            from pdf2image import convert_from_path
        except ImportError as e:
            raise ErroLeitura("Para ler PDF instale pdf2image e poppler-utils.") from e
        paginas = convert_from_path(caminho, dpi=200)
        return [cv2.cvtColor(np.array(p.convert("RGB")), cv2.COLOR_RGB2BGR) for p in paginas]
    img = cv2.imread(caminho)
    if img is None:
        raise ErroLeitura(f"Não foi possível abrir a imagem: {caminho}")
    return [img]


# ---------------------------------------------------------------------------
# 1. Localizar a folha
# ---------------------------------------------------------------------------

def _ordenar_cantos(pts: np.ndarray) -> np.ndarray:
    pts = pts.reshape(4, 2).astype("float32")
    s = pts.sum(axis=1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]], dtype="float32")


def localizar_folha(cinza: np.ndarray) -> tuple[np.ndarray, bool, np.ndarray]:
    """Recorta e endireita a área dentro da moldura. Se não achar moldura,
    usa a imagem inteira (moldura_encontrada=False). Devolve também a matriz
    que leva coordenadas da imagem original para a folha normalizada."""
    h, w = cinza.shape
    binaria = cv2.adaptiveThreshold(cv2.GaussianBlur(cinza, (5, 5), 0), 255,
                                    cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, 51, 15)
    # Fecha pequenas falhas na linha da moldura (scanner, ruído)
    binaria = cv2.morphologyEx(binaria, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
    contornos, _ = cv2.findContours(binaria, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    melhor = None
    for c in sorted(contornos, key=cv2.contourArea, reverse=True)[:8]:
        area = cv2.contourArea(c)
        if area < 0.30 * h * w:
            break
        if area > 0.97 * h * w:  # borda da própria imagem
            continue
        aprox = cv2.approxPolyDP(c, 0.02 * cv2.arcLength(c, True), True)
        if len(aprox) == 4 and cv2.isContourConvex(aprox):
            melhor = aprox
            break

    if melhor is None:
        escala = LARGURA_NORMALIZADA / w
        M = np.array([[escala, 0, 0], [0, escala, 0], [0, 0, 1]], dtype="float64")
        return cv2.resize(cinza, (LARGURA_NORMALIZADA, int(h * escala)), interpolation=cv2.INTER_CUBIC), False, M

    cantos = _ordenar_cantos(melhor)
    larg = max(np.linalg.norm(cantos[1] - cantos[0]), np.linalg.norm(cantos[2] - cantos[3]))
    alt = max(np.linalg.norm(cantos[3] - cantos[0]), np.linalg.norm(cantos[2] - cantos[1]))
    W = LARGURA_NORMALIZADA
    H = int(round(W * alt / larg))
    destino = np.array([[0, 0], [W - 1, 0], [W - 1, H - 1], [0, H - 1]], dtype="float32")
    M = cv2.getPerspectiveTransform(cantos, destino)
    return cv2.warpPerspective(cinza, M, (W, H), flags=cv2.INTER_CUBIC, borderValue=255), True, M


# ---------------------------------------------------------------------------
# 2. Detectar bolinhas
# ---------------------------------------------------------------------------

@dataclass
class Bolha:
    x: float
    y: float
    r: float
    preenchimento: float = 0.0


def detectar_bolhas(folha: np.ndarray) -> list[Bolha]:
    W = folha.shape[1]
    binaria = cv2.adaptiveThreshold(folha, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                    cv2.THRESH_BINARY_INV, 31, 10)
    contornos, _ = cv2.findContours(binaria, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    d_min, d_max = 0.022 * W, 0.075 * W
    candidatas: list[Bolha] = []
    for c in contornos:
        x, y, w, h = cv2.boundingRect(c)
        if not (d_min <= w <= d_max and d_min <= h <= d_max):
            continue
        if not (0.75 <= w / h <= 1.33):
            continue
        (cx, cy), r = cv2.minEnclosingCircle(c)
        # Círculo ocupa ~100% do círculo envolvente; quadrado ocupa ~64%.
        if cv2.contourArea(c) / (math.pi * r * r) < 0.75:
            continue
        candidatas.append(Bolha(cx, cy, r))

    # Remove duplicatas (borda externa e interna do mesmo anel)
    candidatas.sort(key=lambda b: -b.r)
    unicas: list[Bolha] = []
    for b in candidatas:
        if all(math.hypot(b.x - u.x, b.y - u.y) > u.r * 0.6 for u in unicas):
            unicas.append(b)

    if not unicas:
        return []
    # Mantém só o tamanho dominante (descarta letras redondas de títulos etc.)
    raio_tipico = float(np.median([b.r for b in unicas]))
    return [b for b in unicas if 0.7 * raio_tipico <= b.r <= 1.3 * raio_tipico]


# ---------------------------------------------------------------------------
# 3. Organizar em grade
# ---------------------------------------------------------------------------

def estimar_inclinacao(bolhas: list[Bolha], raio: float) -> float:
    """Ângulo (rad) das linhas de bolinhas, usando pares de vizinhas lado a lado.
    Permite ler folhas tortas mesmo quando a moldura não foi encontrada."""
    angulos = []
    for a in bolhas:
        for b in bolhas:
            dx, dy = b.x - a.x, b.y - a.y
            if raio * 1.5 < dx < raio * 5 and abs(dy) < dx * 0.27:  # até ~15°
                angulos.append(math.atan2(dy, dx))
    return float(np.median(angulos)) if angulos else 0.0


def organizar_grade(bolhas: list[Bolha], layout: Layout) -> list[list[Bolha]]:
    """Devolve uma lista indexada pela questão (0..n-1); cada item é a lista
    de bolhas daquela questão na ordem das alternativas."""
    por_linha = layout.colunas * len(layout.alternativas)
    if not bolhas:
        raise ErroLeitura("Nenhuma bolinha encontrada na folha.")

    raio = float(np.median([b.r for b in bolhas]))
    angulo = estimar_inclinacao(bolhas, raio)
    cos, sen = math.cos(-angulo), math.sin(-angulo)
    # Coordenadas "desentortadas" usadas só para agrupar (a medição usa as reais)
    rot = {id(b): (b.x * cos - b.y * sen, b.x * sen + b.y * cos) for b in bolhas}

    linhas: list[list[Bolha]] = []
    for b in sorted(bolhas, key=lambda b: rot[id(b)][1]):
        y = rot[id(b)][1]
        if linhas and abs(y - np.mean([rot[id(o)][1] for o in linhas[-1]])) < raio:
            linhas[-1].append(b)
        else:
            linhas.append([b])

    validas = [sorted(l, key=lambda b: rot[id(b)][0]) for l in linhas if len(l) == por_linha]
    if len(validas) != layout.linhas:
        raise ErroLeitura(
            f"Esperava {layout.linhas} linhas com {por_linha} bolinhas, "
            f"encontrei {len(validas)}. Confira o layout da prova ou a qualidade da digitalização."
        )

    n_alt = len(layout.alternativas)
    questoes: list[list[Bolha]] = [[] for _ in range(layout.questoes)]
    for i_linha, linha in enumerate(validas):
        for i_col in range(layout.colunas):
            grupo = linha[i_col * n_alt:(i_col + 1) * n_alt]
            if layout.ordem == "linhas":
                idx = i_linha * layout.colunas + i_col
            else:
                idx = i_col * layout.linhas + i_linha
            questoes[idx] = grupo
    return questoes


# ---------------------------------------------------------------------------
# 4. Medir preenchimento e decidir
# ---------------------------------------------------------------------------

def medir_preenchimento(folha: np.ndarray, bolha: Bolha, fundo: float) -> float:
    mascara = np.zeros(folha.shape, dtype=np.uint8)
    cv2.circle(mascara, (int(round(bolha.x)), int(round(bolha.y))), max(1, int(bolha.r * 0.65)), 255, -1)
    media = cv2.mean(folha, mask=mascara)[0]
    return float(np.clip((fundo - media) / max(fundo, 1), 0, 1))


def ler_folha(imagem_bgr: np.ndarray, layout: Layout) -> dict:
    cinza = cv2.cvtColor(imagem_bgr, cv2.COLOR_BGR2GRAY) if imagem_bgr.ndim == 3 else imagem_bgr
    folha, moldura, matriz = localizar_folha(cinza)
    bolhas = detectar_bolhas(folha)
    grade = organizar_grade(bolhas, layout)

    fundo = float(np.percentile(folha, 90))  # tom do papel
    brutos = [[medir_preenchimento(folha, b, fundo) for b in grupo] for grupo in grade]

    # Calibração por folha: a maioria das bolinhas está vazia, então a mediana
    # representa "bolinha vazia" (anel + letra) naquela digitalização. Os níveis
    # são reescalados para 0 = vazia típica, 1 = totalmente preenchida.
    base = float(np.median([v for g in brutos for v in g]))
    respostas = []
    for num, (grupo, niveis_brutos) in enumerate(zip(grade, brutos), start=1):
        niveis = [float(np.clip((v - base) / max(1 - base, 1e-6), 0, 1)) for v in niveis_brutos]
        for b, v in zip(grupo, niveis):
            b.preenchimento = v
        marcadas = [layout.alternativas[i] for i, v in enumerate(niveis) if v >= LIMIAR_MARCADA]
        duvidosa = any(ZONA_DUVIDA[0] < v < ZONA_DUVIDA[1] for v in niveis)

        if len(marcadas) == 1:
            situacao, resposta = "marcada", marcadas[0]
        elif not marcadas:
            situacao, resposta = "branco", None
        else:
            situacao, resposta = "multipla", None

        respostas.append({
            "questao": num,
            "resposta": resposta,
            "situacao": situacao,
            "duvidosa": duvidosa,
            "niveis": [round(v, 3) for v in niveis],
        })

    return {
        "moldura_encontrada": moldura,
        "respostas": respostas,
        "duvidosas": [r["questao"] for r in respostas if r["duvidosa"]],
        "_folha": folha,
        "_grade": grade,
        "_matriz": matriz,
    }


def desenhar_depuracao(resultado: dict, layout: Layout) -> np.ndarray:
    """Imagem com as bolinhas identificadas: verde = marcada, laranja = dúvida."""
    folha = cv2.cvtColor(resultado["_folha"], cv2.COLOR_GRAY2BGR)
    for resp, grupo in zip(resultado["respostas"], resultado["_grade"]):
        for alt, b in zip(layout.alternativas, grupo):
            centro = (int(b.x), int(b.y))
            if ZONA_DUVIDA[0] < b.preenchimento < ZONA_DUVIDA[1]:
                cor, esp = (0, 140, 255), 4
            elif alt == resp["resposta"]:
                cor, esp = (40, 170, 40), 4
            else:
                cor, esp = (200, 200, 200), 1
            cv2.circle(folha, centro, int(b.r) + 4, cor, esp)
        ultima = grupo[-1]
        rotulo = resp["resposta"] or {"branco": "branco", "multipla": "dupla"}[resp["situacao"]]
        cv2.putText(folha, rotulo, (int(ultima.x + ultima.r * 1.8), int(ultima.y + 6)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.55, (180, 60, 60), 1, cv2.LINE_AA)
    return folha


def resultado_publico(resultado: dict) -> dict:
    """Remove os campos internos (imagens) para serializar em JSON."""
    return {k: v for k, v in resultado.items() if not k.startswith("_")}
