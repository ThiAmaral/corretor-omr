import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';

import { Carregando, cor } from '@/componentes/ui';
import { ProvedorDeSessao, useSessao } from '@/lib/sessao';

export default function Raiz() {
  return (
    <ProvedorDeSessao>
      <StatusBar style="dark" />
      <Navegacao />
    </ProvedorDeSessao>
  );
}

function Navegacao() {
  const { sessao, carregando } = useSessao();
  if (carregando) return <Carregando />;
  const logado = sessao !== null;
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: cor.superficie },
        headerTintColor: cor.primaria,
        headerTitleStyle: { color: cor.texto },
        contentStyle: { backgroundColor: cor.fundo },
        headerBackButtonDisplayMode: 'minimal',
      }}
    >
      <Stack.Protected guard={!logado}>
        <Stack.Screen name="login" options={{ headerShown: false }} />
      </Stack.Protected>
      <Stack.Protected guard={logado}>
        <Stack.Screen name="index" options={{ title: 'Minhas provas' }} />
        <Stack.Screen name="nova-prova" options={{ title: 'Nova prova' }} />
        <Stack.Screen name="prova/[id]/index" options={{ title: 'Prova' }} />
        <Stack.Screen name="prova/[id]/revisar" options={{ title: 'Revisar gabarito' }} />
        <Stack.Screen name="prova/[id]/escanear" options={{ title: 'Escanear turma', headerShown: false }} />
        <Stack.Screen name="prova/[id]/resultado" options={{ title: 'Resultado da turma' }} />
        <Stack.Screen name="folha/[id]" options={{ title: 'Folha' }} />
      </Stack.Protected>
    </Stack>
  );
}
