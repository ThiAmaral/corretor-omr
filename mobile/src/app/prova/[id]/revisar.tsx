import { router, Stack, useLocalSearchParams } from 'expo-router';
import { memo, useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { FotoComMarcacoes } from '@/componentes/FotoComMarcacoes';
import { Aviso, Botao, Campo, Carregando, Chip, cor, estilos, FalhaAoCarregar, ImagemAjustada, Secao } from '@/componentes/ui';
import { apagarOriginais } from '@/lib/fotosLocais';
import { api, ErroApi, mensagemDeErro } from '@/lib/api';
import { listarNumeros, NOME_DIFICULDADE } from '@/lib/formato';
import { memoria } from '@/lib/memoria';
import type { Dificuldade, Prova, Questao } from '@/lib/tipos';

const DIFICULDADES: Dificuldade[] = ['facil', 'medio', 'dificil'];
const COR_DIFICULDADE: Record<Dificuldade, { fundo: string; texto: string }> = {
  facil: { fundo: cor.certo, texto: '#FFF' },
  medio: { fundo: cor.alerta, texto: '#FFF' },
  dificil: { fundo: cor.erro, texto: '#FFF' },
};

/**
 * Revisão do gabarito (a mesma da Fase 3, no celular): marcações sobre a foto, um cartão
 * por questão com resposta, dificuldade e disciplina, e a marcação por intervalo
 * (ex.: questões 1 a 10 de Língua Portuguesa, 11 a 26 Fácil). Depois, aprovar.
 */
export default function RevisarGabarito() {
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const insets = useSafeAreaInsets();
  const [prova, setProva] = useState<Prova | null>(null);
  const [erroCarga, setErroCarga] = useState<string | null>(null);
  const [nome, setNome] = useState('');
  const [questoes, setQuestoes] = useState<Questao[]>([]);
  const [destaque, setDestaque] = useState<number | null>(null);
  const [alterado, setAlterado] = useState(false);
  const [enviando, setEnviando] = useState<'salvar' | 'aprovar' | null>(null);
  const [erros, setErros] = useState<string[]>([]);
  const [salvo, setSalvo] = useState(false);

  const [tentativa, setTentativa] = useState(0);

  useEffect(() => {
    let valida = true;
    api
      .verProva(id)
      .then((p) => {
        if (!valida) return;
        setErroCarga(null);
        setProva(p);
        setNome(p.nome);
        setQuestoes(p.questoes);
        // Abre o cartão da primeira questão que precisa de atenção.
        setDestaque(p.questoes.find((q) => q.duvidosa || !q.resposta)?.numero ?? null);
      })
      .catch((e) => valida && setErroCarga(mensagemDeErro(e)));
    return () => {
      valida = false;
    };
  }, [id, tentativa]);

  const mudar = useCallback((numero: number, campos: Partial<Questao>) => {
    // Escolher a resposta tira o aviso de leitura duvidosa daquela questão.
    setQuestoes((qs) =>
      qs.map((q) => (q.numero === numero ? { ...q, ...campos, ...('resposta' in campos ? { duvidosa: false } : {}) } : q)),
    );
    setAlterado(true);
    setSalvo(false);
  }, []);

  const mudarIntervalo = useCallback((de: number, ate: number, campos: Partial<Questao>) => {
    setQuestoes((qs) => qs.map((q) => (q.numero >= de && q.numero <= ate ? { ...q, ...campos } : q)));
    setAlterado(true);
    setSalvo(false);
  }, []);

  const respostas = useMemo(() => Object.fromEntries(questoes.map((q) => [q.numero, q.resposta])), [questoes]);
  const gabaritoFoto = memoria.gabarito(id);

  if (erroCarga) return <FalhaAoCarregar mensagem={erroCarga} tentarDeNovo={() => (setErroCarga(null), setTentativa((t) => t + 1))} />;
  if (!prova) return <Carregando />;

  const rascunho = prova.status === 'rascunho';
  const semResposta = questoes.filter((q) => !q.resposta).map((q) => q.numero);
  const semDisciplina = questoes.filter((q) => !q.disciplina).map((q) => q.numero);
  const semDificuldade = questoes.filter((q) => !q.dificuldade).map((q) => q.numero);
  const duvidosas = questoes.filter((q) => q.duvidosa).map((q) => q.numero);

  async function enviar(aprovar: boolean) {
    setEnviando(aprovar ? 'aprovar' : 'salvar');
    setErros([]);
    try {
      const p = aprovar ? await api.aprovarGabarito(id, nome, questoes) : await api.salvarGabarito(id, nome, questoes);
      setProva(p);
      setQuestoes(p.questoes);
      setAlterado(false);
      if (aprovar) {
        router.replace(`/prova/${id}`);
      } else {
        setSalvo(true);
      }
    } catch (e) {
      if (e instanceof ErroApi) {
        setErros(e.erros.length ? e.erros : [e.message]);
      } else {
        setErros([String(e)]);
      }
    } finally {
      setEnviando(null);
    }
  }

  function descartar() {
    const confirmar = async () => {
      try {
        await api.descartarProva(id);
        apagarOriginais(id);
        router.replace('/');
      } catch (e) {
        setErros([mensagemDeErro(e)]);
      }
    };
    if (Platform.OS === 'web') {
      if (window.confirm('Descartar este rascunho? A prova e a foto do gabarito serão apagadas.')) confirmar();
      return;
    }
    Alert.alert('Descartar rascunho?', 'A prova e a foto do gabarito serão apagadas.', [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Descartar', style: 'destructive', onPress: confirmar },
    ]);
  }

  const cabecalho = (
    <View style={{ gap: 16 }}>
      <Campo rotulo="Nome da prova" value={nome} onChangeText={(t) => (setNome(t), setAlterado(true))} />
      <Text style={estilos.dica}>
        {prova.formato ?? `${questoes.length} questões · alternativas ${prova.alternativas.join(', ')}`}
        {prova.modelo ? ` · ${prova.modelo}` : ''}
      </Text>

      {gabaritoFoto && !gabaritoFoto.leitura.girada ? (
        <FotoComMarcacoes
          foto={gabaritoFoto.foto}
          lidas={gabaritoFoto.leitura.respostas}
          respostas={respostas}
          alternativas={prova.alternativas}
          raio={gabaritoFoto.leitura.raio_bolinha}
          destaque={destaque}
        />
      ) : prova.imagem_gabarito_url ? (
        <ImagemAjustada uri={prova.imagem_gabarito_url} descricao="Gabarito lido, com as marcações" />
      ) : null}

      <Pendencias
        itens={[
          duvidosas.length > 0 && `Confira a leitura ${dasQuestoes(duvidosas)}.`,
          semResposta.length > 0 && `Falta a resposta ${dasQuestoes(semResposta)}.`,
          semDisciplina.length > 0 && `Falta a disciplina ${dasQuestoes(semDisciplina)}.`,
          semDificuldade.length > 0 && `Sem dificuldade ${dasQuestoes(semDificuldade)} (ajuda nos relatórios).`,
        ]}
      />

      <MarcarIntervalo total={questoes.length} disciplinas={prova.disciplinas} onAplicar={mudarIntervalo} />
      <Text style={estilos.secao}>Questões</Text>
    </View>
  );

  return (
    <View style={estilos.tela}>
      <Stack.Screen options={{ title: rascunho ? 'Revisar gabarito' : 'Editar gabarito' }} />
      <FlatList
        data={questoes}
        keyExtractor={(q) => String(q.numero)}
        contentContainerStyle={[estilos.conteudo, { paddingBottom: 150 + insets.bottom }]}
        ListHeaderComponent={cabecalho}
        ItemSeparatorComponent={() => <View style={{ height: 8 }} />}
        renderItem={({ item }) => (
          <CartaoQuestao
            questao={item}
            alternativas={prova.alternativas}
            disciplinas={prova.disciplinas}
            aberta={destaque === item.numero}
            onAbrir={setDestaque}
            onMudar={mudar}
          />
        )}
        ListFooterComponent={
          rascunho ? (
            <Botao titulo="Descartar rascunho" tipo="perigo" onPress={descartar} estilo={{ marginTop: 16 }} />
          ) : null
        }
        keyboardShouldPersistTaps="handled"
      />
      <View style={[estilosRodape, { paddingBottom: 12 + insets.bottom }]}>
        {erros.map((m) => (
          <Aviso key={m} texto={m} />
        ))}
        {salvo && <Aviso tipo="certo" texto="Revisão salva." />}
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Botao
            titulo="Salvar"
            tipo={rascunho ? 'secundario' : 'primario'}
            onPress={() => enviar(false)}
            carregando={enviando === 'salvar'}
            desativado={!alterado || enviando !== null}
            estilo={{ flex: 1 }}
          />
          {rascunho && (
            <Botao
              titulo="Aprovar gabarito"
              onPress={() => enviar(true)}
              carregando={enviando === 'aprovar'}
              desativado={enviando !== null}
              estilo={{ flex: 2 }}
            />
          )}
        </View>
      </View>
    </View>
  );
}

const estilosRodape = {
  position: 'absolute' as const,
  left: 0,
  right: 0,
  bottom: 0,
  padding: 12,
  gap: 8,
  backgroundColor: cor.superficie,
  borderTopWidth: 1,
  borderTopColor: cor.borda,
};

function dasQuestoes(numeros: number[]) {
  return (numeros.length === 1 ? 'da questão ' : 'das questões ') + listarNumeros(numeros);
}

function Pendencias({ itens }: { itens: (string | false)[] }) {
  const lista = itens.filter(Boolean) as string[];
  if (!lista.length) return <Aviso tipo="certo" texto="Tudo preenchido. Confira e aprove." />;
  return <Aviso tipo="alerta" texto={lista.join('\n')} />;
}

/** Disciplina e/ou dificuldade de várias questões de uma vez. */
function MarcarIntervalo({
  total,
  disciplinas,
  onAplicar,
}: {
  total: number;
  disciplinas: string[];
  onAplicar: (de: number, ate: number, campos: Partial<Questao>) => void;
}) {
  const [aberto, setAberto] = useState(false);
  const [de, setDe] = useState('1');
  const [ate, setAte] = useState(String(total));
  const [disciplina, setDisciplina] = useState<string | null>(null);
  const [dificuldade, setDificuldade] = useState<Dificuldade | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);

  if (!aberto) {
    return (
      <Botao titulo="Marcar várias questões de uma vez" tipo="secundario" onPress={() => setAberto(true)} />
    );
  }

  function aplicar() {
    const a = Number(de);
    const b = Number(ate);
    if (!Number.isInteger(a) || !Number.isInteger(b) || a < 1 || b > total || a > b) {
      setAviso(`Escolha um intervalo entre 1 e ${total}.`);
      return;
    }
    if (!disciplina && !dificuldade) {
      setAviso('Escolha a disciplina, a dificuldade ou as duas.');
      return;
    }
    onAplicar(a, b, { ...(disciplina ? { disciplina } : {}), ...(dificuldade ? { dificuldade } : {}) });
    setAviso(`Aplicado às questões ${a} a ${b}.`);
    setDe(String(Math.min(b + 1, total)));
    setAte(String(total));
  }

  const campoNumero = (valor: string, mudar: (t: string) => void, rotulo: string) => (
    <TextInput
      value={valor}
      onChangeText={(t) => mudar(t.replace(/\D/g, ''))}
      keyboardType="number-pad"
      accessibilityLabel={rotulo}
      style={[estilos.campo, { width: 70, textAlign: 'center' }]}
      maxLength={3}
    />
  );

  return (
    <View style={estilos.cartao}>
      <Text style={estilos.rotulo}>Marcar várias questões</Text>
      <View style={estilos.linha}>
        <Text style={estilos.texto}>Da questão</Text>
        {campoNumero(de, setDe, 'Primeira questão')}
        <Text style={estilos.texto}>até a</Text>
        {campoNumero(ate, setAte, 'Última questão')}
      </View>
      <Secao titulo="Disciplina">
        <View style={estilos.linha}>
          {disciplinas.map((d) => (
            <Chip key={d} rotulo={d} compacto marcado={disciplina === d} onPress={() => setDisciplina(disciplina === d ? null : d)} />
          ))}
        </View>
      </Secao>
      <Secao titulo="Dificuldade">
        <View style={estilos.linha}>
          {DIFICULDADES.map((d) => (
            <Chip
              key={d}
              rotulo={NOME_DIFICULDADE[d]}
              marcado={dificuldade === d}
              cores={COR_DIFICULDADE[d]}
              onPress={() => setDificuldade(dificuldade === d ? null : d)}
            />
          ))}
        </View>
      </Secao>
      {aviso && <Text style={estilos.dica}>{aviso}</Text>}
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <Botao titulo="Fechar" tipo="secundario" onPress={() => setAberto(false)} estilo={{ flex: 1 }} />
        <Botao titulo="Aplicar" onPress={aplicar} estilo={{ flex: 1 }} />
      </View>
    </View>
  );
}

const CartaoQuestao = memo(function CartaoQuestao({
  questao: q,
  alternativas,
  disciplinas,
  aberta,
  onAbrir,
  onMudar,
}: {
  questao: Questao;
  alternativas: string[];
  disciplinas: string[];
  aberta: boolean;
  onAbrir: (n: number | null) => void;
  onMudar: (n: number, campos: Partial<Questao>) => void;
}) {
  const falta = !q.resposta || !q.disciplina;
  return (
    <View
      style={[
        estilos.cartao,
        aberta && { borderColor: cor.primaria, borderWidth: 2 },
        (q.duvidosa || falta) && !aberta && { borderColor: cor.alerta },
      ]}
    >
      <Pressable
        onPress={() => onAbrir(aberta ? null : q.numero)}
        accessibilityRole="button"
        style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}
      >
        <Text style={[estilos.rotulo, { fontSize: 17 }]}>Questão {q.numero}</Text>
        {q.duvidosa && (
          <Text style={{ color: cor.alerta, fontWeight: '700', fontSize: 13 }}>CONFIRA A LEITURA</Text>
        )}
        <Text style={[estilos.dica, { flex: 1, textAlign: 'right' }]} numberOfLines={1}>
          {[q.disciplina ?? 'sem disciplina', q.habilidade].filter(Boolean).join(' · ')} {aberta ? '▲' : '▼'}
        </Text>
      </Pressable>
      <View style={estilos.linha}>
        {alternativas.map((a) => (
          <Chip key={a} rotulo={a} marcado={q.resposta === a} onPress={() => onMudar(q.numero, { resposta: a })} />
        ))}
        <View style={{ width: 8 }} />
        {DIFICULDADES.map((d) => (
          <Chip
            key={d}
            compacto
            rotulo={NOME_DIFICULDADE[d]}
            marcado={q.dificuldade === d}
            cores={COR_DIFICULDADE[d]}
            onPress={() => onMudar(q.numero, { dificuldade: q.dificuldade === d ? null : d })}
          />
        ))}
      </View>
      {aberta && (
        <View style={{ gap: 8 }}>
          <Text style={estilos.rotulo}>Disciplina</Text>
          <View style={estilos.linha}>
            {disciplinas.map((d) => (
              <Chip
                key={d}
                compacto
                rotulo={d}
                marcado={q.disciplina === d}
                onPress={() => onMudar(q.numero, { disciplina: d })}
              />
            ))}
          </View>
          <Campo
            rotulo="Habilidade (opcional)"
            value={q.habilidade ?? ''}
            onChangeText={(t) => onMudar(q.numero, { habilidade: t.toUpperCase() || null })}
            placeholder="D07"
            autoCapitalize="characters"
            maxLength={20}
          />
        </View>
      )}
    </View>
  );
});
