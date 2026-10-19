/*
 * Os 4 quadrados pretos dos cantos da área de respostas (folha AMA), achados no próprio
 * celular para recortar a folha antes do envio: a foto inteira, com o nome do aluno, não
 * sai do aparelho. Mesmas regras de worker/omr.py (localizar_marcadores, _faixa_do_qr),
 * sem dependência do React Native, para rodar também nos testes com Node.
 */

export type Ponto = { x: number; y: number };
/** Cantos em ordem: cima-esquerda, cima-direita, baixo-direita, baixo-esquerda (na imagem). */
export type Marcadores = { cantos: [Ponto, Ponto, Ponto, Ponto]; lado: number; estimado: boolean };
export type Caixa = { x0: number; y0: number; x1: number; y1: number };
export type ImagemCinza = { largura: number; altura: number; dados: Uint8Array };

/** Folga do recorte em volta dos centros dos marcadores, em lados de marcador. */
export const MARGEM_RECORTE = 1.2;
export const MARGEM_ESTIMADO = 2.5;
/**
 * Faixa do QR, em larguras da área a partir do marcador de cima à esquerda (a folha em pé).
 * O QR vai de -0,47 a -0,38, o código impresso termina em -0,36 e o rótulo "Nome do(a)
 * estudante" começa em -0,30: a faixa termina antes do nome. Ela é o recorte do QR enviado.
 */
export const FAIXA_QR = { esq: -0.05, dir: 0.25, cima: -0.56, baixo: -0.33 };

/** RGBA (jpeg-js) para tons de cinza, com o mesmo peso do OpenCV. */
export function paraCinza(rgba: Uint8Array, largura: number, altura: number): ImagemCinza {
  const dados = new Uint8Array(largura * altura);
  for (let i = 0, j = 0; i < dados.length; i++, j += 4) {
    dados[i] = (rgba[j] * 299 + rgba[j + 1] * 587 + rgba[j + 2] * 114) / 1000;
  }
  return { largura, altura, dados };
}

/**
 * Divide a imagem pelo próprio fundo (papel + sombra): o papel vira ~255 em toda a foto.
 * O fundo é o máximo em blocos maiores que os marcadores, alisado e interpolado.
 */
export function normalizarIluminacao(img: ImagemCinza): ImagemCinza {
  const { largura: w, altura: h, dados } = img;
  const b = Math.max(4, Math.round(0.025 * Math.max(w, h)));
  const gw = Math.ceil(w / b);
  const gh = Math.ceil(h / b);
  const grade = new Float32Array(gw * gh);
  for (let y = 0; y < h; y++) {
    const gy = Math.floor(y / b) * gw;
    for (let x = 0; x < w; x++) {
      const g = gy + Math.floor(x / b);
      const v = dados[y * w + x];
      if (v > grade[g]) grade[g] = v;
    }
  }
  // Máximo com os vizinhos: um bloco todo coberto por um marcador pega o papel do lado.
  const fundo = new Float32Array(gw * gh);
  for (let gy = 0; gy < gh; gy++) {
    for (let gx = 0; gx < gw; gx++) {
      let m = 0;
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          const yy = gy + dy;
          const xx = gx + dx;
          if (yy >= 0 && yy < gh && xx >= 0 && xx < gw) m = Math.max(m, grade[yy * gw + xx]);
        }
      }
      fundo[gy * gw + gx] = m;
    }
  }
  const saida = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) {
    const fy = Math.min(Math.max((y + 0.5) / b - 0.5, 0), gh - 1);
    const y0 = Math.floor(fy);
    const y1 = Math.min(y0 + 1, gh - 1);
    const ty = fy - y0;
    for (let x = 0; x < w; x++) {
      const fx = Math.min(Math.max((x + 0.5) / b - 0.5, 0), gw - 1);
      const x0 = Math.floor(fx);
      const x1 = Math.min(x0 + 1, gw - 1);
      const tx = fx - x0;
      const f =
        (fundo[y0 * gw + x0] * (1 - tx) + fundo[y0 * gw + x1] * tx) * (1 - ty) +
        (fundo[y1 * gw + x0] * (1 - tx) + fundo[y1 * gw + x1] * tx) * ty;
      saida[y * w + x] = Math.min(255, (dados[y * w + x] * 255) / Math.max(f, 1));
    }
  }
  return { largura: w, altura: h, dados: saida };
}

/** Escuro (1) onde a média 3x3 da imagem normalizada fica abaixo de 150, como no leitor. */
function binarizar(img: ImagemCinza): Uint8Array {
  const { largura: w, altura: h, dados } = img;
  const bin = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      let soma = 0;
      let n = 0;
      for (let dy = -1; dy <= 1; dy++) {
        const yy = y + dy;
        if (yy < 0 || yy >= h) continue;
        for (let dx = -1; dx <= 1; dx++) {
          const xx = x + dx;
          if (xx < 0 || xx >= w) continue;
          soma += dados[yy * w + xx];
          n++;
        }
      }
      bin[y * w + x] = soma / n < 150 ? 1 : 0;
    }
  }
  return bin;
}

type Candidato = { x: number; y: number; lado: number };

/** Fração de pixels escuros no anel quadrado entre as distâncias r0 e r1 do centro. */
function escuroEmVolta(bin: Uint8Array, w: number, h: number, cx: number, cy: number, r0: number, r1: number) {
  let escuros = 0;
  let total = 0;
  for (let y = Math.max(0, Math.floor(cy - r1)); y <= Math.min(h - 1, Math.ceil(cy + r1)); y++) {
    for (let x = Math.max(0, Math.floor(cx - r1)); x <= Math.min(w - 1, Math.ceil(cx + r1)); x++) {
      const d = Math.max(Math.abs(x - cx), Math.abs(y - cy));
      if (d > r0 && d <= r1) {
        total++;
        escuros += bin[y * w + x];
      }
    }
  }
  return escuros / Math.max(total, 1);
}

/** Quadrados pretos cheios e isolados, como _candidatos_marcador() do leitor. */
export function candidatosMarcador(normal: ImagemCinza): Candidato[] {
  const { largura: w, altura: h } = normal;
  const ladoImg = Math.max(w, h);
  const bin = binarizar(normal);
  const rotulo = new Int32Array(w * h);
  const pilha = new Int32Array(w * h);
  const pxs: number[] = [];
  const candidatos: Candidato[] = [];
  let atual = 0;
  for (let inicio = 0; inicio < w * h; inicio++) {
    if (!bin[inicio] || rotulo[inicio]) continue;
    atual++;
    // Componente conexa (8 vizinhos) de pixels escuros.
    pxs.length = 0;
    let topo = 0;
    pilha[topo++] = inicio;
    rotulo[inicio] = atual;
    let minX = w, maxX = 0, minY = h, maxY = 0;
    while (topo > 0) {
      const p = pilha[--topo];
      pxs.push(p);
      const px = p % w;
      const py = (p - px) / w;
      if (px < minX) minX = px;
      if (px > maxX) maxX = px;
      if (py < minY) minY = py;
      if (py > maxY) maxY = py;
      for (let dy = -1; dy <= 1; dy++) {
        const yy = py + dy;
        if (yy < 0 || yy >= h) continue;
        for (let dx = -1; dx <= 1; dx++) {
          const xx = px + dx;
          if (xx < 0 || xx >= w) continue;
          const q = yy * w + xx;
          if (bin[q] && !rotulo[q]) {
            rotulo[q] = atual;
            pilha[topo++] = q;
          }
        }
      }
    }
    const cw = maxX - minX + 1;
    const ch = maxY - minY + 1;
    if (cw < 0.006 * ladoImg || cw > 0.06 * ladoImg || ch < 0.006 * ladoImg || ch > 0.06 * ladoImg) continue;
    let sx = 0;
    let sy = 0;
    let borda = 0;
    for (const p of pxs) {
      const px = p % w;
      const py = (p - px) / w;
      sx += px;
      sy += py;
      if (px === 0 || px === w - 1 || py === 0 || py === h - 1 ||
          rotulo[p - 1] !== atual || rotulo[p + 1] !== atual || rotulo[p - w] !== atual || rotulo[p + w] !== atual) {
        borda++;
      }
    }
    const cx = sx / pxs.length;
    const cy = sy / pxs.length;
    // Caixa girada de menor área (foto torta): quadrado cheio ocupa ~100%; círculo, ~78%.
    // Medidas pelos centros dos pixels da borda, como o contorno do OpenCV: área =
    // pixels - borda/2 e caixa sem o +1, para não depender do tamanho do marcador.
    let melhorArea = Infinity;
    let razao = 1;
    for (let grau = 0; grau < 90; grau += 2) {
      const a = (grau * Math.PI) / 180;
      const c = Math.cos(a);
      const s = Math.sin(a);
      let u0 = Infinity, u1 = -Infinity, v0 = Infinity, v1 = -Infinity;
      for (const p of pxs) {
        const x = (p % w) - cx;
        const y = Math.floor(p / w) - cy;
        const u = x * c + y * s;
        const v = -x * s + y * c;
        if (u < u0) u0 = u;
        if (u > u1) u1 = u;
        if (v < v0) v0 = v;
        if (v > v1) v1 = v;
      }
      const area = (u1 - u0) * (v1 - v0);
      if (area < melhorArea) {
        melhorArea = area;
        razao = (u1 - u0) / Math.max(v1 - v0, 1e-9);
      }
    }
    if (razao < 0.7 || razao > 1.4 || (pxs.length - borda / 2) / melhorArea < 0.84) continue;
    // Isolado: num anel em volta o papel é branco. Exclui os "olhos" do QR.
    const lado = Math.sqrt(pxs.length);
    if (escuroEmVolta(bin, w, h, cx, cy, 0.85 * lado, 2.0 * lado) > 0.08) continue;
    candidatos.push({ x: cx, y: cy, lado });
  }
  return candidatos;
}

/** Ordena 4 pontos como cima-esquerda, cima-direita, baixo-direita, baixo-esquerda. */
export function ordenarCantos(pts: Ponto[]): [Ponto, Ponto, Ponto, Ponto] {
  const soma = (p: Ponto) => p.x + p.y;
  const dif = (p: Ponto) => p.y - p.x;
  const porSoma = [...pts].sort((a, b) => soma(a) - soma(b));
  const porDif = [...pts].sort((a, b) => dif(a) - dif(b));
  return [porSoma[0], porDif[0], porSoma[3], porDif[3]];
}

function areaPoligono(c: Ponto[]): number {
  let a = 0;
  for (let i = 0; i < c.length; i++) {
    const p = c[i];
    const q = c[(i + 1) % c.length];
    a += p.x * q.y - q.x * p.y;
  }
  return Math.abs(a) / 2;
}

/** Convexo, sem pontos repetidos e com os 4 ângulos a até 30° de um ângulo reto. */
function quadrilateroValido(c: Ponto[]): boolean {
  const unicos = new Set(c.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`));
  if (unicos.size < 4) return false;
  let sinal = 0;
  for (let i = 0; i < 4; i++) {
    const a = c[(i + 3) % 4];
    const b = c[i];
    const d = c[(i + 1) % 4];
    const v1 = { x: a.x - b.x, y: a.y - b.y };
    const v2 = { x: d.x - b.x, y: d.y - b.y };
    const cruz = v1.x * v2.y - v1.y * v2.x;
    const s = Math.sign(cruz);
    if (!s || (sinal && s !== sinal)) return false;
    sinal = s;
    const cos = (v1.x * v2.x + v1.y * v2.y) / (Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y) + 1e-9);
    const ang = (Math.acos(Math.min(1, Math.max(-1, cos))) * 180) / Math.PI;
    if (Math.abs(ang - 90) > 30) return false;
  }
  return true;
}

function* combinacoes<T>(lista: T[], k: number, inicio = 0, atual: T[] = []): Generator<T[]> {
  if (atual.length === k) {
    yield atual;
    return;
  }
  for (let i = inicio; i < lista.length; i++) yield* combinacoes(lista, k, i + 1, [...atual, lista[i]]);
}

/**
 * Os 4 candidatos que formam o maior quadrilátero quase retangular, com quadrados de
 * tamanho parecido. Com só 3 achados (dedo ou dobra num canto), o 4º completa o
 * paralelogramo. Como localizar_marcadores() do leitor.
 */
export function localizarMarcadores(normal: ImagemCinza): Marcadores | null {
  const { largura: w, altura: h } = normal;
  let candidatos = candidatosMarcador(normal);
  // Quadrados pretos em grupo são outra coisa (os olhos do QR numa foto desfocada).
  candidatos = candidatos.filter(
    (c) => !candidatos.some((o) => o !== c && Math.hypot(o.x - c.x, o.y - c.y) < 4 * Math.max(o.lado, c.lado)),
  );
  candidatos = candidatos.sort((a, b) => b.lado - a.lado).slice(0, 24);
  const areaMinima = 0.08 * w * h;
  let melhor: Marcadores | null = null;
  let melhorArea = areaMinima;
  for (const combo of combinacoes(candidatos, 4)) {
    const lados = combo.map((c) => c.lado);
    if (Math.max(...lados) > 1.5 * Math.min(...lados)) continue;
    const cantos = ordenarCantos(combo);
    if (!quadrilateroValido(cantos)) continue;
    const area = areaPoligono(cantos);
    if (area > melhorArea) {
      melhor = { cantos, lado: lados.reduce((s, l) => s + l, 0) / 4, estimado: false };
      melhorArea = area;
    }
  }
  if (melhor) return melhor;

  for (const combo of combinacoes(candidatos, 3)) {
    const lados = combo.map((c) => c.lado);
    if (Math.max(...lados) > 1.25 * Math.min(...lados)) continue;
    for (let i = 0; i < 3; i++) {
      const a = combo[(i + 1) % 3];
      const b = combo[i];
      const c = combo[(i + 2) % 3];
      const v1 = { x: a.x - b.x, y: a.y - b.y };
      const v2 = { x: c.x - b.x, y: c.y - b.y };
      const cos = (v1.x * v2.x + v1.y * v2.y) / (Math.hypot(v1.x, v1.y) * Math.hypot(v2.x, v2.y) + 1e-9);
      if (Math.abs(cos) > 0.34) continue;
      const quarto = { x: a.x + c.x - b.x, y: a.y + c.y - b.y };
      if (quarto.x < -0.05 * w || quarto.x > 1.05 * w || quarto.y < -0.05 * h || quarto.y > 1.05 * h) continue;
      const cantos = ordenarCantos([a, b, c, quarto]);
      if (!quadrilateroValido(cantos)) continue;
      const area = areaPoligono(cantos);
      if (area > melhorArea) {
        melhor = { cantos, lado: lados.reduce((s, l) => s + l, 0) / 3, estimado: true };
        melhorArea = area;
      }
    }
  }
  return melhor;
}

/**
 * Quanto girar a foto (graus, sentido horário, como o expo-image-manipulator) para o lado
 * comprido da área mais perto do alto da foto ficar em cima e nivelado.
 */
export function giroParaNivelar(cantos: Ponto[]): number {
  let melhor = { nota: Infinity, graus: 0 };
  const lados = [0, 1, 2, 3]
    .map((i) => ({ a: cantos[i], b: cantos[(i + 1) % 4] }))
    .sort((p, q) => Math.hypot(q.b.x - q.a.x, q.b.y - q.a.y) - Math.hypot(p.b.x - p.a.x, p.b.y - p.a.y))
    .slice(0, 2);
  for (const { a, b } of lados) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    // Normal para fora (cantos no sentido horário da tela): (dy, -dx). Menor y = mais para cima.
    const nota = -dx / Math.hypot(dx, dy);
    if (nota < melhor.nota) melhor = { nota, graus: -(Math.atan2(dy, dx) * 180) / Math.PI };
  }
  return melhor.graus;
}

/** Caixa da área de respostas (centros dos marcadores + folga), dentro da imagem. */
export function caixaDaArea(m: Marcadores, largura: number, altura: number): Caixa {
  // Canto estimado pode errar alguns pixels: folga maior, para o marcador não ficar de fora.
  const folga = (m.estimado ? MARGEM_ESTIMADO : MARGEM_RECORTE) * m.lado;
  const xs = m.cantos.map((p) => p.x);
  const ys = m.cantos.map((p) => p.y);
  return limitar(
    { x0: Math.min(...xs) - folga, y0: Math.min(...ys) - folga, x1: Math.max(...xs) + folga, y1: Math.max(...ys) + folga },
    largura,
    altura,
  );
}

/** Caixa da faixa do QR, com a folha em pé (cantos já nivelados). */
export function caixaDoQr(m: Marcadores, largura: number, altura: number): Caixa {
  const [tl, tr] = m.cantos;
  const L = Math.hypot(tr.x - tl.x, tr.y - tl.y);
  const y = Math.min(tl.y, tr.y);
  return limitar(
    { x0: tl.x + FAIXA_QR.esq * L, y0: y + FAIXA_QR.cima * L, x1: tl.x + FAIXA_QR.dir * L, y1: y + FAIXA_QR.baixo * L },
    largura,
    altura,
  );
}

/** Os mesmos marcadores com a imagem girada 180°. */
export function girar180(m: Marcadores, largura: number, altura: number): Marcadores {
  return { ...m, cantos: ordenarCantos(m.cantos.map((p) => ({ x: largura - p.x, y: altura - p.y }))) };
}

/** Mesmos marcadores numa cópia da imagem com outra escala. */
export function escalar(m: Marcadores, fator: number): Marcadores {
  return {
    ...m,
    lado: m.lado * fator,
    cantos: m.cantos.map((p) => ({ x: p.x * fator, y: p.y * fator })) as Marcadores['cantos'],
  };
}

function limitar(c: Caixa, largura: number, altura: number): Caixa {
  return {
    x0: Math.max(0, Math.floor(c.x0)),
    y0: Math.max(0, Math.floor(c.y0)),
    x1: Math.min(largura, Math.ceil(c.x1)),
    y1: Math.min(altura, Math.ceil(c.y1)),
  };
}
