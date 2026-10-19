import type { Dificuldade } from './tipos';

/** 63.1 -> "63,1%"; sem dado -> "—" */
export function pct(valor: number | null | undefined): string {
  return valor == null ? '—' : `${valor.toFixed(1).replace('.', ',')}%`;
}

export const NOME_DIFICULDADE: Record<Dificuldade, string> = { facil: 'Fácil', medio: 'Médio', dificil: 'Difícil' };

/** Resposta de uma folha como o professor lê: letra, "Em branco" ou "Dupla". */
export function nomeResposta(r: string | null): string {
  if (r === null) return 'Em branco';
  if (r === 'MULTIPLA') return 'Dupla';
  return r;
}

/** [1, 2, 3, 5] -> "1 a 3 e 5" (como listar_numeros() do PHP). */
export function listarNumeros(numeros: number[]): string {
  const ordenados = [...numeros].sort((a, b) => a - b);
  const partes: string[] = [];
  for (let i = 0; i < ordenados.length; ) {
    let j = i;
    while (j + 1 < ordenados.length && ordenados[j + 1] === ordenados[j] + 1) j++;
    partes.push(j - i >= 2 ? `${ordenados[i]} a ${ordenados[j]}` : ordenados.slice(i, j + 1).join(', '));
    i = j + 1;
  }
  const texto = partes.join(', ');
  const ultima = texto.lastIndexOf(', ');
  return ultima < 0 ? texto : `${texto.slice(0, ultima)} e ${texto.slice(ultima + 2)}`;
}
