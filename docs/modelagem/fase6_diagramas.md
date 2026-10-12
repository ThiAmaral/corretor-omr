# Diagramas da Fase 6 (referência para o Visual Paradigm)

Rascunho em Mermaid dos dois diagramas que o plano pede nesta fase. Servem de
base para desenhar a versão final no Visual Paradigm (salvar o `.vpp` e as
imagens nesta pasta). O GitHub e o VS Code mostram os diagramas abaixo.

## Sequência: foto da folha até a nota no celular

```mermaid
sequenceDiagram
    autonumber
    actor P as Professor
    participant App as App (Expo)
    participant Auth as Supabase Auth
    participant API as API PHP
    participant OMR as Leitor Python
    participant DB as Supabase Database
    participant ST as Supabase Storage

    P->>App: e-mail e senha
    App->>Auth: signInWithPassword
    Auth-->>App: token (JWT)
    P->>App: fotografa a folha (turma 8A)
    App->>API: POST /api/provas/{id}/folhas<br/>Authorization: Bearer token
    API->>Auth: GET /auth/v1/user (se não estiver no cache)
    Auth-->>API: professor
    API->>DB: busca prova e gabarito
    API->>OMR: omr_cli.py ler foto --layout
    OMR-->>API: respostas, qualidade, coordenadas
    alt folha lida
        API->>ST: recorte da área de respostas e imagem anotada
        API->>DB: INSERT folhas (respostas, revisar)
        API->>API: corrigir() com o gabarito
        API-->>App: nota, acertos, questões a revisar, coordenadas
        App-->>P: nota e questões duvidosas sobre a foto
    else foto ruim ou de outro caderno
        API-->>App: erro e código (ex.: desfocada)
        App-->>P: pede outra foto
    end
    Note over API: a foto original fica só na pasta temporária<br/>e é apagada no fim da requisição
```

## Componentes e implantação

```mermaid
flowchart LR
    subgraph Celular
        App["App Expo<br/>(React Native)"]
    end
    subgraph Navegador
        Painel["Painel web<br/>(relatórios, coordenação)"]
    end
    subgraph Servidor["Servidor (Docker: PHP 8.3 + Apache + Python)"]
        API["API REST<br/>web/api/index.php"]
        Paginas["Páginas PHP<br/>index, gabarito, prova, folha"]
        Servicos["Serviços<br/>web/src/servicos.php"]
        Login["Login<br/>web/src/auth.php"]
        OMR["Leitor óptico<br/>worker/omr.py (OpenCV)"]
        API --> Servicos
        Paginas --> Servicos
        API --> Login
        Paginas --> Login
        Servicos -->|linha de comando, JSON| OMR
    end
    subgraph Supabase["Supabase (São Paulo)"]
        Auth["Auth"]
        DB[("Database<br/>PostgreSQL")]
        ST[("Storage<br/>buckets privados")]
    end
    App -->|"login (chave publishable)"| Auth
    App -->|"HTTPS + Bearer token"| API
    Painel -->|"HTTPS + sessão"| Paginas
    Login -->|"curl: conferir token, login, renovação"| Auth
    Servicos -->|"PDO (pdo_pgsql)"| DB
    Servicos -->|"curl (chave secret)"| ST
```

Pontos para o texto do TCC:

- **Uma regra só:** a API e as páginas chamam as mesmas funções de
  `servicos.php`; a correção continua em um lugar.
- **O app não acessa o banco:** usa o Supabase só para o login. As tabelas têm
  RLS ligado e sem políticas; só o PHP, conectado direto no PostgreSQL, lê e grava.
- **O Python não conhece o banco:** recebe a foto e devolve JSON.
