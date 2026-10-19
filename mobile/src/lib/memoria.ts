import type { Foto } from './api';
import type { Leitura } from './tipos';

/*
 * Recorte do gabarito e leitura da última prova cadastrada, guardadas só enquanto o app
 * está aberto, para a revisão desenhar as marcações sobre o recorte enviado.
 * Se a revisão for aberta depois, ela usa a imagem anotada que o servidor guardou.
 */
const gabaritos = new Map<number, { foto: Foto; leitura: Leitura }>();

export const memoria = {
  guardarGabarito: (provaId: number, foto: Foto, leitura: Leitura) => gabaritos.set(provaId, { foto, leitura }),
  gabarito: (provaId: number) => gabaritos.get(provaId) ?? null,
};
