<?php

namespace App\Providers;

use App\Support\WorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
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
    }
}
