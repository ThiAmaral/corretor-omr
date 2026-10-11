"""
Leitura óptica de folhas de resposta (OMR).

Este módulo só LÊ a folha: devolve qual alternativa foi marcada em cada
questão. Comparar com o gabarito e calcular notas é responsabilidade do
backend (PHP), para que a regra de correção fique em um lugar só.

Etapas de ler_folha():
  1. Normaliza a iluminação e procura os 4 quadrados pretos dos cantos da área
     de respostas (folha AMA).
  2. Lê o QR code (código do caderno), que também indica o lado de cima.
  3. Endireita a área entre os marcadores. Sem marcadores, procura a moldura
     retangular (modelo do protótipo).
  4. Detecta as bolinhas (círculos vazios e preenchidos).
  5. Descobre os blocos de questões e organiza as bolinhas por questão.
  6. Mede o quanto cada bolinha está escurecida e decide a marcação.
"""
from __future__ import annotations

import itertools
import math
import os
from dataclasses import dataclass, field

import cv2
import numpy as np

LARGURA_NORMALIZADA = 1000  # px; toda folha é redimensionada para esta largura
MARGEM_AREA = 0.04  # folga em volta dos marcadores, em fração da largura

# Limiares sobre o nível calibrado (0 = bolinha vazia típica, 1 = toda preenchida)
LIMIAR_MARCADA = 0.50
ZONA_DUVIDA = (0.25, 0.65)  # leituras nesta faixa vão para revisão manual
# Teto do "nível de bolinha vazia" usado na calibração. Sem ele, uma folha com
# quase todas as bolinhas marcadas seria calibrada como se marcada fosse vazia.
BASE_MAXIMA = 0.50

LETRAS = "ABCDEFGHIJ"


class ErroLeitura(Exception):
    """Folha que não pôde ser lida (ex.: grade de bolinhas não encontrada)."""


def blocos_iguais(questoes: int, colunas: int) -> list[int]:
    """Converte o formato antigo (questões + colunas) em blocos.
    20 em 2 colunas -> [10, 10]; 26 em 4 colunas -> [7, 7, 7, 5]."""
    if questoes < 1 or colunas < 1:
        raise ValueError("Informe ao menos uma questão e uma coluna.")
    tam = math.ceil(questoes / colunas)
    blocos = [min(tam, questoes - i * tam) for i in range(colunas)]
    if any(b <= 0 for b in blocos):
        raise ValueError(f"Não dá para dividir {questoes} questões em {colunas} colunas.")
    return blocos


@dataclass
class Layout:
    """Formato da folha. Os blocos são lidos da esquerda para a direita e, em
    cada bloco, as questões descem de cima para baixo (folha AMA)."""
    blocos: list[int] = field(default_factory=lambda: [10, 10])
    alternativas: list[str] = field(default_factory=lambda: ["A", "B", "C", "D"])
    ordem: str = "colunas"  # "colunas": desce cada bloco; "linhas": 1, 2 na mesma linha
    caderno: str | None = None  # código do QR; se existir, a folha precisa ter o mesmo

    @classmethod
    def de_dict(cls, d: dict) -> "Layout":
        alts = d.get("alternativas", ["A", "B", "C", "D"])
        if isinstance(alts, str):
            alts = [a.strip() for a in alts.split(",") if a.strip()]
        if d.get("blocos"):
            blocos = [int(b) for b in d["blocos"]]
            if "questoes" in d and int(d["questoes"]) != sum(blocos):
                raise ValueError(f"Os blocos {blocos} somam {sum(blocos)} questões, "
                                 f"mas o layout diz {d['questoes']}.")
        else:
            blocos = blocos_iguais(int(d.get("questoes", 20)), int(d.get("colunas", 2)))
        lay = cls(blocos=blocos, alternativas=list(alts), ordem=d.get("ordem", "colunas"),
                  caderno=d.get("caderno") or None)
        if lay.ordem == "linhas" and len(set(blocos)) > 1:
            raise ValueError("A numeração por linhas só funciona com blocos do mesmo tamanho.")
        return lay

    def para_dict(self) -> dict:
        d = {"caderno": self.caderno, "alternativas": self.alternativas,
             "blocos": self.blocos, "questoes": self.questoes}
        if self.ordem != "colunas":
            d["ordem"] = self.ordem
        return d

    @property
    def questoes(self) -> int:
        return sum(self.blocos)

    @property
    def colunas(self) -> int:
        return len(self.blocos)


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
# 1. Iluminação e marcadores
# ---------------------------------------------------------------------------

def normalizar_iluminacao(cinza: np.ndarray) -> np.ndarray:
    """Divide a imagem pelo próprio fundo (papel + sombra), estimado apagando o
    que é escuro e pequeno. O papel vira ~255 em toda a foto, com ou sem sombra."""
    h, w = cinza.shape
    esc = 800 / max(h, w)
    peq = cv2.resize(cinza, None, fx=esc, fy=esc, interpolation=cv2.INTER_AREA)
    k = int(0.05 * 800) | 1  # maior que marcadores e bolinhas
    fundo = cv2.morphologyEx(peq, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))
    fundo = cv2.resize(cv2.GaussianBlur(fundo, (k, k), 0), (w, h), interpolation=cv2.INTER_LINEAR)
    return np.clip(cinza.astype(np.float32) / np.maximum(fundo, 1) * 255, 0, 255).astype(np.uint8)


def _ordenar_cantos(pts: np.ndarray) -> np.ndarray:
    pts = pts.reshape(4, 2).astype("float32")
    s = pts.sum(axis=1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]], dtype="float32")


def _candidatos_marcador(normal: np.ndarray) -> list[tuple[float, float, float]]:
    """Quadrados pretos cheios e isolados: (x do centro, y do centro, lado).
    Recebe a imagem com a iluminação já normalizada."""
    h, w = normal.shape
    lado_img = max(h, w)
    _, binaria = cv2.threshold(cv2.GaussianBlur(normal, (5, 5), 0), 150, 255, cv2.THRESH_BINARY_INV)
    contornos, _ = cv2.findContours(binaria, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    candidatos = []
    for c in contornos:
        x, y, cw, ch = cv2.boundingRect(c)
        if not (0.006 * lado_img <= cw <= 0.06 * lado_img and 0.006 * lado_img <= ch <= 0.06 * lado_img):
            continue
        (cx, cy), (rw, rh), _ = cv2.minAreaRect(c)  # caixa girada: vale para foto torta
        if not rw or not rh or not (0.7 <= rw / rh <= 1.4):
            continue
        area = cv2.contourArea(c)
        casco = cv2.contourArea(cv2.convexHull(c))
        # Quadrado cheio ocupa ~100% da caixa; círculo cheio, ~78%.
        if area / (rw * rh) < 0.84 or not casco or area / casco < 0.9:
            continue
        # Preto por dentro (exclui o miolo branco de quadradinhos de grade)...
        mascara = np.zeros((ch, cw), np.uint8)
        cv2.drawContours(mascara, [c - [x, y]], -1, 255, -1)
        if cv2.mean(binaria[y:y + ch, x:x + cw], mask=mascara)[0] < 0.85 * 255:
            continue
        # ...e isolado: em volta dele o papel é quase todo branco.
        m = max(cw, ch) // 2
        x0, y0, x1, y1 = max(0, x - m), max(0, y - m), min(w, x + cw + m), min(h, y + ch + m)
        escuro_volta = cv2.countNonZero(binaria[y0:y1, x0:x1]) - cv2.countNonZero(binaria[y:y + ch, x:x + cw])
        if escuro_volta > 0.25 * ((x1 - x0) * (y1 - y0) - cw * ch):
            continue
        candidatos.append((cx, cy, math.sqrt(area)))
    return candidatos


def _angulos_internos(cantos: np.ndarray) -> list[float]:
    angs = []
    for i in range(4):
        a, b, c = cantos[i - 1], cantos[i], cantos[(i + 1) % 4]
        v1, v2 = a - b, c - b
        cos = np.dot(v1, v2) / (np.linalg.norm(v1) * np.linalg.norm(v2) + 1e-9)
        angs.append(math.degrees(math.acos(np.clip(cos, -1, 1))))
    return angs


def _quadrilatero_valido(cantos: np.ndarray) -> bool:
    if len({tuple(np.round(p, 1)) for p in cantos}) < 4 or not cv2.isContourConvex(cantos.reshape(-1, 1, 2)):
        return False
    return all(abs(a - 90) <= 30 for a in _angulos_internos(cantos))


def localizar_marcadores(normal: np.ndarray):
    """Entre os candidatos, os 4 que formam o maior quadrilátero 'quase
    retangular' com quadrados de tamanho parecido. Se só 3 forem achados (um
    dedo ou uma dobra cobrindo um canto), o 4º é estimado completando o
    paralelogramo. Devolve (cantos tl, tr, br, bl; lado médio do marcador;
    se um canto foi estimado) ou None."""
    candidatos = sorted(_candidatos_marcador(normal), key=lambda c: -c[2])[:24]
    area_minima = 0.08 * normal.size  # a área de respostas não é minúscula
    melhor, melhor_area = None, area_minima
    for combo in itertools.combinations(candidatos, 4):
        lados = [c[2] for c in combo]
        if max(lados) > 1.5 * min(lados):
            continue
        cantos = _ordenar_cantos(np.array([(c[0], c[1]) for c in combo]))
        if not _quadrilatero_valido(cantos):
            continue
        area = cv2.contourArea(cantos)
        if area > melhor_area:
            melhor, melhor_area = (cantos, float(np.mean(lados)), False), area
    if melhor:
        return melhor

    h, w = normal.shape
    for combo in itertools.combinations(candidatos, 3):
        lados = [c[2] for c in combo]
        if max(lados) > 1.25 * min(lados):  # sem o 4º para conferir, exige mais
            continue
        pts = np.array([(c[0], c[1]) for c in combo], dtype="float32")
        for i in range(3):  # i = vértice do ângulo reto
            a, b, c = pts[(i + 1) % 3], pts[i], pts[(i + 2) % 3]
            cos = np.dot(a - b, c - b) / (np.linalg.norm(a - b) * np.linalg.norm(c - b) + 1e-9)
            if abs(cos) > 0.34:  # ângulo em b longe de 90° (± 20°)
                continue
            quarto = a + c - b
            if not (-0.05 * w <= quarto[0] <= 1.05 * w and -0.05 * h <= quarto[1] <= 1.05 * h):
                continue
            cantos = _ordenar_cantos(np.array([a, b, c, quarto]))
            if not _quadrilatero_valido(cantos):
                continue
            area = cv2.contourArea(cantos)
            if area > melhor_area:
                melhor, melhor_area = (cantos, float(np.mean(lados)), True), area
    return melhor


def _transformacao(cantos: np.ndarray):
    """Matriz que leva os 4 cantos (tl, tr, br, bl) para um retângulo de largura fixa."""
    larg = (np.linalg.norm(cantos[1] - cantos[0]) + np.linalg.norm(cantos[2] - cantos[3])) / 2
    alt = (np.linalg.norm(cantos[3] - cantos[0]) + np.linalg.norm(cantos[2] - cantos[1])) / 2
    W = LARGURA_NORMALIZADA
    H = int(round(W * alt / larg))
    destino = np.array([[0, 0], [W - 1, 0], [W - 1, H - 1], [0, H - 1]], dtype="float32")
    return cv2.getPerspectiveTransform(cantos, destino), W, H, larg


# ---------------------------------------------------------------------------
# 2. QR code
# ---------------------------------------------------------------------------

def _decodificar_qr(img: np.ndarray, escalas=(1.0,)):
    detectores = [cv2.QRCodeDetector()]
    if hasattr(cv2, "QRCodeDetectorAruco"):
        detectores.append(cv2.QRCodeDetectorAruco())
    for esc in escalas:
        im = img if esc == 1.0 else cv2.resize(img, None, fx=esc, fy=esc,
                                               interpolation=cv2.INTER_AREA if esc < 1 else cv2.INTER_CUBIC)
        for det in detectores:
            try:
                texto, pontos, _ = det.detectAndDecode(im)
            except cv2.error:
                continue
            if texto and pontos is not None:
                return texto.strip(), pontos.reshape(-1, 2) / esc
    return None, None


def ler_qr(cinza: np.ndarray) -> tuple[str | None, np.ndarray | None]:
    """Procura o QR na imagem inteira. Devolve (texto, cantos do QR na imagem)."""
    lado = max(cinza.shape)
    escalas = [1.0] + ([2000 / lado] if lado > 2400 else []) + ([2.0] if lado < 1600 else [])
    return _decodificar_qr(cinza, escalas)


def ler_qr_acima(cinza: np.ndarray, cantos: np.ndarray) -> tuple[str | None, int | None]:
    """Procura o QR na faixa ACIMA da área de respostas, já endireitada e
    ampliada, o que funciona em fotos onde o QR inteiro fica pequeno ou torto.
    Testa as 4 orientações; a que acha o QR diz qual é o lado de cima.
    Devolve (texto, giro), em que giro = quantos cantos rodar."""
    for giro in (0, 2, 1, 3):
        M, W, H, _ = _transformacao(np.roll(cantos, -giro, axis=0))
        larg = 2400
        s = larg / W
        faixa = 1.6 * H * s  # até 1,6 alturas acima dos marcadores de cima
        T = np.array([[s, 0, 0.05 * larg], [0, s, faixa], [0, 0, 1]]) @ M
        recorte = cv2.warpPerspective(cinza, T, (int(1.1 * larg), int(faixa)),
                                      flags=cv2.INTER_CUBIC, borderValue=255)
        texto, _ = _decodificar_qr(recorte, (1.0, 0.5))
        if texto:
            return texto, giro
    return None, None


def _giro_pelo_qr(cantos: np.ndarray, pontos_qr: np.ndarray) -> int:
    """Orientação em que o QR (achado na imagem inteira) fica acima da área."""
    centro = pontos_qr.mean(axis=0).reshape(1, 1, 2).astype("float64")
    notas = []
    for k in range(4):
        M, W, H, _ = _transformacao(np.roll(cantos, -k, axis=0))
        notas.append(cv2.perspectiveTransform(centro, M).ravel()[1] / H)
    return int(np.argmin(notas))


# ---------------------------------------------------------------------------
# 3. Alinhar a área de respostas
# ---------------------------------------------------------------------------

def alinhar_por_marcadores(normal: np.ndarray, cantos: np.ndarray, lado: float, giro: int = 0):
    """Endireita a área entre os 4 marcadores, girando `giro` cantos para que
    o lado de cima fique em cima. Deixa uma margem em volta, para não cortar
    bolinhas da borda quando um canto foi estimado ou a folha está curvada.
    Devolve (área alinhada, matriz imagem -> área, lado do marcador na área)."""
    M, W, H, larg = _transformacao(np.roll(cantos, -giro, axis=0))
    m = int(MARGEM_AREA * W)
    M = np.array([[1, 0, m], [0, 1, m], [0, 0, 1]], dtype="float64") @ M
    area = cv2.warpPerspective(normal, M, (W + 2 * m, H + 2 * m), flags=cv2.INTER_CUBIC, borderValue=255)
    return area, M, lado * W / larg


def localizar_folha(cinza: np.ndarray) -> tuple[np.ndarray, bool, np.ndarray]:
    """Modelo do protótipo: recorta e endireita a área dentro da moldura. Se não
    achar moldura, usa a imagem inteira (moldura_encontrada=False). Devolve
    também a matriz que leva coordenadas da imagem original para a folha normalizada."""
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

    M, W, H, _ = _transformacao(_ordenar_cantos(melhor))
    return cv2.warpPerspective(cinza, M, (W, H), flags=cv2.INTER_CUBIC, borderValue=255), True, M


# ---------------------------------------------------------------------------
# 4. Detectar bolinhas
# ---------------------------------------------------------------------------

@dataclass
class Bolha:
    x: float
    y: float
    r: float
    preenchimento: float = 0.0
    estimada: bool = False  # não foi achada; a posição veio da grade


def detectar_bolhas(folha: np.ndarray, diametro: tuple[float, float] | None = None) -> list[Bolha]:
    """Círculos de tamanho parecido. `diametro` = (mínimo, máximo) em px; sem ele,
    usa a faixa do modelo do protótipo (proporcional à largura da folha)."""
    W = folha.shape[1]
    binaria = cv2.adaptiveThreshold(folha, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                    cv2.THRESH_BINARY_INV, 31, 10)
    contornos, _ = cv2.findContours(binaria, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    d_min, d_max = diametro or (0.022 * W, 0.075 * W)
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
# 5. Blocos de questões
# ---------------------------------------------------------------------------

def estimar_inclinacao(bolhas: list[Bolha], raio: float, vertical: bool = False) -> float | None:
    """Ângulo (rad) das linhas de bolinhas, usando pares de vizinhas lado a lado.
    Com vertical=True, o ângulo das colunas (pares uma embaixo da outra), medido
    a partir da vertical. Permite ler folhas tortas ou com a área "cisalhada"
    (perspectiva mal corrigida, papel curvado)."""
    angulos = []
    for a in bolhas:
        for b in bolhas:
            dx, dy = b.x - a.x, b.y - a.y
            if vertical:
                dx, dy = dy, dx
            if raio * 1.5 < dx < raio * 5 and abs(dy) < dx * 0.27:  # até ~15°
                angulos.append(math.atan2(dy, dx))
    return float(np.median(angulos)) if angulos else None


def _agrupar(valores: list[float], tolerancia: float) -> list[list[int]]:
    """Agrupa índices de valores 1D próximos (ordem crescente)."""
    ordem = sorted(range(len(valores)), key=lambda i: valores[i])
    grupos: list[list[int]] = []
    for i in ordem:
        if grupos and valores[i] - np.mean([valores[j] for j in grupos[-1]]) < tolerancia:
            grupos[-1].append(i)
        else:
            grupos.append([i])
    return grupos


def detectar_layout(bolhas: list[Bolha]) -> tuple[Layout, list[list[list[Bolha]]], int]:
    """Descobre o formato da folha só pela posição das bolinhas.

    - Colunas de bolinhas (uma por alternativa) são separadas por espaços
      pequenos; blocos de questões, por espaços grandes.
    - Em cada bloco, cada linha com bolinhas é uma questão, de cima para baixo.
    - Bolinhas que faltam numa questão (ex.: marcação borrada que não parece
      círculo) são estimadas pela grade, desde que a maioria tenha sido achada.

    Devolve (layout, bolinhas[bloco][questão][alternativa], nº de estimadas)."""
    if not bolhas:
        raise ErroLeitura("Nenhuma bolinha encontrada na folha.")
    raio = float(np.median([b.r for b in bolhas]))
    ang_linhas = estimar_inclinacao(bolhas, raio) or 0.0
    ang_colunas = estimar_inclinacao(bolhas, raio, vertical=True)
    if ang_colunas is None:
        ang_colunas = -ang_linhas
    # Coordenadas "desentortadas" usadas só para agrupar (a medição usa as reais):
    # x ao longo das linhas de bolinhas, y ao longo das colunas.
    base = np.array([[math.cos(ang_linhas), math.sin(ang_colunas)],
                     [math.sin(ang_linhas), math.cos(ang_colunas)]])
    inversa = np.linalg.inv(base)
    xs, ys = (inversa @ np.array([[b.x for b in bolhas], [b.y for b in bolhas]])).tolist()

    linhas = [g for g in _agrupar(ys, raio) if len(g) >= 2]
    colunas = _agrupar(xs, raio)
    maior = max(len(c) for c in colunas)
    # Coluna verdadeira tem bolinhas em várias linhas; descarta sujeira isolada.
    colunas = [c for c in colunas if len(c) >= max(2, 0.3 * maior)]
    if len(colunas) < 2 or not linhas:
        raise ErroLeitura("Não encontrei a grade de bolinhas. Confira a qualidade da foto.")
    cx = [float(np.mean([xs[i] for i in c])) for c in colunas]

    # Espaço entre alternativas = o menor espaço típico entre colunas vizinhas
    espacos = np.diff(cx)
    passo = float(np.median([e for e in espacos if e < 1.5 * espacos.min()]))
    blocos_cols: list[list[int]] = [[0]]
    for i, e in enumerate(espacos, start=1):
        if e > 1.6 * passo:
            blocos_cols.append([i])
        else:
            blocos_cols[-1].append(i)

    tamanhos = [len(b) for b in blocos_cols]
    n_alt = max(set(tamanhos), key=tamanhos.count)
    if any(t != n_alt for t in tamanhos):
        raise ErroLeitura(f"Os blocos têm números diferentes de alternativas ({tamanhos}). "
                          "Confira a qualidade da foto.")

    coluna_de = {}
    for i_col, c in enumerate(colunas):
        for i in c:
            coluna_de[i] = i_col

    estimadas = 0
    grade: list[list[list[Bolha]]] = []
    for cols in blocos_cols:
        questoes_bloco = []
        for linha in linhas:
            achadas = {coluna_de[i]: i for i in linha if coluna_de.get(i) in cols}
            if len(achadas) < math.ceil(n_alt / 2):
                continue
            y_linha = float(np.mean([ys[i] for i in achadas.values()]))
            grupo = []
            for c in cols:
                if c in achadas:
                    grupo.append(bolhas[achadas[c]])
                else:  # volta da coordenada desentortada para a real
                    xr, yr = base @ (cx[c], y_linha)
                    grupo.append(Bolha(float(xr), float(yr), raio, estimada=True))
                    estimadas += 1
            questoes_bloco.append(grupo)
        if questoes_bloco:
            grade.append(questoes_bloco)

    layout = Layout(blocos=[len(b) for b in grade], alternativas=list(LETRAS[:n_alt]))
    return layout, grade, estimadas


def organizar_grade(grade: list[list[list[Bolha]]], layout: Layout) -> list[list[Bolha]]:
    """Põe as questões na ordem da numeração. Devolve uma lista indexada pela
    questão (0..n-1); cada item é a lista de bolhas na ordem das alternativas."""
    if layout.ordem == "linhas":
        return [grade[b][q] for q in range(len(grade[0])) for b in range(len(grade))]
    return [q for bloco in grade for q in bloco]


def conferir_layout(detectado: Layout, esperado: Layout) -> None:
    if detectado.blocos != esperado.blocos or len(detectado.alternativas) != len(esperado.alternativas):
        raise ErroLeitura(
            f"Esperava blocos de {esperado.blocos} questões com {len(esperado.alternativas)} alternativas, "
            f"encontrei {detectado.blocos} com {len(detectado.alternativas)}. "
            "Confira se a folha é desta prova ou tire outra foto."
        )


# ---------------------------------------------------------------------------
# 6. Medir preenchimento e decidir
# ---------------------------------------------------------------------------

def medir_preenchimento(folha: np.ndarray, bolha: Bolha, fundo: float) -> float:
    mascara = np.zeros(folha.shape, dtype=np.uint8)
    cv2.circle(mascara, (int(round(bolha.x)), int(round(bolha.y))), max(1, int(bolha.r * 0.65)), 255, -1)
    media = cv2.mean(folha, mask=mascara)[0]
    return float(np.clip((fundo - media) / max(fundo, 1), 0, 1))


def ler_folha(imagem_bgr: np.ndarray, layout: Layout | None = None) -> dict:
    """Lê uma folha. Sem `layout`, descobre o formato sozinho; com `layout`,
    confere se a folha tem o formato (e o caderno) esperado."""
    cinza = cv2.cvtColor(imagem_bgr, cv2.COLOR_BGR2GRAY) if imagem_bgr.ndim == 3 else imagem_bgr
    normal = normalizar_iluminacao(cinza)

    marcadores = localizar_marcadores(normal)
    caderno, giro, girada, canto_estimado = None, 0, False, False
    if marcadores:
        cantos, lado, canto_estimado = marcadores
        caderno, giro = ler_qr_acima(cinza, cantos)
    if not caderno:
        caderno, pontos_qr = ler_qr(cinza)
        if caderno and marcadores:
            giro = _giro_pelo_qr(cantos, pontos_qr)
    giro = giro or 0
    if layout and layout.caderno and caderno and caderno != layout.caderno:
        raise ErroLeitura(f"Esta folha é do caderno {caderno}, e a prova é do caderno {layout.caderno}.")

    alinhada = None
    if marcadores:
        folha, matriz, lado_marcador = alinhar_por_marcadores(normal, cantos, lado, giro)
        diametro = (0.45 * lado_marcador, 1.6 * lado_marcador)
        moldura = False
        try:
            detectado, blocos, estimadas = detectar_layout(detectar_bolhas(folha, diametro))
            # Sem QR não se sabe o lado de cima. Na AMA os blocos menores ficam à
            # direita (7, 7, 7, 5); se o menor está à esquerda, a folha está virada.
            if not caderno and detectado.blocos[0] < detectado.blocos[-1]:
                H, W = folha.shape
                matriz = np.array([[-1, 0, W - 1], [0, -1, H - 1], [0, 0, 1]], dtype="float64") @ matriz
                folha = cv2.rotate(folha, cv2.ROTATE_180)
                detectado, blocos, estimadas = detectar_layout(detectar_bolhas(folha, diametro))
                girada = True
            if layout:
                conferir_layout(detectado, layout)
            alinhada = True
        except ErroLeitura:
            if caderno:  # folha AMA de verdade: o erro vale
                raise
            # 4 "marcadores" que não eram marcadores: tenta a moldura
    if not alinhada:
        folha, moldura, matriz = localizar_folha(cinza)
        try:
            detectado, blocos, estimadas = detectar_layout(detectar_bolhas(folha))
        except ErroLeitura as e:
            if moldura:
                raise
            raise ErroLeitura("Não encontrei os 4 quadrados pretos dos cantos da área de respostas "
                              "(nem a moldura do modelo antigo). Fotografe a folha inteira, de frente, "
                              "sem cobrir os cantos.") from e
        if layout:
            conferir_layout(detectado, layout)

    detectado.caderno = caderno
    if layout:
        detectado.alternativas = list(layout.alternativas)
        detectado.ordem = layout.ordem
    grade = organizar_grade(blocos, detectado)

    fundo = float(np.percentile(folha, 90))  # tom do papel
    brutos = [[medir_preenchimento(folha, b, fundo) for b in grupo] for grupo in grade]

    # Calibração por folha: a maioria das bolinhas está vazia, então a mediana
    # representa "bolinha vazia" (anel + letra) naquela digitalização. Os níveis
    # são reescalados para 0 = vazia típica, 1 = totalmente preenchida.
    base = min(float(np.median([v for g in brutos for v in g])), BASE_MAXIMA)
    respostas = []
    for num, (grupo, niveis_brutos) in enumerate(zip(grade, brutos), start=1):
        niveis = [float(np.clip((v - base) / max(1 - base, 1e-6), 0, 1)) for v in niveis_brutos]
        for b, v in zip(grupo, niveis):
            b.preenchimento = v
        marcadas = [detectado.alternativas[i] for i, v in enumerate(niveis) if v >= LIMIAR_MARCADA]
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

    # Confiança: motivos para pedir outra foto ou conferir a leitura
    motivos = []
    if not alinhada and not moldura:
        motivos.append("Não encontrei os marcadores dos cantos nem a moldura da folha.")
    if alinhada and canto_estimado:
        motivos.append("Um dos marcadores dos cantos não foi encontrado; a posição dele foi estimada.")
    if alinhada and not caderno:
        motivos.append("Não consegui ler o QR code da folha"
                       + (" (o lado de cima foi deduzido pelos blocos)." if girada else "."))
    if layout and layout.caderno and not caderno:
        motivos.append("Sem o QR code, não foi possível conferir se a folha é do caderno da prova.")
    if estimadas:
        motivos.append(f"{estimadas} bolinha(s) não foram encontradas e tiveram a posição estimada.")

    return {
        "caderno": caderno,
        "marcadores_encontrados": bool(alinhada),
        "girada": bool(giro) or girada,
        "moldura_encontrada": moldura,
        "layout": detectado.para_dict(),
        "confianca": {"nivel": "baixa" if motivos else "alta", "motivos": motivos},
        "respostas": respostas,
        "duvidosas": [r["questao"] for r in respostas if r["duvidosa"]],
        "_folha": folha,
        "_grade": grade,
        "_matriz": matriz,
    }


def desenhar_depuracao(resultado: dict, layout: Layout | None = None) -> np.ndarray:
    """Imagem com as bolinhas identificadas: verde = marcada, laranja = dúvida,
    azul = bolinha não encontrada (posição estimada)."""
    alternativas = (layout.alternativas if layout else resultado["layout"]["alternativas"])
    folha = cv2.cvtColor(resultado["_folha"], cv2.COLOR_GRAY2BGR)
    for resp, grupo in zip(resultado["respostas"], resultado["_grade"]):
        for alt, b in zip(alternativas, grupo):
            centro = (int(b.x), int(b.y))
            if ZONA_DUVIDA[0] < b.preenchimento < ZONA_DUVIDA[1]:
                cor, esp = (0, 140, 255), 4
            elif alt == resp["resposta"]:
                cor, esp = (40, 170, 40), 4
            elif b.estimada:
                cor, esp = (220, 120, 0), 2
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
