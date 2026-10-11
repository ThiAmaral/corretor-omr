"""
Gera folhas de resposta FICTÍCIAS a partir da imagem real do gabarito, para
testar o sistema sem precisar digitalizar provas.

Cada aluno fictício tem um "nível" (probabilidade de acertar). O gerador
também simula situações reais: questão em branco, marcação dupla, marcação
fraca (lápis claro), folha levemente torta, ruído e borrão de scanner.

Uso:
  python ferramentas/gerar_folhas_teste.py exemplos/gabarito_oficial.png \
      --layout exemplos/layout_linguagens_matematica.json \
      --turma 7A --alunos 12 --saida exemplos/provas
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

ESCALA = 2.0  # aumenta a resolução para parecer um scan de ~150 dpi


def posicoes_originais(img, layout):
    """Lê o gabarito e devolve, por questão, as bolinhas em coordenadas da
    imagem original (já multiplicadas por ESCALA)."""
    res = omr.ler_folha(img, layout)
    inversa = np.linalg.inv(res["_matriz"])
    questoes = []
    for grupo in res["_grade"]:
        pts = np.array([[[b.x, b.y]] for b in grupo], dtype="float64")
        orig = cv2.perspectiveTransform(pts, inversa).reshape(-1, 2)
        escala_r = 1 / abs(res["_matriz"][0, 0])
        questoes.append([(x * ESCALA, y * ESCALA, b.r * escala_r * ESCALA) for (x, y), b in zip(orig, grupo)])
    gabarito = [r["resposta"] for r in res["respostas"]]
    return questoes, gabarito


def desenhar_bolinha(img, x, y, r, letra, marca):
    c = (int(round(x)), int(round(y)))
    cv2.circle(img, c, int(r * 1.12), (255, 255, 255), -1)            # apaga
    cv2.circle(img, c, int(r * 0.95), (0, 0, 0), 2, cv2.LINE_AA)       # anel
    fonte, tam = cv2.FONT_HERSHEY_SIMPLEX, r / 22
    (lw, lh), _ = cv2.getTextSize(letra, fonte, tam, 2)
    cv2.putText(img, letra, (c[0] - lw // 2, c[1] + lh // 2), fonte, tam, (0, 0, 0), 2, cv2.LINE_AA)
    if marca == "cheia":
        dx, dy = random.uniform(-0.12, 0.12) * r, random.uniform(-0.12, 0.12) * r
        cv2.circle(img, (int(c[0] + dx), int(c[1] + dy)), int(r * random.uniform(0.8, 0.98)), (20, 20, 20), -1, cv2.LINE_AA)
    elif marca == "fraca":
        # Lápis claro por cima da letra (escurece sem apagar): deve ir para revisão
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


def main():
    p = argparse.ArgumentParser()
    p.add_argument("gabarito")
    p.add_argument("--layout", required=True)
    p.add_argument("--turma", default="7A")
    p.add_argument("--alunos", type=int, default=10)
    p.add_argument("--saida", default="exemplos/provas")
    p.add_argument("--semente", type=int, default=42)
    p.add_argument("--rotacao", type=float, default=2.5, help="inclinação máxima da folha, em graus")
    p.add_argument("--pdf", action="store_true", help="também junta as folhas em um PDF da turma")
    a = p.parse_args()

    random.seed(a.semente)
    np.random.seed(a.semente)
    with open(a.layout, encoding="utf-8") as f:
        layout = omr.Layout.de_dict(json.load(f))

    original = cv2.imread(a.gabarito)
    questoes, gabarito = posicoes_originais(original, layout)
    base = cv2.resize(original, None, fx=ESCALA, fy=ESCALA, interpolation=cv2.INTER_CUBIC)
    os.makedirs(a.saida, exist_ok=True)

    esperado, paginas = {}, []
    for i in range(1, a.alunos + 1):
        nivel = random.uniform(0.35, 0.95)
        folha = base.copy()
        respostas = []
        for q, bolhas in enumerate(questoes):
            sorteio = random.random()
            if sorteio < 0.03:
                marcas, resp = {}, None  # em branco
            elif sorteio < 0.05:
                dupla = random.sample(layout.alternativas, 2)
                marcas, resp = {x: "cheia" for x in dupla}, "MULTIPLA"
            else:
                certa = gabarito[q]
                escolha = certa if random.random() < nivel else random.choice(
                    [x for x in layout.alternativas if x != certa])
                fraca = random.random() < 0.03
                marcas, resp = {escolha: "fraca" if fraca else "cheia"}, (escolha + "?" if fraca else escolha)
            for letra, (x, y, r) in zip(layout.alternativas, bolhas):
                desenhar_bolinha(folha, x, y, r, letra, marcas.get(letra))
            respostas.append(resp)

        folha = simular_scanner(folha, a.rotacao)
        nome = f"{a.turma}_aluno{i:02d}.jpg"
        cv2.imwrite(os.path.join(a.saida, nome), folha, [cv2.IMWRITE_JPEG_QUALITY, 85])
        paginas.append(folha)
        esperado[nome] = respostas

    with open(os.path.join(a.saida, "respostas_esperadas.json"), "w", encoding="utf-8") as f:
        json.dump({"gabarito": gabarito, "folhas": esperado}, f, ensure_ascii=False, indent=1)

    if a.pdf:
        from PIL import Image
        imgs = [Image.fromarray(cv2.cvtColor(p, cv2.COLOR_BGR2RGB)) for p in paginas]
        caminho = os.path.join(a.saida, f"turma_{a.turma}.pdf")
        imgs[0].save(caminho, save_all=True, append_images=imgs[1:], resolution=150, quality=80)
        print("PDF:", caminho)

    print(f"{a.alunos} folhas geradas em {a.saida}")
    print("Legenda em respostas_esperadas.json: 'X?' = marcação fraca (deve ir para revisão), "
          "MULTIPLA = marcação dupla, null = em branco")


if __name__ == "__main__":
    main()
