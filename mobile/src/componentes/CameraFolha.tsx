import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import * as ImagePicker from 'expo-image-picker';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { escanearDocumento, scannerDisponivel } from '@/lib/foto';
import { Botao, cor } from './ui';

export type FotoCapturada = { uri: string; width?: number; height?: number };

/**
 * Câmera para o gabarito e as folhas: cantoneiras discretas (a folha é alinhada depois da
 * foto, em lib/recorte.ts, então não precisa ser encaixada num contorno), leitura
 * do QR ao vivo (avisa na hora se a folha é de outro caderno), galeria e, no development
 * build, o scanner de documento do sistema. O resultado de cada folha aparece em `painel`,
 * por cima da câmera, que continua aberta.
 */
export function CameraFolha({
  titulo,
  onFoto,
  onFechar,
  cadernoEsperado,
  ocupado = false,
  lendo = false,
  painel,
  topo,
}: {
  titulo: string;
  onFoto: (foto: FotoCapturada) => void;
  onFechar: () => void;
  cadernoEsperado?: string | null;
  /** Câmera bloqueada (lendo a folha ou esperando o professor confirmar). */
  ocupado?: boolean;
  /** Mostra o indicador de leitura no botão da câmera. */
  lendo?: boolean;
  painel?: ReactNode;
  topo?: ReactNode;
}) {
  const [permissao, pedirPermissao] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [pronta, setPronta] = useState(false);
  const [qr, setQr] = useState<{ codigo: string; visto: number } | null>(null);
  const [erro, setErro] = useState<string | null>(null);

  function aoLerQr(r: BarcodeScanningResult) {
    const codigo = r.data.trim();
    // O evento chega várias vezes por segundo: só atualiza se mudou ou a cada 1 s.
    setQr((atual) => (atual?.codigo === codigo && Date.now() - atual.visto < 1000 ? atual : { codigo, visto: Date.now() }));
  }

  async function fotografar() {
    if (!camera.current || ocupado) return;
    setErro(null);
    try {
      const foto = await camera.current.takePictureAsync({ quality: 0.9 });
      if (foto) onFoto({ uri: foto.uri, width: foto.width, height: foto.height });
    } catch (e) {
      setErro(`A câmera não tirou a foto: ${String(e)}`);
    }
  }

  async function daGaleria() {
    const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1 });
    const a = r.canceled ? null : r.assets[0];
    if (a) onFoto({ uri: a.uri, width: a.width, height: a.height });
  }

  async function doScanner() {
    try {
      const uri = await escanearDocumento();
      if (uri) onFoto({ uri });
    } catch (e) {
      setErro(`O scanner não abriu: ${String(e)}`);
    }
  }

  useEffect(() => {
    if (!qr) return;
    const prazo = setTimeout(() => setQr(null), 3000);
    return () => clearTimeout(prazo);
  }, [qr]);

  // QR visto há pouco e diferente do caderno da prova: avisa antes da foto.
  const qrRecente = qr?.codigo ?? null;
  const outroCaderno = !!(cadernoEsperado && qrRecente && qrRecente !== cadernoEsperado);

  return (
    <View style={{ flex: 1, backgroundColor: '#000' }}>
      {permissao?.granted ? (
        <CameraView
          ref={camera}
          style={StyleSheet.absoluteFill}
          facing="back"
          onCameraReady={() => setPronta(true)}
          barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
          onBarcodeScanned={aoLerQr}
        />
      ) : null}

      {permissao?.granted && <Cantoneiras alerta={outroCaderno} />}

      <SafeAreaView style={{ flex: 1, justifyContent: 'space-between' }} pointerEvents="box-none">
        <View style={e.barraTopo}>
          <Pressable onPress={onFechar} hitSlop={12} accessibilityRole="button" accessibilityLabel="Fechar a câmera">
            <Text style={e.textoBarra}>✕ Fechar</Text>
          </Pressable>
          <Text style={[e.textoBarra, { flex: 1, textAlign: 'center' }]} numberOfLines={1}>
            {titulo}
          </Text>
          <View style={{ width: 70 }} />
        </View>
        {topo}

        <View style={{ paddingHorizontal: 16, gap: 8 }}>
          {!permissao ? null : !permissao.granted ? (
            <View style={e.caixa}>
              <Text style={{ fontSize: 16, color: cor.texto }}>
                O app precisa da câmera para fotografar o gabarito e as folhas.
              </Text>
              <Botao titulo="Permitir a câmera" onPress={pedirPermissao} />
            </View>
          ) : (
            <Text style={e.instrucao}>
              {outroCaderno
                ? `Esta folha é do caderno ${qrRecente}, não do ${cadernoEsperado}.`
                : 'Folha inteira na tela, bem iluminada. Pode estar um pouco torta.'}
            </Text>
          )}
          {qrRecente && !outroCaderno ? <Text style={e.qr}>Caderno {qrRecente}</Text> : null}
          {erro ? <Text style={[e.instrucao, { backgroundColor: cor.erro }]}>{erro}</Text> : null}
        </View>

        <View style={{ gap: 10, paddingHorizontal: 16, paddingBottom: 12 }}>
          {painel}
          <View style={e.controles}>
            <Pressable onPress={daGaleria} disabled={ocupado} style={e.lateral} accessibilityRole="button">
              <Text style={e.textoBarra}>Galeria</Text>
            </Pressable>
            <Pressable
              onPress={fotografar}
              disabled={!pronta || ocupado}
              accessibilityRole="button"
              accessibilityLabel="Tirar foto"
              style={({ pressed }) => [e.disparo, (!pronta || ocupado) && { opacity: 0.5 }, pressed && { opacity: 0.7 }]}
            >
              {lendo ? <ActivityIndicator color={cor.primaria} /> : null}
            </Pressable>
            {scannerDisponivel ? (
              <Pressable onPress={doScanner} disabled={ocupado} style={e.lateral} accessibilityRole="button">
                <Text style={e.textoBarra}>Scanner</Text>
              </Pressable>
            ) : (
              <View style={e.lateral} />
            )}
          </View>
        </View>
      </SafeAreaView>
    </View>
  );
}

/** Espaço da tela ocupado pela barra de cima, pelas instruções e pelos botões. */
const ESPACO_DE_CIMA = 150;
const ESPACO_DE_BAIXO = 170;

/**
 * Só uma dica de enquadramento: 4 cantos finos perto da borda. O recorte acha os 4
 * quadrados pretos em qualquer lugar da foto, com a folha torta ou de cabeça para baixo.
 * Ficam vermelhos quando o QR visto é de outro caderno.
 */
function Cantoneiras({ alerta }: { alerta: boolean }) {
  const { width, height } = useWindowDimensions();
  const corLinha = alerta ? '#FF6B5B' : 'rgba(255,255,255,0.55)';
  const tamanho = Math.min(width, height) * 0.08;
  const margem = Math.max(12, width * 0.04);
  const canto = { position: 'absolute' as const, width: tamanho, height: tamanho, borderColor: corLinha };
  return (
    <View
      pointerEvents="none"
      style={{ position: 'absolute', left: margem, right: margem, top: ESPACO_DE_CIMA, bottom: ESPACO_DE_BAIXO }}
    >
      <View style={[canto, { left: 0, top: 0, borderLeftWidth: 2, borderTopWidth: 2 }]} />
      <View style={[canto, { right: 0, top: 0, borderRightWidth: 2, borderTopWidth: 2 }]} />
      <View style={[canto, { left: 0, bottom: 0, borderLeftWidth: 2, borderBottomWidth: 2 }]} />
      <View style={[canto, { right: 0, bottom: 0, borderRightWidth: 2, borderBottomWidth: 2 }]} />
    </View>
  );
}

const e = StyleSheet.create({
  barraTopo: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 10,
    backgroundColor: 'rgba(0,0,0,0.45)',
  },
  textoBarra: { color: '#FFF', fontSize: 16, fontWeight: '600' },
  instrucao: {
    color: '#FFF',
    fontSize: 15,
    textAlign: 'center',
    backgroundColor: 'rgba(0,0,0,0.55)',
    padding: 8,
    borderRadius: 8,
    overflow: 'hidden',
  },
  qr: {
    alignSelf: 'center',
    color: '#FFF',
    backgroundColor: 'rgba(30,123,69,0.85)',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    overflow: 'hidden',
    fontWeight: '600',
  },
  caixa: { backgroundColor: '#FFF', borderRadius: 12, padding: 16, gap: 12 },
  controles: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  lateral: { width: 80, height: 48, alignItems: 'center', justifyContent: 'center' },
  disparo: {
    width: 76,
    height: 76,
    borderRadius: 38,
    backgroundColor: '#FFF',
    borderWidth: 5,
    borderColor: 'rgba(255,255,255,0.5)',
    alignItems: 'center',
    justifyContent: 'center',
  },
});
