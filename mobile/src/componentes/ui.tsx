import { useEffect, useState, type ReactNode } from 'react';
import {
  ActivityIndicator,
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';

/** Cores e medidas do app. Toque mínimo de 44 px, texto grande o bastante para a sala de aula. */
export const cor = {
  fundo: '#F4F6F8',
  superficie: '#FFFFFF',
  texto: '#1B2430',
  suave: '#5B6676',
  borda: '#D5DBE3',
  primaria: '#1F5FAD',
  primariaClara: '#E3EDF9',
  certo: '#1E7B45',
  certoClaro: '#E1F3E8',
  erro: '#B42318',
  erroClaro: '#FDECEA',
  alerta: '#9A5B00',
  alertaClaro: '#FFF4DB',
};

export function Botao({
  titulo,
  onPress,
  tipo = 'primario',
  carregando = false,
  desativado = false,
  estilo,
}: {
  titulo: string;
  onPress: () => void;
  tipo?: 'primario' | 'secundario' | 'perigo';
  carregando?: boolean;
  desativado?: boolean;
  estilo?: StyleProp<ViewStyle>;
}) {
  const inativo = desativado || carregando;
  const cores = {
    primario: { fundo: cor.primaria, texto: '#FFFFFF', borda: cor.primaria },
    secundario: { fundo: cor.superficie, texto: cor.primaria, borda: cor.primaria },
    perigo: { fundo: cor.superficie, texto: cor.erro, borda: cor.erro },
  }[tipo];
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inativo, busy: carregando }}
      onPress={onPress}
      disabled={inativo}
      style={({ pressed }) => [
        estilos.botao,
        { backgroundColor: cores.fundo, borderColor: cores.borda, opacity: inativo ? 0.5 : pressed ? 0.8 : 1 },
        estilo,
      ]}
    >
      {carregando ? (
        <ActivityIndicator color={cores.texto} />
      ) : (
        <Text style={[estilos.botaoTexto, { color: cores.texto }]}>{titulo}</Text>
      )}
    </Pressable>
  );
}

/** Opção que se marca com um toque (letra, período, dificuldade...). */
export function Chip({
  rotulo,
  marcado,
  onPress,
  cores,
  compacto = false,
}: {
  rotulo: string;
  marcado: boolean;
  onPress: () => void;
  cores?: { fundo: string; texto: string };
  compacto?: boolean;
}) {
  const fundo = marcado ? (cores?.fundo ?? cor.primaria) : cor.superficie;
  const texto = marcado ? (cores?.texto ?? '#FFFFFF') : cor.texto;
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ checked: marcado, selected: marcado }}
      onPress={onPress}
      hitSlop={4}
      style={[
        estilos.chip,
        compacto && estilos.chipCompacto,
        { backgroundColor: fundo, borderColor: marcado ? fundo : cor.borda },
      ]}
    >
      <Text style={[estilos.chipTexto, { color: texto }]}>{rotulo}</Text>
    </Pressable>
  );
}

export function Campo({ rotulo, dica, ...props }: TextInputProps & { rotulo: string; dica?: string }) {
  return (
    <View style={{ gap: 4 }}>
      <Text style={estilos.rotulo}>{rotulo}</Text>
      {dica ? <Text style={estilos.dica}>{dica}</Text> : null}
      <TextInput placeholderTextColor="#8A94A3" {...props} style={[estilos.campo, props.style]} />
    </View>
  );
}

export function Cartao({ children, estilo }: { children: ReactNode; estilo?: StyleProp<ViewStyle> }) {
  return <View style={[estilos.cartao, estilo]}>{children}</View>;
}

export function Aviso({ texto, tipo = 'erro' }: { texto: string; tipo?: 'erro' | 'alerta' | 'certo' }) {
  const cores = {
    erro: [cor.erroClaro, cor.erro],
    alerta: [cor.alertaClaro, cor.alerta],
    certo: [cor.certoClaro, cor.certo],
  }[tipo];
  return (
    <View
      accessibilityRole={tipo === 'erro' ? 'alert' : undefined}
      style={[estilos.aviso, { backgroundColor: cores[0], borderColor: cores[1] }]}
    >
      <Text style={{ color: cores[1], fontSize: 15 }}>{texto}</Text>
    </View>
  );
}

export function Carregando({ texto }: { texto?: string }) {
  return (
    <View style={estilos.centro}>
      <ActivityIndicator size="large" color={cor.primaria} />
      {texto ? <Text style={estilos.dica}>{texto}</Text> : null}
    </View>
  );
}

/** Tela de erro com botão para tentar de novo. */
export function FalhaAoCarregar({ mensagem, tentarDeNovo }: { mensagem: string; tentarDeNovo: () => void }) {
  return (
    <View style={[estilos.centro, { padding: 24, gap: 16 }]}>
      <Aviso texto={mensagem} />
      <Botao titulo="Tentar de novo" onPress={tentarDeNovo} tipo="secundario" />
    </View>
  );
}

export function Secao({ titulo, children }: { titulo: string; children: ReactNode }) {
  return (
    <View style={{ gap: 8 }}>
      <Text style={estilos.secao}>{titulo}</Text>
      {children}
    </View>
  );
}

export const estilos = StyleSheet.create({
  tela: { flex: 1, backgroundColor: cor.fundo },
  conteudo: { padding: 16, gap: 16, paddingBottom: 40 },
  centro: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  linha: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center' },
  botao: {
    minHeight: 48,
    borderRadius: 10,
    borderWidth: 1.5,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
  },
  botaoTexto: { fontSize: 16, fontWeight: '600' },
  chip: {
    minHeight: 40,
    minWidth: 44,
    paddingHorizontal: 12,
    borderRadius: 20,
    borderWidth: 1.5,
    alignItems: 'center',
    justifyContent: 'center',
  },
  chipCompacto: { minHeight: 36, minWidth: 40, paddingHorizontal: 8 },
  chipTexto: { fontSize: 15, fontWeight: '600' },
  rotulo: { fontSize: 15, fontWeight: '600', color: cor.texto },
  dica: { fontSize: 13, color: cor.suave },
  campo: {
    minHeight: 48,
    borderWidth: 1.5,
    borderColor: cor.borda,
    borderRadius: 10,
    paddingHorizontal: 12,
    fontSize: 16,
    color: cor.texto,
    backgroundColor: cor.superficie,
  },
  cartao: {
    backgroundColor: cor.superficie,
    borderRadius: 12,
    padding: 14,
    gap: 8,
    borderWidth: 1,
    borderColor: cor.borda,
  },
  aviso: { borderRadius: 10, borderWidth: 1, padding: 12 },
  secao: { fontSize: 17, fontWeight: '700', color: cor.texto },
  titulo: { fontSize: 20, fontWeight: '700', color: cor.texto },
  texto: { fontSize: 15, color: cor.texto },
});

/** Imagem remota na largura disponível, com a proporção real (a área de respostas anotada é larga). */
export function ImagemAjustada({ uri, descricao }: { uri: string; descricao: string }) {
  const [proporcao, setProporcao] = useState(0.8);
  useEffect(() => {
    Image.getSize(uri, (w, h) => w > 0 && h > 0 && setProporcao(w / h), () => {});
  }, [uri]);
  return (
    <Image
      source={{ uri }}
      style={{ width: '100%', aspectRatio: proporcao, borderRadius: 10, backgroundColor: '#000' }}
      resizeMode="contain"
      accessibilityLabel={descricao}
    />
  );
}
