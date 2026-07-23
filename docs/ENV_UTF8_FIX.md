# Corrección UTF-8 pendiente en `.env` (responsable/solicitante de invalidación)

## Diagnóstico confirmado

El `.env` de esta máquina tiene, literalmente en el archivo, estas dos líneas con
**doble codificación UTF-8** (los bytes UTF-8 de "á/ñ" fueron reinterpretados como
Windows-1252 y vueltos a guardar como UTF-8):

```
DTE_INVALIDACION_RESP_NOMBRE="Elsa Fidelina HernÃ¡ndez CaÃ±as"
DTE_INVALIDACION_SOL_NOMBRE="Elsa Fidelina HernÃ¡ndez CaÃ±as"
```

**Esto NO es un artefacto de la consola de Windows.** Se confirmó corriendo
`php artisan dte:invalidacion-preflight 145 ...` (solo lectura): el JSON del evento
generado por el código muestra la misma corrupción, porque el valor ya llega mal
desde `.env` — el código de serialización no la introduce (ver
`tests/Feature/Dte/SerializadorInvalidacionMhTest.php::test_evento_serializado_conserva_utf8_correcto_en_nombre_responsable_y_solicitante`,
que prueba que si el nombre llega bien codificado, el JSON sale bien codificado).

Esta tarea **no modificó `.env`** (instrucción explícita). Queda pendiente que el
operador pegue las dos líneas correctas manualmente.

## Líneas correctas a pegar

```
DTE_INVALIDACION_RESP_NOMBRE="Elsa Fidelina Hernández Cañas"
DTE_INVALIDACION_SOL_NOMBRE="Elsa Fidelina Hernández Cañas"
```

## Cómo editarlas SIN corromper el archivo

### Opción A — Editor de texto (más simple)
Abrí `.env` con VS Code (o Notepad++). Antes de guardar, confirmá la codificación:
- VS Code: abajo a la derecha debe decir **"UTF-8"** (NO "UTF-8 with BOM"). Si dice
  otra cosa, hacé clic ahí y elegí "Save with Encoding" → "UTF-8".
- Reemplazá las dos líneas exactamente como arriba y guardá.

### Opción B — PowerShell (si no hay editor a mano)

**No uses** `Set-Content -Encoding utf8` en Windows PowerShell 5.1: ese modo agrega un
BOM (marca de orden de bytes) al PRINCIPIO del archivo, lo que puede romper el
parseo de la primera línea de `.env` (y algunos parsers de `.env` no toleran un BOM
al inicio). En su lugar, usá lectura/escritura explícita SIN BOM:

```powershell
$ruta = "C:\laragon\www\Facturacion\.env"

# Leer preservando UTF-8 (sin asumir la codificación por defecto del sistema).
$lineas = [System.IO.File]::ReadAllLines($ruta, [System.Text.Encoding]::UTF8)

for ($i = 0; $i -lt $lineas.Length; $i++) {
    if ($lineas[$i] -like 'DTE_INVALIDACION_RESP_NOMBRE=*') {
        $lineas[$i] = 'DTE_INVALIDACION_RESP_NOMBRE="Elsa Fidelina Hernández Cañas"'
    }
    if ($lineas[$i] -like 'DTE_INVALIDACION_SOL_NOMBRE=*') {
        $lineas[$i] = 'DTE_INVALIDACION_SOL_NOMBRE="Elsa Fidelina Hernández Cañas"'
    }
}

# Escribir de vuelta en UTF-8 SIN BOM ($false), preservando el resto del archivo intacto.
$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllLines($ruta, $lineas, $enc)
```

Este script solo toca esas dos líneas (todo lo demás del `.env` queda idéntico) y
nunca introduce un BOM. **No se ejecutó** como parte de esta tarea — es para que lo
corras vos cuando quieras.

## Verificación después de editar

```cmd
php artisan tinker --execute="echo config('dte.invalidacion.responsable.nombre');"
```
Debe mostrar `Elsa Fidelina Hernández Cañas` (sin `Ã`). También podés confirmar con
`php artisan dte:invalidacion-preflight 145 --tipo=3 --motivo="..."` (solo lectura)
y revisar el bloque "motivo del evento" del JSON impreso.

## Qué NO cambia con esto

- No transmite, firma ni invalida nada.
- No afecta el candado `produccion_enabled` ni la protección de evidencia.
- El test de regresión UTF-8 ya existente sigue verde independientemente de si se
  edita el `.env` o no (prueba el código, no el archivo real de esta máquina).
