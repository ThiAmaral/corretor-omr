# Política de privacidade — Corretor de provas

> **Rascunho (Fase 6, 2026-10-12).** Escrito a partir do que o sistema guarda
> hoje. Antes de publicar nas lojas, a escola e o orientador precisam preencher
> os campos entre colchetes e confirmar a base legal e o prazo de guarda. Não é
> uma revisão jurídica.

Última atualização: [data da publicação]

## Quem somos

O Corretor de provas é um sistema usado pelos professores da [nome da escola]
para corrigir os simulados da Avaliação de Monitoramento da Aprendizagem (AMA)
e planejar a revisão do conteúdo com as turmas. A escola é a responsável pelos
dados tratados no sistema (controladora, nos termos da Lei Geral de Proteção de
Dados, Lei nº 13.709/2018).

Contato para dúvidas e pedidos sobre dados pessoais: [nome do responsável],
[e-mail].

## Quem usa o sistema

Só professores e a coordenação da escola, com uma conta criada pela
coordenação. Não existe cadastro aberto. Os alunos não usam o sistema e não
têm conta.

## Quais dados são tratados

### Dos professores

| Dado | Para quê |
|---|---|
| E-mail e senha | Entrar no aplicativo e no painel web. A senha é guardada pelo serviço de login (Supabase Auth) de forma cifrada; o sistema não tem acesso a ela |
| Identificador da conta | Registrar quem cadastrou cada prova |

### Das folhas de resposta dos alunos

O sistema **não guarda o nome do aluno** nem a foto inteira da folha.

| Dado | Para quê |
|---|---|
| Foto da folha | Só para a leitura. Fica numa pasta temporária do servidor e é apagada assim que a leitura termina (em segundos) |
| Recorte da área de respostas | A grade de bolinhas, sem o cabeçalho com o nome do aluno. Permite ao professor conferir uma leitura duvidosa |
| Imagem anotada | O mesmo recorte, com as marcações que o sistema leu |
| Respostas marcadas, acertos e nota | Correção e relatórios da turma |
| Turma | Separar os relatórios por turma |
| Nome do arquivo enviado e número da página | Localizar a folha na lista. O aplicativo envia nomes genéricos; pelo painel web, o professor deve evitar arquivos com o nome do aluno |

Como as folhas não têm o nome do aluno, os relatórios mostram resultados da
turma e das questões, não de alunos identificados.

### Câmera

O aplicativo usa a câmera só quando o professor fotografa o gabarito oficial ou
as folhas dos alunos. [Fase 7: confirmar se as fotos ficam ou não na galeria do
aparelho depois do envio.]

## Para que os dados são usados

Corrigir as folhas, mostrar o acerto da turma por disciplina, por dificuldade e
por questão, e indicar as questões que a turma precisa revisar. Os dados não
são vendidos, não são usados para publicidade e não são compartilhados com
outras escolas ou empresas, exceto os serviços que guardam o sistema (abaixo).

Base legal: [a definir com a escola, por exemplo execução de política pública
educacional ou legítimo interesse da escola na avaliação da aprendizagem].

## Onde os dados ficam

- **Banco de dados, imagens e login:** Supabase, num servidor em São Paulo
  (Brasil). O Supabase atua como operador: guarda os dados em nome da escola.
- **Servidor do sistema:** [hospedagem a definir]. Recebe as fotos, faz a
  leitura e a correção e não guarda as fotos.

## Como os dados são protegidos

- Login obrigatório no aplicativo e no painel web; só contas criadas pela
  coordenação.
- Conexão cifrada (HTTPS) entre o aplicativo e o servidor.
- As imagens ficam em áreas privadas do armazenamento. Para mostrar uma imagem,
  o sistema gera um endereço que vale 5 minutos.
- O banco de dados não pode ser lido diretamente pela internet: só o servidor
  do sistema tem acesso.
- As chaves de acesso ficam só no servidor, nunca no aplicativo.

## Por quanto tempo

As provas, folhas e imagens ficam guardadas até a escola apagá-las, [por
exemplo até o fim do ano letivo seguinte ao da prova]. A conta do professor é
removida quando ele deixa a escola, a pedido da coordenação.

## Direitos

Os titulares dos dados (professores, e alunos e responsáveis no que se refere
às folhas) podem pedir a confirmação do tratamento, acesso, correção ou
eliminação dos dados, nos termos do art. 18 da LGPD, pelo contato acima.

## Mudanças nesta política

Mudanças serão publicadas nesta página, com a data da última atualização.
