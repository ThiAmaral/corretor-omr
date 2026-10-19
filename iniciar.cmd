@echo off
rem Sobe o corretor sem Docker no Windows (PHP + Python instalados via winget).
rem Dois cliques neste arquivo e abra http://localhost:8001. Ctrl+C ou feche a janela para parar.
rem Porta 8001 porque a 8000 costuma estar ocupada por outros projetos (ex.: php artisan serve).
rem Aceita conexoes de outros aparelhos da rede (o celular com o aplicativo, Fase 7).
rem "iniciar.cmd local" aceita so do proprio computador.
cd /d "%~dp0"

set "OMR_PYTHON=python"
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" set "OMR_PYTHON=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"

where php >nul 2>nul || (
    echo PHP nao encontrado. Instale com: winget install PHP.PHP.8.3
    pause
    exit /b 1
)

if not exist ".env" (
    echo Falta o arquivo .env com os dados do Supabase. Copie o .env.exemplo para .env e preencha.
    pause
    exit /b 1
)

set "PORTA=8001"
set "ENDERECO=0.0.0.0:%PORTA%"
if /i "%~1"=="local" (
    set "ENDERECO=localhost:%PORTA%"
) else (
    echo Aberto para a rede local: no celular, use http://IP-DESTE-COMPUTADOR:%PORTA% ^(veja o IP com ipconfig^).
)
echo Corretor de provas em http://localhost:%PORTA%  (Ctrl+C para parar)
start "" http://localhost:%PORTA%
rem pdo_pgsql (banco), curl e openssl (Storage e login) vem com o PHP do winget, mas desligadas no php.ini.
rem O roteador manda /api/... para a API e esconde as pastas src/ e testes/.
php -d extension=pdo_pgsql -d extension=curl -d extension=openssl -d upload_max_filesize=50M -d post_max_size=200M -d max_file_uploads=100 -S %ENDERECO% -t web web/src/roteador.php
