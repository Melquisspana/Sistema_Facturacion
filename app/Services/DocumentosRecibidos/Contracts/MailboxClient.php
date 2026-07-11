<?php

namespace App\Services\DocumentosRecibidos\Contracts;

/**
 * Fuente de correo de "Documentos recibidos", INDEPENDIENTE de Gmail/PPQ.
 *
 * Contrato de SOLO LECTURA: la implementación no debe borrar, mover ni marcar como
 * leído ningún correo. Solo lista mensajes con sus adjuntos (PDF/JSON/XML) para que
 * el sincronizador registre los DTE recibidos localmente.
 */
interface MailboxClient
{
    /** ¿Hay fuente configurada y utilizable (extensión + credenciales)? */
    public function disponible(): bool;

    /** Descripción legible de la fuente (sin secretos), para la UI. */
    public function fuente(): string;

    /**
     * Mensajes recientes con adjuntos, normalizados. SOLO LECTURA.
     *
     * Si $desde no es null, la búsqueda se acota a los correos recibidos DESDE ese
     * día inclusive (IMAP SINCE), para una revisión incremental y rápida. Con null
     * se revisa todo el buzón (histórico).
     *
     * @return array<int, array{
     *   id: string, asunto: ?string, remitente: ?string, fecha: ?string,
     *   adjuntos: array<int, array{filename: string, mime: string, data: string}>
     * }>
     */
    public function mensajesConAdjuntos(int $limite = 30, ?\Illuminate\Support\Carbon $desde = null): array;
}
