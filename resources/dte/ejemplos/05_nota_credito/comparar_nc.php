<?php
/**
 * Comparador FUNCIONAL de Notas de Crédito tipo 05 (MH El Salvador).
 *
 * Uso:
 *   php comparar_nc.php <nuestra.json> <aceptada.json>
 *   (por defecto: nc58_ENVIADO.json  vs  nc_aceptada.json)
 *
 * Marca SOLO diferencias funcionales/estructurales:
 *   - Campos presentes en uno y ausentes en el otro (FALTA / SOBRA).
 *   - Diferencia de TIPO (string vs number, array vs null, etc.).
 *   - Diferencia de NULL-idad (null vs con valor) — funcional (p.ej. tributos null vs array).
 *   - Diferencia de VALOR solo en campos CATEGÓRICOS / de catálogo (enums, códigos de tributo).
 *
 * IGNORA (no son funcionales): montos, identificadores (numeroControl, codigoGeneracion,
 * numeroDocumento, NIT/NRC), nombres, direcciones, teléfonos, correos, descripciones, fechas,
 * códigos de producto. De esos solo se compara presencia/tipo/null-idad, NUNCA el valor.
 */

$ours = $argv[1] ?? __DIR__ . '/nc58_ENVIADO.json';
$theirs = $argv[2] ?? __DIR__ . '/nc_aceptada.json';

if (! is_file($ours)) {
    fwrite(STDERR, "No existe nuestro JSON: $ours\n");
    exit(1);
}
if (! is_file($theirs)) {
    fwrite(STDERR, "No existe el JSON aceptado: $theirs\n");
    fwrite(STDERR, "Dejá el JSON de la NC ACEPTADA en: $theirs\n");
    exit(1);
}

$a = json_decode(file_get_contents($ours), true);   // nuestra (rechazada)
$b = json_decode(file_get_contents($theirs), true); // aceptada (referencia)
if ($a === null || $b === null) {
    fwrite(STDERR, "JSON inválido en alguno de los archivos.\n");
    exit(1);
}

/**
 * Campos CATEGÓRICOS: su VALOR sí es funcional (enums, catálogos, flags estructurales).
 * Para el resto solo se compara presencia/tipo/null-idad.
 */
$CATEGORICOS = [
    // identificacion
    'version', 'ambiente', 'tipoDte', 'tipoModelo', 'tipoOperacion',
    'tipoContingencia', 'motivoContin', 'tipoMoneda', 'fusion',
    // documentoRelacionado
    'tipoDocumento', 'tipoGeneracion',
    // cuerpoDocumento (línea)
    'tipoItem', 'codTributo', 'uniMedida',
    // resumen
    'condicionOperacion', 'codigoRetencionMH', 'numPagoElectronico',
];

$diffs = [];

/** Tipo JSON normalizado de un valor. */
function jtype($v): string {
    if ($v === null) return 'null';
    if (is_bool($v)) return 'bool';
    if (is_int($v) || is_float($v)) return 'number';
    if (is_string($v)) return 'string';
    if (is_array($v)) return array_keys($v) === range(0, count($v) - 1) ? 'array' : 'object';
    return gettype($v);
}

/** Conjunto de códigos de tributo de un array de tributos (línea = strings; resumen = objetos). */
function tributoCodes($arr): array {
    if (! is_array($arr)) return [];
    $codes = [];
    foreach ($arr as $t) {
        if (is_string($t)) $codes[] = $t;
        elseif (is_array($t) && isset($t['codigo'])) $codes[] = $t['codigo'];
    }
    sort($codes);
    return $codes;
}

function walk($x, $y, string $path, array $CATEGORICOS, array &$diffs): void {
    // tributos: comparar el CONJUNTO de códigos (funcional), ignorar valores/descripciones.
    if (preg_match('#/tributos$#', $path)) {
        $cx = tributoCodes($x);
        $cy = tributoCodes($y);
        if ($cx !== $cy) {
            $diffs[] = "[TRIBUTOS] $path: nuestra=[" . implode(',', $cx) . "]  aceptada=[" . implode(',', $cy) . "]";
        }
        return;
    }

    $tx = jtype($x);
    $ty = jtype($y);

    // NULL-idad: uno null y el otro no → funcional.
    if (($tx === 'null') !== ($ty === 'null')) {
        $diffs[] = "[NULL]  $path: nuestra=" . ($tx === 'null' ? 'null' : 'con valor') .
                   "  aceptada=" . ($ty === 'null' ? 'null' : 'con valor');
        return;
    }
    if ($tx === 'null' && $ty === 'null') return;

    // TIPO distinto → funcional.
    if ($tx !== $ty) {
        $diffs[] = "[TIPO]  $path: nuestra=$tx  aceptada=$ty";
        return;
    }

    if ($tx === 'object') {
        $keys = array_unique(array_merge(array_keys($x), array_keys($y)));
        foreach ($keys as $k) {
            $hasX = array_key_exists($k, $x);
            $hasY = array_key_exists($k, $y);
            if (! $hasX) { $diffs[] = "[FALTA] $path/$k  (presente en la ACEPTADA, ausente en la nuestra)"; continue; }
            if (! $hasY) { $diffs[] = "[SOBRA] $path/$k  (presente en la nuestra, ausente en la ACEPTADA)"; continue; }
            walk($x[$k], $y[$k], "$path/$k", $CATEGORICOS, $diffs);
        }
        return;
    }

    if ($tx === 'array') {
        // Diferencia de array vacío vs no vacío (funcional).
        if ((count($x) === 0) !== (count($y) === 0)) {
            $diffs[] = "[ARRAY] $path: nuestra=" . count($x) . " items  aceptada=" . count($y) . " items";
        }
        // Comparar la ESTRUCTURA del primer item (representativo).
        if (count($x) > 0 && count($y) > 0) {
            walk($x[0], $y[0], "$path[0]", $CATEGORICOS, $diffs);
        }
        return;
    }

    // Escalar: comparar VALOR solo si la clave es categórica.
    $key = basename($path);
    if (in_array($key, $CATEGORICOS, true) && $x !== $y) {
        $diffs[] = "[VALOR] $path: nuestra=" . json_encode($x) . "  aceptada=" . json_encode($y);
    }
}

echo "================= COMPARACIÓN FUNCIONAL NC tipo 05 =================\n";
echo "Nuestra (rechazada): $ours\n";
echo "Aceptada (ref.):     $theirs\n";
echo "(Ignora montos, identificadores, nombres, direcciones, fechas, descripciones)\n\n";

foreach (['identificacion', 'documentoRelacionado', 'emisor', 'receptor', 'cuerpoDocumento', 'resumen', 'apendice', 'extension', 'ventaTercero'] as $sec) {
    walk($a[$sec] ?? null, $b[$sec] ?? null, "/$sec", $CATEGORICOS, $diffs);
}

if (! $diffs) {
    echo "(SIN diferencias funcionales — la estructura es idéntica; el problema estaría en datos/montos)\n";
} else {
    echo "DIFERENCIAS FUNCIONALES ENCONTRADAS (" . count($diffs) . "):\n\n";
    sort($diffs);
    foreach ($diffs as $d) echo "  $d\n";
}
echo "\n===================================================================\n";
