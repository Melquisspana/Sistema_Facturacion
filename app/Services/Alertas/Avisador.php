<?php

namespace App\Services\Alertas;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Detectores\ContingenciaVencida;
use App\Services\Alertas\Detectores\DteRechazados;
use App\Services\Alertas\Detectores\EventosRecientes;
use App\Services\Alertas\Detectores\LectorSinPing;
use App\Services\Alertas\Detectores\RespaldoFallido;
use App\Services\Alertas\Detectores\SaludDelSistema;
use App\Services\Alertas\Detectores\TrabajosFallidos;
use App\Support\Correo\CandadoCorreoReal;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Decide cuándo escribir. Cada tipo de aviso pasa por tres momentos:
 *
 *  - se abre  -> «falla»: qué falló, desde cuándo y qué hacer;
 *  - sigue    -> «sigue»: solo si EMPEORÓ y pasó el tiempo de reaviso desde el
 *                último correo, con todo lo acumulado en uno solo;
 *  - se cierra -> «ya se normalizó».
 *
 * Todos los correos de un tipo cuentan para su tope diario: una falla que se abre
 * y se cierra cada cinco minutos no llena el buzón.
 *
 * El correo sale en el momento (no por la cola, que puede ser lo que cayó). Si
 * falla, se anota en el log y nada más: no se reintenta antes del reaviso, el
 * intento cuenta para el tope y este código nunca anota un evento propio. Así un
 * SMTP caído no puede generar avisos sobre sí mismo.
 *
 * El estado vive en la caché (en producción, la tabla `cache`). Si alguien la vacía,
 * lo peor que pasa es un aviso repetido o un «normalizado» que no llega.
 */
class Avisador
{
    public const CLAVE_ULTIMA_REVISION = 'alertas.ultima_revision';

    public function __construct(
        private readonly CandadoCorreoReal $candado,
        private readonly ConfiguracionCorreoRuntime $correo,
    ) {}

    /** @return list<Detector> */
    public function detectores(): array
    {
        return [
            app(SaludDelSistema::class),
            app(TrabajosFallidos::class),
            app(DteRechazados::class),
            app(ContingenciaVencida::class),
            app(RespaldoFallido::class),
            app(LectorSinPing::class),
            EventosRecientes::errores500(),
            EventosRecientes::tareasProgramadas(),
        ];
    }

    /** @return list<string> */
    public function destinatarios(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) config('alertas.correo', ''))),
            fn (string $c) => filter_var($c, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /**
     * @param  list<Detector>|null  $detectores
     * @return array<string, string> tipo => qué se hizo, solo los que mandaron algo
     */
    public function revisar(?array $detectores = null): array
    {
        Cache::put(self::CLAVE_ULTIMA_REVISION, now()->getTimestamp(), now()->addDay());

        $hechos = [];
        foreach ($detectores ?? $this->detectores() as $detector) {
            try {
                $accion = $this->procesar($detector);
            } catch (Throwable $e) {
                // Un detector roto (una tabla que falta) no apaga a los demás.
                Log::warning('Alertas: no se pudo revisar '.$detector->tipo(), ['error' => mb_substr($e->getMessage(), 0, 300)]);

                continue;
            }

            if ($accion !== null) {
                $hechos[$detector->tipo()] = $accion;
            }
        }

        return $hechos;
    }

    /** @return 'falla'|'sigue'|'normalizado'|null */
    public function procesar(Detector $detector): ?string
    {
        if (! $detector->aplica()) {
            return null;
        }

        $clave = 'alertas.abiertas.'.$detector->tipo();
        $estado = Cache::get($clave);
        $hallazgo = $detector->revisar();

        if ($hallazgo === null) {
            if ($estado === null) {
                return null;
            }

            Cache::forget($clave);

            // Si nunca se llegó a avisar (tope), tampoco se avisa que pasó.
            if (! $estado['avisada']) {
                return null;
            }

            return $this->enviar($detector, 'normalizado', null, $estado['desde']) ? 'normalizado' : null;
        }

        if ($estado === null) {
            $estado = [
                'desde' => ($hallazgo->desde ?? now())->getTimestamp(),
                'avisada' => false,
                'conteo_avisado' => 0,
                'ultimo_intento' => null,
            ];
            $accion = 'falla';
        } elseif (! $estado['avisada'] && $this->pasoElReaviso($estado)) {
            $accion = 'falla';
        } elseif ($estado['avisada'] && $hallazgo->conteo > $estado['conteo_avisado'] && $this->pasoElReaviso($estado)) {
            $accion = 'sigue';
        } else {
            // Si mejoró, se baja la marca: lo que vuelva a subir desde acá es nuevo.
            $estado['conteo_avisado'] = min($estado['conteo_avisado'], $hallazgo->conteo);
            Cache::forever($clave, $estado);

            return null;
        }

        $salio = $this->enviar($detector, $accion, $hallazgo, $estado['desde']);

        $estado['ultimo_intento'] = now()->getTimestamp();
        if ($salio) {
            $estado['avisada'] = true;
            $estado['conteo_avisado'] = $hallazgo->conteo;
        }
        Cache::forever($clave, $estado);

        return $salio ? $accion : null;
    }

    private function pasoElReaviso(array $estado): bool
    {
        return $estado['ultimo_intento'] === null
            || $estado['ultimo_intento'] <= now()->subMinutes((int) config('alertas.reaviso_minutos', 60))->getTimestamp();
    }

    /** true = salió (o quedó simulado fuera de producción). */
    private function enviar(Detector $detector, string $accion, ?Hallazgo $hallazgo, int $desde): bool
    {
        $claveTope = 'alertas.envios.'.$detector->tipo().'.'.HoraNegocio::fechaHoy();
        $enviados = (int) Cache::get($claveTope, 0);
        $tope = max(1, (int) config('alertas.tope_diario', 6));

        if ($enviados >= $tope) {
            Log::info('Alertas: tope diario alcanzado, no se manda', ['tipo' => $detector->tipo(), 'accion' => $accion]);

            return false;
        }

        Cache::put($claveTope, $enviados + 1, now()->addDays(2));

        $correo = new AlertaSistemaCorreo(
            accion: $accion,
            nombre: $detector->nombre(),
            hallazgo: $hallazgo,
            desde: HoraNegocio::aLocal(Carbon::createFromTimestamp($desde))->format('d/m/Y H:i'),
            enlace: $this->enlace($hallazgo?->enlace),
            ultimoDelDia: $enviados + 1 === $tope,
        );

        if ($this->candado->debeSimular()) {
            Log::info('Alertas: correo SIMULADO', ['tipo' => $detector->tipo(), 'accion' => $accion, 'motivo' => $this->candado->motivo()]);

            return true;
        }

        try {
            $this->correo->aplicar();
            Mail::to($this->destinatarios())->send($correo);

            return true;
        } catch (Throwable $e) {
            // Solo al log, nunca report(): no puede volver como aviso.
            Log::warning('Alertas: el correo no salió', ['tipo' => $detector->tipo(), 'accion' => $accion, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }

    private function enlace(?string $ruta): string
    {
        return $ruta !== null && Route::has($ruta) ? route($ruta) : url('/');
    }
}
