# Corretor AMA — aplicativo (Fase 7)

Aplicativo iOS/Android em Expo (React Native, TypeScript) para o professor
fazer tudo pelo celular: entrar, cadastrar a prova pelo gabarito oficial
(foto ou digitado), revisar e aprovar o gabarito, escanear a turma e ver o
resultado. O app não tem banco próprio: o login é no Supabase Auth e todo o
resto passa pela API PHP (`web/api/`, Fase 6).

## Telas

| Tela | Arquivo | O que faz |
|---|---|---|
| Login | `src/app/login.tsx` | `signInWithPassword` no Supabase (URL + chave publishable) |
| Minhas provas | `src/app/index.tsx` | Lista com filtros de ano e edição do simulado; rascunho abre a revisão |
| Nova prova | `src/app/nova-prova.tsx` | Ano, edição do simulado, nome e o gabarito: câmera, galeria ou letras digitadas |
| Revisar gabarito | `src/app/prova/[id]/revisar.tsx` | Marcações sobre o recorte enviado, um cartão por questão (resposta, dificuldade, disciplina, habilidade), marcação por intervalo, salvar, aprovar, descartar |
| Prova | `src/app/prova/[id]/index.tsx` | Turmas já corrigidas, escolher a turma para escanear, editar o gabarito, apagar as fotos guardadas no celular |
| Escanear turma | `src/app/prova/[id]/escanear.tsx` | Câmera aberta; a cada foto mostra a nota e as questões duvidosas, para confirmar ou corrigir; contador de folhas |
| Resultado da turma | `src/app/prova/[id]/resultado.tsx` | Acerto geral, por dificuldade e por disciplina, prioridades de revisão (Fase 4) e lista de folhas |
| Folha | `src/app/folha/[id].tsx` | Imagem anotada pelo leitor e correção manual |

Outras partes: `src/lib/api.ts` (cliente da API, token e erros),
`src/lib/foto.ts` (redução para 1600 px e scanner do sistema),
`src/lib/recorte.ts` e `src/lib/marcadores.ts` (recorte da folha no celular),
`src/lib/fotosLocais.ts` (fotos originais na pasta privada do app),
`src/componentes/CameraFolha.tsx` (câmera com moldura-guia e leitura do QR ao
vivo) e `src/componentes/FotoComMarcacoes.tsx` (leitura desenhada sobre a foto).

## O que sai do celular

A foto da folha **não sai do aparelho**. Antes do envio, o app:

1. acha os 4 quadrados pretos da área de respostas (mesmas regras do leitor em
   Python, `worker/omr.py`);
2. gira a foto para a folha ficar em pé e nivelada;
3. recorta a faixa do QR, que termina antes do campo "Nome do(a) estudante", e
   lê o QR nela com o leitor do sistema;
4. recorta a área entre os quadrados.

Vão para o servidor só esses dois recortes e o código do caderno. Folha sem os
4 quadrados (ex.: o modelo antigo) é recusada no celular, sem enviar nada. A
foto original fica em `Paths.document/fotos/prova_<id>`, fora da galeria; a
tela da prova mostra quantas há e as apaga.

Para conferir o recorte sem o celular, o mesmo algoritmo existe em Python:
`python worker/omr_cli.py recortar exemplos/ama/geradas_8A --saida /tmp/recortes`.

## Configurar

1. Instale o Node.js 20+ e rode, nesta pasta: `npm install`.
2. Copie `.env.exemplo` para `.env` e preencha:
   - `EXPO_PUBLIC_SUPABASE_URL` e `EXPO_PUBLIC_SUPABASE_PUBLISHABLE_KEY`: os
     mesmos do `.env` do servidor (só a chave **publishable**; a secret nunca
     vai para o app);
   - `EXPO_PUBLIC_API_URL`: o endereço do servidor PHP visto pelo celular,
     por exemplo `http://192.168.0.10:8001` (o IP do computador na rede, que
     aparece no `ipconfig`).
3. No computador, suba o servidor com o `iniciar.cmd`, que já aceita conexões
   da rede (o `iniciar.cmd local` aceita só do próprio computador). O Windows
   pode perguntar se libera o PHP no firewall: libere só para redes privadas.

## Rodar no celular

**Com o Expo Go** (mais rápido para testar; sem o scanner do sistema):

```bash
npx expo start
```

Instale o Expo Go no celular e leia o QR que aparece no terminal. O celular e
o computador precisam estar na mesma rede. O app usa a câmera própria (com a
moldura-guia) e a galeria.

**Com um development build** (necessário para o scanner de documento do
sistema, ML Kit no Android e VisionKit no iOS):

```bash
npx expo run:android
```

Isso exige o Android Studio. Sem ele, ou para iPhone (que exige um Mac para
compilar localmente), use o EAS Build: `npx eas-cli@latest build --profile development`.
Com o scanner disponível, a câmera mostra o botão **Scanner** ao lado do disparo.

## Testar no navegador (desenvolvimento)

`npx expo start --web` abre o app no navegador. Como ele roda em outra porta,
o servidor PHP precisa liberar essa origem: `CORS_ORIGENS=http://localhost:8081`
no `.env` da raiz. Sem câmera no navegador, use o botão **Galeria**.

## Verificações

```bash
npx tsc --noEmit
npx expo lint
npx expo-doctor
```

O roteiro de teste manual em aparelho está em
[../docs/roteiro_teste_app.md](../docs/roteiro_teste_app.md).

## Limitações conhecidas

- O iOS fora do modo de desenvolvimento só aceita servidor com **HTTPS**
  (depende da hospedagem, Fase 8).
- As marcações sobre a foto aparecem logo depois do cadastro. Se a revisão for
  aberta mais tarde, o app mostra a imagem anotada que o servidor guardou.
  Também não aparecem quando a folha foi fotografada de lado ou de cabeça para
  baixo (a leitura vale, só o desenho fica de fora).
- O aviso de "outro caderno" antes da foto depende de a câmera ler o QR. Se
  não ler, o servidor recusa a folha do mesmo jeito, com a mensagem.
