"""
Mede a precisão do leitor comparando com respostas_esperadas.json
(gerado por gerar_folhas_teste.py).

  python ferramentas/validar_leitura.py exemplos/provas_7A --layout exemplos/layout_linguagens_matematica.json
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "worker"))
import omr  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument("pasta")
p.add_argument("--layout", required=True)
a = p.parse_args()

layout = omr.Layout.de_dict(json.load(open(a.layout, encoding="utf-8")))
esperado = json.load(open(os.path.join(a.pasta, "respostas_esperadas.json"), encoding="utf-8"))["folhas"]

certas = erradas = fracas_revisadas = fracas_total = 0
for nome, resp_esperadas in esperado.items():
    leitura = omr.ler_folha(omr.carregar_paginas(os.path.join(a.pasta, nome))[0], layout)
    for esp, lida in zip(resp_esperadas, leitura["respostas"]):
        if esp and esp.endswith("?"):  # marcação fraca: o certo é mandar para revisão
            fracas_total += 1
            fracas_revisadas += lida["duvidosa"]
            continue
        obtido = {"marcada": lida["resposta"], "branco": None, "multipla": "MULTIPLA"}[lida["situacao"]]
        if obtido == esp and not lida["duvidosa"]:
            certas += 1
        else:
            erradas += 1
            print(f"  {nome} Q{lida['questao']}: esperado {esp}, lido {obtido}, níveis {lida['niveis']}")

total = certas + erradas
print(f"Leituras corretas: {certas}/{total} ({100 * certas / total:.1f}%)")
print(f"Marcações fracas enviadas para revisão: {fracas_revisadas}/{fracas_total}")
