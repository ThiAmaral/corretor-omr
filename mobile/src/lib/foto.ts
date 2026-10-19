import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import { Image, Platform, TurboModuleRegistry } from 'react-native';

import type { Foto } from './api';

/** Lado menor da foto enviada: ~1600 px bastam ao leitor e deixam o envio rápido (Fase 7). */
export const LADO_ENVIO = 1600;

/** Reduz a foto para o lado menor ter LADO_ENVIO px e salva em JPEG. Foto menor fica como está. */
export async function prepararFoto(original: { uri: string; width?: number; height?: number }): Promise<Foto> {
  const foto = original.width && original.height ? (original as Foto) : await comTamanho(original.uri);
  const menor = Math.min(foto.width, foto.height);
  const contexto = ImageManipulator.manipulate(foto.uri);
  if (menor > LADO_ENVIO) {
    // Os dois lados explícitos: no navegador, height: null vira 0.
    const escala = LADO_ENVIO / menor;
    contexto.resize({ width: Math.round(foto.width * escala), height: Math.round(foto.height * escala) });
  }
  const imagem = await contexto.renderAsync();
  const salva = await imagem.saveAsync({ format: SaveFormat.JPEG, compress: 0.85 });
  return { uri: salva.uri, width: salva.width, height: salva.height };
}

function comTamanho(uri: string): Promise<Foto> {
  return new Promise((ok, falha) => Image.getSize(uri, (width, height) => ok({ uri, width, height }), falha));
}

/*
 * Scanner de documento do sistema (ML Kit no Android, VisionKit no iOS). Só existe no
 * development build; no Expo Go e no navegador o app usa a própria câmera.
 */
export const scannerDisponivel = Platform.OS !== 'web' && TurboModuleRegistry?.get?.('DocumentScanner') != null;

/** Abre o scanner do sistema; devolve a uri da página ou null se o professor cancelar. */
export async function escanearDocumento(): Promise<string | null> {
  if (!scannerDisponivel) return null;
  const { default: DocumentScanner } = await import('react-native-document-scanner-plugin');
  const { scannedImages } = await DocumentScanner.scanDocument({ maxNumDocuments: 1, croppedImageQuality: 95 });
  return scannedImages?.[0] ?? null;
}
