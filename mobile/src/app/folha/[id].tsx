import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';

import { GradeRespostas } from '@/componentes/GradeRespostas';
import { Aviso, Botao, Carregando, Chip, cor, estilos, FalhaAoCarregar, ImagemAjustada } from '@/componentes/ui';
import { api, mensagemDeErro } from '@/lib/api';
import { pct } from '@/lib/formato';
import type { FolhaDetalhe } from '@/lib/tipos';

/** Uma folha lida: imagem anotada pelo leitor e correção manual das respostas. */
export default function VerFolha() {
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const [folha, setFolha] = useState<FolhaDetalhe | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [correcoes, setCorrecoes] = useState<Record<number, string | null>>({});
  const [todas, setTodas] = useState(false);
  const [salvando, setSalvando] = useState(false);
  const [mensagem, setMensagem] = useState<{ texto: string; tipo: 'erro' | 'certo' } | null>(null);

  const [tentativa, setTentativa] = useState(0);

  useEffect(() => {
    let valida = true;
    api
      .verFolha(id)
      .then((f) => {
        if (!valida) return;
        setErro(null);
        setFolha(f);
        // Na abertura, só as duvidosas (ou todas, se não houver nenhuma).
        setTodas(f.revisar.length === 0);
      })
      .catch((e) => valida && setErro(mensagemDeErro(e)));
    return () => {
      valida = false;
    };
  }, [id, tentativa]);

  if (erro) return <FalhaAoCarregar mensagem={erro} tentarDeNovo={() => (setErro(null), setTentativa((t) => t + 1))} />;
  if (!folha) return <Carregando />;

  const respostas = folha.respostas.map((r, i) => (i + 1 in correcoes ? correcoes[i + 1] : r));
  const acertos = respostas.filter((r, i) => r !== null && r === folha.gabarito[i]).length;
  const numeros = todas ? respostas.map((_, i) => i + 1) : folha.revisar;

  async function salvar() {
    setSalvando(true);
    setMensagem(null);
    try {
      await api.revisarFolha(id, correcoes);
      setCorrecoes({});
      setFolha(await api.verFolha(id)); // mantém o filtro escolhido
      setMensagem({ texto: 'Correção salva.', tipo: 'certo' });
    } catch (e) {
      setMensagem({ texto: mensagemDeErro(e), tipo: 'erro' });
    } finally {
      setSalvando(false);
    }
  }

  return (
    <ScrollView style={estilos.tela} contentContainerStyle={estilos.conteudo}>
      <Stack.Screen options={{ title: `Folha · turma ${folha.turma}` }} />
      <View style={[estilos.cartao, { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }]}>
        <Text style={[estilos.titulo, { fontSize: 26 }]}>
          {acertos}/{folha.questoes}
        </Text>
        <Text style={[estilos.titulo, { color: cor.primaria }]}>{pct((acertos / folha.questoes) * 100)}</Text>
      </View>
      {folha.revisada && <Text style={estilos.dica}>Esta folha já foi revisada por um professor.</Text>}
      {folha.imagem_url && (
        <ImagemAjustada uri={folha.imagem_url} descricao="Folha lida, com as marcações do leitor" />
      )}
      <View style={estilos.linha}>
        <Chip rotulo={`A conferir (${folha.revisar.length})`} marcado={!todas} onPress={() => setTodas(false)} />
        <Chip rotulo="Todas as questões" marcado={todas} onPress={() => setTodas(true)} />
      </View>
      {numeros.length === 0 ? (
        <Text style={estilos.dica}>Nenhuma questão para conferir.</Text>
      ) : (
        <GradeRespostas
          numeros={numeros}
          respostas={respostas}
          gabarito={folha.gabarito}
          alternativas={folha.alternativas}
          revisar={folha.revisar}
          onMudar={(n, r) => setCorrecoes((c) => ({ ...c, [n]: r }))}
        />
      )}
      {mensagem && <Aviso texto={mensagem.texto} tipo={mensagem.tipo} />}
      <Botao
        titulo="Salvar correção"
        onPress={salvar}
        carregando={salvando}
        desativado={Object.keys(correcoes).length === 0}
      />
    </ScrollView>
  );
}
