@echo off
rem Sobe o corretor sem Docker no Windows (PHP + Python instalados via winget).
rem Dois cliques neste arquivo e abra http://localhost:8000. Ctrl+C ou feche a janela para parar.
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

echo Corretor de provas em http://localhost:8000  (Ctrl+C para parar)
start "" http://localhost:8000
rem pdo_pgsql (banco), curl e openssl (Storage e login) vem com o PHP do winget, mas desligadas no php.ini.
rem O roteador manda /api/... para a API e esconde as pastas src/ e testes/.
php -d extension=pdo_pgsql -d extension=curl -d extension=openssl -d upload_max_filesize=50M -d post_max_size=200M -d max_file_uploads=100 -S localhost:8000 -t web web/src/roteador.php
