// No navegador (versão web, só para desenvolvimento), o localStorage do próprio navegador.
export const armazenamento = typeof window === 'undefined' ? undefined : window.localStorage;
