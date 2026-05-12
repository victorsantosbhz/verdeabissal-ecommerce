@echo off
REM Verde Abissal - Diagnostico de plugins
REM Da duplo-clique para rodar. Gera diagnose.txt na mesma pasta.

cd /d "%~dp0"

echo Rodando diagnostico... isso leva uns 15s.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File ".\diagnose-plugins.ps1" > diagnose.txt 2>&1

echo.
echo Pronto! Saida gravada em: %~dp0diagnose.txt
echo.
pause
