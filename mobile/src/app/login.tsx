import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Aviso, Botao, Campo, cor, estilos } from '@/componentes/ui';
import { useSessao } from '@/lib/sessao';
import { configuracaoFaltando } from '@/lib/supabase';

export default function Login() {
  const { entrar } = useSessao();
  const [email, setEmail] = useState('');
  const [senha, setSenha] = useState('');
  const [erro, setErro] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  async function aoEntrar() {
    if (!email.trim() || !senha) {
      setErro('Informe o e-mail e a senha.');
      return;
    }
    setEnviando(true);
    setErro(null);
    const mensagem = await entrar(email, senha);
    setEnviando(false);
    if (mensagem) setErro(mensagem);
    // com sucesso, a navegação troca de tela sozinha (Stack.Protected)
  }

  return (
    <SafeAreaView style={estilos.tela}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={[estilos.conteudo, { flexGrow: 1, justifyContent: 'center' }]}>
          <View style={{ gap: 6, marginBottom: 8 }}>
            <Text style={{ fontSize: 28, fontWeight: '800', color: cor.primaria }}>Corretor AMA</Text>
            <Text style={estilos.dica}>Correção do simulado pelo celular. Entre com a conta de professor.</Text>
          </View>
          {configuracaoFaltando.length > 0 && (
            <Aviso tipo="alerta" texto={`Configure ${configuracaoFaltando.join(', ')} no mobile/.env e reinicie com "npx expo start --clear".`} />
          )}
          <Campo
            rotulo="E-mail"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoComplete="email"
            keyboardType="email-address"
            textContentType="username"
            placeholder="professor@escola.br"
          />
          <Campo
            rotulo="Senha"
            value={senha}
            onChangeText={setSenha}
            secureTextEntry
            autoCapitalize="none"
            autoCorrect={false}
            autoComplete="current-password"
            textContentType="password"
            onSubmitEditing={aoEntrar}
          />
          {erro && <Aviso texto={erro} />}
          <Botao titulo="Entrar" onPress={aoEntrar} carregando={enviando} />
          <Text style={estilos.dica}>
            Não tem conta? Peça à coordenação: o cadastro de professores é feito pela escola.
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
