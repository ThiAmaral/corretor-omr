import { Redirect, router, Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';

import { Aviso, Botao, Carregando, Chip, cor, estilos, FalhaAoCarregar, Secao } from '@/componentes/ui';
import { api, ErroApi } from '@/lib/api';
import { pct } from '@/lib/formato';
import { apagarOriginais, contarOriginais } from '@/lib/fotosLocais';
import type { Prova, Relatorio } from '@/lib/tipos';

/** Prova aprovada: turmas já corrigidas, escanear uma turma e ver o resultado. */
export default function DetalheProva() {
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const [prova, setProva] = useState<Prova | null>(null);
  const [relatorio, setRelatorio] = useState<Relatorio | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [turma, setTurma] = useState('');
  const [avisoTurma, setAvisoTurma] = useState<string | null>(null);
  const [fotosLocais, setFotosLocais] = useState(0);

  const carregar = useCallback(async () => {
    setErro(null);
    try {
      const p = await api.verProva(id);
      setProva(p);
      setFotosLocais(contarOriginais(id));
      if (p.status === 'ativa') setRelatorio(await api.relatorio(id));
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : String(e));
    }
  }, [id]);

  useFocusEffect(
    useCallback(() => {
      carregar();
    }, [carregar]),
  );

  if (erro) return <FalhaAoCarregar mensagem={erro} tentarDeNovo={carregar} />;
  if (!prova) return <Carregando />;
  if (prova.status === 'rascunho') return <Redirect href={`/prova/${id}/revisar`} />;

  function escanear(nomeTurma: string) {
    const t = nomeTurma.trim().toUpperCase();
    if (!t) {
      setAvisoTurma('Escreva o nome da turma, por exemplo 8A.');
      return;
    }
    setAvisoTurma(null);
    router.push({ pathname: '/prova/[id]/escanear', params: { id: String(id), turma: t } });
  }

  function apagarFotos() {
    const apagar = () => {
      apagarOriginais(id);
      setFotosLocais(contarOriginais(id));
    };
    const texto = `As ${fotosLocais} fotos originais desta prova serão apagadas deste celular. As notas e as leituras continuam no sistema.`;
    if (Platform.OS === 'web') {
      if (window.confirm(texto)) apagar();
      return;
    }
    Alert.alert('Apagar as fotos?', texto, [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Apagar', style: 'destructive', onPress: apagar },
    ]);
  }

  const turmas = relatorio?.resumo ?? [];
  return (
    <ScrollView style={estilos.tela} contentContainerStyle={estilos.conteudo} keyboardShouldPersistTaps="handled">
      <Stack.Screen options={{ title: prova.nome }} />
      <View style={{ gap: 4 }}>
        <Text style={estilos.titulo}>{prova.nome}</Text>
        <Text style={estilos.dica}>
          {prova.periodo_nome} de {prova.ano}
          {prova.caderno ? ` · caderno ${prova.caderno}` : ''} · {prova.questoes.length} questões
        </Text>
      </View>

      <Secao titulo="Escanear turma">
        <View style={estilos.cartao}>
          {turmas.length > 0 && (
            <>
              <Text style={estilos.dica}>Continuar uma turma:</Text>
              <View style={estilos.linha}>
                {turmas.map((t) => (
                  <Chip key={t.turma} rotulo={t.turma} marcado={false} onPress={() => escanear(t.turma)} />
                ))}
              </View>
              <Text style={estilos.dica}>Ou uma turma nova:</Text>
            </>
          )}
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <TextInput
              value={turma}
              onChangeText={setTurma}
              placeholder="Turma, ex.: 8A"
              placeholderTextColor="#8A94A3"
              autoCapitalize="characters"
              autoCorrect={false}
              maxLength={20}
              onSubmitEditing={() => escanear(turma)}
              style={[estilos.campo, { flex: 1 }]}
              accessibilityLabel="Turma"
            />
            <Botao titulo="Escanear" onPress={() => escanear(turma)} />
          </View>
          {avisoTurma && <Text style={{ color: cor.erro }}>{avisoTurma}</Text>}
        </View>
      </Secao>

      <Secao titulo="Turmas corrigidas">
        {relatorio === null ? (
          <Carregando />
        ) : turmas.length === 0 ? (
          <Text style={estilos.dica}>Nenhuma folha corrigida ainda.</Text>
        ) : (
          turmas.map((t) => (
            <Pressable
              key={t.turma}
              accessibilityRole="button"
              onPress={() => router.push({ pathname: '/prova/[id]/resultado', params: { id: String(id), turma: t.turma } })}
              style={({ pressed }) => [estilos.cartao, pressed && { opacity: 0.8 }]}
            >
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={[estilos.rotulo, { fontSize: 18 }]}>{t.turma}</Text>
                <Text style={[estilos.rotulo, { fontSize: 18, color: cor.primaria }]}>{pct(t.acerto)}</Text>
              </View>
              <Text style={estilos.dica}>
                {t.folhas} {t.folhas === 1 ? 'folha' : 'folhas'} · {t.para_reforco} para reforço
                {t.a_revisar > 0 ? ` · ${t.a_revisar} a conferir` : ''}
              </Text>
            </Pressable>
          ))
        )}
        {relatorio && relatorio.recusadas > 0 && (
          <Aviso tipo="alerta" texto={`${relatorio.recusadas} folhas enviadas pelo painel não puderam ser lidas.`} />
        )}
      </Secao>

      {fotosLocais > 0 && (
        <Secao titulo="Fotos neste celular">
          <View style={estilos.cartao}>
            <Text style={estilos.texto}>
              {fotosLocais} {fotosLocais === 1 ? 'foto original guardada' : 'fotos originais guardadas'} só neste
              aparelho, numa pasta do app (fora da galeria). O servidor recebeu apenas a área de respostas e o QR.
            </Text>
            <Botao titulo="Apagar as fotos deste celular" tipo="secundario" onPress={apagarFotos} />
          </View>
        </Secao>
      )}

      <Botao titulo="Editar gabarito" tipo="secundario" onPress={() => router.push(`/prova/${id}/revisar`)} />
    </ScrollView>
  );
}
