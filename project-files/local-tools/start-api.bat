@echo off
REM Starts the BPSF API under PM2 on this machine.
REM Called at logon by the Startup shortcut, and usable by double-clicking.
REM
REM Paths are absolute because this file no longer lives inside the server
REM folder — it is a machine-specific helper, not part of the deployable app.
REM Node is not on this machine's PATH, so it is invoked by full path too.

set "BPSF_SERVER=C:\Users\HP\Documents\buypersquarefoot\deploy\server"

cd /d "%BPSF_SERVER%"
"C:\Program Files\nodejs\node.exe" "%BPSF_SERVER%\node_modules\pm2\bin\pm2" resurrect
