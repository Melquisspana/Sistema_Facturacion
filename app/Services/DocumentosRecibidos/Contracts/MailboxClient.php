<?php

namespace App\Services\DocumentosRecibidos\Contracts;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\Buzon\EstadoBuzon;
use App\Services\DocumentosRecibidos\Buzon\PaginaMensajes;
use Carbon\CarbonInterface;

/**
 * Fuente de correo de "Documentos recibidos" (compras), INDEPENDIENTE de Gmail/PPQ.
 *
 * Contrato de SOLO LECTURA: la implementación no debe borrar, mover ni marcar como
 * leído ningún correo.
 *
 * DOS REGLAS QUE EL CONTRATO ANTERIOR NO TENÍA, y que eran la causa de que se
 * perdieran correos:
 *
 *  1. **Se lee por DÍA y por PÁGINA de UID ascendente.** Antes había un solo método
 *     que traía "los N más recientes": sin cursor no había forma de retroceder, así
 *     que repetir la revisión releía siempre lo mismo y el histórico nunca avanzaba.
 *  2. **Un problema es una EXCEPCIÓN, nunca un arreglo vacío.** Antes cualquier fallo
 *     devolvía `[]` y la pantalla lo informaba como una revisión exitosa sin novedades.
 */
interface MailboxClient
{
    /** ¿Hay fuente configurada y utilizable (extensión + credenciales)? */
    public function disponible(): bool;

    /** Descripción legible de la fuente (sin secretos), para la UI. */
    public function fuente(): string;

    /**
     * Abre la carpeta y devuelve sus metadatos, sin leer un solo mensaje.
     *
     * Sirve para dos cosas antes de sincronizar: confirmar que el buzón responde y
     * acepta las credenciales, y obtener el `UIDVALIDITY` con el que se valida el
     * progreso guardado.
     *
     * @throws AutenticacionBuzonException credenciales rechazadas
     * @throws BuzonInaccesibleException red, servidor o carpeta
     */
    public function estado(): EstadoBuzon;

    /**
     * Una página de mensajes CON ADJUNTOS de un día concreto, en UID ascendente.
     *
     * La ventana se acota del lado del servidor (`SINCE`/`BEFORE` del día), no
     * recortando en PHP un resultado grande. `$desdeUid` es el cursor: se devuelven
     * solo los UID ESTRICTAMENTE MAYORES, para reanudar un día a medio leer sin
     * repetir lo ya procesado.
     *
     * @param  int  $limite  máximo de mensajes de esta página (> 0)
     * @param  int|null  $desdeUid  cursor: solo UID mayores que este
     *
     * @throws AutenticacionBuzonException credenciales rechazadas
     * @throws BuzonInaccesibleException red, servidor o carpeta
     */
    public function mensajesDelDia(CarbonInterface $dia, int $limite, ?int $desdeUid = null): PaginaMensajes;
}
