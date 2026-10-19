import { Text, View } from 'react-native';

import { nomeResposta } from '@/lib/formato';
import { Chip, cor, estilos } from './ui';

/**
 * Correção manual das respostas de uma folha: uma linha por questão com as alternativas,
 * "Branco" e "Dupla". Mostra a resposta certa e se a marcada está certa.
 */
export function GradeRespostas({
  numeros,
  respostas,
  gabarito,
  alternativas,
  revisar,
  onMudar,
}: {
  /** Questões mostradas (1 = primeira). */
  numeros: number[];
  respostas: (string | null)[];
  gabarito: (string | null)[];
  alternativas: string[];
  revisar: number[];
  onMudar: (numero: number, resposta: string | null) => void;
}) {
  return (
    <View style={{ gap: 6 }}>
      {numeros.map((n) => {
        const r = respostas[n - 1] ?? null;
        const certa = gabarito[n - 1] ?? null;
        const acertou = r !== null && r === certa;
        const duvidosa = revisar.includes(n);
        return (
          <View
            key={n}
            style={[
              estilos.cartao,
              { paddingVertical: 8, gap: 6 },
              duvidosa && { borderColor: cor.alerta, borderWidth: 2 },
            ]}
          >
            <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
              <Text style={[estilos.rotulo, { width: 34 }]}>{n}</Text>
              <Text style={[estilos.dica, { flex: 1 }]}>
                {duvidosa ? 'Confira: ' : ''}lida {nomeResposta(r)} · certa {certa ?? '—'}
              </Text>
              <Text style={{ fontWeight: '700', color: acertou ? cor.certo : cor.erro }}>
                {acertou ? '✓ certo' : '✗ errado'}
              </Text>
            </View>
            <View style={estilos.linha}>
              {alternativas.map((a) => (
                <Chip key={a} compacto rotulo={a} marcado={r === a} onPress={() => onMudar(n, a)} />
              ))}
              <Chip compacto rotulo="Branco" marcado={r === null} onPress={() => onMudar(n, null)} />
              <Chip compacto rotulo="Dupla" marcado={r === 'MULTIPLA'} onPress={() => onMudar(n, 'MULTIPLA')} />
            </View>
          </View>
        );
      })}
    </View>
  );
}
