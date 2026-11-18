<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Support\Dte\PedidoSinSalida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Los envíos de un documento a Hacienda, anotados uno por uno.
 *
 * PARA QUÉ. Si se cae internet, la consulta a Hacienda tampoco contesta y no hay cómo
 * saber por ella si el documento entró. Lo único que permite darlo por NO recibido es
 * tener probado que ningún envío suyo llegó a salir de esta máquina, ni ahora ni en un
 * intento anterior. Esa prueba es esta bitácora ({@see sinEnviosEnDuda()}), y con ella
 * el documento puede pasar a contingencia sin que Hacienda conteste nada
 * ({@see DteTransmisionResiliente}, {@see ContingenciaAutomaticaService}).
 *
 * QUÉ SE ANOTA, por documento:
 *   · la MARCA «firmado: todavía no se envió», al firmar un documento que estaba generado
 *     dentro de {@see FirmaTransmisionDte}. Ahí es seguro que no hubo ningún envío: un
 *     documento generado nunca tuvo firma que mandar, y de firmado no se vuelve atrás;
 *   · «Empezó un envío», ANTES de mandar nada, con un número de intento;
 *   · «No salió», después, solo si cURL lo demuestra ({@see PedidoSinSalida}).
 * Un envío que empezó y no tiene su «No salió» está EN DUDA: pudo salir. Así queda todo
 * lo que se corta a la mitad (proceso muerto, corte de luz, respuesta perdida), y queda
 * así para siempre: esa duda no la borra nada.
 *
 * A QUIÉN ALCANZA. Solo a los documentos con marca. Uno sin marca (firmado antes de este
 * cambio, con `dte:firmar`, o con el interruptor de contingencia apagado) no se anota ni
 * se revalida: se comporta igual que siempre y nunca usa esta prueba. Uno CON marca se
 * anota siempre, esté como esté el interruptor: si no, apagarlo y volver a encenderlo
 * dejaría un registro incompleto que parecería limpio.
 *
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║ LA BITÁCORA `dte_envio` ES ESTADO OPERATIVO: NO SE DEPURA.                       ║
 * ║ Si se borra un «Empezó un envío» y queda la marca, un documento que pudo salir   ║
 * ║ parecería no haber salido nunca, y podría duplicarse en Hacienda.                ║
 * ║ Hoy nada depura `activity_log` (ni tarea programada ni `activitylog:clean`).     ║
 * ║ Quien agregue una limpieza tiene que excluir `log_name = 'dte_envio'`.           ║
 * ║ Ver docs/TRANSMISION_DTE.md, «Bitácora de envíos».                               ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
 *
 * Va en la bitácora (`activity_log`) con nombre propio, igual que
 * {@see CopiaDeAceptadoTardio}: sin columna ni tabla nueva.
 */
class BitacoraEnvioDte
{
    public const BITACORA = 'dte_envio';

    public const FIRMA_NUEVA = 'Documento firmado: todavía no se envió a Hacienda.';

    public const ENVIO_INICIADO = 'Empezó un envío a Hacienda.';

    public const ENVIO_SIN_SALIDA = 'El envío no salió de esta máquina: no había conexión con Hacienda.';

    /**
     * Pone la marca en un documento RECIÉN firmado. Solo con el interruptor de contingencia
     * encendido. Nunca lanza: si no se puede guardar, el documento queda sin marca y se
     * comporta como siempre.
     */
    public function anclarFirma(Dte $dte): void
    {
        // Un documento que ya nació en contingencia no va a recepción normal: no hay
        // envíos que anotar.
        if (! config('dte.contingencia.enabled', false)
            || $dte->estado !== EstadoDte::Firmado || $dte->contingencia_id !== null) {
            return;
        }

        rescue(fn () => activity(self::BITACORA)->performedOn($dte)
            ->withProperties(['codigo_generacion' => $dte->codigo_generacion])
            ->log(self::FIRMA_NUEVA));
    }

    /**
     * Anota que empieza un envío, ANTES de mandarlo, y devuelve su número de intento.
     * Devuelve null, sin escribir ni revisar nada, si el documento no tiene marca.
     *
     * Con marca, la anotación queda guardada antes de hablar con Hacienda, con la fila
     * del documento bloqueada y vuelta a mirar: así no se cruza con la conversión a
     * contingencia, que lee esta bitácora bajo el mismo bloqueo. SIN ANOTACIÓN NO HAY
     * ENVÍO: si no se puede guardar, se lanza y quien llama no manda nada.
     *
     * Quien transmite no puede estar dentro de una transacción propia: la anotación
     * tiene que quedar confirmada antes del envío.
     *
     * @throws DteTransmisionException si el documento cambió o la anotación no se guardó
     */
    public function abrir(Dte $dte): ?string
    {
        try {
            if (! $this->anotaciones($dte)->contains('description', self::FIRMA_NUEVA)) {
                return null;
            }

            return DB::transaction(function () use ($dte) {
                $actual = Dte::whereKey($dte->id)->lockForUpdate()->first();
                if ($actual === null || $actual->estado !== EstadoDte::Firmado
                    || filled($actual->sello_recepcion) || $actual->contingencia_id !== null) {
                    throw new DteTransmisionException('El documento cambió mientras se emitía y no se envió nada. Recargá la ficha.');
                }

                $intento = (string) Str::uuid();
                $fila = activity(self::BITACORA)->performedOn($actual)
                    ->withProperties(['intento' => $intento, 'codigo_generacion' => $actual->codigo_generacion])
                    ->log(self::ENVIO_INICIADO);
                // Con la bitácora apagada `log()` no guarda nada y devuelve null.
                if (! $fila?->exists) {
                    throw new DteTransmisionException('No se pudo anotar el envío, así que no se envió nada a Hacienda.');
                }

                return $intento;
            });
        } catch (DteTransmisionException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new DteTransmisionException('No se pudo anotar el envío, así que no se envió nada a Hacienda.');
        }
    }

    /**
     * Cierra un intento como «no salió». Nunca lanza: si no se puede guardar, el intento
     * queda abierto, o sea, en duda.
     *
     * @param  array<string, mixed>  $evidencia  lo que midió cURL ({@see PedidoSinSalida::evidencia()})
     */
    public function cerrarSinSalida(Dte $dte, string $intento, array $evidencia): void
    {
        rescue(fn () => activity(self::BITACORA)->performedOn($dte)
            ->withProperties(['intento' => $intento, 'codigo_generacion' => $dte->codigo_generacion] + $evidencia)
            ->log(self::ENVIO_SIN_SALIDA));
    }

    /**
     * ¿Está probado que NINGÚN envío de este documento llegó a salir de la máquina?
     *
     * Solo si tiene la marca de firma con su mismo código de generación y cada «Empezó un
     * envío» tiene su «No salió» con el mismo número de intento. Sin marca, con un intento
     * abierto o si la bitácora no se puede leer, es `false`: no saber no autoriza.
     */
    public function sinEnviosEnDuda(Dte $dte): bool
    {
        return (bool) rescue(function () use ($dte) {
            $anotaciones = $this->anotaciones($dte);

            $marcado = filled($dte->codigo_generacion) && $anotaciones->contains(
                fn (Activity $a) => $a->description === self::FIRMA_NUEVA
                    && ($a->properties['codigo_generacion'] ?? null) === $dte->codigo_generacion
            );
            if (! $marcado) {
                return false;
            }

            $cerrados = $anotaciones->where('description', self::ENVIO_SIN_SALIDA)
                ->map(fn (Activity $a) => $a->properties['intento'] ?? null)->filter()->all();

            return $anotaciones->where('description', self::ENVIO_INICIADO)->every(
                fn (Activity $a) => filled($a->properties['intento'] ?? null)
                    && in_array($a->properties['intento'], $cerrados, true)
            );
        }, false);
    }

    /** @return Collection<int, Activity> */
    private function anotaciones(Dte $dte): Collection
    {
        return Activity::query()
            ->where('log_name', self::BITACORA)
            ->where('subject_type', $dte->getMorphClass())
            ->where('subject_id', $dte->id)
            ->orderBy('id')
            ->get(['id', 'description', 'properties']);
    }
}
