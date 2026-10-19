import { File } from 'expo-file-system';
import { Platform } from 'react-native';

import { ErroRecorte, type Recorte } from './recorte';
import { supabase } from './supabase';
import type {
  Config,
  Folha,
  FolhaDetalhe,
  Leitura,
  Prova,
  ProvaResumo,
  Questao,
  Relatorio,
  ResultadoEnvio,
} from './tipos';

/*
 * Cliente da API PHP (Fase 6). Toda chamada leva o token do Supabase Auth em
 * "Authorization: Bearer". Os erros da API chegam como {"erro": "..."} e viram ErroApi,
 * com a mensagem já escrita para o professor.
 */
const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? '').replace(/\/+$/, '');

export class ErroApi extends Error {
  constructor(
    mensagem: string,
    /** Código HTTP; 0 quando o servidor não respondeu. */
    public readonly status: number,
    public readonly dados: Record<string, unknown> = {},
  ) {
    super(mensagem);
  }

  /** Lista de erros da aprovação do gabarito, quando houver. */
  get erros(): string[] {
    return Array.isArray(this.dados.erros) ? (this.dados.erros as string[]) : [];
  }

  get codigo(): string | null {
    return typeof this.dados.codigo === 'string' ? this.dados.codigo : null;
  }
}

/** Mensagem de qualquer erro para mostrar na tela. */
export function mensagemDeErro(e: unknown): string {
  return e instanceof ErroApi || e instanceof ErroRecorte ? e.message : String(e);
}

/** Foto local pronta para envio. */
export type Foto = { uri: string; width: number; height: number };

type Opcoes = {
  json?: unknown;
  form?: FormData;
  query?: Record<string, string | number | undefined>;
};

async function tokenAtual(): Promise<string | null> {
  const { data } = await supabase.auth.getSession();
  return data.session?.access_token ?? null;
}

async function chamar<T>(metodo: string, rota: string, opcoes: Opcoes = {}, tentativa = 1): Promise<T> {
  const token = await tokenAtual();
  if (!token) {
    throw new ErroApi('Entre de novo para continuar.', 401);
  }
  const query = Object.entries(opcoes.query ?? {})
    .filter(([, v]) => v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  const cabecalhos: Record<string, string> = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  let corpo: BodyInit | undefined;
  if (opcoes.form) {
    corpo = opcoes.form; // o fetch põe o Content-Type multipart com o boundary
  } else if (opcoes.json !== undefined) {
    cabecalhos['Content-Type'] = 'application/json';
    corpo = JSON.stringify(opcoes.json);
  }

  let resposta: Response;
  try {
    resposta = await fetch(`${API_URL}/api/${rota}${query ? `?${query}` : ''}`, {
      method: metodo,
      headers: cabecalhos,
      body: corpo,
    });
  } catch (e) {
    console.warn(`Falha no envio para ${rota}:`, e); // aparece no terminal do Expo
    throw new ErroApi('Sem conexão com o servidor. Confira a internet e tente de novo.', 0);
  }

  // Token recusado: renova a sessão uma vez e repete (o token pode ter vencido com o app parado).
  if (resposta.status === 401 && tentativa === 1) {
    const { error } = await supabase.auth.refreshSession();
    if (!error) {
      return chamar<T>(metodo, rota, opcoes, 2);
    }
  }
  if (resposta.status === 204) {
    return undefined as T;
  }
  let dados: Record<string, unknown> = {};
  try {
    dados = await resposta.json();
  } catch {
    // corpo vazio ou que não é JSON: trata abaixo pelo código HTTP
  }
  if (!resposta.ok) {
    if (resposta.status === 401) {
      await supabase.auth.signOut({ scope: 'local' });
    }
    const mensagem = typeof dados.erro === 'string' ? dados.erro : `Erro ${resposta.status} no servidor.`;
    throw new ErroApi(mensagem, resposta.status, dados);
  }
  return dados as T;
}

/**
 * Acrescenta a foto ao formulário. No celular, o fetch global do Expo (expo/fetch) só aceita
 * arquivos como File do expo-file-system (o antigo {uri, name, type} do React Native falha antes
 * de sair do aparelho). No navegador, o arquivo vira Blob.
 */
async function anexarFoto(form: FormData, campo: string, foto: Foto, nome: string) {
  if (Platform.OS === 'web') {
    const blob = await (await fetch(foto.uri)).blob();
    form.append(campo, blob, nome);
  } else {
    form.append(campo, new File(foto.uri), nome);
  }
}

/** Área de respostas, faixa do QR (se achada) e o caderno lido no celular. */
async function anexarRecorte(form: FormData, campoArea: string, campoQr: string, r: Recorte, nome: string) {
  await anexarFoto(form, campoArea, r.area, `${nome}_area.jpg`);
  if (r.qr) await anexarFoto(form, campoQr, r.qr, `${nome}_qr.jpg`);
  if (r.caderno) form.append('caderno', r.caderno);
}

/* ------------------------------------------------------------- Rotas */

export const api = {
  config: () => chamar<Config>('GET', 'config'),

  listarProvas: (filtros: { ano?: number; periodo?: number }) =>
    chamar<{ provas: ProvaResumo[] }>('GET', 'provas', { query: filtros }).then((r) => r.provas),

  /** Cadastra pelo recorte do gabarito ou pelas respostas digitadas; devolve o rascunho e a leitura. */
  async cadastrarProva(dados: { ano: number; periodo: number; nome?: string; recorte?: Recorte; digitado?: string }) {
    const form = new FormData();
    form.append('ano', String(dados.ano));
    form.append('periodo', String(dados.periodo));
    if (dados.nome) form.append('nome', dados.nome);
    if (dados.recorte) {
      await anexarRecorte(form, 'gabarito_imagem', 'gabarito_qr', dados.recorte, 'gabarito');
    } else {
      form.append('gabarito_digitado', dados.digitado ?? '');
    }
    return chamar<{ prova: Prova; leitura: Leitura | null }>('POST', 'provas', { form });
  },

  verProva: (id: number) => chamar<{ prova: Prova }>('GET', `provas/${id}`).then((r) => r.prova),

  descartarProva: (id: number) => chamar<void>('DELETE', `provas/${id}`),

  salvarGabarito: (id: number, nome: string, questoes: Questao[]) =>
    chamar<{ prova: Prova }>('PUT', `provas/${id}/questoes`, { json: { nome, questoes: semDuvida(questoes) } }).then(
      (r) => r.prova,
    ),

  aprovarGabarito: (id: number, nome: string, questoes: Questao[]) =>
    chamar<{ prova: Prova }>('POST', `provas/${id}/aprovar`, { json: { nome, questoes: semDuvida(questoes) } }).then(
      (r) => r.prova,
    ),

  listarFolhas: (id: number, turma?: string) =>
    chamar<{ folhas: Folha[]; recusadas: { id: number; turma: string; erro: string }[] }>(
      'GET',
      `provas/${id}/folhas`,
      { query: { turma } },
    ),

  /** Envia o recorte de uma folha (nunca a foto inteira); o nome é genérico. */
  async enviarFolha(id: number, turma: string, recorte: Recorte, ordem: number) {
    const form = new FormData();
    form.append('turma', turma);
    await anexarRecorte(form, 'folha', 'qr', recorte, `${turma}_folha${String(ordem).padStart(2, '0')}`);
    return chamar<{ turma: string; folhas_da_turma: number; folhas: ResultadoEnvio[] }>(
      'POST',
      `provas/${id}/folhas`,
      { form },
    );
  },

  relatorio: (id: number, turma?: string) =>
    chamar<Relatorio>('GET', `provas/${id}/relatorio`, { query: { turma } }),

  verFolha: (id: number) => chamar<{ folha: FolhaDetalhe }>('GET', `folhas/${id}`).then((r) => r.folha),

  /** Só as respostas que mudaram: {"12": "B", "13": null}. */
  revisarFolha: (id: number, respostas: Record<number, string | null>) =>
    chamar<{ folha: Folha }>('PUT', `folhas/${id}`, { json: { respostas } }).then((r) => r.folha),
};

function semDuvida(questoes: Questao[]) {
  return questoes.map(({ duvidosa: _, ...q }) => q);
}
