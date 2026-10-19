import { router, Stack, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';

import { Aviso, Botao, Carregando, Chip, cor, estilos, FalhaAoCarregar } from '@/componentes/ui';
import { api, ErroApi } from '@/lib/api';
import { useSessao } from '@/lib/sessao';
import type { ProvaResumo } from '@/lib/tipos';

export default function MinhasProvas() {
  const { config, erroConfig, recarregarConfig, sair, sessao } = useSessao();
  const anoAtual = new Date().getFullYear();
  const [ano, setAno] = useState(anoAtual);
  const [periodo, setPeriodo] = useState<number | undefined>(undefined);
  const [provas, setProvas] = useState<ProvaResumo[] | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [atualizando, setAtualizando] = useState(false);

  const carregar = useCallback(async () => {
    setErro(null);
    try {
      setProvas(await api.listarProvas({ ano, periodo }));
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : String(e));
    }
  }, [ano, periodo]);

  // Recarrega ao voltar para a tela (depois de cadastrar, aprovar ou escanear).
  useFocusEffect(
    useCallback(() => {
      carregar();
    }, [carregar]),
  );

  async function atualizar() {
    setAtualizando(true);
    await carregar();
    setAtualizando(false);
  }

  if (erroConfig) return <FalhaAoCarregar mensagem={erroConfig} tentarDeNovo={recarregarConfig} />;
  if (!config) return <Carregando texto="Conectando ao servidor..." />;

  return (
    <View style={estilos.tela}>
      <Stack.Screen
        options={{
          headerRight: () => (
            <Pressable onPress={sair} hitSlop={10} accessibilityRole="button">
              <Text style={{ color: cor.primaria, fontSize: 16, fontWeight: '600' }}>Sair</Text>
            </Pressable>
          ),
        }}
      />
      <FlatList
        data={provas ?? []}
        keyExtractor={(p) => String(p.id)}
        contentContainerStyle={[estilos.conteudo, { paddingBottom: 110 }]}
        refreshControl={<RefreshControl refreshing={atualizando} onRefresh={atualizar} />}
        ListHeaderComponent={
          <View style={{ gap: 10 }}>
            <Text style={estilos.dica}>{sessao?.user.email}</Text>
            <View style={estilos.linha}>
              {[anoAtual - 1, anoAtual].map((a) => (
                <Chip key={a} rotulo={String(a)} marcado={ano === a} onPress={() => setAno(a)} />
              ))}
            </View>
            <View style={estilos.linha}>
              <Chip rotulo="Todas" marcado={periodo === undefined} onPress={() => setPeriodo(undefined)} />
              {config.periodos.map((p) => (
                <Chip key={p.numero} rotulo={p.nome} marcado={periodo === p.numero} onPress={() => setPeriodo(p.numero)} />
              ))}
            </View>
            {erro && <Aviso texto={erro} />}
            {provas === null && !erro && <Carregando />}
          </View>
        }
        ListEmptyComponent={
          provas !== null ? (
            <Text style={[estilos.dica, { textAlign: 'center', marginTop: 24 }]}>
              Nenhuma prova neste filtro. Toque em “Nova prova” para cadastrar o gabarito.
            </Text>
          ) : null
        }
        renderItem={({ item }) => <CartaoProva prova={item} />}
        ItemSeparatorComponent={() => <View style={{ height: 10 }} />}
      />
      <View style={{ position: 'absolute', left: 16, right: 16, bottom: 24 }}>
        <Botao titulo="+ Nova prova" onPress={() => router.push('/nova-prova')} />
      </View>
    </View>
  );
}

function CartaoProva({ prova }: { prova: ProvaResumo }) {
  const rascunho = prova.status === 'rascunho';
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => router.push(rascunho ? `/prova/${prova.id}/revisar` : `/prova/${prova.id}`)}
      style={({ pressed }) => [estilos.cartao, pressed && { opacity: 0.8 }]}
    >
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
        <Text style={[estilos.rotulo, { flex: 1, fontSize: 16 }]}>{prova.nome}</Text>
        <Text
          style={{
            fontSize: 13,
            fontWeight: '700',
            color: rascunho ? cor.alerta : cor.certo,
            backgroundColor: rascunho ? cor.alertaClaro : cor.certoClaro,
            paddingHorizontal: 8,
            paddingVertical: 2,
            borderRadius: 8,
            alignSelf: 'flex-start',
          }}
        >
          {rascunho ? 'Revisar gabarito' : 'Ativa'}
        </Text>
      </View>
      <Text style={estilos.dica}>
        {prova.periodo_nome}
        {prova.caderno ? ` · caderno ${prova.caderno}` : ''}
      </Text>
      {!rascunho && (
        <Text style={estilos.texto}>
          {prova.turmas} {prova.turmas === 1 ? 'turma' : 'turmas'} · {prova.folhas}{' '}
          {prova.folhas === 1 ? 'folha corrigida' : 'folhas corrigidas'}
        </Text>
      )}
    </Pressable>
  );
}
