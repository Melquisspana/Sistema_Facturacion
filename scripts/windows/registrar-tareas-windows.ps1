<#
.SYNOPSIS
    Registra las tareas programadas de Windows del sistema DTE (worker de colas y
    backup diario de BD). NO SE EJECUTA AUTOMATICAMENTE: es el operador quien la corre
    a mano, una vez, en el servidor real, como Administrador.

.DESCRIPTION
    Este script NO modifica nada por si solo al ser creado/editado en el repositorio:
    hay que ejecutarlo explicitamente (`powershell -ExecutionPolicy Bypass -File
    scripts\windows\registrar-tareas-windows.ps1`) para que registre algo en el
    Programador de tareas de Windows real. Requiere PowerShell como Administrador.

    Tarea "DTE Queue Worker":
      - Ejecuta scripts\windows\queue-worker-auto.bat.
      - Disparador: al iniciar sesion Y al iniciar el sistema (cubre servidor sin
        sesion interactiva con "Ejecutar tanto si el usuario inicio sesion como si no").
      - Oculta (sin ventana visible), privilegios mas altos (-RunLevel Highest).
      - Reinicio automatico si falla (RestartCount/RestartInterval).
      - MultipleInstances = IgnoreNew: si la tarea ya esta corriendo, Windows NO
        arranca una segunda instancia (complementa la verificacion propia del .bat).

    Tarea "DTE Backup Diario":
      - Ejecuta scripts\windows\backup-diario.bat (php artisan backup:mysql-diario).
      - Disparador diario a las 2:00 a.m.
      - Oculta, privilegios mas altos, sin reintentos multiples simultaneos.
      - Independiente de spatie/laravel-backup (backup:run/backup:clean, ya
        programados en routes/console.php si algo corre schedule:run cada minuto):
        este es el backup NUEVO que alimenta el readiness de produccion.

.NOTES
    No transmite nada a Hacienda ni toca .env: solo registra tareas de SO que arrancan
    procesos ya existentes (worker de Laravel). Revisar rutas (PHP_BIN_DTE, carpeta del
    proyecto) antes de correr en un servidor distinto a esta PC.
#>

param(
    [string]$ProjectDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)

$ErrorActionPreference = 'Stop'

function Registrar-TareaWorker {
    $nombre = 'DTE Queue Worker'
    $script = Join-Path $ProjectDir 'scripts\windows\queue-worker-auto.bat'

    if (-not (Test-Path $script)) {
        Write-Warning "No se encontro $script. Abortando registro de '$nombre'."
        return
    }

    $accion = New-ScheduledTaskAction -Execute $script -WorkingDirectory $ProjectDir

    $disparadores = @(
        New-ScheduledTaskTrigger -AtLogOn
        New-ScheduledTaskTrigger -AtStartup
    )

    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

    $ajustes = New-ScheduledTaskSettingsSet `
        -Hidden `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -RestartCount 999 `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Hours 0) # sin limite: es un worker de larga duracion

    Register-ScheduledTask -TaskName $nombre -Action $accion -Trigger $disparadores `
        -Principal $principal -Settings $ajustes -Force `
        -Description 'Worker de colas (queue:work) del sistema DTE. Reinicia solo si se cae. No transmite nada por si mismo: solo procesa jobs ya encolados (correos, etc).' | Out-Null

    Write-Host "Tarea '$nombre' registrada. Probarla sin esperar el disparador: Start-ScheduledTask -TaskName '$nombre'"
}

function Registrar-TareaBackup {
    $nombre = 'DTE Backup Diario'
    $script = Join-Path $ProjectDir 'scripts\windows\backup-diario.bat'

    if (-not (Test-Path $script)) {
        Write-Warning "No se encontro $script. Abortando registro de '$nombre'."
        return
    }

    $accion = New-ScheduledTaskAction -Execute $script -Argument 'auto' -WorkingDirectory $ProjectDir
    $disparador = New-ScheduledTaskTrigger -Daily -At '02:00'
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $ajustes = New-ScheduledTaskSettingsSet -Hidden -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5)

    Register-ScheduledTask -TaskName $nombre -Action $accion -Trigger $disparador `
        -Principal $principal -Settings $ajustes -Force `
        -Description 'Backup diario verificado de la base de datos (mysqldump + SHA-256 + registro en respaldo_ejecuciones). No reemplaza al zip completo de spatie/laravel-backup.' | Out-Null

    Write-Host "Tarea '$nombre' registrada. Probarla sin esperar el disparador: Start-ScheduledTask -TaskName '$nombre'"
}

Write-Host "Proyecto: $ProjectDir"
Write-Host 'Este script va a registrar tareas programadas de Windows REALES en esta maquina.'
Write-Host 'Ctrl+C ahora para cancelar, o Enter para continuar...'
[void][System.Console]::ReadLine()

Registrar-TareaWorker
Registrar-TareaBackup

Write-Host ''
Write-Host 'Listo. Revisa el Programador de tareas de Windows (taskschd.msc) para confirmar.'
