<?php

namespace App\Services\Cobros\Exportadores;

use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;

/**
 * Un formato de archivo de SOLICITUD de cobro exigido por el cliente.
 *
 * Existe como interfaz —con una sola implementación hoy— por la misma razón que la de las
 * notas de crédito: el formato se elige por un SLUG que queda guardado en la solicitud, así
 * que una solicitud se GENERA (una sola vez, en su primera descarga) con el formato con el
 * que nació aunque el cliente ya pida otro; las descargas siguientes entregan la copia
 * archivada. El día que el portal cambie de archivo, se agrega una implementación y las
 * solicitudes anteriores no se tocan.
 *
 * Lo que NO se hace es adelantar formatos imaginados: acá solo entra un formato cuando hay
 * una plantilla real del cliente que copiar.
 */
interface ExportadorSolicitud
{
    /** Slug con el que una solicitud recuerda con qué formato se armó. */
    public static function slug(): string;

    /** Nombre legible del formato, para pantallas y listados. */
    public static function nombre(): string;

    /** Cómo llega este archivo al cliente y qué NO significa haberlo descargado. */
    public static function entrega(): string;

    /**
     * Datos que le faltan a este documento para poder escribir su fila, ya redactados para
     * leerse en pantalla. Vacío = la fila sale entera.
     *
     * @return array<int, string>
     */
    public function faltantes(CobroDocumento $documento): array;

    /** Escribe el archivo de la solicitud y devuelve la ruta temporal generada. */
    public function generar(CobroSolicitud $solicitud): string;
}
