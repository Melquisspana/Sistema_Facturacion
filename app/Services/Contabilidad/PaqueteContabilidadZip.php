<?php

namespace App\Services\Contabilidad;

use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\Dte\DtePdfService;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Support\Archivos\ArchivoAlmacenado;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

/**
 * Arma el ZIP mensual para contabilidad (herramienta INTERNA; la contadora no entra al
 * sistema). Junta COMPRAS (documentos recibidos: Excel + PDF/JSON ya guardados
 * localmente) y VENTAS (reporte contadora: Excel + PDF/JSON de los DTE emitidos).
 *
 * SOLO LECTURA: no vuelve a descargar correos, no envía nada, no toca DTE emitidos,
 * correlativos ni transmisión. El PDF de ventas se regenera vía DtePdfService (mismo
 * servicio que "ver/descargar PDF" e email, idempotente); el JSON de ventas se lee del
 * archivo ya guardado en `json_generado_path` (no se regenera ni transmite nada).
 *
 * DOS COSAS QUE ANTES SE PERDÍAN EN SILENCIO:
 *
 *  1. **Un adjunto que no se pudo leer** se omitía sin dejar rastro, porque los discos
 *     locales están declarados con `throw => false` y `exists()` devuelve `false` tanto
 *     si el archivo no está como si el disco falla. Ahora cada lectura pasa por
 *     {@see ArchivoAlmacenado}, que separa "no está" de "no se pudo leer", y las dos
 *     cosas quedan escritas en el LEEME.txt con el motivo.
 *  2. **Un período incompleto** salía idéntico a uno completo. Ahora, si la cobertura
 *     dice que faltan días, el ZIP lo declara en la primera línea del LEEME.txt y el
 *     nombre del archivo lleva `_INCOMPLETO`: si el paquete sale del sistema, el aviso
 *     sale con él.
 */
class PaqueteContabilidadZip
{
    public function __construct(
        private readonly DocumentosRecibidosExcel $comprasExcel,
        private readonly ReporteContadoraExcel $ventasExcel,
        private readonly DtePdfService $ventasPdf,
    ) {}

    /**
     * @param  Collection<int, DocumentoRecibido>  $compras
     * @param  Collection<int, Dte>  $ventas
     * @param  array<string, mixed>|null  $cobertura  resultado de {@see CoberturaPaquete::para()}
     * @return array{ruta: string, compras_pdf: int, compras_json: int, ventas_pdf: int, ventas_json: int, incompleto: bool, incidencias: array<int, string>}
     */
    public function generar(
        string $etiqueta,
        Collection $compras,
        Collection $ventas,
        bool $incluirCompras,
        bool $incluirVentas,
        ?array $cobertura = null,
    ): array {
        // tempnam() CREA el archivo; agregarle '.zip' apuntaba a otra ruta y dejaba el
        // temporal original huérfano, uno por cada paquete generado. Se borra el que
        // sobra y se usa el nombre definitivo.
        $semilla = tempnam(sys_get_temp_dir(), 'paq_contab_');
        $rutaZip = $semilla.'.zip';
        @unlink($semilla);

        $zip = new ZipArchive;
        if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP del paquete en '.$rutaZip.'.');
        }

        $pdf = 0;
        $json = 0;
        $ventasPdf = 0;
        $ventasJson = 0;
        /** @var array<int, string> $incidencias */
        $incidencias = [];

        if ($incluirCompras) {
            $zip->addFromString("compras/documentos_recibidos_{$etiqueta}.xlsx", $this->xlsx($this->comprasExcel->generar($compras)));

            // Adjuntos ya guardados localmente (no se re-descarga nada de Yahoo).
            foreach ($compras as $doc) {
                foreach ((array) data_get($doc->metadata_json, 'archivos', []) as $ruta) {
                    if (! is_string($ruta)) {
                        continue;
                    }
                    $ext = strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION));
                    $sub = $ext === 'pdf' ? 'pdf' : ($ext === 'json' ? 'json' : null);
                    if ($sub === null) {
                        continue;
                    }

                    $archivo = ArchivoAlmacenado::leer('local', $ruta);
                    if (! $archivo->presente()) {
                        $incidencias[] = 'Compra #'.$doc->id.' ('.($doc->numero_control ?: 'sin número').'): '.$archivo->explicacion();

                        continue;
                    }

                    $zip->addFromString("compras/{$sub}/{$doc->id}_".basename($ruta), (string) $archivo->contenido);
                    $sub === 'pdf' ? $pdf++ : $json++;
                }
            }
        }

        if ($incluirVentas) {
            $zip->addFromString("ventas/reporte_contadora_{$etiqueta}.xlsx", $this->xlsx($this->ventasExcel->generar($ventas)));

            $discoDte = (string) config('dte.storage.disk', 'local');
            /** @var Dte $dte */
            foreach ($ventas as $dte) {
                $zip->addFromString('ventas/pdf/'.$dte->id.'_'.$this->ventasPdf->nombre($dte), $this->ventasPdf->bytes($dte));
                $ventasPdf++;

                $archivo = ArchivoAlmacenado::leer($discoDte, $dte->json_generado_path);
                if ($archivo->presente()) {
                    $zip->addFromString('ventas/json/'.$dte->id.'_'.basename((string) $dte->json_generado_path), (string) $archivo->contenido);
                    $ventasJson++;

                    continue;
                }

                // Un DTE sin JSON oficial guardado es un hecho conocido y esperable; un
                // JSON que está pero no se puede leer es un problema del servidor. Se
                // reportan los dos, con palabras distintas.
                $incidencias[] = 'Venta #'.$dte->id.' ('.($dte->numero_control ?: 'sin número').'): '.$archivo->explicacion();
            }
        }

        $incompleto = $cobertura !== null && ! ($cobertura['cubierto'] ?? true);

        $zip->addFromString('LEEME.txt', $this->leeme(
            $etiqueta, $incluirCompras, $incluirVentas, $pdf, $json, $ventasPdf, $ventasJson, $cobertura, $incidencias,
        ));

        if (! $zip->close()) {
            throw new RuntimeException('El ZIP del paquete no se pudo cerrar correctamente: el archivo podría estar incompleto.');
        }

        return [
            'ruta' => $rutaZip,
            'compras_pdf' => $pdf,
            'compras_json' => $json,
            'ventas_pdf' => $ventasPdf,
            'ventas_json' => $ventasJson,
            'incompleto' => $incompleto,
            'incidencias' => $incidencias,
        ];
    }

    /**
     * Nombre del archivo. Un paquete incompleto lo dice EN EL NOMBRE: el ZIP se
     * descarga, se reenvía y se archiva fuera del sistema, donde el aviso de la pantalla
     * ya no está para explicarlo.
     */
    public function nombreArchivo(string $etiqueta, bool $incompleto = false): string
    {
        return 'documentos_contabilidad_'.$etiqueta.($incompleto ? '_INCOMPLETO' : '').'.zip';
    }

    /** Lee el .xlsx generado a un string y borra el temporal. */
    private function xlsx(string $rutaTemporal): string
    {
        $contenido = (string) file_get_contents($rutaTemporal);
        @unlink($rutaTemporal);

        return $contenido;
    }

    /**
     * @param  array<string, mixed>|null  $cobertura
     * @param  array<int, string>  $incidencias
     */
    private function leeme(
        string $etiqueta, bool $compras, bool $ventas,
        int $pdf, int $json, int $ventasPdf, int $ventasJson,
        ?array $cobertura, array $incidencias,
    ): string {
        $lineas = [];

        // El aviso va PRIMERO, antes del índice de carpetas: quien abre el LEEME de un
        // paquete incompleto tiene que verlo sin desplazarse.
        if ($cobertura !== null && ! ($cobertura['cubierto'] ?? true)) {
            $lineas[] = '*** PERIODO INCOMPLETO — ESTE PAQUETE NO ESTA CERRADO ***';
            $lineas[] = (string) ($cobertura['motivo'] ?? 'Faltan días por revisar en el buzón de compras.');
            $pendientes = collect($cobertura['dias_pendientes'] ?? [])->pluck('dia');
            if ($pendientes->isNotEmpty()) {
                $lineas[] = 'Días sin revisar ('.$pendientes->count().'): '.$pendientes->implode(', ');
            }
            $lineas[] = 'Pueden faltar compras. Volvé a generarlo después de recuperar el período.';
            $lineas[] = '';
        }

        $lineas[] = 'Paquete de contabilidad '.$etiqueta;
        $lineas[] = 'Herramienta interna: la contadora no entra al sistema. Este paquete se le envía por fuera.';
        $lineas[] = '';

        if ($compras) {
            $lineas[] = 'compras/documentos_recibidos_'.$etiqueta.'.xlsx — CCF/facturas de proveedores recibidas.';
            $lineas[] = "compras/pdf/ — {$pdf} PDF de compras (adjuntos recibidos).";
            $lineas[] = "compras/json/ — {$json} JSON de compras (adjuntos recibidos).";
        }
        if ($ventas) {
            $lineas[] = 'ventas/reporte_contadora_'.$etiqueta.'.xlsx — documentos emitidos (ventas).';
            $lineas[] = "ventas/pdf/ — {$ventasPdf} PDF de ventas (documentos emitidos).";
            $lineas[] = "ventas/json/ — {$ventasJson} JSON de ventas (documentos emitidos con JSON oficial guardado).";
        }

        $lineas[] = '';
        $lineas[] = 'El período se arma por la FECHA DE EMISIÓN del documento (la fiscal), no por la fecha del correo.';

        if ($cobertura !== null) {
            $lineas[] = 'Cobertura: '.($cobertura['dias_completos'] ?? 0).' de '.($cobertura['dias_totales'] ?? 0).' día(s) revisados por completo.';
            if (($cobertura['compras_sin_fecha_fiscal'] ?? 0) > 0) {
                $lineas[] = 'ATENCIÓN: '.$cobertura['compras_sin_fecha_fiscal'].' compra(s) sin fecha de emisión legible NO entraron en ningún período. '
                    .'Están listadas en Compras y hay que resolverlas a mano.';
            }
        }

        if ($incidencias !== []) {
            $lineas[] = '';
            $lineas[] = 'ARCHIVOS QUE NO SE PUDIERON INCLUIR ('.count($incidencias).'):';
            foreach ($incidencias as $incidencia) {
                $lineas[] = '  - '.$incidencia;
            }
        }

        return implode("\r\n", $lineas)."\r\n";
    }
}
