"""
Linha de comando do leitor óptico.

  ler       Lê folhas (PNG/JPG/PDF) e imprime JSON. É o que o backend PHP chama.
  corrigir  Teste rápido sem o PHP: lê gabarito + provas e imprime a taxa de acerto.

Exemplos:
  python worker/omr_cli.py ler exemplos/provas_7A/turma_7A.pdf --layout exemplos/layout_linguagens_matematica.json
  python worker/omr_cli.py corrigir --gabarito exemplos/gabarito_oficial.png \
      --provas exemplos/provas_7A --turma 7A --layout exemplos/layout_linguagens_matematica.json
"""
import argparse
import glob
import json
import os
import sys

import cv2

sys.path.insert(0, os.path.dirname(__file__))
import omr  # noqa: E402

EXTENSOES = (".png", ".jpg", ".jpeg", ".tif", ".tiff", ".bmp", ".pdf")


def expandir(caminhos):
    arquivos = []
    for c in caminhos:
        if os.path.isdir(c):
            arquivos += sorted(f for f in glob.glob(os.path.join(c, "*")) if f.lower().endswith(EXTENSOES))
        else:
            arquivos.append(c)
    return arquivos


def ler_arquivos(arquivos, layout, pasta_debug=None):
    saida = []
    for arq in arquivos:
        try:
            paginas = omr.carregar_paginas(arq)
        except omr.ErroLeitura as e:
            saida.append({"arquivo": arq, "pagina": 1, "ok": False, "erro": str(e)})
            continue
        for n, img in enumerate(paginas, start=1):
            item = {"arquivo": arq, "pagina": n}
            try:
                res = omr.ler_folha(img, layout)
                item.update(ok=True, **omr.resultado_publico(res))
                if pasta_debug:
                    os.makedirs(pasta_debug, exist_ok=True)
                    base = os.path.splitext(os.path.basename(arq))[0]
                    destino = os.path.join(pasta_debug, f"{base}_p{n}.png")
                    cv2.imwrite(destino, omr.desenhar_depuracao(res, layout))
                    item["imagem_debug"] = destino
            except omr.ErroLeitura as e:
                item.update(ok=False, erro=str(e))
            saida.append(item)
    return saida


def areas_do_layout(cfg, total):
    areas = cfg.get("areas") or [{"nome": "Geral", "de": 1, "ate": total}]
    return [(a["nome"], int(a["de"]), int(a["ate"])) for a in areas]


def corrigir(gabarito, leitura, areas):
    """Mesma regra usada no PHP: só conta acerto a marcação única igual ao gabarito."""
    por_questao = []
    for g, r in zip(gabarito, leitura["respostas"]):
        por_questao.append(r["situacao"] == "marcada" and r["resposta"] == g)
    resumo_areas = {}
    for nome, de, ate in areas:
        trecho = por_questao[de - 1:ate]
        resumo_areas[nome] = (sum(trecho), len(trecho))
    return sum(por_questao), por_questao, resumo_areas


def pct(a, b):
    return f"{100 * a / b:5.1f}%" if b else "   - "


def cmd_ler(a):
    cfg = json.load(open(a.layout, encoding="utf-8"))
    layout = omr.Layout.de_dict(cfg)
    json.dump(ler_arquivos(expandir(a.arquivos), layout, a.debug), sys.stdout, ensure_ascii=False)


def cmd_corrigir(a):
    cfg = json.load(open(a.layout, encoding="utf-8"))
    layout = omr.Layout.de_dict(cfg)
    areas = areas_do_layout(cfg, layout.questoes)

    gab = ler_arquivos([a.gabarito], layout, a.debug)[0]
    if not gab["ok"]:
        sys.exit(f"Gabarito ilegível: {gab['erro']}")
    problemas = [r["questao"] for r in gab["respostas"] if r["situacao"] != "marcada"]
    if problemas:
        sys.exit(f"O gabarito precisa ter exatamente uma marcação por questão. Verifique: {problemas}")
    gabarito = [r["resposta"] for r in gab["respostas"]]
    print(f"Gabarito: {''.join(gabarito)}\n")

    folhas = ler_arquivos(expandir(a.provas), layout, a.debug)
    acertos_q = [0] * layout.questoes
    soma_areas = {n: [0, 0] for n, _, _ in areas}
    total_ac = total_q = lidas = 0

    print(f"Turma {a.turma}")
    print(f"{'Folha':32} {'Acertos':>8} {'Taxa':>7}  {'Revisar'}")
    for f in folhas:
        nome = f"{os.path.basename(f['arquivo'])} p{f['pagina']}"
        if not f["ok"]:
            print(f"{nome:32} {'ERRO':>8}          {f['erro']}")
            continue
        lidas += 1
        ac, por_q, res_areas = corrigir(gabarito, f, areas)
        for i, ok in enumerate(por_q):
            acertos_q[i] += ok
        for n, (x, y) in res_areas.items():
            soma_areas[n][0] += x
            soma_areas[n][1] += y
        total_ac += ac
        total_q += layout.questoes
        revisar = [str(r["questao"]) for r in f["respostas"] if r["duvidosa"] or r["situacao"] == "multipla"]
        print(f"{nome:32} {ac:>4}/{layout.questoes:<3} {pct(ac, layout.questoes)}  {', '.join(revisar)}")

    print(f"\nTaxa de acerto da turma: {pct(total_ac, total_q)}  ({lidas} folhas lidas)")
    print("Por área:")
    for n, (x, y) in soma_areas.items():
        print(f"  {n:20} {pct(x, y)}")
    print("Por questão:")
    for i, ac in enumerate(acertos_q, start=1):
        barra = "#" * round(20 * ac / lidas) if lidas else ""
        print(f"  Q{i:<3} {pct(ac, lidas)}  {barra}")


def main():
    p = argparse.ArgumentParser(description="Leitor óptico de folhas de resposta")
    sub = p.add_subparsers(dest="comando", required=True)

    pl = sub.add_parser("ler")
    pl.add_argument("arquivos", nargs="+")
    pl.add_argument("--layout", required=True)
    pl.add_argument("--debug", help="pasta para salvar imagens anotadas")
    pl.set_defaults(func=cmd_ler)

    pc = sub.add_parser("corrigir")
    pc.add_argument("--gabarito", required=True)
    pc.add_argument("--provas", nargs="+", required=True)
    pc.add_argument("--turma", default="sem turma")
    pc.add_argument("--layout", required=True)
    pc.add_argument("--debug")
    pc.set_defaults(func=cmd_corrigir)

    a = p.parse_args()
    a.func(a)


if __name__ == "__main__":
    main()
