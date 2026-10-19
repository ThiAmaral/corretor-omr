import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ScrollView, Text, useWindowDimensions, View } from 'react-native';

import { CameraFolha, type FotoCapturada } from '@/componentes/CameraFolha';
import { GradeRespostas } from '@/componentes/GradeRespostas';
import { Aviso, Botao, Carregando, cor, estilos, FalhaAoCarregar } from '@/componentes/ui';
import { api, mensagemDeErro, type Foto } from '@/lib/api';
import { listarNumeros, pct } from '@/lib/formato';
import { prepararFoto } from '@/lib/foto';
import { guardarOriginal } from '@/lib/fotosLocais';
import { apagar, ErroRecorte, recortarFolha } from '@/lib/recorte';
import type { Folha, Prova, ResultadoEnvio } from '@/lib/tipos';

type Estado =
  | { tipo: 'pronto' }
  | { tipo: 'lendo'; etapa: string }
  | { tipo: 'lida'; folha: Folha; correcoes: Record<number, string | null>; todas: boolean }
  | { tipo: 'recusada'; mensagem: string; codigo: string | null };

/**
 * Escanear a turma: a câmera fica aberta; a cada foto o app envia a folha e mostra na
 * hora a nota e as questões duvidosas. O professor confirma ou corrige e passa à próxima.
 */
export default function EscanearTurma() {
  const params = useLocalSearchParams<{ id: string; turma: string }>();
  const id = Number(params.id);
  const turma = String(params.turma ?? '');
  const { height } = useWindowDimensions();
  const [prova, setProva] = useState<Prova | null>(null);
  const [erroCarga, setErroCarga] = useState<string | null>(null);
  const [lidas, setLidas] = useState(0);
  const [estado, setEstado] = useState<Estado>({ tipo: 'pronto' });
  const [salvando, setSalvando] = useState(false);
  const [erroSalvar, setErroSalvar] = useState<string | null>(null);
  const enviadas = useRef(0);

  const [tentativa, setTentativa] = useState(0);

  useEffect(() => {
    let valida = true;
    Promise.all([api.verProva(id), api.listarFolhas(id, turma)])
      .then(([p, f]) => {
        if (!valida) return;
        setErroCarga(null);
        setProva(p);
        setLidas(f.folhas.length);
        enviadas.current = f.folhas.length + f.recusadas.length;
      })
      .catch((e) => valida && setErroCarga(mensagemDeErro(e)));
    return () => {
      valida = false;
    };
  }, [id, turma, tentativa]);

  if (erroCarga) {
    return <FalhaAoCarregar mensagem={erroCarga} tentarDeNovo={() => (setErroCarga(null), setTentativa((t) => t + 1))} />;
  }
  if (!prova) return <Carregando />;
  const gabarito = prova.questoes.map((q) => q.resposta);

  async function aoFotografar(capturada: FotoCapturada) {
    setEstado({ tipo: 'lendo', etapa: 'Procurando os 4 quadrados pretos...' });
    setErroSalvar(null);
    let foto: Foto | null = null;
    try {
      // Só o recorte (área de respostas + QR) vai para o servidor; a foto fica no celular.
      foto = await prepararFoto(capturada);
      const recorte = await recortarFolha(foto);
      setEstado({ tipo: 'lendo', etapa: 'Lendo a folha...' });
      enviadas.current += 1;
      const r = await api.enviarFolha(id, turma, recorte, enviadas.current);
      apagar(recorte.area.uri);
      if (recorte.qr) apagar(recorte.qr.uri);
      setLidas(r.folhas_da_turma);
      const resultado: ResultadoEnvio | undefined = r.folhas[0];
      if (!resultado) {
        setEstado({ tipo: 'recusada', mensagem: 'O servidor não devolveu a leitura. Tire outra foto.', codigo: null });
      } else if (resultado.ok) {
        await guardarOriginal(id, `${turma}_folha_${resultado.id}.jpg`, foto);
        setEstado({ tipo: 'lida', folha: resultado, correcoes: {}, todas: false });
      } else {
        setEstado({ tipo: 'recusada', mensagem: resultado.erro, codigo: resultado.codigo });
      }
    } catch (e) {
      const codigo = e instanceof ErroRecorte ? e.codigo : null;
      setEstado({ tipo: 'recusada', mensagem: mensagemDeErro(e), codigo });
    } finally {
      if (foto) apagar(foto.uri);
    }
  }

  /** Confirma a folha (gravando as correções, se houver) e libera a câmera para a próxima. */
  async function confirmar() {
    if (estado.tipo !== 'lida') return;
    const mudadas = Object.keys(estado.correcoes).length > 0;
    if (mudadas) {
      setSalvando(true);
      setErroSalvar(null);
      try {
        await api.revisarFolha(estado.folha.id, estado.correcoes);
      } catch (e) {
        setErroSalvar(mensagemDeErro(e));
        setSalvando(false);
        return;
      }
      setSalvando(false);
    }
    setEstado({ tipo: 'pronto' });
  }

  function corrigir(numero: number, resposta: string | null) {
    if (estado.tipo !== 'lida') return;
    setEstado({ ...estado, correcoes: { ...estado.correcoes, [numero]: resposta } });
  }

  function concluir() {
    router.replace({ pathname: '/prova/[id]/resultado', params: { id: String(id), turma } });
  }

  let painel = null;
  if (estado.tipo === 'lendo') {
    painel = (
      <View style={[estilos.cartao, { alignItems: 'center' }]}>
        <Text style={estilos.rotulo}>{estado.etapa}</Text>
      </View>
    );
  } else if (estado.tipo === 'recusada') {
    const outroCaderno = estado.codigo === 'caderno_diferente' || estado.codigo === 'formato_diferente';
    painel = (
      <View style={estilos.cartao}>
        <Text style={[estilos.rotulo, { color: cor.erro }]}>
          {outroCaderno ? 'Folha de outra prova' : 'A folha não foi lida'}
        </Text>
        <Text style={estilos.texto}>{estado.mensagem}</Text>
        <Text style={estilos.dica}>Ela não foi gravada. Tire outra foto.</Text>
        <Botao titulo="Tirar outra foto" onPress={() => setEstado({ tipo: 'pronto' })} />
      </View>
    );
  } else if (estado.tipo === 'lida') {
    const { folha, correcoes, todas } = estado;
    const respostas = folha.respostas.map((r, i) => (i + 1 in correcoes ? correcoes[i + 1] : r));
    const acertos = respostas.filter((r, i) => r !== null && r === gabarito[i]).length;
    const numeros = todas ? respostas.map((_, i) => i + 1) : folha.revisar;
    painel = (
      <View style={[estilos.cartao, { maxHeight: height * 0.62 }]}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline' }}>
          <Text style={[estilos.titulo, { fontSize: 24 }]}>
            {acertos}/{folha.questoes}
          </Text>
          <Text style={[estilos.titulo, { color: cor.primaria }]}>{pct((acertos / folha.questoes) * 100)}</Text>
        </View>
        {folha.revisar.length > 0 ? (
          <Aviso tipo="alerta" texto={`Confira ${folha.revisar.length === 1 ? 'a questão' : 'as questões'} ${listarNumeros(folha.revisar)}: a marcação ficou fraca ou dupla.`} />
        ) : (
          <Text style={{ color: cor.certo, fontWeight: '600' }}>Leitura sem dúvidas.</Text>
        )}
        {numeros.length > 0 && (
          <ScrollView style={{ flexGrow: 0 }} nestedScrollEnabled>
            <GradeRespostas
              numeros={numeros}
              respostas={respostas}
              gabarito={gabarito}
              alternativas={prova.alternativas}
              revisar={folha.revisar}
              onMudar={corrigir}
            />
          </ScrollView>
        )}
        {erroSalvar && <Aviso texto={erroSalvar} />}
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <Botao
            titulo={todas ? 'Só as duvidosas' : 'Ver todas'}
            tipo="secundario"
            onPress={() => setEstado({ ...estado, todas: !todas })}
            estilo={{ flex: 1 }}
          />
          <Botao
            titulo={Object.keys(correcoes).length ? 'Salvar e próxima' : 'Confirmar e próxima'}
            onPress={confirmar}
            carregando={salvando}
            estilo={{ flex: 2 }}
          />
        </View>
      </View>
    );
  }

  return (
    <CameraFolha
      titulo={`Turma ${turma}`}
      onFoto={aoFotografar}
      onFechar={concluir}
      cadernoEsperado={prova.caderno}
      ocupado={estado.tipo !== 'pronto'}
      lendo={estado.tipo === 'lendo'}
      topo={
        <View style={{ alignItems: 'center', gap: 6, paddingTop: 8 }}>
          <Text style={contador} accessibilityLiveRegion="polite">
            {lidas} {lidas === 1 ? 'folha lida' : 'folhas lidas'}
          </Text>
          {estado.tipo === 'pronto' && lidas > 0 && (
            <Botao titulo="Concluir e ver resultado" tipo="secundario" onPress={concluir} />
          )}
        </View>
      }
      painel={painel}
    />
  );
}

const contador = {
  color: '#FFF',
  fontSize: 18,
  fontWeight: '700' as const,
  backgroundColor: 'rgba(31,95,173,0.9)',
  paddingHorizontal: 14,
  paddingVertical: 6,
  borderRadius: 16,
  overflow: 'hidden' as const,
};
