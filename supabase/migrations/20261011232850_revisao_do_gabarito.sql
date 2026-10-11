-- Fase 3: revisão do gabarito oficial.
-- A prova nasce como rascunho; o professor confere a leitura do gabarito, marca
-- disciplina e dificuldade de cada questão e aprova (status 'ativa').

-- Imagem anotada da foto do gabarito (bucket "anotadas"), mostrada na revisão.
alter table provas add column imagem_gabarito text;

-- No rascunho, uma questão pode ficar sem resposta (gabarito em branco ou com
-- marcação dupla na foto). A aprovação exige todas preenchidas.
alter table questoes alter column resposta drop not null;

-- Leitura duvidosa do gabarito: a questão aparece em destaque na revisão.
alter table questoes add column duvidosa boolean not null default false;
