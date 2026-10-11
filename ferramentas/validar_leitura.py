"""
Mede a precisão do leitor comparando com respostas_esperadas.json
(gerado por gerar_folhas_teste.py ou preenchido à mão para fotos reais).

  python ferramentas/validar_leitura.py exemplos/provas_7A --layout exemplos/layout_linguagens_matematica.json
  python ferramentas/validar_leitura.py exemplos/ama/geradas_8A

Sem --layout, o leitor descobre o formato sozinho. Se o JSON tiver "caderno"
e "layout", mede também se o caderno (QR) e os blocos foram detectados certo.
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "worker"))
import omr  # noqa: E402

p = argparse.ArgumentParser()
p.add_argument("pasta")
p.add_argument("--layout", help="layout da prova; sem ele, o formato é detectado")
a = p.parse_args()

layout = omr.Layout.de_dict(json.load(open(a.layout, encoding="utf-8"))) if a.layout else None
dados = json.load(open(os.path.join(a.pasta, "respostas_esperadas.json"), encoding="utf-8"))
esperado = dados["folhas"]
caderno_esp = dados.get("caderno")
blocos_esp = (dados.get("layout") or {}).get("blocos")

certas = erradas = fracas_revisadas = fracas_total = 0
ilegiveis = caderno_ok = layout_ok = confianca_alta = 0
for nome, resp_esperadas in esperado.items():
    try:
        leitura = omr.ler_folha(omr.carregar_paginas(os.path.join(a.pasta, nome))[0], layout)
    except omr.ErroLeitura as e:
        ilegiveis += 1
        erradas += len(resp_esperadas)
        print(f"  {nome}: NÃO LIDA ({e})")
        continue
    caderno_ok += leitura["caderno"] == caderno_esp
    layout_ok += blocos_esp is None or leitura["layout"]["blocos"] == blocos_esp
    confianca_alta += leitura["confianca"]["nivel"] == "alta"
    if caderno_esp and leitura["caderno"] != caderno_esp:
        print(f"  {nome}: caderno esperado {caderno_esp}, lido {leitura['caderno']}")
    if blocos_esp and leitura["layout"]["blocos"] != blocos_esp:
        print(f"  {nome}: blocos esperados {blocos_esp}, lidos {leitura['layout']['blocos']}")
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

n = len(esperado)
total = certas + erradas
print(f"Folhas lidas: {n - ilegiveis}/{n}")
if caderno_esp:
    print(f"Caderno (QR) correto: {caderno_ok}/{n}")
if blocos_esp:
    print(f"Layout (blocos) correto: {layout_ok}/{n}")
print(f"Confiança alta: {confianca_alta}/{n}")
print(f"Leituras corretas: {certas}/{total} ({100 * certas / total:.1f}%)")
print(f"Marcações fracas enviadas para revisão: {fracas_revisadas}/{fracas_total}")
