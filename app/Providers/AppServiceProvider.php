<?php

namespace App\Providers;

use App\Enums\AreaSistema;
use App\Services\Dte\DteTransmisionService;
use App\Support\WorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Centro de Configuración. Singleton porque el resolver memoriza el mapa de
        // overrides mientras la huella de caché no cambie: compartir la instancia
        // dentro de la petición evita releer la tabla una vez por consumidor.
        // El catálogo y el conversor no tienen estado mutable; el repositorio recibe
        // el store de caché POR DEFECTO (compartido entre web, worker y CLI), que es
        // lo que permite que una escritura llegue a todos los procesos.
        $this->app->singleton(\App\Ajustes\RepositorioAjustes::class, static fn ($app) => new \App\Ajustes\RepositorioAjustes(
            $app->make(\Illuminate\Contracts\Cache\Factory::class)->store()
        ));
        $this->app->singleton(\App\Ajustes\CatalogoAjustes::class);
        $this->app->singleton(\App\Ajustes\Ajustes::class);

        // Fuente de correo de "Documentos recibidos" (INDEPENDIENTE de Gmail/PPQ):
        // driver 'imap' → lector IMAP de solo lectura (Yahoo); cualquier otro valor,
        // o falta de soporte/credenciales, cae al Null (revisión deshabilitada).
        $this->app->bind(
            \App\Services\DocumentosRecibidos\Contracts\MailboxClient::class,
            static function () {
                $driver = strtolower((string) config('documentos_recibidos.mail.driver', 'none'));

                return $driver === 'imap'
                    ? new \App\Services\DocumentosRecibidos\ImapMailboxClient()
                    : new \App\Services\DocumentosRecibidos\NullMailboxClient();
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SEGUNDA BARRERA del candado de correo real ({@see \App\Support\Correo\CandadoCorreoReal}):
        // fuera de producción se fuerza el mailer a `log`, así ni un flujo que se olvide de
        // consultar el candado (o los correos propios de Laravel, como el reset de contraseña)
        // puede llegar al SMTP real. En producción no se toca nada.
        if (! $this->app->environment('production')) {
            config(['mail.default' => 'log']);
        }

        // Heartbeat del worker de colas: cada iteración del daemon `queue:work` dispara
        // Looping (aun estando ocioso) y marca "vivo" en cache. Solo se dispara dentro del
        // proceso worker; en peticiones web queda registrado pero no se ejecuta. Observación
        // pura: no toca la cola, el envío ni la firma/transmisión.
        Event::listen(Looping::class, static fn () => WorkerHeartbeat::pulse());

        // Contador de trabajos fallidos para el navbar (badge junto a "Salud del sistema").
        // Solo se consulta para administradores (que ven ese enlace); para el resto es 0 sin
        // tocar la BD. Solo lectura de failed_jobs; no reintenta ni borra nada.
        View::composer('layouts.navigation', static function ($view) {
            $esAdmin = (bool) auth()->user()?->hasRole('administrador');
            $view->with('jobsFallidos', $esAdmin ? (int) DB::table('failed_jobs')->count() : 0);

            // Badge de modo DTE (paralelo/respaldo/principal) visible para quienes facturan
            // (administrador/facturación), para que quede claro en TODA pantalla si el
            // sistema nuevo podría transmitir real o sigue en modo paralelo/preproducción.
            // Solo lectura: reutiliza evaluarCandados(), no transmite ni muestra secretos.
            $esGestor = (bool) auth()->user()?->can('dte.emitir');
            $view->with('modoDte', $esGestor ? app(DteTransmisionService::class)->estadoOperativo() : null);

            // Áreas de trabajo (ver App\Enums\AreaSistema): cuál se está viendo y
            // cuáles puede ver este usuario, para el selector superior y para elegir
            // la sidebar. Es PRESENTACIÓN: la autorización real vive en el middleware
            // de cada grupo de rutas. El área activa se deriva de la URL, nunca de la
            // sesión. Solo permisos (que Spatie cachea) y config: CERO consultas.
            $view->with('areaActiva', AreaSistema::activaDesdeRequest());
            $view->with('areasVisibles', AreaSistema::visiblesPara(auth()->user()));
        });

        // Mismo estado operativo DTE para las pantallas de facturación (ficha + creación),
        // así el aviso "MODO PARALELO SEGURO — NO EMITE PRODUCCIÓN" y la guardia de botones
        // están disponibles sin recalcular en cada controlador. Solo gestores; solo lectura.
        View::composer('facturacion.*', static function ($view) {
            if (! array_key_exists('modoDte', $view->getData())) {
                $esGestor = (bool) auth()->user()?->can('dte.emitir');
                $view->with('modoDte', $esGestor ? app(DteTransmisionService::class)->estadoOperativo() : null);
            }
        });
    }
}
