<#
Genera las frases habladas del lector (voces.h) con la voz en espanol de Windows.

Uso (desde la raiz del proyecto, en Windows):

    powershell -NoProfile -ExecutionPolicy Bypass -File firmware/asistencia_es3c28p/herramientas/generar_voces.ps1

Escribe firmware/asistencia_es3c28p/voces.h con cada frase como PCM de 16 bits,
mono, a 16 kHz (la misma frecuencia del I2S del firmware), sin el silencio del
principio y del final, y normalizada para que todas suenen al mismo volumen.

Requiere una voz es-* instalada (Windows en espanol trae "Microsoft Sabina" o
"Microsoft Helena"). Para cambiar una frase, editarla aca y volver a generar.
#>

$ErrorActionPreference = 'Stop'

$frases = [ordered]@{
    'VOZ_ENTRADA'       = 'Entrada registrada'
    'VOZ_SALIDA'        = 'Salida registrada'
    'VOZ_YA_MARCASTE'   = 'Ya marcaste'
    'VOZ_NO_REGISTRADA' = 'Huella no registrada'
}

$hz = 16000
$pico = 0.85      # fraccion del maximo de 16 bits tras normalizar
$umbral = 300     # amplitud bajo la cual se considera silencio al recortar
$margen = 0.04    # segundos de silencio que se dejan a cada lado

$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$destino = Join-Path $raiz 'firmware\asistencia_es3c28p\voces.h'

Add-Type -AssemblyName System.Speech
$voz = New-Object System.Speech.Synthesis.SpeechSynthesizer
$espanol = $voz.GetInstalledVoices() | Where-Object { $_.Enabled -and $_.VoiceInfo.Culture.Name -like 'es-*' } | Select-Object -First 1
if (-not $espanol) { throw 'No hay ninguna voz en espanol instalada en Windows.' }
$voz.SelectVoice($espanol.VoiceInfo.Name)
$voz.Rate = 0

$formato = New-Object System.Speech.AudioFormat.SpeechAudioFormatInfo(
    $hz,
    [System.Speech.AudioFormat.AudioBitsPerSample]::Sixteen,
    [System.Speech.AudioFormat.AudioChannel]::Mono
)

$sb = New-Object System.Text.StringBuilder
[void]$sb.AppendLine('// GENERADO por herramientas/generar_voces.ps1. No editar a mano.')
[void]$sb.AppendLine("// Voz: $($espanol.VoiceInfo.Name). PCM 16 bits, mono, $hz Hz.")
[void]$sb.AppendLine('#pragma once')
[void]$sb.AppendLine('#include <stdint.h>')
[void]$sb.AppendLine('')

$total = 0
foreach ($nombre in $frases.Keys) {
    $wav = [IO.Path]::GetTempFileName()
    try {
        $voz.SetOutputToWaveFile($wav, $formato)
        $voz.Speak($frases[$nombre])
        $voz.SetOutputToNull()
        $bytes = [IO.File]::ReadAllBytes($wav)
    } finally {
        Remove-Item $wav -ErrorAction SilentlyContinue
    }

    # Buscar el bloque "data" del WAV (no siempre empieza en el byte 44).
    $i = 12
    $inicioDatos = -1; $largoDatos = 0
    while ($i -lt $bytes.Length - 8) {
        $id = [Text.Encoding]::ASCII.GetString($bytes, $i, 4)
        $tam = [BitConverter]::ToInt32($bytes, $i + 4)
        if ($id -eq 'data') { $inicioDatos = $i + 8; $largoDatos = $tam; break }
        $i += 8 + $tam
    }
    if ($inicioDatos -lt 0) { throw "WAV sin bloque data para $nombre" }

    $n = [int]($largoDatos / 2)
    $muestras = New-Object 'int16[]' $n
    [Buffer]::BlockCopy($bytes, $inicioDatos, $muestras, 0, $n * 2)

    # Recortar silencio.
    $a = 0; while ($a -lt $n -and [Math]::Abs([int]$muestras[$a]) -lt $umbral) { $a++ }
    $b = $n - 1; while ($b -gt $a -and [Math]::Abs([int]$muestras[$b]) -lt $umbral) { $b-- }
    $m = [int]($margen * $hz)
    $a = [Math]::Max(0, $a - $m); $b = [Math]::Min($n - 1, $b + $m)

    # Normalizar.
    $max = 1
    for ($k = $a; $k -le $b; $k++) { $v = [Math]::Abs([int]$muestras[$k]); if ($v -gt $max) { $max = $v } }
    $factor = ($pico * 32767) / $max

    $largo = $b - $a + 1
    $total += $largo
    [void]$sb.AppendLine("// '$($frases[$nombre])' ($([Math]::Round($largo / $hz, 2)) s)")
    [void]$sb.AppendLine("const uint32_t ${nombre}_LARGO = $largo;")
    [void]$sb.Append("const int16_t ${nombre}_PCM[$largo] = {")
    for ($k = 0; $k -lt $largo; $k++) {
        if ($k % 16 -eq 0) { [void]$sb.Append("`n  ") }
        $v = [int][Math]::Round($muestras[$a + $k] * $factor)
        $v = [Math]::Max(-32768, [Math]::Min(32767, $v))
        [void]$sb.Append($v).Append(',')
    }
    [void]$sb.AppendLine("`n};")
    [void]$sb.AppendLine('')
}

$voz.Dispose()
[IO.File]::WriteAllText($destino, $sb.ToString().Replace("`r`n", "`n"), (New-Object Text.UTF8Encoding $false))
Write-Output ("voces.h: {0} frases, {1:N0} muestras ({2:N0} KB de flash)" -f $frases.Count, $total, ($total * 2 / 1024))
