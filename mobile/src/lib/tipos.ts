// Formato das respostas da API PHP (web/src/api.php). Os nomes seguem o JSON.

export type Dificuldade = 'facil' | 'medio' | 'dificil';

export type Config = {
  professor: { id: string; email: string | null };
  periodo_escola: string;
  /** "Edição", "Bimestre"... (título do campo). */
  periodo_rotulo?: string;
  periodos: { numero: number; nome: string }[];
  periodo_atual: number;
  disciplinas: string[];
  dificuldades: Record<Dificuldade, string>;
  limites: { reforco: number; facil: number; dificil: number };
  tamanho_maximo_arquivo: number;
  tipos_aceitos: string[];
};

export type ProvaResumo = {
  id: number;
  nome: string;
  ano: number;
  periodo: number | null;
  periodo_nome: string;
  status: 'rascunho' | 'ativa';
  caderno: string | null;
  turmas: number;
  folhas: number;
  criado_em: string | null;
};

export type Questao = {
  numero: number;
  resposta: string | null;
  disciplina: string | null;
  dificuldade: Dificuldade | null;
  habilidade: string | null;
  duvidosa?: boolean;
};

export type Prova = Omit<ProvaResumo, 'turmas' | 'folhas'> & {
  modelo: string | null;
  criado_por: string | null;
  formato_conhecido: boolean;
  formato: string | null;
  alternativas: string[];
  blocos: number[] | null;
  questoes: Questao[];
  disciplinas: string[];
  imagem_gabarito_url: string | null;
};

/** Uma questão como o leitor devolve: posição das bolinhas em fração da largura e da altura da foto. */
export type QuestaoLida = {
  questao: number;
  resposta: string | null;
  situacao: 'marcada' | 'branco' | 'multipla';
  duvidosa: boolean;
  niveis: number[];
  bolinhas: [number, number][];
};

export type Leitura = {
  caderno: string | null;
  girada?: boolean;
  layout: { caderno?: string; alternativas: string[]; blocos: number[]; questoes: number };
  respostas: QuestaoLida[];
  raio_bolinha?: number;
  confianca: { nivel: 'alta' | 'baixa'; motivos: string[] };
  duvidosas?: number[];
  qualidade?: { problema: string | null };
};

export type Folha = {
  id: number;
  prova_id: number;
  turma: string;
  arquivo: string;
  pagina: number;
  /** Letra, null (em branco) ou "MULTIPLA" (marcação dupla). */
  respostas: (string | null)[];
  acertos: boolean[];
  n_acertos: number;
  questoes: number;
  taxa: number | null;
  revisar: number[];
  revisada: boolean;
  criado_em: string | null;
};

export type FolhaDetalhe = Folha & {
  alternativas: string[];
  gabarito: (string | null)[];
  niveis: (number[] | null)[] | null;
  imagem_url: string | null;
};

export type ResultadoEnvio =
  | ({ ok: true; leitura: Leitura } & Folha)
  | { ok: false; arquivo: string; pagina: number; erro: string; codigo: string | null };

export type Prioridade = {
  numero: number;
  certa: string;
  disciplina: string | null;
  dificuldade: Dificuldade | null;
  habilidade: string | null;
  taxa: number | null;
  distrator: string | null;
  distrator_qtd: number;
  brancos: number;
  grupo: number;
  motivo: string;
};

/** Precisão da leitura (relatório): quanto foi lido com certeza e a faixa do acerto. */
export type Precisao = {
  questoes: number;
  com_certeza: number;
  duvidosas: number;
  pct_com_certeza: number | null;
  pct_duvidosas: number | null;
  conferidas: number;
  pendentes: number;
  margem: number | null;
  acerto: number | null;
  acerto_min: number | null;
  acerto_max: number | null;
  corrigidas_duvidosas: number;
  pct_corrigidas_duvidosas: number | null;
  corrigidas_sem_duvida: number;
  conferidas_sem_duvida: number;
  pct_corrigidas_sem_duvida: number | null;
  sem_niveis: number;
};

export type ResumoTurma = {
  turma: string;
  folhas: number;
  acerto: number | null;
  por_disciplina: Record<string, number | null>;
  por_dificuldade: Partial<Record<Dificuldade, number | null>>;
  para_reforco: number;
  a_revisar: number;
  precisao: Precisao;
};

export type Relatorio = {
  prova: { id: number; nome: string; questoes: number };
  turma: string;
  turmas: string[];
  limites: { reforco: number; facil: number; dificil: number };
  folhas: number;
  recusadas: number;
  resumo: ResumoTurma[];
  prioridades: Prioridade[];
  precisao: Precisao;
};
