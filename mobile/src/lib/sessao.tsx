import type { Session } from '@supabase/supabase-js';
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';

import { api, ErroApi } from './api';
import { configuracaoFaltando, supabase } from './supabase';
import type { Config } from './tipos';

type Sessao = {
  sessao: Session | null;
  /** Ainda lendo a sessão guardada no aparelho. */
  carregando: boolean;
  /** Períodos, disciplinas, dificuldades e limites da escola (GET /api/config). */
  config: Config | null;
  erroConfig: string | null;
  recarregarConfig: () => void;
  entrar: (email: string, senha: string) => Promise<string | null>;
  sair: () => Promise<void>;
};

const Contexto = createContext<Sessao | null>(null);

export function ProvedorDeSessao({ children }: { children: ReactNode }) {
  const [sessao, setSessao] = useState<Session | null>(null);
  const [carregando, setCarregando] = useState(true);
  const [carregada, setCarregada] = useState<{ usuario: string; config: Config } | null>(null);
  const [erroConfig, setErroConfig] = useState<string | null>(null);
  const [tentativa, setTentativa] = useState(0);

  useEffect(() => {
    supabase.auth.getSession().then(({ data }) => {
      setSessao(data.session);
      setCarregando(false);
    });
    const { data } = supabase.auth.onAuthStateChange((_evento, nova) => setSessao(nova));
    return () => data.subscription.unsubscribe();
  }, []);

  const usuario = sessao?.user.id ?? null;
  const config = usuario && carregada?.usuario === usuario ? carregada.config : null;

  useEffect(() => {
    if (!usuario) return;
    let valida = true;
    api
      .config()
      .then((c) => valida && (setErroConfig(null), setCarregada({ usuario, config: c })))
      .catch((e) => valida && setErroConfig(e instanceof ErroApi ? e.message : String(e)));
    return () => {
      valida = false;
    };
  }, [usuario, tentativa]);

  const recarregarConfig = useCallback(() => {
    setErroConfig(null);
    setTentativa((t) => t + 1);
  }, []);

  /** Devolve a mensagem de erro para a tela, ou null se entrou. */
  const entrar = useCallback(async (email: string, senha: string) => {
    if (configuracaoFaltando.length) {
      // Sem isso o login tenta um endereço inexistente e só diria "sem conexão".
      return `O app não está configurado: preencha ${configuracaoFaltando.join(', ')} no mobile/.env e reinicie com "npx expo start --clear".`;
    }
    const { error } = await supabase.auth.signInWithPassword({ email: email.trim(), password: senha });
    if (!error) return null;
    if (error.message.includes('Invalid login credentials')) return 'E-mail ou senha incorretos.';
    if (error.message.includes('Email not confirmed')) return 'Confirme o e-mail antes de entrar.';
    if (error.name === 'AuthRetryableFetchError') return 'Sem conexão com o servidor de login. Confira a internet.';
    return `Não foi possível entrar: ${error.message}`;
  }, []);

  const sair = useCallback(async () => {
    setErroConfig(null);
    await supabase.auth.signOut();
  }, []);

  return (
    <Contexto.Provider value={{ sessao, carregando, config, erroConfig, recarregarConfig, entrar, sair }}>
      {children}
    </Contexto.Provider>
  );
}

export function useSessao(): Sessao {
  const valor = useContext(Contexto);
  if (!valor) throw new Error('useSessao fora do ProvedorDeSessao');
  return valor;
}
