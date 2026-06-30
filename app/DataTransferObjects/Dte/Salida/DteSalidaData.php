<?php

namespace App\DataTransferObjects\Dte\Salida;

/**
 * Agrupa todas las secciones internas de un DTE listo para mapear al JSON oficial.
 *
 * Estructura INTERNA y preparatoria: no es el JSON final ni usa los nombres del
 * schema MH. El mapeo `Dte` → `DteSalidaData` y `DteSalidaData` → JSON oficial se
 * implementarán en pasos posteriores (mappers por tipo), cuando los esquemas
 * oficiales estén versionados.
 */
final readonly class DteSalidaData
{
    /**
     * @param  array<int, LineaDteData>  $lineas
     * @param  array<int, ApendiceDteData>  $apendice
     * @param  array<int, DocumentoRelacionadoDteData>  $documentoRelacionado
     */
    public function __construct(
        public IdentificacionDteData $identificacion,
        public EmisorDteData $emisor,
        public ResumenDteData $resumen,
        public array $lineas,
        public ?ReceptorDteData $receptor = null,
        public array $apendice = [],
        public array $documentoRelacionado = [],
    ) {}
}
