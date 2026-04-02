@echo off
REM Riverso POS - GUI de Administración
REM Ejecutar desde la carpeta del proyecto

cd /d "%~dp0"

REM Activar entorno virtual si existe
if exist "env\Scripts\activate.bat" (
    call env\Scripts\activate.bat
)

REM Ejecutar GUI
python riverso_pos_gui.py

pause
