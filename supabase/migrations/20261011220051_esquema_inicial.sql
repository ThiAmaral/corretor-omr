-- Esquema inicial do Corretor de provas (Fase 2: troca do SQLite pelo Supabase).
-- Toda mudança futura de banco vira um novo arquivo nesta pasta.

create table provas (
    id         bigint generated always as identity primary key,
    nome       text not null,
    ano        int not null,
    periodo    int,
    caderno    text,                         -- código lido do QR, ex.: 2265M0801
    modelo     text,
    status     text not null default 'rascunho' check (status in ('rascunho', 'ativa')),
    layout     jsonb not null,               -- só o que o leitor precisa: alternativas e blocos
    criado_por uuid references auth.users(id),
    criado_em  timestamptz not null default now()
);
create index on provas (caderno);

create table questoes (
    prova_id    bigint not null references provas(id) on delete cascade,
    numero      int not null,
    resposta    text not null,
    disciplina  text,
    dificuldade text check (dificuldade in ('facil', 'medio', 'dificil')),
    habilidade  text,                        -- opcional: descritor da matriz de referência, ex.: D07
    primary key (prova_id, numero)
);

create table folhas (
    id           bigint generated always as identity primary key,
    prova_id     bigint not null references provas(id) on delete cascade,
    turma        text not null,
    arquivo      text not null,               -- nome original enviado
    pagina       int not null,
    imagem       text,                        -- caminho no Storage (só a área de respostas)
    imagem_debug text,                        -- caminho no Storage
    respostas    jsonb,
    niveis       jsonb,
    revisar      jsonb not null default '[]',
    revisada     boolean not null default false,
    erro         text,
    criado_em    timestamptz not null default now()
);
create index on folhas (prova_id, turma);

-- Bloqueia a API pública do Supabase: só o PHP, conectado direto no PostgreSQL, acessa.
alter table provas   enable row level security;
alter table questoes enable row level security;
alter table folhas   enable row level security;

-- Buckets privados do Storage: área de respostas recortada e imagem anotada pelo leitor.
-- Sem políticas em storage.objects: só a chave secret (no servidor) lê e grava.
insert into storage.buckets (id, name, public)
values ('folhas', 'folhas', false),
       ('anotadas', 'anotadas', false)
on conflict (id) do nothing;
