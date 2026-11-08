<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Arma la factura de una compra EN PANTALLA a partir del JSON del DTE que mandó el
 * proveedor. Solo lee: no guarda PDF ni archivo nuevo, no cambia estados.
 *
 * El JSON es de terceros y viene de muchos sistemas distintos, así que se lee con
 * tolerancia: cada campo que falta o no tiene el tipo esperado queda en null (la
 * vista lo muestra como «—»). Devuelve solo cadenas ya formateadas; la vista las
 * escapa con {{ }}.
 */
class RepresentacionDteRecibido
{
    private const TIPOS = [
        '01' => 'Factura',
        '03' => 'Comprobante de crédito fiscal',
        '05' => 'Nota de crédito',
        '06' => 'Nota de débito',
        '07' => 'Comprobante de retención',
        '11' => 'Factura de exportación',
        '14' => 'Factura de sujeto excluido',
    ];

    /** CAT-022: tipo de documento de identificación del receptor. */
    private const DOCUMENTOS = ['36' => 'NIT', '13' => 'DUI', '02' => 'Carné de residente', '03' => 'Pasaporte', '37' => 'Documento'];

    private const CONDICIONES = ['1' => 'Contado', '2' => 'A crédito', '3' => 'Otro'];

    public function __construct(
        private readonly JsonAdjuntoDecoder $decoder,
        private readonly DteCorreoParser $comun,
    ) {}

    /**
     * Lee el JSON guardado de la compra. Prueba cada .json guardado, igual que el
     * sincronizador, y se queda con el primero que tenga forma de DTE.
     *
     * @return array{dte: ?array<string, mixed>, aviso: ?string}
     */
    public function leer(DocumentoRecibido $documento): array
    {
        $rutas = $documento->archivosGuardados('json');
        if ($rutas->isEmpty()) {
            return ['dte' => null, 'aviso' => 'Esta compra no tiene guardado el JSON del DTE, así que no se puede armar la factura en pantalla.'];
        }

        $aviso = 'El JSON guardado de esta compra no se pudo leer: está dañado o no es un JSON.';
        foreach ($rutas as $ruta) {
            try {
                $dec = $this->decoder->decodificar((string) Storage::disk('local')->get($ruta));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if (empty($dec['ok']) || ! is_array($dec['data'])) {
                continue;
            }

            $dte = $this->desdeJson($dec['data']);
            if ($dte !== null) {
                return ['dte' => $dte, 'aviso' => null];
            }
            $aviso = 'El JSON guardado de esta compra no tiene la forma de un DTE de Hacienda.';
        }

        return ['dte' => null, 'aviso' => $aviso];
    }

    /**
     * Datos listos para la vista, o null si el JSON no tiene ninguna parte de un DTE.
     *
     * @param  array<mixed>  $json
     * @return array<string, mixed>|null
     */
    public function desdeJson(array $json): ?array
    {
        $dte = $this->comun->desenvolver($json);
        $ident = $this->arreglo($dte['identificacion'] ?? null);
        $emisor = $this->arreglo($dte['emisor'] ?? null);
        $receptor = $this->arreglo($dte['receptor'] ?? null) ?: $this->arreglo($dte['sujetoExcluido'] ?? null);
        $resumen = $this->arreglo($dte['resumen'] ?? null);
        $cuerpo = array_values(array_filter($this->arreglo($dte['cuerpoDocumento'] ?? null), 'is_array'));

        if ($ident === [] && $emisor === [] && $resumen === [] && $cuerpo === []) {
            return null;
        }

        // Mismas claves que DteCorreoParser, pero leídas acá con `texto()`: aquel
        // convierte a cadena sin mirar el tipo y un objeto donde va un texto lo rompería.
        $tipo = $this->texto($ident, 'tipoDte') ?? $this->texto($dte, 'tipoDte');

        [$columnas, $lineas, $sumas] = $this->lineas($tipo, $cuerpo, $resumen);

        return [
            'tipo' => $tipo,
            'titulo' => self::TIPOS[$tipo] ?? ($tipo !== null ? 'Documento tipo '.$tipo : 'Documento tributario electrónico'),
            'numero_control' => $this->texto($ident, 'numeroControl') ?? $this->texto($dte, 'numeroControl'),
            'codigo_generacion' => $this->texto($ident, 'codigoGeneracion') ?? $this->texto($dte, 'codigoGeneracion'),
            'sello' => $this->texto($json, 'selloRecibido', 'sello', 'selloRecepcion')
                ?? $this->texto($dte, 'selloRecibido', 'sello', 'selloRecepcion')
                ?? $this->texto($this->arreglo($json['respuestaMH'] ?? null), 'selloRecibido')
                ?? $this->texto($this->arreglo($json['respuesta'] ?? null), 'selloRecibido'),
            'fecha' => $this->fecha($this->texto($ident, 'fecEmi', 'fechaEmision')),
            'hora' => $this->texto($ident, 'horEmi'),
            'condicion' => self::CONDICIONES[$this->texto($resumen, 'condicionOperacion') ?? ''] ?? null,
            'emisor' => $this->parte($emisor),
            'receptor' => $this->parte($receptor),
            'etiqueta_receptor' => $tipo === '14' ? 'Sujeto excluido' : 'Receptor',
            'relacionados' => $this->relacionados($dte),
            'columnas' => $columnas,
            'lineas' => $lineas,
            'sumas' => $sumas,
            'totales' => $this->totales($tipo, $resumen),
            'total_letras' => $this->texto($resumen, 'totalLetras', 'totalIVAretenidoLetras'),
            'apendice' => $this->apendice($dte),
        ];
    }

    /**
     * Emisor o receptor. Acepta la forma del CCF (nit/nrc) y la de la factura
     * (tipoDocumento/numDocumento).
     *
     * @param  array<mixed>  $p
     * @return array<string, ?string>
     */
    private function parte(array $p): array
    {
        $documento = null;
        if (($num = $this->texto($p, 'numDocumento')) !== null) {
            $documento = (self::DOCUMENTOS[$this->texto($p, 'tipoDocumento') ?? ''] ?? 'Documento').' '.$num;
        }

        $direccion = $p['direccion'] ?? null;

        return [
            'nombre' => $this->texto($p, 'nombre'),
            'nombre_comercial' => $this->texto($p, 'nombreComercial'),
            'nit' => $this->texto($p, 'nit'),
            'nrc' => $this->texto($p, 'nrc'),
            'documento' => $documento,
            'actividad' => $this->texto($p, 'descActividad', 'codActividad'),
            'direccion' => is_array($direccion) ? $this->texto($direccion, 'complemento') : $this->escalar($direccion),
            'telefono' => $this->texto($p, 'telefono'),
            'correo' => $this->texto($p, 'correo'),
        ];
    }

    /**
     * Columnas, filas y suma de columnas según el tipo de documento.
     *
     * @param  array<int, array<mixed>>  $cuerpo
     * @param  array<mixed>  $resumen
     * @return array{0: array<int, array{clave: string, etiqueta: string, num: bool}>, 1: array<int, array<string, ?string>>, 2: array<string, ?string>}
     */
    private function lineas(?string $tipo, array $cuerpo, array $resumen): array
    {
        $hayDescuento = collect($cuerpo)->contains(fn ($l) => $this->positivo($l['montoDescu'] ?? null));

        if ($tipo === '07') {
            $columnas = [
                ['clave' => 'item', 'etiqueta' => 'N.º', 'num' => false],
                ['clave' => 'documento', 'etiqueta' => 'Documento retenido', 'num' => false],
                ['clave' => 'fecha', 'etiqueta' => 'Fecha', 'num' => false],
                ['clave' => 'descripcion', 'etiqueta' => 'Descripción', 'num' => false],
                ['clave' => 'sujeto', 'etiqueta' => 'Monto sujeto', 'num' => true],
                ['clave' => 'retenido', 'etiqueta' => 'IVA retenido', 'num' => true],
            ];
            $filas = array_map(fn (array $l, int $i) => [
                'item' => $this->texto($l, 'numItem') ?? (string) ($i + 1),
                'documento' => trim(implode(' ', array_filter([
                    self::TIPOS[$this->texto($l, 'tipoDte') ?? ''] ?? $this->texto($l, 'tipoDte'),
                    $this->texto($l, 'numDocumento'),
                ]))) ?: null,
                'fecha' => $this->fecha($this->texto($l, 'fechaEmision')),
                'descripcion' => $this->texto($l, 'descripcion'),
                'sujeto' => $this->monto($l['montoSujetoGrav'] ?? null),
                'retenido' => $this->monto($l['ivaRetenido'] ?? null),
            ], $cuerpo, array_keys($cuerpo));

            return [$columnas, $filas, [
                'sujeto' => $this->monto($resumen['totalSujetoRetencion'] ?? null),
                'retenido' => $this->monto($resumen['totalIVAretenido'] ?? null),
            ]];
        }

        $columnas = [
            ['clave' => 'item', 'etiqueta' => 'N.º', 'num' => false],
            ['clave' => 'cantidad', 'etiqueta' => 'Cantidad', 'num' => true],
            ['clave' => 'descripcion', 'etiqueta' => 'Descripción', 'num' => false],
            ['clave' => 'precio', 'etiqueta' => 'Precio unitario', 'num' => true],
        ];
        if ($hayDescuento) {
            $columnas[] = ['clave' => 'descuento', 'etiqueta' => 'Descuento', 'num' => true];
        }

        // Columnas de monto por tipo: [clave, etiqueta, campo de la línea, campo del resumen].
        $montos = match ($tipo) {
            '11' => [['gravado', 'Gravado', 'ventaGravada', 'totalGravada'], ['no_gravado', 'No gravado', 'noGravado', 'totalNoGravado']],
            '14' => [['compra', 'Compra', 'compra', 'totalCompra']],
            default => [
                ['no_sujeto', 'No sujeto', 'ventaNoSuj', 'totalNoSuj'],
                ['exento', 'Exento', 'ventaExenta', 'totalExenta'],
                ['gravado', 'Gravado', 'ventaGravada', 'totalGravada'],
            ],
        };
        foreach ($montos as [$clave, $etiqueta]) {
            $columnas[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'num' => true];
        }

        $filas = [];
        foreach ($cuerpo as $i => $l) {
            $fila = [
                'item' => $this->texto($l, 'numItem') ?? (string) ($i + 1),
                'cantidad' => $this->cantidad($l['cantidad'] ?? null),
                'descripcion' => $this->texto($l, 'descripcion'),
                'precio' => $this->precio($l['precioUni'] ?? null),
                'descuento' => $this->monto($l['montoDescu'] ?? null),
            ];
            foreach ($montos as [$clave, , $campo]) {
                $fila[$clave] = $this->monto($l[$campo] ?? null);
            }
            $filas[] = $fila;
        }

        $sumas = [];
        foreach ($montos as [$clave, , , $total]) {
            $sumas[$clave] = $this->monto($resumen[$total] ?? null);
        }

        return [$columnas, $filas, $sumas];
    }

    /**
     * Renglones del resumen. Los fijos se muestran siempre («—» si faltan); los de
     * descuentos, retenciones y percepciones solo si traen un monto.
     *
     * @param  array<mixed>  $r
     * @return array<int, array{etiqueta: string, valor: ?string, destacado: bool}>
     */
    private function totales(?string $tipo, array $r): array
    {
        $filas = [];
        $fijo = function (string $etiqueta, string $campo, bool $destacado = false) use (&$filas, $r) {
            $filas[] = ['etiqueta' => $etiqueta, 'valor' => $this->monto($r[$campo] ?? null), 'destacado' => $destacado];
        };
        $siHay = function (string $etiqueta, string $campo) use (&$filas, $r) {
            if ($this->positivo($r[$campo] ?? null)) {
                $filas[] = ['etiqueta' => $etiqueta, 'valor' => $this->monto($r[$campo]), 'destacado' => false];
            }
        };

        if ($tipo === '07') {
            $fijo('Total sujeto a retención', 'totalSujetoRetencion');
            $fijo('Total IVA retenido', 'totalIVAretenido', true);

            return $filas;
        }

        if ($tipo === '14') {
            $siHay('Descuentos', 'totalDescu');
            $fijo('Subtotal', 'subTotal');
            $siHay('IVA retenido', 'ivaRete1');
            $siHay('Retención de renta', 'reteRenta');
            $fijo('Total a pagar', 'totalPagar', true);

            return $filas;
        }

        if ($tipo !== '11') {
            $fijo('Suma de ventas', 'subTotalVentas');
        }
        $siHay('Descuentos', 'totalDescu');
        foreach ($this->arreglo($r['tributos'] ?? null) as $t) {
            if (is_array($t)) {
                $filas[] = [
                    'etiqueta' => $this->texto($t, 'descripcion', 'codigo') ?? 'Tributo',
                    'valor' => $this->monto($t['valor'] ?? null),
                    'destacado' => false,
                ];
            }
        }
        if ($tipo === '11') {
            $siHay('Seguro', 'seguro');
            $siHay('Flete', 'flete');
        } else {
            $fijo('Subtotal', 'subTotal');
        }
        // En la factura (01) el IVA ya va dentro de los precios: se informa, no se suma.
        $siHay('IVA incluido', 'totalIva');
        $siHay('IVA percibido', 'ivaPerci1');
        $siHay('IVA retenido', 'ivaRete1');
        $siHay('Retención de renta', 'reteRenta');

        // Las notas de crédito y débito no tienen «total a pagar»: su total es el
        // monto de la operación.
        $tienePagar = array_key_exists('totalPagar', $r);
        $fijo('Monto total de la operación', 'montoTotalOperacion', ! $tienePagar);
        $siHay('No gravado', 'totalNoGravado');
        if ($tienePagar) {
            $fijo('Total a pagar', 'totalPagar', true);
        }

        return $filas;
    }

    /**
     * Documentos relacionados (el CCF que corrige una nota de crédito o débito).
     *
     * @param  array<mixed>  $dte
     * @return array<int, array{tipo: ?string, numero: ?string, fecha: ?string}>
     */
    private function relacionados(array $dte): array
    {
        $salida = [];
        foreach ($this->arreglo($dte['documentoRelacionado'] ?? null) as $d) {
            if (is_array($d)) {
                $tipo = $this->texto($d, 'tipoDocumento');
                $salida[] = [
                    'tipo' => self::TIPOS[$tipo ?? ''] ?? $tipo,
                    'numero' => $this->texto($d, 'numeroDocumento'),
                    'fecha' => $this->fecha($this->texto($d, 'fechaEmision')),
                ];
            }
        }

        return $salida;
    }

    /**
     * @param  array<mixed>  $dte
     * @return array<int, array{etiqueta: string, valor: ?string}>
     */
    private function apendice(array $dte): array
    {
        $salida = [];
        foreach ($this->arreglo($dte['apendice'] ?? null) as $a) {
            if (is_array($a) && ($valor = $this->texto($a, 'valor')) !== null) {
                $salida[] = ['etiqueta' => $this->texto($a, 'etiqueta', 'campo') ?? 'Dato', 'valor' => $valor];
            }
        }

        return $salida;
    }

    /** @return array<mixed> */
    private function arreglo(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }

    /** Primer valor no vacío y escalar de las claves dadas. */
    private function texto(array $datos, string ...$claves): ?string
    {
        foreach ($claves as $clave) {
            if (($v = $this->escalar($datos[$clave] ?? null)) !== null) {
                return $v;
            }
        }

        return null;
    }

    private function escalar(mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_int($valor) && ! is_float($valor)) {
            return null;
        }
        $v = trim((string) $valor);

        return $v === '' ? null : $v;
    }

    private function positivo(mixed $valor): bool
    {
        return is_numeric($valor) && (float) $valor > 0;
    }

    private function monto(mixed $valor): ?string
    {
        return is_numeric($valor) ? '$'.number_format((float) $valor, 2) : null;
    }

    /** Precio con al menos 2 decimales y hasta 6 si el proveedor los mandó. */
    private function precio(mixed $valor): ?string
    {
        if (! is_numeric($valor)) {
            return null;
        }
        $decimales = strlen(rtrim(substr(strrchr((string) $valor, '.') ?: '', 1), '0'));

        return '$'.number_format((float) $valor, max(2, min(6, $decimales)));
    }

    private function cantidad(mixed $valor): ?string
    {
        if (! is_numeric($valor)) {
            return null;
        }
        $texto = number_format((float) $valor, 4);

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    /** Fecha del DTE (Y-m-d) en d/m/Y. Si viene en otro formato, se muestra tal cual. */
    private function fecha(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $valor)->format('d/m/Y');
        } catch (Throwable) {
            return $valor;
        }
    }
}
