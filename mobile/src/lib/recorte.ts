import { scanFromURLAsync } from 'expo-camera';
import { File } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import { decode } from 'jpeg-js';
import { Platform } from 'react-native';

import type { Foto } from './api';
import {
  caixaDaArea,
  caixaDoQr,
  escalar,
  giroParaNivelar,
  girar180,
  localizarMarcadores,
  normalizarIluminacao,
  paraCinza,
  type Caixa,
  type Marcadores,
} from './marcadores';

/*
 * Recorte da folha no próprio celular: a foto inteira, com o nome e a turma do aluno,
 * não sai do aparelho. Vão para o servidor só a área entre os 4 quadrados pretos (com o
 * lado de cima em cima) e a faixa do QR, que termina antes do campo do nome.
 * Mesmos passos de recortar_para_envio() em worker/omr.py.
 */

/** O que o app envia no lugar da foto. */
export type Recorte = { area: Foto; qr: Foto | null; caderno: string | null };

export class ErroRecorte extends Error {
  constructor(
    mensagem: string,
    public readonly codigo = 'sem_marcadores',
  ) {
    super(mensagem);
  }
}

/** Lado maior da cópia analisada: rápido no celular e o bastante para achar os marcadores. */
const LADO_ANALISE = 1000;

export async function recortarFolha(foto: Foto): Promise<Recorte> {
  const temporarios: string[] = [];
  try {
    let m = await acharMarcadores(foto, temporarios);
    if (!m) {
      throw new ErroRecorte(
        'Não encontrei os 4 quadrados pretos dos cantos da área de respostas. Fotografe a folha inteira, ' +
          'de frente, sem cobrir os cantos. O app lê só a folha AMA.',
      );
    }
    // Nivela com o lado comprido mais perto do alto da foto em cima; depois acha de novo.
    let emPe = foto;
    const giro = giroParaNivelar(m.cantos);
    if (Math.abs(giro) > 0.3) {
      emPe = await salvar(ImageManipulator.manipulate(foto.uri).rotate(giro), temporarios);
      m = await acharMarcadores(emPe, temporarios);
      if (!m) throw new ErroRecorte('Não consegui endireitar a folha. Tire outra foto, de frente.');
    }

    // O QR fica numa faixa acima da área. Não achou: tenta com a folha de cabeça para baixo.
    let qr: Foto | null = null;
    let caderno: string | null = null;
    const opcoes: { foto: Foto; m: Marcadores }[] = [{ foto: emPe, m }];
    for (let tentativa = 0; tentativa < 2 && !caderno; tentativa++) {
      if (tentativa === 1) {
        opcoes.push({
          foto: await salvar(ImageManipulator.manipulate(emPe.uri).rotate(180), temporarios),
          m: girar180(m, emPe.width, emPe.height),
        });
      }
      const { foto: f, m: mm } = opcoes[tentativa];
      const faixa = caixaDoQr(mm, f.width, f.height);
      if (faixa.x1 - faixa.x0 < 10 || faixa.y1 - faixa.y0 < 10) continue;
      const recorteQr = await cortar(f, faixa);
      caderno = await lerQr(recorteQr.uri);
      if (caderno) {
        qr = recorteQr;
        emPe = f;
        m = mm;
      } else {
        apagar(recorteQr.uri);
      }
    }
    // Sem QR, vale a primeira opção (lado de cima perto do alto da foto); o servidor confere pelos blocos.
    const area = await cortar(emPe, caixaDaArea(m, emPe.width, emPe.height));
    return { area, qr, caderno };
  } finally {
    temporarios.forEach(apagar);
  }
}

/** Marcadores na foto, procurados numa cópia menor e devolvidos na escala da foto. */
async function acharMarcadores(foto: Foto, temporarios: string[]): Promise<Marcadores | null> {
  const escala = Math.min(1, LADO_ANALISE / Math.max(foto.width, foto.height));
  const contexto = ImageManipulator.manipulate(foto.uri);
  if (escala < 1) {
    contexto.resize({ width: Math.round(foto.width * escala), height: Math.round(foto.height * escala) });
  }
  const menor = await salvar(contexto, temporarios);
  const jpeg = decode(await bytes(menor.uri), { useTArray: true, formatAsRGBA: true });
  const m = localizarMarcadores(normalizarIluminacao(paraCinza(jpeg.data, jpeg.width, jpeg.height)));
  return m && escalar(m, foto.width / jpeg.width);
}

async function cortar(foto: Foto, c: Caixa): Promise<Foto> {
  const contexto = ImageManipulator.manipulate(foto.uri);
  contexto.crop({ originX: c.x0, originY: c.y0, width: c.x1 - c.x0, height: c.y1 - c.y0 });
  return salvar(contexto, [], 0.9);
}

async function salvar(
  contexto: ReturnType<typeof ImageManipulator.manipulate>,
  temporarios: string[],
  qualidade = 0.92,
): Promise<Foto> {
  const imagem = await contexto.renderAsync();
  const salva = await imagem.saveAsync({ format: SaveFormat.JPEG, compress: qualidade });
  temporarios.push(salva.uri);
  return { uri: salva.uri, width: salva.width, height: salva.height };
}

async function lerQr(uri: string): Promise<string | null> {
  try {
    const r = await scanFromURLAsync(uri, ['qr']);
    return r[0]?.data?.trim() || null;
  } catch {
    return null;
  }
}

async function bytes(uri: string): Promise<Uint8Array> {
  if (Platform.OS === 'web') return new Uint8Array(await (await fetch(uri)).arrayBuffer());
  return new File(uri).bytes();
}

/** Apaga um arquivo temporário do cache (no navegador, as imagens são blobs na memória). */
export function apagar(uri: string) {
  if (Platform.OS === 'web') return;
  try {
    const f = new File(uri);
    if (f.exists) f.delete();
  } catch {
    // Arquivo do cache que já não existe: o sistema limpa sozinho.
  }
}
