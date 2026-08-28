' Starts the BPSF API under PM2 at logon, with no visible console window.
'
' The batch path is absolute on purpose: a copy of this file lives in the
' Windows Startup folder, so deriving the path from WScript.ScriptFullName
' would resolve to the Startup folder and fail to find the batch file.
'
' If the project is ever moved, update BOTH this path and the copy in:
'   %APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\BPSF-API.vbs
Dim sh
Set sh = CreateObject("WScript.Shell")
sh.Run """C:\Users\HP\Documents\buypersquarefoot\project-files\local-tools\start-api.bat""", 0, False
