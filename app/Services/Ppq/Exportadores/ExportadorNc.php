<?php

namespace App\Services\Ppq\Exportadores;

use App\Enums\EstadoNcExportacion;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\NcExportacion;
use App\Services\Dte\Serializadores\SerializadorMhFactory;

/**
 * Un formato de archivo de notas de crédito exigido por un cliente.
 *
 * La interfaz existe para que el formato se elija por un SLUG guardado en el perfil
 * (`cliente_perfiles_documento.formato_export`) y no por el nombre del cliente. El día
 * que otra cadena pida el mismo archivo se le pone el mismo slug y no hace falta
 * código nuevo; el día que pida uno distinto, se agrega una implementación acá y se
 * registra en {@see ExportadorNcFactory}. Mismo patrón que
 * {@see SerializadorMhFactory}.
 *
 * Además del archivo, cada formato declara CÓMO SE ENTREGA ({@see entrega()}) y QUÉ DATOS
 * NECESITA ({@see faltantes()}). Las dos cosas viven acá y no en la pantalla porque
 * dependen del formato: un archivo que se adjunta a un correo y otro que se sube a un
 * portal no se explican igual, y cada uno pide columnas distintas. La pantalla solo
 * muestra lo que el formato activo responda.
 */
interface ExportadorNc
{
    /** Slug con el que el perfil de un cliente pide este formato. */
    public static function slug(): string;

    /** Nombre legible del formato, para pantallas y listados. */
    public static function nombre(): string;

    /**
     * Cómo llega este archivo al cliente y qué NO significa haberlo descargado. El sistema
     * no entrega nada por su cuenta, así que el texto nunca debe dar a entender lo
     * contrario (ver {@see EstadoNcExportacion}).
     */
    public static function entrega(): string;

    /**
     * Datos que le FALTAN a esta nota para poder escribir su fila completa, ya redactados
     * para leerse en pantalla. Vacío = la fila sale entera.
     *
     * Existe para que el sistema diga qué falta ANTES de armar el archivo, en vez de
     * rellenar el hueco con algo inventado o mandar una celda vacía que el cliente
     * descubre después.
     *
     * @return array<int, string>
     */
    public function faltantes(Dte $nc, ClientePerfilDocumento $perfil): array;

    /** Escribe el archivo del lote y devuelve la ruta temporal generada. */
    public function generar(NcExportacion $lote, ClientePerfilDocumento $perfil): string;
}
