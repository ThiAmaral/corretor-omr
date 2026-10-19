<#
Teste de ponta a ponta da API com um professor de verdade (Fase 6).

Faz o que o aplicativo vai fazer: login no Supabase Auth, cadastro da prova pelo
recorte do gabarito, revisão (dificuldade e habilidade), aprovação, envio das 20
folhas da turma 8A uma a uma, relatório, revisão de uma folha e os erros de
login. No fim mostra uma tabela com o resultado de cada passo.

Como o app, envia só os recortes (área de respostas + QR), feitos antes com
"omr_cli.py recortar"; a foto inteira enviada no lugar do recorte tem de ser recusada.

Uso, com o sistema rodando (iniciar.cmd ou Docker):
  powershell -ExecutionPolicy Bypass -File ferramentas/testar_api.ps1 -Email professor@escola.br
  powershell -ExecutionPolicy Bypass -File ferramentas/testar_api.ps1 -Email professor@escola.br -Servidor http://localhost:8080

A senha é pedida sem aparecer na tela e vai só para o Supabase Auth. Para rodar
sem ninguém digitar (automação), ela pode vir da variável de ambiente CORRETOR_SENHA.
-SupabaseUrl troca a URL do .env (ex.: um Auth simulado em teste local).
A prova criada ("TESTE Fase 6 - API ...") fica no banco, como as provas de teste das outras fases.
#>
param(
    [Parameter(Mandatory = $true)][string]$Email,
    [string]$Servidor = 'http://localhost:8001',
    [string]$SupabaseUrl = ''
)
$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$fotos = Join-Path $raiz 'exemplos\ama\geradas_8A'
$temp = Join-Path $env:TEMP ('teste_api_' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null
$semBom = New-Object System.Text.UTF8Encoding $false

# Recortes como o app faz (a foto inteira, com o nome do aluno, não sai do celular).
$python = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python312\python.exe'
if (-not (Test-Path $python)) { $python = 'python' }
$recortes = Join-Path $temp 'recortes'
& $python (Join-Path $raiz 'worker\omr_cli.py') recortar $fotos --saida $recortes | Out-Null
function Recorte([string]$Nome, [string]$CampoArea = 'folha', [string]$CampoQr = 'qr') {
    $campos = @('-F', "$CampoArea=@$(Join-Path $recortes "$($Nome)_area.jpg")")
    $qr = Join-Path $recortes "$($Nome)_qr.jpg"
    if (Test-Path $qr) { $campos += @('-F', "$CampoQr=@$qr") }
    return $campos
}

# URL do Supabase e chave para o login: a publishable (como o app), ou a secret se não houver.
$config = @{}
foreach ($linha in Get-Content (Join-Path $raiz '.env') -Encoding UTF8) {
    if ($linha -match '^\s*([A-Z_]+)\s*=\s*(.*)$') { $config[$Matches[1]] = $Matches[2].Trim().Trim('"', "'") }
}
if ($SupabaseUrl) { $config['SUPABASE_URL'] = $SupabaseUrl }
$chave = $config['SUPABASE_PUBLISHABLE_KEY']
if (-not $chave) { $chave = $config['SUPABASE_SECRET_KEY'] }

$resultados = New-Object System.Collections.ArrayList
function Conferir([string]$Passo, [bool]$Ok, [string]$Detalhe) {
    [void]$resultados.Add([pscustomobject]@{ Passo = $Passo; OK = $(if ($Ok) { 'sim' } else { 'NAO' }); Detalhe = $Detalhe })
    $cor = if ($Ok) { 'Green' } else { 'Red' }
    Write-Host ("[{0}] {1}: {2}" -f $(if ($Ok) { 'ok' } else { 'FALHOU' }), $Passo, $Detalhe) -ForegroundColor $cor
}

# Chamada à API com curl.exe (vem com o Windows 10+). Devolve o código HTTP e o JSON.
function Api([string]$Metodo, [string]$Rota, [string[]]$Extra = @(), [string]$Token = $script:token) {
    $saida = Join-Path $temp 'resposta.json'
    if (Test-Path $saida) { Remove-Item $saida }
    $argumentos = @('-s', '-o', $saida, '-w', '%{http_code}', '-X', $Metodo)
    if ($Token) { $argumentos += @('-H', "Authorization: Bearer $Token") }
    $argumentos += $Extra
    $argumentos += "$Servidor/api/$Rota"
    $status = & curl.exe @argumentos
    $texto = if (Test-Path $saida) { [IO.File]::ReadAllText($saida, [Text.Encoding]::UTF8) } else { '' }
    $json = $null
    if ($texto.Trim()) { try { $json = $texto | ConvertFrom-Json } catch { $json = $texto } }
    return [pscustomobject]@{ Status = [int]$status; Json = $json }
}

# Corpo JSON num arquivo UTF-8: o PowerShell 5.1 estraga aspas e acentos passados direto ao curl.
function ApiJson([string]$Metodo, [string]$Rota, $Objeto) {
    $arquivo = Join-Path $temp 'corpo.json'
    [IO.File]::WriteAllText($arquivo, ($Objeto | ConvertTo-Json -Depth 6 -Compress), $semBom)
    return Api $Metodo $Rota @('-H', 'Content-Type: application/json', '--data-binary', "@$arquivo")
}

try {
    # ------------------------------------------------------------ Login
    $senha = $env:CORRETOR_SENHA
    if (-not $senha) {
        $senhaSegura = Read-Host "Senha de $Email" -AsSecureString
        $ponteiro = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($senhaSegura)
        $senha = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ponteiro)
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ponteiro)
    }
    $corpoLogin = Join-Path $temp 'login.json'
    [IO.File]::WriteAllText($corpoLogin, (@{ email = $Email; password = $senha } | ConvertTo-Json -Compress), $semBom)
    $senha = $null
    $respostaLogin = & curl.exe -s -X POST "$($config['SUPABASE_URL'])/auth/v1/token?grant_type=password" `
        -H "apikey: $chave" -H 'Content-Type: application/json' --data-binary "@$corpoLogin"
    Remove-Item $corpoLogin
    $login = $respostaLogin | ConvertFrom-Json
    if (-not $login.access_token) { throw "Login recusado pelo Supabase: $respostaLogin" }
    $script:token = $login.access_token
    Conferir 'Login no Supabase Auth' $true "professor $($login.user.id)"

    # ----------------------------------------------------- Erros de login
    $r = Api 'GET' 'provas' @() ''
    Conferir 'Sem token' ($r.Status -eq 401) "HTTP $($r.Status)"
    $r = Api 'GET' 'provas' @() 'token.que.nao.vale'
    Conferir 'Token falso' ($r.Status -eq 401) "HTTP $($r.Status)"

    $r = Api 'GET' 'config'
    Conferir 'GET /api/config' ($r.Status -eq 200 -and $r.Json.professor.email -eq $Email) "HTTP $($r.Status), professor $($r.Json.professor.email)"
    $periodo = $r.Json.periodo_atual

    # ------------------------------------------- Cadastro pelo recorte do gabarito
    $nome = 'TESTE Fase 6 - API ' + (Get-Date -Format 'dd/MM HH:mm')
    $r = Api 'POST' 'provas' (@('-F', "ano=$((Get-Date).Year)", '-F', "periodo=$periodo", '-F', "nome=$nome") +
        (Recorte 'gabarito' 'gabarito_imagem' 'gabarito_qr'))
    if ($r.Status -ne 201) { throw "O cadastro da prova falhou (HTTP $($r.Status)): $($r.Json.erro)" }
    $prova = $r.Json.prova
    Conferir 'POST /api/provas (recorte do gabarito)' ($r.Status -eq 201 -and $prova.status -eq 'rascunho' -and $prova.caderno -eq '2265M0801' -and @($prova.questoes).Count -eq 26) `
        "HTTP $($r.Status), prova $($prova.id), caderno $($prova.caderno), $(@($prova.questoes).Count) questões"
    Conferir 'Autoria (criado_por)' ($prova.criado_por -eq $login.user.id) "$($prova.criado_por)"
    Conferir 'Leitura com coordenadas' ($null -ne $r.Json.leitura.respostas[0].bolinhas) "confiança $($r.Json.leitura.confianca.nivel)"
    $id = $prova.id

    $r = Api 'POST' "provas/$id/folhas" (@('-F', 'turma=8A') + (Recorte '8A_aluno01'))
    Conferir 'Folha antes de aprovar' ($r.Status -eq 409) "HTTP $($r.Status): $($r.Json.erro)"

    # --------------------------------------------------- Revisão e aprovação
    $questoes = @()
    foreach ($n in 1..26) {
        $nivel = if ($n -le 9) { 'facil' } elseif ($n -le 18) { 'medio' } else { 'dificil' }
        $q = @{ numero = $n; dificuldade = $nivel }
        if ($n -le 4) { $q.habilidade = 'D07' }
        $questoes += $q
    }
    $r = ApiJson 'PUT' "provas/$id/questoes" @{ questoes = $questoes }
    Conferir 'PUT /questoes (dificuldade)' ($r.Status -eq 200 -and $r.Json.prova.questoes[0].dificuldade -eq 'facil') "HTTP $($r.Status)"

    $r = ApiJson 'PUT' "provas/$id/questoes" @{ questoes = @(@{ numero = 1; resposta = 'Z' }) }
    Conferir 'Resposta inválida' ($r.Status -eq 422) "HTTP $($r.Status): $($r.Json.erros -join ' ')"

    $r = Api 'POST' "provas/$id/aprovar"
    Conferir 'POST /aprovar' ($r.Status -eq 200 -and $r.Json.prova.status -eq 'ativa') "HTTP $($r.Status), $($r.Json.prova.status) $($r.Json.erros -join ' ')"
    $r = Api 'POST' "provas/$id/aprovar"
    Conferir 'Aprovar de novo' ($r.Status -eq 409) "HTTP $($r.Status)"

    # ------------------------------------------- Envio das folhas, uma a uma
    $lidas = 0; $primeira = $null
    $relogio = [Diagnostics.Stopwatch]::StartNew()
    foreach ($foto in Get-ChildItem $fotos -Filter '8A_aluno*.jpg' | Sort-Object Name) {
        $r = Api 'POST' "provas/$id/folhas" (@('-F', 'turma=8A') + (Recorte $foto.BaseName))
        if ($r.Status -eq 200 -and $r.Json.folhas[0].ok) {
            $lidas++
            if (-not $primeira) { $primeira = $r.Json.folhas[0] }
        } else {
            Write-Host "  $($foto.Name): HTTP $($r.Status) $($r.Json.erro) $($r.Json.folhas[0].erro)" -ForegroundColor Yellow
        }
        $contador = $r.Json.folhas_da_turma
    }
    $relogio.Stop()
    Conferir 'POST /folhas (20 recortes)' ($lidas -eq 20 -and $contador -eq 20) ("{0} lidas, contador {1}, {2:N1} s por foto" -f $lidas, $contador, ($relogio.Elapsed.TotalSeconds / 20))

    $r = Api 'POST' "provas/$id/folhas" @('-F', 'turma=8A', '-F', "folha=@$(Join-Path $raiz 'exemplos\gabarito_oficial.png')")
    Conferir 'Folha de outro modelo' ($r.Status -eq 200 -and -not $r.Json.folhas[0].ok -and $r.Json.folhas_da_turma -eq 20) "codigo $($r.Json.folhas[0].codigo)"

    $r = Api 'POST' "provas/$id/folhas" @('-F', 'turma=8A', '-F', "folha=@$(Join-Path $fotos '8A_aluno01.jpg')")
    Conferir 'Foto inteira no lugar do recorte' ($r.Status -eq 200 -and $r.Json.folhas[0].codigo -eq 'nao_recorte' -and $r.Json.folhas_da_turma -eq 20) "codigo $($r.Json.folhas[0].codigo)"

    # ------------------------------------------------------------- Relatório
    $r = Api 'GET' "provas/$id/relatorio?turma=8A"
    $resumo = $r.Json.resumo[0]
    Conferir 'GET /relatorio' ($r.Status -eq 200 -and $resumo.acerto -eq 63.1) `
        "acerto $($resumo.acerto)% (esperado 63,1), fácil $($resumo.por_dificuldade.facil), médio $($resumo.por_dificuldade.medio), difícil $($resumo.por_dificuldade.dificil); prioridades $(@($r.Json.prioridades | ForEach-Object { $_.numero }) -join ', ')"

    # ------------------------------------------------- Revisão de uma folha
    $r = Api 'GET' "folhas/$($primeira.id)"
    $imagem = & curl.exe -s -o NUL -w '%{http_code}' $r.Json.folha.imagem_url
    Conferir 'GET /folhas/{id} e imagem' ($r.Status -eq 200 -and $imagem -eq '200') "HTTP $($r.Status), imagem HTTP $imagem"
    $r = ApiJson 'PUT' "folhas/$($primeira.id)" @{ respostas = @{ '1' = 'MULTIPLA' } }
    Conferir 'PUT /folhas/{id}' ($r.Status -eq 200 -and $r.Json.folha.revisada -and $r.Json.folha.respostas[0] -eq 'MULTIPLA') "HTTP $($r.Status), nota $($r.Json.folha.n_acertos)/26"

    $r = Api 'GET' 'provas'
    Conferir 'GET /api/provas' ($r.Status -eq 200 -and @($r.Json.provas | Where-Object { $_.id -eq $id }).Count -eq 1) "$(@($r.Json.provas).Count) provas"
}
catch {
    Conferir 'Execução' $false $_.Exception.Message
}
finally {
    Remove-Item -Recurse -Force $temp -ErrorAction SilentlyContinue
}

$resultados | Format-Table -AutoSize -Wrap
$falhas = @($resultados | Where-Object { $_.OK -ne 'sim' }).Count
if ($falhas) { Write-Host "$falhas passo(s) falharam." -ForegroundColor Red; exit 1 }
Write-Host 'Todos os passos passaram.' -ForegroundColor Green
