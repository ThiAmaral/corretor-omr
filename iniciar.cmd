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

echo Corretor de provas em http://localhost:8000  (Ctrl+C para parar)
start "" http://localhost:8000
php -S localhost:8000 -t web
