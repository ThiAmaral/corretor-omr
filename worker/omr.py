"""
Leitura óptica de folhas de resposta (OMR).

Este módulo só LÊ a folha: devolve qual alternativa foi marcada em cada
questão. Comparar com o gabarito e calcular notas é responsabilidade do
backend (PHP), para que a regra de correção fique em um lugar só.

Etapas de ler_folha():
  1. Normaliza a iluminação e procura os 4 quadrados pretos dos cantos da área
     de respostas (folha AMA). Mede a qualidade da foto (brilho, resolução,
     desfoque) e recusa as que não dão para ler com segurança.
  2. Lê o QR code (código do caderno), que também indica o lado de cima.
  3. Endireita a área entre os marcadores. Sem marcadores, procura a moldura
     retangular (modelo do protótipo).
  4. Detecta as bolinhas (círculos vazios e preenchidos).
  5. Descobre os blocos de questões e organiza as bolinhas por questão.
  6. Mede o quanto cada bolinha está escurecida e decide a marcação.
  7. Devolve também onde está cada bolinha na foto recebida (de 0 a 1).
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

# Qualidade da foto (medida nos marcadores). O desfoque é a largura da borda
# borrada (sigma, em px) dividida pelo lado do marcador, então não depende da
# resolução. Calibrado com fotos simuladas: até ~0,06 nas fotos boas; acima de
# ~0,07 o leitor começa a não achar os marcadores ou a grade.
LADO_MINIMO = 10  # px; marcador menor que isso = foto pequena demais (já leu errado)
LADO_BOM = 16  # px; abaixo disso, a borda borrada vem mais da resolução que do foco
DESFOQUE_MAXIMO = 0.07
BRILHO_MINIMO = 60  # tom do papel (0-255) abaixo do qual a foto está escura demais

LETRAS = "ABCDEFGHIJ"

MENSAGENS = {
    "escura": "A foto está escura demais. Fotografe num lugar mais iluminado, sem sombra sobre a folha.",
    "pouca_resolucao": "A foto tem pouca resolução. Aproxime o celular até a folha ocupar quase toda "
                       "a tela e tire outra foto.",
    "desfocada": "A foto está tremida ou fora de foco. Segure o celular firme, toque na folha para "
                 "focar e tire outra foto.",
}


class ErroLeitura(Exception):
    """Folha que não pôde ser lida (ex.: grade de bolinhas não encontrada).
    `codigo` diz o tipo do problema, para o app reagir sem interpretar o texto:
    arquivo, grade, sem_marcadores, formato_diferente, caderno_diferente,
    escura, pouca_resolucao, desfocada."""

    def __init__(self, mensagem: str, codigo: str = "grade"):
        super().__init__(mensagem)
        self.codigo = codigo


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
            raise ErroLeitura("Para ler PDF instale pdf2image e poppler-utils.", "arquivo") from e
        paginas = convert_from_path(caminho, dpi=200)
        return [cv2.cvtColor(np.array(p.convert("RGB")), cv2.COLOR_RGB2BGR) for p in paginas]
    img = cv2.imread(caminho)
    if img is None:
        raise ErroLeitura(f"Não foi possível abrir a imagem: {caminho}", "arquivo")
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
    # Suavização curta: o fundo acompanha a borda nítida de uma sombra (mão, celular).
    suave = (k // 2) | 1
    fundo = cv2.resize(cv2.GaussianBlur(fundo, (suave, suave), 0), (w, h), interpolation=cv2.INTER_LINEAR)
    return np.clip(cinza.astype(np.float32) / np.maximum(fundo, 1) * 255, 0, 255).astype(np.uint8)


def _ordenar_cantos(pts: np.ndarray) -> np.ndarray:
    pts = pts.reshape(4, 2).astype("float32")
    s = pts.sum(axis=1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]], dtype="float32")


def _escuro_em_volta(binaria: np.ndarray, cx: float, cy: float, r0: float, r1: float) -> float:
    """Fração de pixels escuros no anel quadrado entre as distâncias r0 e r1 do centro."""
    h, w = binaria.shape
    x0, x1 = max(0, int(cx - r1)), min(w, int(cx + r1) + 1)
    y0, y1 = max(0, int(cy - r1)), min(h, int(cy + r1) + 1)
    yy, xx = np.mgrid[y0:y1, x0:x1]
    dist = np.maximum(np.abs(xx - cx), np.abs(yy - cy))
    anel = (dist > r0) & (dist <= r1)
    return float(np.count_nonzero(binaria[y0:y1, x0:x1][anel]) / max(np.count_nonzero(anel), 1))


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
        # ...e isolado: num anel em volta dele (até um lado além da borda) o
        # papel é branco. Exclui o "olho" do QR code, que tem módulos em volta.
        lado = math.sqrt(area)
        if _escuro_em_volta(binaria, cx, cy, 0.85 * lado, 2.0 * lado) > 0.08:
            continue
        candidatos.append((cx, cy, lado))
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


def localizar_marcadores(normal: np.ndarray, area_minima: float = 0.08):
    """Entre os candidatos, os 4 que formam o maior quadrilátero 'quase
    retangular' com quadrados de tamanho parecido. Se só 3 forem achados (um
    dedo ou uma dobra cobrindo um canto), o 4º é estimado completando o
    paralelogramo. Devolve (cantos tl, tr, br, bl; lado médio do marcador;
    se um canto foi estimado) ou None."""
    candidatos = _candidatos_marcador(normal)
    # Os marcadores ficam longe uns dos outros. Quadrados pretos em grupo são
    # outra coisa: em foto desfocada, os 3 "olhos" do QR viram quadrados cheios.
    candidatos = [c for c in candidatos
                  if not any(o is not c and math.hypot(o[0] - c[0], o[1] - c[1]) < 4 * max(o[2], c[2])
                             for o in candidatos)]
    candidatos = sorted(candidatos, key=lambda c: -c[2])[:24]
    area_minima = area_minima * normal.size  # a área de respostas não é minúscula
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
# Qualidade da foto
# ---------------------------------------------------------------------------

def estimar_desfoque(cinza: np.ndarray, mascara: np.ndarray | None = None) -> float | None:
    """Largura da borda borrada (sigma da gaussiana, em px), medida nas bordas
    fortes. Numa borda de contraste C borrada com sigma s, o gradiente máximo é
    C / (s·√(2π)); então s = C / (gradiente máximo · √(2π)). Usa o quartil de
    baixo, que vem das bordas mais nítidas (as do papel impresso, não as sombras)."""
    g = cinza.astype(np.float32)
    grad = np.hypot(cv2.Sobel(g, cv2.CV_32F, 1, 0, ksize=3), cv2.Sobel(g, cv2.CV_32F, 0, 1, ksize=3)) / 8
    k = np.ones((9, 9), np.uint8)
    contraste = cv2.dilate(g, k) - cv2.erode(g, k)
    bordas = (grad >= cv2.dilate(grad, np.ones((3, 3), np.uint8))) & (contraste > 80)
    if mascara is not None:
        bordas &= mascara
    if np.count_nonzero(bordas) < 30:
        return None
    sigmas = contraste[bordas] / (math.sqrt(2 * math.pi) * np.maximum(grad[bordas], 1e-3))
    return float(np.percentile(sigmas, 25))


def avaliar_qualidade(cinza: np.ndarray, marcadores) -> dict:
    """Mede brilho, resolução e desfoque da foto. Com os marcadores achados,
    mede o desfoque nas bordas deles; sem eles, na foto inteira, supondo que a
    folha ocupa a foto (marcador ≈ 1,3% do lado maior).
    `problema` é None ou um dos códigos de MENSAGENS."""
    h, w = cinza.shape
    if marcadores:
        cantos, lado, _ = marcadores
        mascara = np.zeros((h, w), bool)
        for x, y in cantos:
            mascara[max(0, int(y - lado)):int(y + lado), max(0, int(x - lado)):int(x + lado)] = True
        sigma = estimar_desfoque(cinza, mascara)
    else:
        lado = 0.013 * max(h, w)
        sigma = estimar_desfoque(cinza)
    desfoque = sigma / lado if sigma else None
    brilho = float(np.percentile(cinza, 95))

    problema = None
    if brilho < BRILHO_MINIMO:
        problema = "escura"
    elif lado < LADO_MINIMO:
        problema = "pouca_resolucao"
    elif desfoque and desfoque > DESFOQUE_MAXIMO:
        problema = "pouca_resolucao" if lado < LADO_BOM else "desfocada"
    return {
        "lado_marcador": round(float(lado), 1),
        "desfoque": round(desfoque, 3) if desfoque else None,
        "brilho": round(brilho),
        "medida_nos_marcadores": bool(marcadores),
        "problema": problema,
    }


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
        # Se não sair de primeira, tenta com mais nitidez (foto um pouco borrada).
        borrado = None
        for reforco in (0, 1.5, 3):
            if reforco:
                borrado = cv2.GaussianBlur(recorte, (0, 0), 3) if borrado is None else borrado
                img = cv2.addWeighted(recorte, 1 + reforco, borrado, -reforco, 0)
            else:
                img = recorte
            texto, _ = _decodificar_qr(img, (1.0, 0.5))
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

    # As linhas (questões) são agrupadas dentro de cada bloco, com a inclinação
    # do próprio bloco: no papel curvado, a mesma linha muda de altura e de
    # ângulo de um bloco para outro.
    blocos_linhas = []  # por bloco: (base, x de cada coluna, sub, [(y da linha, achadas)])
    for cols in blocos_cols:
        indices = [i for c in cols for i in colunas[c]]
        sub = [bolhas[i] for i in indices]
        ang_l = estimar_inclinacao(sub, raio)
        ang_c = estimar_inclinacao(sub, raio, vertical=True)
        ang_l = ang_linhas if ang_l is None else ang_l
        ang_c = ang_colunas if ang_c is None else ang_c
        base_b = np.array([[math.cos(ang_l), math.sin(ang_c)],
                           [math.sin(ang_l), math.cos(ang_c)]])
        xb, yb = (np.linalg.inv(base_b) @ np.array([[b.x for b in sub], [b.y for b in sub]])).tolist()
        x_col = {c: float(np.mean([xb[k] for k, i in enumerate(indices) if coluna_de[i] == c])) for c in cols}
        linhas_b = []
        for linha in _agrupar(yb, raio):
            achadas = {coluna_de[indices[k]]: k for k in linha}
            if len(achadas) >= math.ceil(n_alt / 2):
                linhas_b.append((float(np.mean([yb[k] for k in achadas.values()])), achadas))
        blocos_linhas.append((cols, base_b, x_col, sub, linhas_b))

    # Uma linha com poucas bolinhas achadas (foto ruim) seria descartada e as
    # questões seguintes mudariam de número. Pelo espaço entre as linhas vizinhas
    # (o dobro do normal), ela é recolocada com as bolinhas estimadas.
    passos = [b[0] - a[0] for *_, linhas_b in blocos_linhas for a, b in zip(linhas_b, linhas_b[1:])]
    passo_linha = float(np.median(passos)) if passos else None

    estimadas = 0
    grade: list[list[list[Bolha]]] = []
    for cols, base_b, x_col, sub, linhas_b in blocos_linhas:
        completas = []
        for j, (y_linha, achadas) in enumerate(linhas_b):
            if j and passo_linha:
                vao = y_linha - linhas_b[j - 1][0]
                faltam = round(vao / passo_linha) - 1
                if 1 <= faltam <= 2 and abs(vao / passo_linha - round(vao / passo_linha)) < 0.25:
                    completas += [(linhas_b[j - 1][0] + k * vao / (faltam + 1), {}) for k in range(1, faltam + 1)]
            completas.append((y_linha, achadas))

        questoes_bloco = []
        for y_linha, achadas in completas:
            grupo = []
            for c in cols:
                if c in achadas:
                    grupo.append(sub[achadas[c]])
                else:  # volta da coordenada desentortada para a real
                    xr, yr = base_b @ (x_col[c], y_linha)
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
            "Confira se a folha é desta prova ou tire outra foto.",
            "formato_diferente",
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
    confere se a folha tem o formato (e o caderno) esperado.

    Antes, mede a qualidade da foto. Foto escura ou pequena demais é recusada
    logo: ela chega a ser lida, mas com respostas erradas. Desfocada só muda a
    mensagem quando a leitura falha, e baixa a confiança quando dá certo."""
    cinza = _cinza(imagem_bgr)
    normal = normalizar_iluminacao(cinza)
    return _ler_com_qualidade(cinza, normal, localizar_marcadores(normal), layout)


def _cinza(imagem: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(imagem, cv2.COLOR_BGR2GRAY) if imagem.ndim == 3 else imagem


def _ler_com_qualidade(cinza, normal, marcadores, layout, qr_do_app=None) -> dict:
    qualidade = avaliar_qualidade(cinza, marcadores)
    problema = qualidade["problema"]
    if problema == "escura" or (marcadores and qualidade["lado_marcador"] < LADO_MINIMO):
        raise ErroLeitura(MENSAGENS[problema], problema)

    try:
        resultado = _ler_folha(cinza, normal, marcadores, layout, qr_do_app)
    except ErroLeitura as e:
        if problema and e.codigo in ("grade", "sem_marcadores", "formato_diferente"):
            raise ErroLeitura(MENSAGENS[problema], problema) from e
        raise
    if problema:
        resultado["confianca"]["motivos"].append(
            {"pouca_resolucao": "A foto tem pouca resolução",
             "desfocada": "A foto está um pouco desfocada"}[problema] + "; confira a leitura.")
        resultado["confianca"]["nivel"] = "baixa"
    resultado["qualidade"] = qualidade
    adicionar_posicoes(resultado, cinza.shape)
    return resultado


def adicionar_posicoes(resultado: dict, forma: tuple[int, int]) -> None:
    """Põe em cada resposta o centro das bolinhas NA FOTO RECEBIDA, em fração da
    largura e da altura (0 a 1), na ordem das alternativas, e em `raio_bolinha`
    o raio típico em fração da largura. Assim o app desenha a leitura sobre a
    própria foto, sem saber nada da área endireitada."""
    h, w = forma
    inversa = np.linalg.inv(resultado["_matriz"])
    raios = []
    for resp, grupo in zip(resultado["respostas"], resultado["_grade"]):
        pts = np.array([[[b.x, b.y]] for b in grupo] + [[[b.x + b.r, b.y]] for b in grupo], dtype="float64")
        na_foto = cv2.perspectiveTransform(pts, inversa).reshape(-1, 2)
        centros, bordas = na_foto[:len(grupo)], na_foto[len(grupo):]
        raios += [float(np.linalg.norm(bd - c)) for c, bd in zip(centros, bordas)]
        resp["bolinhas"] = [[round(float(x) / w, 4), round(float(y) / h, 4)] for x, y in centros]
    resultado["raio_bolinha"] = round(float(np.median(raios)) / w, 4) if raios else None


def _ler_folha(cinza: np.ndarray, normal: np.ndarray, marcadores, layout: Layout | None,
               qr_do_app: tuple[str | None] | None = None) -> dict:
    """qr_do_app: recorte enviado pelo app, já com o lado de cima em cima; traz o
    caderno lido no recorte do QR (ou None). Não há QR nem moldura para procurar."""
    caderno, giro, girada, canto_estimado = None, 0, False, False
    if marcadores:
        cantos, lado, canto_estimado = marcadores
    if qr_do_app is not None:
        caderno = qr_do_app[0]
    else:
        if marcadores:
            caderno, giro = ler_qr_acima(cinza, cantos)
        if not caderno:
            caderno, pontos_qr = ler_qr(cinza)
            if caderno and marcadores:
                giro = _giro_pelo_qr(cantos, pontos_qr)
    giro = giro or 0
    if layout and layout.caderno and caderno and caderno != layout.caderno:
        raise ErroLeitura(f"Esta folha é do caderno {caderno}, e a prova é do caderno {layout.caderno}.",
                          "caderno_diferente")

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
            if caderno or qr_do_app is not None:  # folha AMA de verdade: o erro vale
                raise
            # 4 "marcadores" que não eram marcadores: tenta a moldura
    if not alinhada and qr_do_app is not None:
        raise ErroLeitura("O recorte não tem os 4 quadrados pretos dos cantos da área de respostas. "
                          "Tire outra foto, com a folha inteira na tela.", "sem_marcadores")
    if not alinhada:
        folha, moldura, matriz = localizar_folha(cinza)
        try:
            detectado, blocos, estimadas = detectar_layout(detectar_bolhas(folha))
        except ErroLeitura as e:
            if moldura:
                raise
            raise ErroLeitura("Não encontrei os 4 quadrados pretos dos cantos da área de respostas "
                              "(nem a moldura do modelo antigo). Fotografe a folha inteira, de frente, "
                              "sem cobrir os cantos.", "sem_marcadores") from e
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


# ---------------------------------------------------------------------------
# Recorte feito no celular (a foto inteira não sai do aparelho)
# ---------------------------------------------------------------------------
# O app acha os 4 marcadores e o QR, gira a foto para o lado de cima ficar em
# cima e envia só dois recortes: a área entre os marcadores e o QR com o código
# impresso. O nome e a turma do aluno ficam entre os dois e não são enviados.
# recortar_para_envio() faz em Python o mesmo que mobile/src/lib/recorte.ts,
# para os testes e para conferir o app com as mesmas fotos.

MARGEM_RECORTE = 1.2  # folga em volta dos centros dos marcadores, em lados de marcador
MARGEM_ESTIMADO = 2.5  # com um canto estimado, que pode errar alguns pixels
AREA_MINIMA_APP = 0.02  # no celular, a área dos marcadores pode ser bem menor que na foto inteira
AREA_MINIMA_RECORTE = 0.45  # os marcadores ocupam quase todo o recorte; a foto inteira, não
QR_MAXIMO_RECORTE = 0.25  # o recorte do QR é pequeno perto da área de respostas


def lado_de_cima(cantos: np.ndarray, para_cima: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Lado do quadrilátero (tl, tr, br, bl na foto) cuja normal para fora fica
    mais perto de `para_cima` (vetor na foto). Devolve (ponta esquerda, ponta
    direita) desse lado, vistas com a folha em pé."""
    melhor, nota = 0, -2.0
    for i in range(4):
        d = cantos[(i + 1) % 4] - cantos[i]
        normal = np.array([d[1], -d[0]]) / (np.linalg.norm(d) + 1e-9)
        n = float(np.dot(normal, para_cima))
        if n > nota:
            melhor, nota = i, n
    return cantos[melhor], cantos[(melhor + 1) % 4]


def _girar(imagem: np.ndarray, graus: float) -> tuple[np.ndarray, np.ndarray]:
    """Gira no sentido anti-horário (como se vê na tela), aumentando a tela para
    não cortar nada, como o expo-image-manipulator. Devolve (imagem, matriz 2x3)."""
    h, w = imagem.shape[:2]
    M = cv2.getRotationMatrix2D((w / 2, h / 2), graus, 1.0)
    cos, sen = abs(M[0, 0]), abs(M[0, 1])
    nw, nh = int(round(h * sen + w * cos)), int(round(h * cos + w * sen))
    M[0, 2] += nw / 2 - w / 2
    M[1, 2] += nh / 2 - h / 2
    borda = (255, 255, 255) if imagem.ndim == 3 else 255
    return cv2.warpAffine(imagem, M, (nw, nh), flags=cv2.INTER_LINEAR, borderValue=borda), M


def _caixa(pts: np.ndarray, esq: float, cima: float, dir_: float, baixo: float, forma) -> tuple[int, int, int, int]:
    h, w = forma[:2]
    x0 = max(int(pts[:, 0].min() - esq), 0)
    y0 = max(int(pts[:, 1].min() - cima), 0)
    x1 = min(int(math.ceil(pts[:, 0].max() + dir_)), w)
    y1 = min(int(math.ceil(pts[:, 1].max() + baixo)), h)
    return x0, y0, x1, y1


# Faixa onde fica o QR na folha AMA, em larguras da área (distância entre os
# marcadores de cima), a partir do marcador de cima à esquerda. Na folha, o QR
# vai de -0,47 a -0,38 e o código impresso termina em -0,36; o rótulo "Nome
# do(a) estudante" começa em -0,30. A faixa é o recorte do QR enviado pelo app.
FAIXA_QR = {"esq": -0.05, "dir": 0.25, "cima": -0.56, "baixo": -0.33}


def _mover(pts, M) -> np.ndarray:
    return cv2.transform(np.asarray(pts, dtype="float64").reshape(-1, 1, 2), M).reshape(-1, 2)


def _faixa_do_qr(cantos_girados: np.ndarray, forma) -> tuple[int, int, int, int]:
    """Caixa (x0, y0, x1, y1) da faixa do QR na foto já em pé."""
    c = cantos_girados[np.argsort(cantos_girados[:, 1])]
    topo = c[:2][np.argsort(c[:2, 0])]  # os dois de cima, da esquerda para a direita
    L = float(np.linalg.norm(topo[1] - topo[0]))
    h, w = forma[:2]
    y = float(topo[:, 1].min())
    return (max(int(topo[0, 0] + FAIXA_QR["esq"] * L), 0), max(int(y + FAIXA_QR["cima"] * L), 0),
            min(int(topo[0, 0] + FAIXA_QR["dir"] * L), w), max(int(y + FAIXA_QR["baixo"] * L), 0))


def recortar_para_envio(imagem_bgr: np.ndarray) -> tuple[np.ndarray, np.ndarray | None, str | None]:
    """O que o app faz antes de enviar: (área de respostas, QR ou None, caderno ou None).
    1. Acha os marcadores. 2. Gira a foto para um lado comprido da área ficar em
    cima e nivelado, primeiro o mais perto do alto da foto. 3. Procura o QR só na
    faixa onde ele fica; achou, o lado de cima é esse e a faixa é o recorte do QR.
    Senão, tenta de cabeça para baixo. 4. Sem QR, vale o lado mais perto do alto."""
    cinza = _cinza(imagem_bgr)
    # No celular a folha pode estar mais longe; o tamanho do marcador é conferido no servidor.
    marcadores = localizar_marcadores(normalizar_iluminacao(cinza), AREA_MINIMA_APP)
    if not marcadores:
        raise ErroLeitura("Não encontrei os 4 quadrados pretos dos cantos da área de respostas.", "sem_marcadores")
    cantos, lado, estimado = marcadores
    # O lado comprido cuja normal para fora aponta mais para o alto da foto.
    lados = sorted(range(4), key=lambda i: -np.linalg.norm(cantos[(i + 1) % 4] - cantos[i]))[:2]
    normais = [np.array([d[1], -d[0]]) for d in (cantos[(i + 1) % 4] - cantos[i] for i in lados)]
    para_cima = min(normais, key=lambda n: n[1] / (np.linalg.norm(n) + 1e-9))
    esq, dir_ = lado_de_cima(cantos, para_cima)
    girada, M = _girar(imagem_bgr, math.degrees(math.atan2(dir_[1] - esq[1], dir_[0] - esq[0])))
    c = _mover(cantos, M)

    caderno, qr = None, None
    for tentativa in (0, 1):
        if tentativa:  # de cabeça para baixo
            girada = cv2.rotate(girada, cv2.ROTATE_180)
            h, w = girada.shape[:2]
            c = np.array([w, h]) - c
        x0, y0, x1, y1 = _faixa_do_qr(c, girada.shape)
        if x1 - x0 < 10 or y1 - y0 < 10:
            continue
        faixa = girada[y0:y1, x0:x1]
        caderno = ler_qr_recortado(faixa)
        if caderno:
            qr = faixa
            break
    if not caderno:  # volta para o lado mais perto do alto da foto
        girada = cv2.rotate(girada, cv2.ROTATE_180)
        h, w = girada.shape[:2]
        c = np.array([w, h]) - c

    m = (MARGEM_ESTIMADO if estimado else MARGEM_RECORTE) * lado
    x0, y0, x1, y1 = _caixa(c, m, m, m, m, girada.shape)
    return girada[y0:y1, x0:x1], qr, caderno


def ler_qr_recortado(qr: np.ndarray) -> str | None:
    """Lê o recorte do QR enviado pelo app, com ampliação e nitidez se precisar."""
    cinza = _cinza(qr)
    for reforco in (0, 1.5, 3):
        img = cinza if not reforco else cv2.addWeighted(cinza, 1 + reforco, cv2.GaussianBlur(cinza, (0, 0), 2), -reforco, 0)
        texto, _ = _decodificar_qr(img, (1.0, 2.0, 3.0))
        if texto:
            return texto
    return None


def ler_recorte(area_bgr: np.ndarray, qr_bgr: np.ndarray | None = None, layout: Layout | None = None,
                caderno_do_app: str | None = None) -> dict:
    """Lê o recorte enviado pelo app. O lado de cima já vem em cima. O caderno é o
    do recorte do QR; se ele não for lido aqui, vale o que o app leu na foto."""
    cinza = _cinza(area_bgr)
    normal = normalizar_iluminacao(cinza)
    marcadores = localizar_marcadores(normal)
    # Defesa: a foto inteira (com o nome do aluno) não é aceita no lugar do recorte.
    if marcadores and cv2.contourArea(marcadores[0]) < AREA_MINIMA_RECORTE * cinza.size:
        raise ErroLeitura("A imagem enviada não é o recorte da área de respostas. Atualize o aplicativo.",
                          "nao_recorte")
    if qr_bgr is not None and qr_bgr.shape[0] * qr_bgr.shape[1] > QR_MAXIMO_RECORTE * cinza.size:
        raise ErroLeitura("O recorte do QR code é grande demais. Atualize o aplicativo.", "nao_recorte")
    caderno = (ler_qr_recortado(qr_bgr) if qr_bgr is not None and qr_bgr.size else None) or caderno_do_app
    return _ler_com_qualidade(cinza, normal, marcadores, layout, (caderno,))


def compor_com_qr(anotada: np.ndarray, qr: np.ndarray | None, altura_qr: int = 150) -> np.ndarray:
    """Imagem guardada: o QR (com o código impresso) numa faixa acima da área anotada."""
    if qr is None or not qr.size:
        return anotada
    qr = cv2.cvtColor(qr, cv2.COLOR_GRAY2BGR) if qr.ndim == 2 else qr
    esc = altura_qr / qr.shape[0]
    qr = cv2.resize(qr, (max(int(qr.shape[1] * esc), 1), altura_qr), interpolation=cv2.INTER_AREA)
    folga = 10
    largura = max(anotada.shape[1], qr.shape[1] + 2 * folga)
    saida = np.full((altura_qr + 2 * folga + anotada.shape[0], largura, 3), 255, np.uint8)
    saida[folga:folga + altura_qr, folga:folga + qr.shape[1]] = qr
    saida[altura_qr + 2 * folga:, :anotada.shape[1]] = anotada
    return saida


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
