"""
Linha de comando do leitor óptico.

  detectar  Descobre o formato da folha (caderno, blocos, alternativas) e imprime JSON.
  ler       Lê folhas (PNG/JPG/PDF) e imprime JSON. É o que o backend PHP chama.
            Sem --layout, cada folha tem o formato detectado sozinho.
  corrigir  Teste rápido sem o PHP: lê gabarito + provas e imprime a taxa de acerto.
            Sem --layout, usa o formato (e o caderno) detectado no gabarito.

Exemplos:
  python worker/omr_cli.py detectar exemplos/ama/geradas_8A/gabarito.jpg
  python worker/omr_cli.py ler exemplos/provas_7A/turma_7A.pdf --layout exemplos/layout_linguagens_matematica.json
  python worker/omr_cli.py corrigir --gabarito exemplos/ama/geradas_8A/gabarito.jpg       --provas exemplos/ama/geradas_8A --turma 8A
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


def recortar_bolinhas(img, grade):
    """Só a região das bolinhas, com uma margem. No modelo antigo a área da
    moldura inclui nome, nascimento e assinatura; isso não pode ser guardado (LGPD)."""
    bolhas = [b for grupo in grade for b in grupo]
    r = max(b.r for b in bolhas)
    x0 = max(int(min(b.x for b in bolhas) - 7 * r), 0)  # inclui o número da questão
    y0 = max(int(min(b.y for b in bolhas) - 3 * r), 0)
    x1 = int(max(b.x for b in bolhas) + 6 * r)  # espaço para o rótulo da resposta lida
    y1 = int(max(b.y for b in bolhas) + 3 * r)
    return img[y0:y1, x0:x1]


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
                    # JPEG: cerca de 70 KB, contra 600 KB em PNG (cabe no Storage gratuito).
                    destino = os.path.join(pasta_debug, f"{base}_p{n}.jpg")
                    anotada = recortar_bolinhas(omr.desenhar_depuracao(res, layout), res["_grade"])
                    cv2.imwrite(destino, anotada, [cv2.IMWRITE_JPEG_QUALITY, 80])
                    item["imagem_debug"] = destino
                    # Só a área de respostas, sem o cabeçalho com o nome do aluno (LGPD).
                    recorte = os.path.join(pasta_debug, f"{base}_p{n}_area.jpg")
                    cv2.imwrite(recorte, recortar_bolinhas(res["_folha"], res["_grade"]),
                                [cv2.IMWRITE_JPEG_QUALITY, 85])
                    item["imagem_recorte"] = recorte
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


def carregar_layout(caminho):
    if not caminho:
        return {}, None
    cfg = json.load(open(caminho, encoding="utf-8"))
    return cfg, omr.Layout.de_dict(cfg)


def cmd_detectar(a):
    saida = []
    for item in ler_arquivos(expandir(a.arquivos), None, a.debug):
        if item["ok"] and not a.respostas:
            item.pop("respostas")
        saida.append(item)
    json.dump(saida, sys.stdout, ensure_ascii=False, indent=1)


def cmd_ler(a):
    _, layout = carregar_layout(a.layout)
    json.dump(ler_arquivos(expandir(a.arquivos), layout, a.debug), sys.stdout, ensure_ascii=False)


def cmd_corrigir(a):
    cfg, layout = carregar_layout(a.layout)

    gab = ler_arquivos([a.gabarito], layout, a.debug)[0]
    if not gab["ok"]:
        sys.exit(f"Gabarito ilegível: {gab['erro']}")
    if layout is None:  # formato e caderno detectados no gabarito valem para as folhas
        layout = omr.Layout.de_dict(gab["layout"])
        print(f"Formato detectado: caderno {layout.caderno or '-'}, blocos {layout.blocos}, "
              f"alternativas {''.join(layout.alternativas)}")
    areas = areas_do_layout(cfg, layout.questoes)
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
    # O PHP lê a saída como UTF-8; no Windows o padrão do console seria outro.
    sys.stdout.reconfigure(encoding="utf-8")
    p = argparse.ArgumentParser(description="Leitor óptico de folhas de resposta")
    sub = p.add_subparsers(dest="comando", required=True)

    pd = sub.add_parser("detectar")
    pd.add_argument("arquivos", nargs="+")
    pd.add_argument("--respostas", action="store_true", help="inclui também a leitura das bolinhas")
    pd.add_argument("--debug", help="pasta para salvar imagens anotadas")
    pd.set_defaults(func=cmd_detectar)

    pl = sub.add_parser("ler")
    pl.add_argument("arquivos", nargs="+")
    pl.add_argument("--layout", help="layout da prova; sem ele, o formato é detectado")
    pl.add_argument("--debug", help="pasta para salvar imagens anotadas")
    pl.set_defaults(func=cmd_ler)

    pc = sub.add_parser("corrigir")
    pc.add_argument("--gabarito", required=True)
    pc.add_argument("--provas", nargs="+", required=True)
    pc.add_argument("--turma", default="sem turma")
    pc.add_argument("--layout", help="sem ele, usa o formato detectado no gabarito")
    pc.add_argument("--debug")
    pc.set_defaults(func=cmd_corrigir)

    a = p.parse_args()
    a.func(a)


if __name__ == "__main__":
    main()
