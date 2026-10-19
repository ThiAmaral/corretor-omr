import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';

import { Aviso, Botao, Carregando, Chip, cor, estilos, FalhaAoCarregar, Secao } from '@/componentes/ui';
import { api, ErroApi } from '@/lib/api';
import { NOME_DIFICULDADE, nomeResposta, pct } from '@/lib/formato';
import type { Dificuldade, Folha, Relatorio } from '@/lib/tipos';

/** Resultado da turma: acerto geral, por disciplina e por dificuldade, e as prioridades de revisão (Fase 4). */
export default function ResultadoTurma() {
  const params = useLocalSearchParams<{ id: string; turma: string }>();
  const id = Number(params.id);
  const [turma, setTurma] = useState(String(params.turma ?? ''));
  const [relatorio, setRelatorio] = useState<Relatorio | null>(null);
  const [folhas, setFolhas] = useState<Folha[] | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [verFolhas, setVerFolhas] = useState(false);

  const carregar = useCallback(async () => {
    setErro(null);
    try {
      const [r, f] = await Promise.all([api.relatorio(id, turma), api.listarFolhas(id, turma)]);
      setRelatorio(r);
      setFolhas(f.folhas);
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : String(e));
    }
  }, [id, turma]);

  useFocusEffect(
    useCallback(() => {
      carregar();
    }, [carregar]),
  );

  if (erro) return <FalhaAoCarregar mensagem={erro} tentarDeNovo={carregar} />;
  if (!relatorio) return <Carregando />;

  const resumo = relatorio.resumo.find((r) => r.turma === turma) ?? relatorio.resumo[0];
  const { limites } = relatorio;

  return (
    <ScrollView style={estilos.tela} contentContainerStyle={estilos.conteudo}>
      <Text style={estilos.dica}>{relatorio.prova.nome}</Text>
      {relatorio.turmas.length > 1 && (
        <View style={estilos.linha}>
          {relatorio.turmas.map((t) => (
            <Chip key={t} rotulo={t} marcado={t === turma} onPress={() => setTurma(t)} />
          ))}
        </View>
      )}

      {!resumo ? (
        <Aviso tipo="alerta" texto="Nenhuma folha corrigida nesta turma ainda." />
      ) : (
        <>
          <View style={[estilos.cartao, { alignItems: 'center', paddingVertical: 20 }]}>
            <Text style={estilos.dica}>Acerto geral da turma {resumo.turma}</Text>
            <Text style={{ fontSize: 44, fontWeight: '800', color: corDaTaxa(resumo.acerto, limites.reforco) }}>
              {pct(resumo.acerto)}
            </Text>
            <Text style={estilos.texto}>
              {resumo.folhas} {resumo.folhas === 1 ? 'folha' : 'folhas'} · {resumo.para_reforco}{' '}
              {resumo.para_reforco === 1 ? 'aluno' : 'alunos'} abaixo de {limites.reforco}%
            </Text>
            {resumo.a_revisar > 0 && (
              <Text style={{ color: cor.alerta }}>{resumo.a_revisar} folhas com questões a conferir</Text>
            )}
          </View>

          <Secao titulo="Por dificuldade">
            <View style={estilos.cartao}>
              {(['facil', 'medio', 'dificil'] as Dificuldade[])
                .filter((d) => resumo.por_dificuldade[d] !== undefined)
                .map((d) => (
                  <Barra key={d} rotulo={NOME_DIFICULDADE[d]} valor={resumo.por_dificuldade[d] ?? null} limite={limites.reforco} />
                ))}
              {Object.keys(resumo.por_dificuldade).length === 0 && (
                <Text style={estilos.dica}>Marque a dificuldade das questões na revisão do gabarito.</Text>
              )}
            </View>
          </Secao>

          <Secao titulo="Por disciplina">
            <View style={estilos.cartao}>
              {Object.entries(resumo.por_disciplina).map(([d, v]) => (
                <Barra key={d} rotulo={d} valor={v} limite={limites.reforco} />
              ))}
            </View>
          </Secao>
        </>
      )}

      <Secao titulo="Revisar em sala primeiro">
        {relatorio.prioridades.length === 0 ? (
          <Text style={estilos.dica}>Nenhuma questão abaixo de {limites.reforco}% de acerto.</Text>
        ) : (
          relatorio.prioridades.map((p, i) => (
            <View key={p.numero} style={estilos.cartao}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={[estilos.rotulo, { fontSize: 17 }]}>
                  {i + 1}º · Questão {p.numero}
                </Text>
                <Text style={[estilos.rotulo, { color: corDaTaxa(p.taxa, limites.reforco) }]}>{pct(p.taxa)}</Text>
              </View>
              <Text style={estilos.dica}>
                Certa: {p.certa}
                {p.disciplina ? ` · ${p.disciplina}` : ''}
                {p.dificuldade ? ` · ${NOME_DIFICULDADE[p.dificuldade]}` : ''}
                {p.habilidade ? ` · ${p.habilidade}` : ''}
              </Text>
              <Text style={estilos.texto}>{p.motivo}</Text>
            </View>
          ))
        )}
      </Secao>

      <Secao titulo="Folhas da turma">
        {!verFolhas ? (
          <Botao titulo={`Ver as ${folhas?.length ?? 0} folhas`} tipo="secundario" onPress={() => setVerFolhas(true)} />
        ) : (
          (folhas ?? []).map((f, i) => (
            <Pressable
              key={f.id}
              accessibilityRole="button"
              onPress={() => router.push(`/folha/${f.id}`)}
              style={({ pressed }) => [estilos.cartao, { flexDirection: 'row', alignItems: 'center' }, pressed && { opacity: 0.8 }]}
            >
              <Text style={[estilos.texto, { flex: 1 }]}>
                Folha {i + 1}
                {f.revisar.length > 0 && !f.revisada ? (
                  <Text style={{ color: cor.alerta }}> · conferir {f.revisar.map((n) => `${n} (${nomeResposta(f.respostas[n - 1])})`).join(', ')}</Text>
                ) : null}
                {f.revisada ? <Text style={estilos.dica}> · revisada</Text> : null}
              </Text>
              <Text style={estilos.rotulo}>
                {f.n_acertos}/{f.questoes}
              </Text>
            </Pressable>
          ))
        )}
      </Secao>

      <Botao
        titulo={`Escanear mais folhas da ${turma}`}
        onPress={() => router.push({ pathname: '/prova/[id]/escanear', params: { id: String(id), turma } })}
      />
    </ScrollView>
  );
}

function corDaTaxa(taxa: number | null, limite: number) {
  if (taxa === null) return cor.suave;
  return taxa < limite ? cor.erro : cor.certo;
}

function Barra({ rotulo, valor, limite }: { rotulo: string; valor: number | null; limite: number }) {
  return (
    <View style={{ gap: 4 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
        <Text style={estilos.texto}>{rotulo}</Text>
        <Text style={[estilos.rotulo, { color: corDaTaxa(valor, limite) }]}>{pct(valor)}</Text>
      </View>
      <View style={{ height: 10, borderRadius: 5, backgroundColor: '#E6EAF0', overflow: 'hidden' }}>
        <View
          style={{ width: `${Math.max(0, Math.min(100, valor ?? 0))}%`, height: '100%', backgroundColor: corDaTaxa(valor, limite) }}
        />
      </View>
    </View>
  );
}
