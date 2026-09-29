param(
    [Parameter(Mandatory = $true)][string]$PromptFile,
    [ValidateSet('Consulta', 'Desarrollo')][string]$Modo = 'Consulta',
    [string]$SessionId,
    [switch]$SoloPreparar,
    [string]$InstruccionPreparada
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$prompt = Get-Content -LiteralPath $PromptFile -Raw -Encoding UTF8
if ([string]::IsNullOrWhiteSpace($prompt)) { throw 'La instruccion esta vacia.' }
if ($SessionId -and $SessionId -notmatch '^[a-fA-F0-9-]{36}$') { throw 'SessionId invalido.' }
$claude = (Get-Command claude.exe -ErrorAction Stop).Source
$logRoot = Join-Path $projectRoot 'tmp/claude-colaboracion'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null
$lock = $null
$previousEncoding = $OutputEncoding
Push-Location $projectRoot
try {
    # Evita dos llamadas simultaneas desde este puente, no desde otras ventanas.
    $lock = [IO.File]::Open((Join-Path $logRoot 'puente.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
    $stamp = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
    $promptPath = Join-Path $logRoot "$stamp-prompt.txt"
    $outputPath = Join-Path $logRoot "$stamp-respuesta.json"
    $errorPath = Join-Path $logRoot "$stamp-stderr.log"
    $instruction = @"
Colaboracion local: Codex coordina y revisa; Claude desarrolla solo la tarea concreta autorizada.
Lee AGENTS.md y las instrucciones aplicables antes de trabajar. Conserva cambios ajenos.
No hagas commit, push, despliegue, migraciones ni cambios de produccion. No accedas a C:\rclone.
No leas credenciales ni .env. No edites firmware/asistencia/asistencia.ino.
Esta llamada tiene modo $Modo. En Consulta solo analiza. En Desarrollo limita las ediciones al encargo.
No tienes herramienta de shell en este puente: informa los comandos de validacion que Codex debe ejecutar.
Si necesitas permisos o herramientas adicionales, informa el bloqueo, no intentes eludirlo.
Devuelve resultados, archivos cambiados, validaciones realizadas y pendientes, sin afirmar pruebas no ejecutadas.

Encargo:
$prompt
"@
    [IO.File]::WriteAllText($promptPath, $instruction, [Text.UTF8Encoding]::new($false))
    if ($SoloPreparar) {
        [pscustomobject]@{ Instruccion = $promptPath; Modo = $Modo; ModeloSolicitado = $env:ANTHROPIC_MODEL }

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

    $cliArgs = @('-p', '--output-format', 'json', '--permission-mode', 'plan', '--tools', 'Read,Glob,Grep')
    if ($Modo -eq 'Desarrollo') {
        $cliArgs = @('-p', '--output-format', 'json', '--permission-mode', 'acceptEdits', '--tools', 'Read,Glob,Grep,Edit,Write')
    }
    if ($SessionId) { $cliArgs += @('--resume', $SessionId) }
    $OutputEncoding = [Text.UTF8Encoding]::new($false)
    # Windows PowerShell convierte stderr nativo en ErrorRecord; un aviso no
    # debe impedir guardar el JSON ni comprobar el codigo real del proceso.
    $ErrorActionPreference = 'Continue'
    try {
        $raw = $instruction | & $claude @cliArgs 2> $errorPath
        $exitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = 'Stop' }
    $json = $raw -join "`n"
    [IO.File]::WriteAllText($outputPath, $json, [Text.UTF8Encoding]::new($false))
    if ($exitCode -ne 0) { throw "Claude termino con codigo $exitCode. Ver $outputPath y $errorPath" }
    $result = $json | ConvertFrom-Json
    if ($result.is_error) { throw "Claude devolvio un error. Ver $outputPath" }
    [pscustomobject]@{ SessionId = $result.session_id; Respuesta = $result.result; Registro = $outputPath }
} finally {
    $OutputEncoding = $previousEncoding
    if ($lock) { $lock.Dispose() }
    Pop-Location
}
