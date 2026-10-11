"""
Gera folhas de resposta FICTÍCIAS para testar o sistema sem precisar
digitalizar provas.

Dois modelos:
  - PNG/JPG do protótipo (gabarito já preenchido): redesenha as bolinhas e
    simula um scanner. Precisa de --layout.
  - PDF da folha AMA em branco: descobre o formato sozinho, preenche as
    bolinhas a lápis e simula uma FOTO DE CELULAR (perspectiva, sombra,
    desfoque, ruído). Também gera gabarito.jpg com as respostas certas.

Cada aluno fictício tem um "nível" (probabilidade de acertar). O gerador
também simula situações reais: questão em branco, marcação dupla, marcação
fraca (lápis claro), folha torta, ruído e borrão.

Uso:
  python ferramentas/gerar_folhas_teste.py exemplos/gabarito_oficial.png \
      --layout exemplos/layout_linguagens_matematica.json \
      --turma 7A --alunos 12 --saida exemplos/provas

  python ferramentas/gerar_folhas_teste.py exemplos/ama/AMA_2026_M0801_folha_em_branco.pdf \
      --turma 8A --alunos 20 --saida exemplos/ama/geradas_8A
"""
import argparse
import json
import os
import random
import sys

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "worker"))
import omr  # noqa: E402

ESCALA = 2.0  # modelo do protótipo: aumenta a resolução para parecer um scan de ~150 dpi


def posicoes_originais(img, layout=None, escala=1.0):
    """Lê a folha e devolve, por questão, as bolinhas (x, y, r) em coordenadas
    da imagem original multiplicadas por `escala`, além da leitura."""
    res = omr.ler_folha(img, layout)
    inversa = np.linalg.inv(res["_matriz"])
    questoes = []
    for grupo in res["_grade"]:
        pts = np.array([[[b.x, b.y]] for b in grupo] + [[[b.x + b.r, b.y]] for b in grupo], dtype="float64")
        orig = cv2.perspectiveTransform(pts, inversa).reshape(-1, 2)
        centros, bordas = orig[:len(grupo)], orig[len(grupo):]
        questoes.append([(x * escala, y * escala, float(np.linalg.norm(bd - (x, y))) * escala)
                         for (x, y), bd in zip(centros, bordas)])
    return questoes, res


def desenhar_bolinha(img, x, y, r, letra, marca):
    """Modelo do protótipo: redesenha anel + letra e, se for o caso, a marcação."""
    c = (int(round(x)), int(round(y)))
    cv2.circle(img, c, int(r * 1.12), (255, 255, 255), -1)            # apaga
    cv2.circle(img, c, int(r * 0.95), (0, 0, 0), 2, cv2.LINE_AA)       # anel
    fonte, tam = cv2.FONT_HERSHEY_SIMPLEX, r / 22
    (lw, lh), _ = cv2.getTextSize(letra, fonte, tam, 2)
    cv2.putText(img, letra, (c[0] - lw // 2, c[1] + lh // 2), fonte, tam, (0, 0, 0), 2, cv2.LINE_AA)
    marcar(img, x, y, r, marca, cor_cheia=20)


def marcar(img, x, y, r, marca, cor_cheia=None):
    """Pinta a bolinha por cima do que já está impresso (lápis/caneta)."""
    c = (int(round(x)), int(round(y)))
    if marca == "cheia":
        cor = cor_cheia if cor_cheia is not None else random.randint(25, 75)
        dx, dy = random.uniform(-0.12, 0.12) * r, random.uniform(-0.12, 0.12) * r
        eixos = (int(r * random.uniform(0.8, 1.0)), int(r * random.uniform(0.8, 1.0)))
        cv2.ellipse(img, (int(c[0] + dx), int(c[1] + dy)), eixos, random.uniform(0, 180), 0, 360,
                    (cor, cor, cor), -1, cv2.LINE_AA)
    elif marca == "fraca":
        # Lápis claro (escurece sem cobrir): deve ir para revisão
        cor = random.randint(115, 140)
        mascara = np.zeros(img.shape[:2], dtype=np.uint8)
        cv2.circle(mascara, c, int(r * 0.85), 255, -1, cv2.LINE_AA)
        img[mascara > 0] = np.minimum(img[mascara > 0], cor)


def simular_scanner(img, rotacao_max):
    h, w = img.shape[:2]
    ang = random.uniform(-rotacao_max, rotacao_max)
    M = cv2.getRotationMatrix2D((w / 2, h / 2), ang, random.uniform(0.97, 1.0))
    M[:, 2] += [random.uniform(-10, 10), random.uniform(-10, 10)]
    img = cv2.warpAffine(img, M, (w, h), borderValue=(255, 255, 255))
    img = cv2.GaussianBlur(img, (3, 3), random.uniform(0.3, 1.0))
    ruido = np.random.normal(0, random.uniform(3, 9), img.shape)
    brilho = random.uniform(-25, 5)
    return np.clip(img.astype(np.float32) + ruido + brilho, 0, 255).astype(np.uint8)


def simular_foto(img, rotacao_max, invertida=False):
    """Folha sobre uma mesa, fotografada de mão: perspectiva, giro, luz
    desigual com sombra, desfoque, ruído e resolução de celular."""
    if invertida:
        img = cv2.rotate(img, cv2.ROTATE_180)
    h, w = img.shape[:2]
    margem = int(0.08 * max(h, w))
    mesa = np.array([random.randint(90, 170), random.randint(100, 170), random.randint(110, 180)], np.float32)
    H, W = h + 2 * margem, w + 2 * margem

    # Perspectiva: cada canto da folha anda um pouco, mais o giro
    orig = np.float32([[0, 0], [w, 0], [w, h], [0, h]])
    desloc = np.float32([[random.uniform(-1, 1), random.uniform(-1, 1)] for _ in range(4)]) * 0.05 * w
    ang = np.radians(random.uniform(-rotacao_max, rotacao_max))
    centro = np.float32([w / 2, h / 2])
    rot = np.float32([[np.cos(ang), -np.sin(ang)], [np.sin(ang), np.cos(ang)]])
    destino = (orig - centro) @ rot.T + centro + desloc + margem
    M = cv2.getPerspectiveTransform(orig, destino.astype(np.float32))
    fundo = np.empty((H, W, 3), np.float32)
    fundo[:] = mesa
    folha = cv2.warpPerspective(img, M, (W, H), flags=cv2.INTER_LINEAR, borderValue=(0, 0, 0))
    mascara = cv2.warpPerspective(np.full((h, w), 255, np.uint8), M, (W, H))
    foto = np.where(mascara[..., None] > 0, folha.astype(np.float32), fundo)

    # Luz: gradiente numa direção aleatória + uma sombra suave (mão/celular)
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    teta = random.uniform(0, 2 * np.pi)
    grad = (np.cos(teta) * xx / W + np.sin(teta) * yy / H)
    grad = (grad - grad.min()) / (grad.max() - grad.min() + 1e-6)
    luz = 1.0 - random.uniform(0.1, 0.35) * grad
    sx, sy = random.uniform(0, W), random.uniform(0, H)
    raio = random.uniform(0.25, 0.5) * max(W, H)
    luz *= 1.0 - random.uniform(0.0, 0.3) * np.exp(-((xx - sx) ** 2 + (yy - sy) ** 2) / (2 * raio ** 2))
    foto = foto * luz[..., None] * random.uniform(0.85, 1.05)

    foto = cv2.GaussianBlur(foto, (0, 0), random.uniform(0.6, 1.8))
    foto += np.random.normal(0, random.uniform(3, 8), foto.shape)
    foto = np.clip(foto, 0, 255).astype(np.uint8)
    # Resolução de celular: lado maior entre 1800 e 3000 px
    alvo = random.randint(1800, 3000) / max(foto.shape[:2])
    return cv2.resize(foto, None, fx=alvo, fy=alvo, interpolation=cv2.INTER_AREA)


def sortear_respostas(gabarito, alternativas, nivel):
    """Por questão: (marcas {letra: "cheia"|"fraca"}, resposta esperada)."""
    saida = []
    for certa in gabarito:
        sorteio = random.random()
        if sorteio < 0.03:
            saida.append(({}, None))  # em branco
        elif sorteio < 0.05:
            dupla = random.sample(alternativas, 2)
            saida.append(({x: "cheia" for x in dupla}, "MULTIPLA"))
        else:
            escolha = certa if random.random() < nivel else random.choice([x for x in alternativas if x != certa])
            fraca = random.random() < 0.03
            saida.append(({escolha: "fraca" if fraca else "cheia"}, escolha + "?" if fraca else escolha))
    return saida


def gerar_prototipo(a):
    with open(a.layout, encoding="utf-8") as f:
        layout = omr.Layout.de_dict(json.load(f))
    original = cv2.imread(a.gabarito)
    questoes, res = posicoes_originais(original, layout, ESCALA)
    gabarito = [r["resposta"] for r in res["respostas"]]
    base = cv2.resize(original, None, fx=ESCALA, fy=ESCALA, interpolation=cv2.INTER_CUBIC)

    esperado, paginas = {}, []
    for i in range(1, a.alunos + 1):
        folha = base.copy()
        sorteio = sortear_respostas(gabarito, layout.alternativas, random.uniform(0.35, 0.95))
        for bolhas, (marcas, _) in zip(questoes, sorteio):
            for letra, (x, y, r) in zip(layout.alternativas, bolhas):
                desenhar_bolinha(folha, x, y, r, letra, marcas.get(letra))
        folha = simular_scanner(folha, a.rotacao)
        nome = f"{a.turma}_aluno{i:02d}.jpg"
        cv2.imwrite(os.path.join(a.saida, nome), folha, [cv2.IMWRITE_JPEG_QUALITY, 85])
        paginas.append(folha)
        esperado[nome] = [resp for _, resp in sorteio]
    return {"gabarito": gabarito, "folhas": esperado}, paginas


def gerar_ama(a):
    """Folha AMA em branco (PDF) -> gabarito + folhas de alunos fotografadas."""
    branca = omr.carregar_paginas(a.gabarito)[0]
    questoes, res = posicoes_originais(branca)
    layout = omr.Layout.de_dict(res["layout"])
    gabarito = [random.choice(layout.alternativas) for _ in range(layout.questoes)]

    def preencher(sorteio):
        folha = branca.copy()
        for bolhas, (marcas, _) in zip(questoes, sorteio):
            for letra, (x, y, r) in zip(layout.alternativas, bolhas):
                marcar(folha, x, y, r, marcas.get(letra))
        return folha

    # Gabarito oficial: uma marcação firme por questão, foto sem inversão
    gab = simular_foto(preencher([({g: "cheia"}, g) for g in gabarito]), a.rotacao)
    cv2.imwrite(os.path.join(a.saida, "gabarito.jpg"), gab, [cv2.IMWRITE_JPEG_QUALITY, 88])

    esperado, paginas = {}, []
    for i in range(1, a.alunos + 1):
        sorteio = sortear_respostas(gabarito, layout.alternativas, random.uniform(0.35, 0.95))
        foto = simular_foto(preencher(sorteio), a.rotacao, invertida=random.random() < a.invertidas)
        nome = f"{a.turma}_aluno{i:02d}.jpg"
        cv2.imwrite(os.path.join(a.saida, nome), foto, [cv2.IMWRITE_JPEG_QUALITY, random.randint(70, 90)])
        paginas.append(foto)
        esperado[nome] = [resp for _, resp in sorteio]
    return {"caderno": layout.caderno, "layout": layout.para_dict(), "gabarito": gabarito,
            "folhas": esperado}, paginas


def main():
    p = argparse.ArgumentParser()
    p.add_argument("gabarito", help="gabarito preenchido (PNG/JPG do protótipo) ou PDF da folha AMA em branco")
    p.add_argument("--layout", help="obrigatório para o modelo do protótipo; a AMA é detectada sozinha")
    p.add_argument("--turma", default="7A")
    p.add_argument("--alunos", type=int, default=10)
    p.add_argument("--saida", default="exemplos/provas")
    p.add_argument("--semente", type=int, default=42)
    p.add_argument("--rotacao", type=float, default=None,
                   help="inclinação máxima da folha, em graus (padrão: 2.5 no protótipo, 8 na AMA)")
    p.add_argument("--invertidas", type=float, default=0.0,
                   help="AMA: fração de fotos de cabeça para baixo (0 a 1)")
    p.add_argument("--pdf", action="store_true", help="também junta as folhas em um PDF da turma")
    a = p.parse_args()

    random.seed(a.semente)
    np.random.seed(a.semente)
    os.makedirs(a.saida, exist_ok=True)
    ama = a.gabarito.lower().endswith(".pdf")
    if a.rotacao is None:
        a.rotacao = 8.0 if ama else 2.5
    if not ama and not a.layout:
        p.error("--layout é obrigatório para o modelo do protótipo")

    esperado, paginas = gerar_ama(a) if ama else gerar_prototipo(a)
    with open(os.path.join(a.saida, "respostas_esperadas.json"), "w", encoding="utf-8") as f:
        json.dump(esperado, f, ensure_ascii=False, indent=1)

    if a.pdf:
        from PIL import Image
        imgs = [Image.fromarray(cv2.cvtColor(p, cv2.COLOR_BGR2RGB)) for p in paginas]
        caminho = os.path.join(a.saida, f"turma_{a.turma}.pdf")
        imgs[0].save(caminho, save_all=True, append_images=imgs[1:], resolution=150, quality=80)
        print("PDF:", caminho)

    print(f"{a.alunos} folhas geradas em {a.saida}" + (" (+ gabarito.jpg)" if ama else ""))
    print("Legenda em respostas_esperadas.json: 'X?' = marcação fraca (deve ir para revisão), "
          "MULTIPLA = marcação dupla, null = em branco")


if __name__ == "__main__":
    main()
