<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
            $esGestor = (bool) auth()->user()?->hasAnyRole(['administrador', 'facturacion']);
            $view->with('modoDte', $esGestor ? app(DteTransmisionService::class)->estadoOperativo() : null);
        });
    }
}
