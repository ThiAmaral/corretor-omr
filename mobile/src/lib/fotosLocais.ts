import { Directory, File, Paths } from 'expo-file-system';
import { Platform } from 'react-native';

import type { Foto } from './api';

/*
 * Fotos originais das folhas, guardadas só neste celular, numa pasta privada do app
 * (não vão para a galeria, para o backup de fotos nem para o servidor). Uma pasta por
 * prova; o professor apaga pela tela da prova. No navegador não há onde guardar.
 */
const guarda = Platform.OS !== 'web';

function pasta(provaId: number) {
  return new Directory(Paths.document, 'fotos', `prova_${provaId}`);
}

/** Copia a foto para a pasta da prova com o nome dado (ex.: 8A_folha_154.jpg). */
export async function guardarOriginal(provaId: number, nome: string, foto: Foto): Promise<void> {
  if (!guarda) return;
  try {
    const p = pasta(provaId);
    p.create({ intermediates: true, idempotent: true });
    const destino = new File(p, nome.replace(/[^A-Za-z0-9_.-]+/g, '_'));
    if (destino.exists) destino.delete();
    await new File(foto.uri).copy(destino);
  } catch {
    // Sem espaço ou sem permissão: a correção vale do mesmo jeito; só a cópia local falta.
  }
}

/** Quantas fotos desta prova estão guardadas no celular. */
export function contarOriginais(provaId: number): number {
  if (!guarda) return 0;
  try {
    const p = pasta(provaId);
    return p.exists ? p.list().filter((f) => f instanceof File).length : 0;
  } catch {
    return 0;
  }
}

/** Apaga todas as fotos desta prova guardadas no celular. */
export function apagarOriginais(provaId: number): void {
  if (!guarda) return;
  const p = pasta(provaId);
  if (p.exists) p.delete();
}
