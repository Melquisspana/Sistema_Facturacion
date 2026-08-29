<?php

namespace App\Services\Ppq\Exportadores;

use App\Models\ClientePerfilDocumento;
use App\Models\NcExportacion;

/**
 * Un formato de archivo de notas de crédito exigido por un cliente.
 *
 * La interfaz existe para que el formato se elija por un SLUG guardado en el perfil
 * (`cliente_perfiles_documento.formato_export`) y no por el nombre del cliente. El día
 * que otra cadena pida el mismo archivo se le pone el mismo slug y no hace falta
 * código nuevo; el día que pida uno distinto, se agrega una implementación acá y se
 * registra en {@see ExportadorNcFactory}. Mismo patrón que
 * {@see \App\Services\Dte\Serializadores\SerializadorMhFactory}.
 */
interface ExportadorNc
{
    /** Slug con el que el perfil de un cliente pide este formato. */
    public static function slug(): string;

    /** Escribe el archivo del lote y devuelve la ruta temporal generada. */
    public function generar(NcExportacion $lote, ClientePerfilDocumento $perfil): string;
}
