import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';

import { CameraFolha, type FotoCapturada } from '@/componentes/CameraFolha';
import { Aviso, Botao, Campo, Carregando, Chip, Secao, estilos } from '@/componentes/ui';
import { api, ErroApi, type Foto } from '@/lib/api';
import { prepararFoto } from '@/lib/foto';
import { guardarOriginal } from '@/lib/fotosLocais';
import { memoria } from '@/lib/memoria';
import { apagar, ErroRecorte, recortarFolha, type Recorte } from '@/lib/recorte';
import { useSessao } from '@/lib/sessao';

/** Nova prova: ano, período e o gabarito oficial, fotografado ou digitado. */
export default function NovaProva() {
  const { config } = useSessao();
  const anoAtual = new Date().getFullYear();
  const [ano, setAno] = useState(anoAtual);
  const [periodoEscolhido, setPeriodo] = useState<number | null>(null);
  // Sem escolha do professor, o período atual da escola (a configuração pode chegar depois da tela abrir).
  const periodo = periodoEscolhido ?? config?.periodo_atual ?? 1;
  const [nome, setNome] = useState('');
  const [modo, setModo] = useState<'escolher' | 'camera' | 'digitar'>('escolher');
  const [digitado, setDigitado] = useState('');
  const [enviando, setEnviando] = useState<string | null>(null);
  const [erro, setErro] = useState<string | null>(null);

  if (!config) return <Carregando />;

  async function cadastrar(dados: { recorte?: Recorte; original?: Foto; digitado?: string }) {
    setErro(null);
    try {
      const { prova, leitura } = await api.cadastrarProva({
        ano,
        periodo,
        nome: nome.trim() || undefined,
        recorte: dados.recorte,
        digitado: dados.digitado,
      });
      if (dados.recorte && leitura) memoria.guardarGabarito(prova.id, dados.recorte.area, leitura);
      if (dados.original) await guardarOriginal(prova.id, 'gabarito.jpg', dados.original);
      router.replace(`/prova/${prova.id}/revisar`);
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : String(e));
      setModo(dados.recorte ? 'escolher' : 'digitar');
    } finally {
      setEnviando(null);
    }
  }

  async function aoFotografar(capturada: FotoCapturada) {
    setModo('escolher');
    setEnviando('Procurando os 4 quadrados pretos...');
    let original: Foto | null = null;
    try {
      // Só o recorte (área de respostas + QR) vai para o servidor; a foto fica no celular.
      original = await prepararFoto(capturada);
      const recorte = await recortarFolha(original);
      setEnviando('Lendo o gabarito...');
      await cadastrar({ recorte, original });
    } catch (e) {
      setEnviando(null);
      setErro(e instanceof ErroRecorte ? e.message : `Não foi possível preparar a foto: ${String(e)}`);
    } finally {
      if (original) apagar(original.uri);
    }
  }

  if (modo === 'camera') {
    return (
      <>
        <Stack.Screen options={{ headerShown: false }} />
        <CameraFolha titulo="Gabarito oficial" onFoto={aoFotografar} onFechar={() => setModo('escolher')} />
      </>
    );
  }
  if (enviando) return <Carregando texto={enviando} />;

  const letras = digitado.toUpperCase().replace(/[^A-Z]/g, '');
  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={estilos.tela}>
      <ScrollView contentContainerStyle={estilos.conteudo} keyboardShouldPersistTaps="handled">
        <Secao titulo="Ano letivo">
          <View style={estilos.linha}>
            {[anoAtual - 1, anoAtual, anoAtual + 1].map((a) => (
              <Chip key={a} rotulo={String(a)} marcado={ano === a} onPress={() => setAno(a)} />
            ))}
          </View>
        </Secao>
        <Secao titulo={config.periodo_rotulo ?? 'Período'}>
          <View style={estilos.linha}>
            {config.periodos.map((p) => (
              <Chip key={p.numero} rotulo={p.nome} marcado={periodo === p.numero} onPress={() => setPeriodo(p.numero)} />
            ))}
          </View>
        </Secao>
        <Campo
          rotulo="Nome da prova (opcional)"
          dica="Com a foto, o nome é sugerido pelo caderno do QR; dá para trocar na revisão."
          value={nome}
          onChangeText={setNome}
          placeholder="AMA – Matemática 8º ano"
        />

        {erro && <Aviso texto={erro} />}

        <Secao titulo="Gabarito oficial">
          {modo === 'digitar' ? (
            <View style={{ gap: 10 }}>
              <Campo
                rotulo="Respostas em ordem"
                dica="Uma letra por questão, sem espaços. O formato da folha é reconhecido na primeira folha de aluno."
                value={digitado}
                onChangeText={setDigitado}
                autoCapitalize="characters"
                autoCorrect={false}
                placeholder="CBDAABCD..."
                style={{ fontFamily: Platform.OS === 'ios' ? 'Menlo' : 'monospace', letterSpacing: 2 }}
              />
              <Text style={estilos.dica}>
                {letras.length} {letras.length === 1 ? 'questão' : 'questões'}
              </Text>
              <Botao
                titulo="Revisar gabarito"
                desativado={letras.length === 0}
                onPress={() => {
                  setEnviando('Criando a prova...');
                  cadastrar({ digitado: letras });
                }}
              />
              <Botao titulo="Fotografar em vez de digitar" tipo="secundario" onPress={() => setModo('camera')} />
            </View>
          ) : (
            <View style={{ gap: 10 }}>
              <Botao titulo="Fotografar o gabarito" onPress={() => setModo('camera')} />
              <Botao titulo="Digitar respostas" tipo="secundario" onPress={() => setModo('digitar')} />
              <Text style={estilos.dica}>
                Com a foto, o app reconhece o caderno, o número de questões e as alternativas. Nos dois casos, você
                confere tudo na tela de revisão antes de aprovar.
              </Text>
            </View>
          )}
        </Secao>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
