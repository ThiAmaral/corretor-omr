import { createClient } from '@supabase/supabase-js';
import { AppState, Platform } from 'react-native';

import { armazenamento } from './armazenamento';

/*
 * O Supabase serve só para o login (Fase 6): o app usa a URL e a chave publishable,
 * e todo o resto passa pela API PHP com o token da sessão.
 */
export const SUPABASE_URL = process.env.EXPO_PUBLIC_SUPABASE_URL ?? '';
const CHAVE_PUBLISHABLE = process.env.EXPO_PUBLIC_SUPABASE_PUBLISHABLE_KEY ?? '';

/** Vazio ou ainda com o texto de exemplo do .env.exemplo ("<...>", "COLE_AQUI..."). */
const naoPreenchido = (v: string | undefined) => !v || v.includes('<') || v.toUpperCase().startsWith('COLE_AQUI');

export const configuracaoFaltando = [
  naoPreenchido(SUPABASE_URL) && 'EXPO_PUBLIC_SUPABASE_URL',
  naoPreenchido(CHAVE_PUBLISHABLE) && 'EXPO_PUBLIC_SUPABASE_PUBLISHABLE_KEY',
  naoPreenchido(process.env.EXPO_PUBLIC_API_URL) && 'EXPO_PUBLIC_API_URL',
].filter(Boolean) as string[];

export const supabase = createClient(SUPABASE_URL || 'http://localhost', CHAVE_PUBLISHABLE || 'faltando', {
  auth: {
    storage: armazenamento,
    autoRefreshToken: true,
    persistSession: true,
    detectSessionInUrl: false,
  },
});

// No celular, o token só é renovado com o app aberto na tela.
if (Platform.OS !== 'web') {
  AppState.addEventListener('change', (estado) => {
    if (estado === 'active') {
      supabase.auth.startAutoRefresh();
    } else {
      supabase.auth.stopAutoRefresh();
    }
  });
}
