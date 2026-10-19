// No celular, o localStorage vem do expo-sqlite e guarda a sessão entre aberturas do app.
import 'expo-sqlite/localStorage/install';

export const armazenamento = globalThis.localStorage;
