import { useState } from 'react';
import { Image, Pressable, Text, useWindowDimensions, View } from 'react-native';
import Svg, { Circle, Text as SvgText } from 'react-native-svg';

import type { Foto } from '@/lib/api';
import type { QuestaoLida } from '@/lib/tipos';
import { cor, estilos } from './ui';

/**
 * Recorte do gabarito (área de respostas) com a leitura desenhada por cima: a bolinha da resposta atual de cada
 * questão (verde), as questões duvidosas (laranja) e a questão escolhida (azul).
 * As posições vêm do leitor em fração da largura e da altura da foto enviada.
 */
export function FotoComMarcacoes({
  foto,
  lidas,
  respostas,
  alternativas,
  raio,
  destaque,
}: {
  foto: Foto;
  lidas: QuestaoLida[];
  respostas: Record<number, string | null>;
  alternativas: string[];
  raio?: number;
  destaque: number | null;
}) {
  // Começa pela largura da tela menos as margens (16 px de cada lado); o onLayout ajusta.
  const { width: tela } = useWindowDimensions();
  const [largura, setLargura] = useState(tela - 32);
  const [ampliada, setAmpliada] = useState(false);
  const proporcao = foto.height / foto.width;
  // Ampliada: o recorte inteiro em largura total. Normal: só uma faixa de 320 px, rolando dentro.
  const altura = largura * proporcao;
  const r = Math.max(5, (raio ?? 0.009) * largura * 1.25);

  return (
    <View style={{ gap: 6 }}>
      <View
        onLayout={(ev) => ev.nativeEvent.layout.width > 0 && setLargura(ev.nativeEvent.layout.width)}
        style={{ borderRadius: 10, overflow: 'hidden', backgroundColor: '#000', maxHeight: ampliada ? undefined : 320 }}
      >
        {largura > 0 && (
          <View style={{ width: largura, height: altura, marginTop: ampliada ? 0 : -Math.max(0, centroY() - 160) }}>
            <Image source={{ uri: foto.uri }} style={{ width: largura, height: altura }} />
            <Svg width={largura} height={altura} style={{ position: 'absolute', left: 0, top: 0 }}>
              {lidas.map((q) => {
                const resposta = respostas[q.questao];
                const i = resposta ? alternativas.indexOf(resposta) : -1;
                const escolhida = destaque === q.questao;
                const [x, y] = q.bolinhas[i >= 0 ? i : 0] ?? [0, 0];
                return (
                  <Circle
                    key={q.questao}
                    cx={x * largura}
                    cy={y * altura}
                    r={escolhida ? r * 1.5 : r}
                    stroke={escolhida ? cor.primaria : q.duvidosa ? '#F08C00' : '#1E9E55'}
                    strokeWidth={escolhida ? 4 : 3}
                    strokeDasharray={i < 0 ? '4 3' : undefined}
                    fill="none"
                  />
                );
              })}
              {destaque !== null &&
                lidas
                  .filter((q) => q.questao === destaque)
                  .map((q) => (
                    <SvgText
                      key="rotulo"
                      x={(q.bolinhas[0]?.[0] ?? 0) * largura - r * 2}
                      y={(q.bolinhas[0]?.[1] ?? 0) * altura + 5}
                      fill={cor.primaria}
                      fontSize={14}
                      fontWeight="bold"
                      textAnchor="end"
                    >
                      {String(q.questao)}
                    </SvgText>
                  ))}
            </Svg>
          </View>
        )}
      </View>
      <Pressable onPress={() => setAmpliada(!ampliada)} accessibilityRole="button" hitSlop={8}>
        <Text style={[estilos.dica, { color: cor.primaria, fontWeight: '600' }]}>
          {ampliada ? 'Mostrar só a questão escolhida' : 'Ver a área de respostas inteira'}
        </Text>
      </Pressable>
    </View>
  );

  /** Altura (px) da questão em destaque, para a faixa mostrar a região certa. */
  function centroY(): number {
    const q = lidas.find((l) => l.questao === destaque) ?? lidas[0];
    return (q?.bolinhas[0]?.[1] ?? 0.5) * altura;
  }
}
