<?php

namespace App\Services\Alertas;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Facades\Ajustes;
use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Detectores\CobrosFueraDeRegla;
use App\Services\Alertas\Detectores\ContingenciaLotesRechazados;
use App\Services\Alertas\Detectores\ContingenciaManualSinTerminar;
use App\Services\Alertas\Detectores\ContingenciaPorVencer;
use App\Services\Alertas\Detectores\ContingenciaVencida;
use App\Services\Alertas\Detectores\DteRechazados;
use App\Services\Alertas\Detectores\EventosRecientes;
use App\Services\Alertas\Detectores\FirmadoSinTransmitir;
use App\Services\Alertas\Detectores\GmailDesconectado;
use App\Services\Alertas\Detectores\LectorSinPing;
use App\Services\Alertas\Detectores\PaqueteMensualPendiente;
use App\Services\Alertas\Detectores\PedidosWebParaRevisar;
use App\Services\Alertas\Detectores\RespaldoFallido;
use App\Services\Alertas\Detectores\SaludDelSistema;
use App\Services\Alertas\Detectores\TiendaWebFallas;
use App\Services\Alertas\Detectores\TiendaWebSinRespuesta;
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
            app(PedidosWebParaRevisar::class),
            app(TiendaWebSinRespuesta::class),
            TiendaWebFallas::pagos(),
            TiendaWebFallas::otras(),
            app(ContingenciaVencida::class),
            app(ContingenciaPorVencer::class),
            app(ContingenciaManualSinTerminar::class),
            app(ContingenciaLotesRechazados::class),
            app(FirmadoSinTransmitir::class),
            app(RespaldoFallido::class),
            app(LectorSinPing::class),
            app(GmailDesconectado::class),
            app(PaqueteMensualPendiente::class),
            EventosRecientes::errores500(),
            EventosRecientes::tareasProgramadas(),
            app(CobrosFueraDeRegla::class),
        ];
    }

    /** @return list<string> */
    public function destinatarios(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) Ajustes::texto('alertas.correo', ''))),
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
            Cache::forever($clave, $this->conTexto($estado, $detector, $hallazgo));

            return null;
        }

        $salio = $this->enviar($detector, $accion, $hallazgo, $estado['desde']);

        $estado['ultimo_intento'] = now()->getTimestamp();
        if ($salio) {
            $estado['avisada'] = true;
            $estado['conteo_avisado'] = $hallazgo->conteo;
        }
        Cache::forever($clave, $this->conTexto($estado, $detector, $hallazgo));

        return $salio ? $accion : null;
    }

    /**
     * El texto del aviso viaja con su estado para que el Inicio lo muestre al día, aunque
     * el correo no haya salido por el tope. Son los mismos textos del correo.
     */
    private function conTexto(array $estado, Detector $detector, Hallazgo $hallazgo): array
    {
        return array_merge($estado, [
            'nombre' => $detector->nombre(), 'titulo' => $hallazgo->titulo, 'detalle' => $hallazgo->detalle,
            'que_hacer' => $hallazgo->queHacer, 'enlace' => $hallazgo->enlace,
        ]);
    }

    /**
     * Los avisos abiertos, para el Inicio. Lee todos los estados en una sola consulta
     * (en producción, un whereIn sobre la tabla `cache`) y no revisa nada: lo que muestra
     * es lo que dejó la última pasada de `alertas:revisar`.
     *
     *  - estado null: los avisos andan;
     *  - 'apagado' o 'sin_correo': `alertas:revisar` no revisa nada, así que no hay avisos;
     *  - 'atrasado': la última revisión es vieja (o no hubo); los avisos se muestran igual.
     *
     * @return array{estado: ?string, revisado_en: ?int, avisos: list<array{tipo: string, nombre: string, titulo: string, detalle: string, que_hacer: string, desde: Carbon, enlace: ?string, correo_salio: bool}>}
     */
    public function abiertos(): array
    {
        if (! Ajustes::bool('alertas.enabled')) {
            return ['estado' => 'apagado', 'revisado_en' => null, 'avisos' => []];
        }
        if ($this->destinatarios() === []) {
            return ['estado' => 'sin_correo', 'revisado_en' => null, 'avisos' => []];
        }

        $detectores = [];
        foreach ($this->detectores() as $detector) {
            $detectores['alertas.abiertas.'.$detector->tipo()] = $detector;
        }
        $valores = Cache::many([...array_keys($detectores), self::CLAVE_ULTIMA_REVISION]);

        $avisos = [];
        foreach ($detectores as $clave => $detector) {
            $estado = $valores[$clave] ?? null;
            if (! is_array($estado) || ! isset($estado['desde'])) {
                continue;
            }
            $enlace = $estado['enlace'] ?? null;
            $avisos[] = [
                'tipo' => $detector->tipo(),
                'nombre' => $estado['nombre'] ?? $detector->nombre(),
                'titulo' => $estado['titulo'] ?? $detector->nombre(),
                'detalle' => $estado['detalle'] ?? '',
                'que_hacer' => $estado['que_hacer'] ?? '',
                'desde' => HoraNegocio::aLocal(Carbon::createFromTimestamp($estado['desde'])),
                'enlace' => is_string($enlace) && Route::has($enlace) ? route($enlace) : null,
                // false: el correo no salió, por el tope o porque el SMTP cayó.
                'correo_salio' => (bool) ($estado['avisada'] ?? false),
            ];
        }
        usort($avisos, fn (array $a, array $b) => $a['desde'] <=> $b['desde']);

        $revisadoEn = $valores[self::CLAVE_ULTIMA_REVISION] ?? null;
        $limite = now()->subMinutes((int) config('alertas.salud.avisos_sin_correr_minutos', 15))->getTimestamp();

        return [
            'estado' => $revisadoEn === null || $revisadoEn < $limite ? 'atrasado' : null,
            'revisado_en' => $revisadoEn,
            'avisos' => $avisos,
        ];
    }

    private function pasoElReaviso(array $estado): bool
    {
        return $estado['ultimo_intento'] === null
            || $estado['ultimo_intento'] <= now()->subMinutes((int) Ajustes::entero('alertas.reaviso_minutos', 60))->getTimestamp();
    }

    /** true = salió (o quedó simulado fuera de producción). */
    private function enviar(Detector $detector, string $accion, ?Hallazgo $hallazgo, int $desde): bool
    {
        $claveTope = 'alertas.envios.'.$detector->tipo().'.'.HoraNegocio::fechaHoy();
        $enviados = (int) Cache::get($claveTope, 0);
        $tope = max(1, (int) Ajustes::entero('alertas.tope_diario', 6));

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
