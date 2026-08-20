<?php

namespace App\Ajustes\Sistema;

use App\Ajustes\Ajustes;
use App\Models\RespaldoEjecucion;
use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\Sistema\NotificacionesRespaldo;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compone la pantalla Configuración → Sistema: respaldos, cola, salud y entorno.
 *
 * NO CALCULA NADA POR SU CUENTA. Todo lo que muestra sale de donde ya vivía:
 *
 *   respaldos → RespaldoEjecucion + NotificacionesRespaldo
 *   cola      → WorkerHeartbeat::diagnostico()
 *   salud     → DiagnosticoSistemaService (el MISMO del Dashboard y de Salud del sistema)
 *   entorno   → config() y las constantes del propio PHP/Laravel
 *
 * Es deliberado: en cuanto esta pantalla empiece a tener su propia idea de "¿la
 * cola está bien?", habrá dos respuestas distintas a la misma pregunta en dos
 * sitios del mismo sistema, y la que se crea será la que uno mire primero. Ya
 * pasó una vez: la pantalla de Salud detectaba el backup por fecha de archivo
 * mientras el readiness lo detectaba por el registro real.
 *
 * NO HACE RED y NO INVENTA ESTADOS: si algo no se puede saber, se dice que no se
 * puede saber. NO PUBLICA SECRETOS: del entorno salen nombres de driver y
 * versiones, nunca credenciales ni rutas absolutas del servidor.
 */
class PanelSistema
{
    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly DiagnosticoSistemaService $diagnostico,
    ) {}

    /**
     * Estado de los respaldos: qué hay configurado y qué pasó de verdad.
     *
     * @return array<string, mixed>
     */
    public function respaldos(): array
    {
        $ultima = $this->intentar(static fn () => RespaldoEjecucion::ultima());
        $ultimaValida = $this->intentar(static fn () => RespaldoEjecucion::exitosos()->latest('terminado_en')->first());

        return [
            'retencion_dias' => $this->ajustes->entero('respaldos.dias_retencion', 30),
            'avisos_configurados' => NotificacionesRespaldo::configurado(),
            'avisos_destinatario' => $this->ajustes->texto('respaldos.notificaciones.correo'),
            'hay_valido_hoy' => (bool) $this->intentar(static fn () => RespaldoEjecucion::hayValidoHoy(), false),

            // Última EJECUCIÓN (haya salido bien o mal) y último respaldo VÁLIDO.
            // Son distintos a propósito: si el de anoche falló, lo que hay que ver
            // es que falló Y cuál es el último bueno que queda.
            'ultima' => $ultima,
            'ultima_valida' => $ultimaValida,
            'tamano' => $this->tamanoLegible($ultimaValida?->archivo_tamano_bytes),
            // El registro dice dónde quedó el archivo; se comprueba que siga ahí,
            // porque un registro sin archivo es peor que no tener registro.
            'archivo_presente' => $this->archivoPresente($ultimaValida?->archivo_ruta),
        ];
    }

    /**
     * Estado de la cola. `WorkerHeartbeat::diagnostico()` ya combina el latido con
     * los trabajos pendientes y fallidos, y su regla de niveles está pensada para
     * no decir "apagado" cuando no hay forma confiable de saberlo.
     *
     * @return array<string, mixed>
     */
    public function cola(): array
    {
        $d = $this->intentar(static fn () => WorkerHeartbeat::diagnostico(), []);

        return [
            'conexion' => (string) config('queue.default'),
            'driver' => (string) config('queue.connections.'.config('queue.default').'.driver', 'desconocido'),
            'estado' => $d['estado'] ?? 'sin_datos',
            'nivel' => $d['nivel'] ?? 'advertencia',
            'mensaje' => $d['mensaje'] ?? 'Sin información del worker.',
            'ultimo_pulso' => $d['ultimo'] ?? null,
            'hace' => $d['hace'] ?? null,
            'pendientes' => $d['jobs_pendientes'] ?? 0,
            'fallidos' => $d['jobs_fallidos'] ?? 0,
        ];
    }

    /**
     * Los checks de salud, tal cual los calcula el servicio compartido.
     *
     * @return array{nivel: string, checks: array<int, array<string, string>>}
     */
    public function salud(): array
    {
        return $this->intentar(
            fn () => $this->diagnostico->evaluar(),
            ['nivel' => 'advertencia', 'checks' => []],
        );
    }

    /**
     * Entorno de ejecución. ESTRICTAMENTE SOLO LECTURA: son decisiones del
     * servidor, no de la aplicación.
     *
     * Se publican NOMBRES y VERSIONES, nunca credenciales: el nombre de la base
     * sí (identifica el despliegue), el usuario y la contraseña no. Tampoco rutas
     * absolutas del servidor.
     *
     * @return array<int, array{etiqueta: string, valor: string, detalle: ?string}>
     */
    public function entorno(): array
    {
        $conexion = (string) config('database.default');
        $disco = (string) config('filesystems.default');

        return [
            $this->dato('Entorno', (string) app()->environment(),
                app()->environment('production') ? 'Producción: el correo puede salir de verdad.' : 'Fuera de producción: el correo se registra como simulado.'),
            $this->dato('Depuración', config('app.debug') ? 'activada' : 'desactivada',
                config('app.debug') && app()->environment('production') ? 'En producción debería estar desactivada.' : null),
            $this->dato('PHP', PHP_VERSION),
            $this->dato('Laravel', app()->version()),
            $this->dato('Base de datos', (string) config("database.connections.{$conexion}.driver"),
                'Conexión «'.$conexion.'» · base '.(string) config("database.connections.{$conexion}.database")),
            $this->dato('Caché', (string) config('cache.default'),
                config('cache.default') === 'array' ? 'La caché «array» no se comparte entre procesos: los cambios de configuración no llegarían al worker.' : null),
            $this->dato('Cola', (string) config('queue.default'),
                config('queue.default') === 'sync' ? 'Con «sync» los trabajos corren dentro de la petición: no hay worker.' : null),
            $this->dato('Sesiones', (string) config('session.driver')),
            $this->dato('Almacenamiento', $disco, 'Enlace público '.(is_link(public_path('storage')) || is_dir(public_path('storage')) ? 'presente' : 'AUSENTE (php artisan storage:link)')),
            $this->dato('Cloudflare Access', config('cloudflare_access.enabled') ? 'activado' : 'desactivado',
                config('cloudflare_access.enabled') ? 'El acceso público pasa por Cloudflare Zero Trust.' : 'El login de la aplicación es la única puerta.'),
            $this->dato('Zona horaria', (string) config('app.timezone')),
        ];
    }

    // ---------------------------------------------------------------- interno

    /** @return array{etiqueta: string, valor: string, detalle: ?string} */
    private function dato(string $etiqueta, string $valor, ?string $detalle = null): array
    {
        return ['etiqueta' => $etiqueta, 'valor' => $valor !== '' ? $valor : '—', 'detalle' => $detalle];
    }

    private function tamanoLegible(?int $bytes): ?string
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 0).' KB';
    }

    /**
     * ¿Sigue existiendo el archivo del último respaldo válido?
     *
     * `null` significa "no se puede comprobar" (no hay ruta registrada), que no es
     * lo mismo que "no está". La pantalla distingue los tres casos en vez de
     * pintar un verde optimista.
     */
    private function archivoPresente(?string $ruta): ?bool
    {
        if (blank($ruta)) {
            return null;
        }

        return (bool) $this->intentar(
            static fn () => is_file($ruta) || Storage::disk('local')->exists($ruta),
            false,
        );
    }

    /**
     * Consulta tolerante al fallo: esta es una pantalla de diagnóstico y se abre
     * justamente cuando algo puede estar a medias. Caerse por una tabla que falta
     * dejaría al administrador sin ver el resto del panel.
     */
    private function intentar(callable $consulta, mixed $porDefecto = null): mixed
    {
        try {
            return $consulta();
        } catch (Throwable) {
            return $porDefecto;
        }
    }

    /** Trabajos pendientes, por si alguna vista los necesita sueltos. */
    public function pendientes(): int
    {
        return (int) $this->intentar(static fn () => DB::table('jobs')->count(), 0);
    }
}
