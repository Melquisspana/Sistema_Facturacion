<?php

namespace App\Services\Asistencia;

use App\DataTransferObjects\Asistencia\ResultadoRegistroMarcacion;
use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use Illuminate\Support\Facades\DB;

/**
 * LA REGLA de la marcación, y el único sitio donde se escribe una.
 *
 * El dispositivo manda un número de ranura. Todo lo demás —quién es, qué hora es,
 * si es entrada o salida, si cuenta o no— se decide acá, con datos del servidor.
 *
 * ───────────────────────────── Entrada o salida ─────────────────────────────
 *
 * Se ALTERNA dentro del día local: sin marcaciones hoy, entrada; después, lo
 * contrario de la última. No se cuenta «la primera y la segunda» porque quien
 * sale a almorzar y vuelve haría cuatro marcaciones y la cuarta tendría que ser
 * salida, no «la cuarta». La regla vive en {@see TipoMarcacion::siguienteTras()}.
 *
 * El día se corta a medianoche LOCAL. Es una decisión, no un descuido: un turno
 * de noche que cruce las 00:00 quedará hoy como salida sin entrada en el segundo
 * día. Se resuelve cuando existan los horarios —que son los que dicen a qué
 * jornada pertenece una marcación—; inventar ahora una regla de turnos sin
 * horarios sería adivinar.
 *
 * ────────────────────── Ventana de cortesía (cooldown) ──────────────────────
 *
 * Si la persona marcó hace menos de `asistencia.cooldown_segundos`, NO se escribe
 * nada y se informa qué marcó antes y cuánto falta. Se compara contra la última
 * marcación de la persona SIN importar el día: dos marcaciones separadas por diez
 * segundos son un dedo repetido aunque caigan a un lado y otro de la medianoche.
 *
 * Deliberadamente NO es un error: el firmware muestra «ya marcaste tu entrada a
 * las 07:02» y la persona se va tranquila. Un error rojo la haría insistir, que
 * es justo lo contrario de lo que se busca.
 *
 * ─────────────────────────────── Concurrencia ───────────────────────────────
 *
 * Todo corre dentro de una transacción que BLOQUEA la fila del empleado. Sin ese
 * bloqueo, dos peticiones simultáneas (el firmware reintentando porque no le
 * llegó la respuesta, dos lectores, un doble toque) podrían leer las dos «no hay
 * marcación reciente» y escribir las dos: la ventana de cortesía se saltaría sola
 * justo en el caso para el que existe.
 */
class RegistrarMarcacion
{
    public function __construct(private readonly HoraOficial $horaOficial) {}

    public function __invoke(AsistenciaDispositivo $dispositivo, int $fingerprintId, ?string $ip = null): ResultadoRegistroMarcacion
    {
        $huella = AsistenciaHuella::query()
            ->where('asistencia_dispositivo_id', $dispositivo->id)
            ->where('fingerprint_id', $fingerprintId)
            ->where('activo', true)
            ->with('empleado')
            ->first();

        // Ranura sin asociar, o plantilla dada de baja: para el lector es lo
        // mismo, una huella que este sistema no reconoce.
        if ($huella === null || $huella->empleado === null) {
            return ResultadoRegistroMarcacion::huellaDesconocida($fingerprintId);
        }

        if (! $huella->empleado->activo) {
            return ResultadoRegistroMarcacion::empleadoInactivo($huella->empleado, $fingerprintId);
        }

        return DB::transaction(function () use ($huella, $dispositivo, $fingerprintId, $ip) {
            // Serializa las marcaciones DE ESTA PERSONA: dos peticiones a la vez
            // se ponen en fila y la segunda ve lo que escribió la primera.
            $empleado = AsistenciaEmpleado::query()
                ->whereKey($huella->asistencia_empleado_id)
                ->lockForUpdate()
                ->first();

            $instante = $this->horaOficial->instante();
            $fechaLocal = $this->horaOficial->fechaLocal($instante);

            $ultima = AsistenciaMarcacion::query()
                ->where('asistencia_empleado_id', $empleado->id)
                ->orderByDesc('marcado_at')
                ->orderByDesc('id')
                ->first();

            $cooldown = max(0, (int) config('asistencia.cooldown_segundos'));

            if ($ultima !== null && $cooldown > 0) {
                // Resta de timestamps absolutos: no depende de la zona con la que
                // Eloquent hidrató la fecha ni de la versión de Carbon.
                $transcurridos = $instante->getTimestamp() - $ultima->marcado_at->getTimestamp();

                if ($transcurridos >= 0 && $transcurridos < $cooldown) {
                    return ResultadoRegistroMarcacion::cooldown(
                        empleado: $empleado,
                        previa: $ultima,
                        esperaSegundos: $cooldown - $transcurridos,
                        fingerprintId: $fingerprintId,
                    );
                }
            }

            // El tipo depende SOLO de lo marcado hoy: el día empieza en entrada.
            $ultimaDeHoy = ($ultima !== null && $ultima->fecha_local->format('Y-m-d') === $fechaLocal)
                ? $ultima
                : null;

            // `created_at` lo pone Eloquent con el mismo reloj (no hay `updated_at`:
            // ver el modelo). Pasarlo a mano acá solo agregaría una forma de que
            // los dos instantes se separen.
            $marcacion = AsistenciaMarcacion::create([
                'asistencia_empleado_id' => $empleado->id,
                'asistencia_dispositivo_id' => $dispositivo->id,
                'asistencia_huella_id' => $huella->id,
                'tipo' => TipoMarcacion::siguienteTras($ultimaDeHoy?->tipo),
                'marcado_at' => $instante,
                'fecha_local' => $fechaLocal,
                'origen' => 'dispositivo',
                'ip' => $ip,
            ]);

            return ResultadoRegistroMarcacion::registrada($empleado, $marcacion, $fingerprintId);
        });
    }
}
