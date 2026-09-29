param(
    [Parameter(Mandatory = $true)][string]$PromptFile,
    [ValidateSet('Consulta', 'Desarrollo')][string]$Modo = 'Consulta',
    [string]$SessionId,
    [string]$Modelo,
    [switch]$SoloPreparar,
    [string]$InstruccionPreparada
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$prompt = Get-Content -LiteralPath $PromptFile -Raw -Encoding UTF8
if ([string]::IsNullOrWhiteSpace($prompt)) { throw 'La instruccion esta vacia.' }
if ($SessionId -and $SessionId -notmatch '^[a-fA-F0-9-]{36}$') { throw 'SessionId invalido.' }
if ($Modelo -and $Modelo -notmatch '^[\w.-]+$') { throw 'Modelo invalido.' }

# La app de Codex (Microsoft Store) trae su CLI; la ruta cambia con cada version.
$codex = (Get-Command codex.exe -ErrorAction SilentlyContinue).Source
if (-not $codex) {
    $paquete = Get-AppxPackage -Name OpenAI.Codex
    if (-not $paquete) { throw 'No se encontro la app de Codex ni codex.exe en PATH.' }
    $codex = Join-Path $paquete.InstallLocation 'app\resources\codex.exe'
}

$logRoot = Join-Path $projectRoot 'tmp/codex-colaboracion'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null
$lock = $null
$previousEncoding = $OutputEncoding
Push-Location $projectRoot
try {
    # Evita dos llamadas simultaneas desde este puente, no desde otras ventanas.
    $lock = [IO.File]::Open((Join-Path $logRoot 'puente.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
    $stamp = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
    $promptPath = Join-Path $logRoot "$stamp-prompt.txt"
    $eventsPath = Join-Path $logRoot "$stamp-eventos.jsonl"
    $lastPath = Join-Path $logRoot "$stamp-respuesta.txt"
    $errorPath = Join-Path $logRoot "$stamp-stderr.log"
    $instruction = @"
Colaboracion local: Claude coordina y revisa; Codex desarrolla solo la tarea concreta autorizada.
Lee AGENTS.md y las instrucciones aplicables antes de trabajar. Conserva cambios ajenos.
No hagas commit, push, despliegue, migraciones ni cambios de produccion. No accedas a C:\rclone.
No leas credenciales ni .env. No edites firmware/asistencia/asistencia.ino. No escribas en la base de datos del usuario.
Esta llamada tiene modo $Modo. En Consulta solo analiza. En Desarrollo limita las ediciones al encargo.
Puedes correr pruebas puntuales (phpunit --filter) y Pint solo sobre los archivos que tocaste.
No corras la suite completa salvo que el encargo lo pida: Claude la corre al revisar.
Si necesitas permisos o herramientas adicionales, informa el bloqueo, no intentes eludirlo.
Devuelve resultados, archivos cambiados, validaciones realizadas y pendientes, sin afirmar pruebas no ejecutadas.

Encargo:
$prompt
"@
    [IO.File]::WriteAllText($promptPath, $instruction, [Text.UTF8Encoding]::new($false))
    if ($SoloPreparar) {
        [pscustomobject]@{ Instruccion = $promptPath; Modo = $Modo; ModeloSolicitado = $Modelo }

        return
    }

    if ($InstruccionPreparada) {
        $preparada = [IO.File]::ReadAllText(
            (Resolve-Path -LiteralPath $InstruccionPreparada).Path,
            [Text.Encoding]::UTF8
        )
        if ($preparada -cne $instruction) {
            throw 'La instruccion preparada ya no coincide con el encargo o el modo. Preparala de nuevo antes de enviar.'
        }
        $instruction = $preparada
    }

    # 'resume' no acepta -s, por eso el aislamiento va como configuracion.
    $sandbox = if ($Modo -eq 'Desarrollo') { 'workspace-write' } else { 'read-only' }
    $cliArgs = @('exec')
    if ($SessionId) { $cliArgs += @('resume', $SessionId) }
    $cliArgs += @('-c', "sandbox_mode=`"$sandbox`"", '-c', 'approval_policy="never"', '--json', '-o', $lastPath)
    if ($Modelo) { $cliArgs += @('-m', $Modelo) }
    $cliArgs += '-'

    $OutputEncoding = [Text.UTF8Encoding]::new($false)
    # Windows PowerShell convierte stderr nativo en ErrorRecord; un aviso no
    # debe impedir guardar los eventos ni comprobar el codigo real del proceso.
    $ErrorActionPreference = 'Continue'
    try {
        $raw = $instruction | & $codex @cliArgs 2> $errorPath
        $exitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = 'Stop' }
    [IO.File]::WriteAllText($eventsPath, ($raw -join "`n"), [Text.UTF8Encoding]::new($false))

    $threadId = $SessionId
    $usage = $null
    $fallo = $null
    foreach ($linea in $raw) {
        try { $evento = $linea | ConvertFrom-Json } catch { continue }
        if ($evento.type -eq 'thread.started' -and $evento.thread_id) { $threadId = $evento.thread_id }
        if ($evento.type -eq 'turn.completed' -and $evento.usage) { $usage = $evento.usage }
        if ($evento.type -in @('turn.failed', 'error')) { $fallo = $linea }
    }
    if ($exitCode -ne 0 -or $fallo) { throw "Codex termino con codigo $exitCode. Ver $eventsPath y $errorPath" }

    $respuesta = if (Test-Path -LiteralPath $lastPath) { [IO.File]::ReadAllText($lastPath, [Text.Encoding]::UTF8) } else { '' }
    [pscustomobject]@{ SessionId = $threadId; Respuesta = $respuesta; Uso = $usage; Registro = $eventsPath }
} finally {
    $OutputEncoding = $previousEncoding
    if ($lock) { $lock.Dispose() }
    Pop-Location
}
