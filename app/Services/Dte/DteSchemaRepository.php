<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;

/**
 * Lector/inventario de los JSON Schema OFICIALES del MH guardados localmente.
 *
 * NO valida ni interpreta el contenido: solo localiza los archivos en
 * resources/dte/schemas/<carpeta>/ y reporta cuáles existen y cuáles faltan.
 * Los esquemas deben descargarse del MH y colocarse manualmente (ver
 * docs/dte/README.md). Este servicio no descarga nada.
 */
class DteSchemaRepository
{
    /** Carpeta por tipo de DTE dentro de resources/dte/schemas. */
    private const CARPETAS = [
        '01' => '01_factura',
        '03' => '03_ccf',
        '05' => '05_nota_credito',
        '11' => '11_exportacion',
    ];

    private string $base;

    public function __construct(?string $base = null)
    {
        $this->base = $base ?? resource_path('dte/schemas');
    }

    /**
     * Tipos cubiertos por el repositorio (alcance del proyecto).
     *
     * @return array<int, TipoDte>
     */
    public function tiposSoportados(): array
    {
        return [TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::NotaCredito, TipoDte::FacturaExportacion];
    }

    /** Carpeta absoluta esperada para el schema de un tipo. */
    public function carpeta(TipoDte $tipo): string
    {
        return $this->base.DIRECTORY_SEPARATOR.(self::CARPETAS[$tipo->value] ?? $tipo->value);
    }

    /**
     * Info del schema disponible para un tipo, o null si falta.
     *
     * @return array{tipo: string, carpeta: string, archivo: string, ruta: string, version: int}|null
     */
    public function paraTipo(TipoDte $tipo): ?array
    {
        $dir = $this->carpeta($tipo);
        if (! is_dir($dir)) {
            return null;
        }

        $archivos = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        if ($archivos === []) {
            return null;
        }

        $version = (int) (config('dte.json.versiones')[$tipo->value] ?? 0);

        // Prefiere el archivo cuya versión coincida con la configurada (-v3 o _v3).
        $elegido = null;
        foreach ($archivos as $archivo) {
            if ($version > 0 && preg_match('/[-_]v'.$version.'\.json$/i', $archivo)) {
                $elegido = $archivo;
                break;
            }
        }
        $elegido ??= $archivos[0];

        return [
            'tipo' => $tipo->value,
            'carpeta' => self::CARPETAS[$tipo->value] ?? $tipo->value,
            'archivo' => basename($elegido),
            'ruta' => $elegido,
            'version' => $version,
        ];
    }

    public function falta(TipoDte $tipo): bool
    {
        return $this->paraTipo($tipo) === null;
    }

    /**
     * Schemas presentes, indexados por código de tipo.
     *
     * @return array<string, array{tipo: string, carpeta: string, archivo: string, ruta: string, version: int}>
     */
    public function disponibles(): array
    {
        $disponibles = [];
        foreach ($this->tiposSoportados() as $tipo) {
            $info = $this->paraTipo($tipo);
            if ($info !== null) {
                $disponibles[$tipo->value] = $info;
            }
        }

        return $disponibles;
    }

    /**
     * Tipos cuyo schema todavía no se ha colocado.
     *
     * @return array<int, TipoDte>
     */
    public function faltantes(): array
    {
        return array_values(array_filter($this->tiposSoportados(), fn (TipoDte $t) => $this->falta($t)));
    }

    /** Contenido crudo del schema (sin parsear) o null si falta. */
    public function leer(TipoDte $tipo): ?string
    {
        $info = $this->paraTipo($tipo);

        return $info ? file_get_contents($info['ruta']) : null;
    }
}
