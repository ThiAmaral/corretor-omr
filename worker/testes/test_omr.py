"""
Casos de borda do leitor óptico. Rodar na raiz do projeto:

  python -m pytest worker/testes -q

Usa as fotos geradas em exemplos/ama/geradas_8A (folha AMA, caderno 2265M0801)
e as folhas do modelo antigo em exemplos/provas_7A. Os testes que precisam da
folha AMA em branco renderizam o PDF; sem o Poppler instalado, eles são pulados.
"""
import json
import os
import sys

import cv2
import numpy as np
import pytest

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(RAIZ, "worker"))
sys.path.insert(0, os.path.join(RAIZ, "ferramentas"))
import omr  # noqa: E402

AMA = os.path.join(RAIZ, "exemplos", "ama", "geradas_8A")
PDF_BRANCO = os.path.join(RAIZ, "exemplos", "ama", "AMA_2026_M0801_folha_em_branco.pdf")
CADERNO = "2265M0801"


def esperado_ama():
    with open(os.path.join(AMA, "respostas_esperadas.json"), encoding="utf-8") as f:
        return json.load(f)


@pytest.fixture(scope="module")
def gabarito_ama():
    return cv2.imread(os.path.join(AMA, "gabarito.jpg"))


@pytest.fixture(scope="module")
def folha_branca():
    try:
        return omr.carregar_paginas(PDF_BRANCO)[0]
    except Exception as e:  # pdf2image sem Poppler
        pytest.skip(f"não foi possível renderizar o PDF: {e}")


def respostas(leitura):
    return [r["resposta"] for r in leitura["respostas"]]


def apagar_regiao(img, x, y, raio):
    img = img.copy()
    cv2.circle(img, (int(x), int(y)), int(raio), (255, 255, 255), -1)
    return img


def apagar_qr(img):
    """Cobre o QR. Na AMA ele fica numa posição fixa em relação aos marcadores:
    centro a 10,6% da largura da área e 1,06 altura acima dos marcadores de cima."""
    cinza = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    cantos, _, _ = omr.localizar_marcadores(omr.normalizar_iluminacao(cinza))
    M, W, H, larg = omr._transformacao(cantos)
    centro = cv2.perspectiveTransform(np.array([[[0.106 * W, -1.06 * H]]]), np.linalg.inv(M)).ravel()
    return apagar_regiao(img, *centro, 0.09 * larg)


# ---------------------------------------------------------------- Layout

def test_blocos_iguais_aceita_divisao_desigual():
    assert omr.blocos_iguais(20, 2) == [10, 10]
    assert omr.blocos_iguais(26, 4) == [7, 7, 7, 5]


def test_layout_antigo_vira_blocos():
    lay = omr.Layout.de_dict({"questoes": 26, "colunas": 4, "alternativas": "A,B,C,D"})
    assert lay.blocos == [7, 7, 7, 5]
    assert lay.questoes == 26
    assert lay.caderno is None


def test_layout_novo_confere_total():
    lay = omr.Layout.de_dict({"caderno": CADERNO, "blocos": [7, 7, 7, 5], "questoes": 26})
    assert lay.para_dict() == {"caderno": CADERNO, "alternativas": ["A", "B", "C", "D"],
                               "blocos": [7, 7, 7, 5], "questoes": 26}
    with pytest.raises(ValueError):
        omr.Layout.de_dict({"blocos": [7, 7, 7, 5], "questoes": 28})


# ---------------------------------------------------------------- Folha AMA

def test_gabarito_ama_detecta_formato_e_respostas(gabarito_ama):
    leitura = omr.ler_folha(gabarito_ama)
    assert leitura["caderno"] == CADERNO
    assert leitura["marcadores_encontrados"]
    assert leitura["layout"]["blocos"] == [7, 7, 7, 5]
    assert leitura["layout"]["alternativas"] == ["A", "B", "C", "D"]
    assert leitura["confianca"]["nivel"] == "alta"
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_folha_de_cabeca_para_baixo(gabarito_ama):
    leitura = omr.ler_folha(cv2.rotate(gabarito_ama, cv2.ROTATE_180))
    assert leitura["girada"]
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_folha_deitada(gabarito_ama):
    leitura = omr.ler_folha(cv2.rotate(gabarito_ama, cv2.ROTATE_90_CLOCKWISE))
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_qr_ilegivel_le_com_confianca_baixa(gabarito_ama):
    leitura = omr.ler_folha(apagar_qr(gabarito_ama))
    assert leitura["caderno"] is None
    assert leitura["confianca"]["nivel"] == "baixa"
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_qr_ilegivel_e_de_cabeca_para_baixo(gabarito_ama):
    """Sem QR, o lado de cima é deduzido pelos blocos (7, 7, 7, 5)."""
    leitura = omr.ler_folha(cv2.rotate(apagar_qr(gabarito_ama), cv2.ROTATE_180))
    assert leitura["girada"]
    assert leitura["layout"]["blocos"] == [7, 7, 7, 5]
    assert respostas(leitura) == esperado_ama()["gabarito"]


@pytest.mark.parametrize("canto", range(4))
def test_sem_um_marcador(gabarito_ama, canto):
    cinza = cv2.cvtColor(gabarito_ama, cv2.COLOR_BGR2GRAY)
    cantos, lado, estimado = omr.localizar_marcadores(omr.normalizar_iluminacao(cinza))
    assert not estimado
    leitura = omr.ler_folha(apagar_regiao(gabarito_ama, *cantos[canto], lado * 1.5))
    assert leitura["marcadores_encontrados"]
    assert leitura["confianca"]["nivel"] == "baixa"
    assert any("marcadores" in m for m in leitura["confianca"]["motivos"])
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_caderno_diferente_e_recusado(gabarito_ama):
    layout = omr.Layout.de_dict({"caderno": "2265L0801", "blocos": [7, 7, 7, 5]})
    with pytest.raises(omr.ErroLeitura, match="caderno 2265M0801.*caderno 2265L0801"):
        omr.ler_folha(gabarito_ama, layout)


def test_layout_diferente_e_recusado(gabarito_ama):
    layout = omr.Layout.de_dict({"questoes": 26, "colunas": 2})  # 13 + 13
    with pytest.raises(omr.ErroLeitura, match="Esperava blocos"):
        omr.ler_folha(gabarito_ama, layout)


def test_imagem_sem_folha():
    papel = np.full((1200, 900, 3), 255, np.uint8)
    with pytest.raises(omr.ErroLeitura):
        omr.ler_folha(papel)


def test_folha_em_branco(folha_branca):
    leitura = omr.ler_folha(folha_branca)
    assert leitura["caderno"] == CADERNO
    assert leitura["layout"]["blocos"] == [7, 7, 7, 5]
    assert all(r["situacao"] == "branco" for r in leitura["respostas"])
    assert not leitura["duvidosas"]


def test_folha_toda_marcada(folha_branca):
    from gerar_folhas_teste import marcar, posicoes_originais

    questoes, _ = posicoes_originais(folha_branca)
    folha = folha_branca.copy()
    for bolhas in questoes:
        for x, y, r in bolhas:
            marcar(folha, x, y, r, "cheia", cor_cheia=40)
    leitura = omr.ler_folha(folha)
    assert all(r["situacao"] == "multipla" for r in leitura["respostas"])


# ---------------------------------------------------------------- Fotos de celular (Fase 5)

def test_foto_boa_sem_problema_de_qualidade(gabarito_ama):
    q = omr.ler_folha(gabarito_ama)["qualidade"]
    assert q["problema"] is None
    assert q["medida_nos_marcadores"]
    assert q["desfoque"] < omr.DESFOQUE_MAXIMO
    assert q["lado_marcador"] >= omr.LADO_BOM


def test_foto_desfocada_nao_confunde_o_qr_com_marcador(gabarito_ama):
    """Borrados, os "olhos" do QR viram quadrados cheios parecidos com os marcadores."""
    cinza = cv2.cvtColor(gabarito_ama, cv2.COLOR_BGR2GRAY)
    certos, lado, _ = omr.localizar_marcadores(omr.normalizar_iluminacao(cinza))
    borrada = cv2.GaussianBlur(gabarito_ama, (0, 0), 2.5)
    cantos, _, _ = omr.localizar_marcadores(omr.normalizar_iluminacao(cv2.cvtColor(borrada, cv2.COLOR_BGR2GRAY)))
    assert np.abs(cantos - certos).max() < lado
    assert respostas(omr.ler_folha(borrada)) == esperado_ama()["gabarito"]


def test_foto_muito_desfocada_pede_outra_foto(gabarito_ama):
    with pytest.raises(omr.ErroLeitura, match="tremida ou fora de foco") as erro:
        omr.ler_folha(cv2.GaussianBlur(gabarito_ama, (0, 0), 7))
    assert erro.value.codigo == "desfocada"


def test_foto_pequena_demais_e_recusada(gabarito_ama):
    pequena = cv2.resize(gabarito_ama, None, fx=0.25, fy=0.25, interpolation=cv2.INTER_AREA)
    with pytest.raises(omr.ErroLeitura, match="pouca resolução") as erro:
        omr.ler_folha(pequena)
    assert erro.value.codigo == "pouca_resolucao"


def test_foto_escura_e_recusada(gabarito_ama):
    escura = (gabarito_ama.astype(np.float32) * 0.15).astype(np.uint8)
    with pytest.raises(omr.ErroLeitura, match="escura") as erro:
        omr.ler_folha(escura)
    assert erro.value.codigo == "escura"


def test_papel_curvado(gabarito_ama):
    import random
    from gerar_folhas_teste import curvar_papel

    random.seed(3)
    leitura = omr.ler_folha(curvar_papel(gabarito_ama, 0.015))
    assert leitura["layout"]["blocos"] == [7, 7, 7, 5]
    assert respostas(leitura) == esperado_ama()["gabarito"]


def test_linha_sem_bolinhas_achadas_e_recolocada(gabarito_ama):
    """Sem nenhuma bolinha achada na questão 24, a linha volta pelo espaço entre
    a 23 e a 25; as questões seguintes não mudam de número."""
    leitura = omr.ler_folha(gabarito_ama)
    h, w = gabarito_ama.shape[:2]
    img = gabarito_ama
    for x, y in leitura["respostas"][23]["bolinhas"]:
        img = apagar_regiao(img, x * w, y * h, 1.4 * leitura["raio_bolinha"] * w)
    leitura = omr.ler_folha(img)
    assert leitura["layout"]["blocos"] == [7, 7, 7, 5]
    esperado = esperado_ama()["gabarito"]
    assert respostas(leitura) == esperado[:23] + [None] + esperado[24:]
    assert leitura["confianca"]["nivel"] == "baixa"  # 4 bolinhas estimadas


@pytest.mark.parametrize("girar", [False, True])
def test_posicoes_das_bolinhas_na_foto(gabarito_ama, girar):
    """As coordenadas (0 a 1) apontam para as bolinhas na foto recebida, mesmo
    de cabeça para baixo: a marcada é escura e as outras, claras no centro."""
    img = cv2.rotate(gabarito_ama, cv2.ROTATE_180) if girar else gabarito_ama
    cinza = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    h, w = cinza.shape
    leitura = omr.ler_folha(img)
    assert 0 < leitura["raio_bolinha"] < 0.05
    for r in leitura["respostas"]:
        assert len(r["bolinhas"]) == 4
        for letra, (x, y) in zip("ABCD", r["bolinhas"]):
            assert 0 <= x <= 1 and 0 <= y <= 1
            tom = cinza[int(y * h), int(x * w)]
            assert (tom < 110) if letra == r["resposta"] else (tom > 110)


# ---------------------------------------------------------------- Modelo antigo

def test_modelo_antigo_continua_funcionando():
    pasta = os.path.join(RAIZ, "exemplos", "provas_7A")
    with open(os.path.join(RAIZ, "exemplos", "layout_linguagens_matematica.json"), encoding="utf-8") as f:
        layout = omr.Layout.de_dict(json.load(f))
    with open(os.path.join(pasta, "respostas_esperadas.json"), encoding="utf-8") as f:
        esperado = json.load(f)["folhas"]["7A_aluno01.jpg"]
    leitura = omr.ler_folha(cv2.imread(os.path.join(pasta, "7A_aluno01.jpg")), layout)
    assert not leitura["marcadores_encontrados"]
    assert leitura["layout"]["blocos"] == [10, 10]
    obtido = [{"marcada": r["resposta"], "branco": None, "multipla": "MULTIPLA"}[r["situacao"]]
              for r in leitura["respostas"]]
    assert obtido == [e if not (e and e.endswith("?")) else o for e, o in zip(esperado, obtido)]
