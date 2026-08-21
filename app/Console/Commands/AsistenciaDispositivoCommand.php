<?php

namespace App\Console\Commands;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Services\Asistencia\RotarTokenDispositivo;
use Illuminate\Console\Command;

/**
 * Da de alta un lector biométrico —o le rota el token— y muestra el token UNA
 * sola vez.
 *
 * Por qué un comando y no una pantalla: es la única operación del módulo que
 * produce un secreto, y un secreto que pasa por el navegador queda en el
 * historial, en la caché y probablemente en una captura de pantalla. Acá se ve en
 * la consola de quien administra el servidor, se copia al firmware y no vuelve a
 * existir en ningún lado: en base solo queda su SHA-256.
 *
 * NO se escribe en el log, ni se devuelve por HTTP, ni se guarda en claro. Si se
 * pierde, no se recupera: se rota (que es exactamente lo que hay que hacer si
 * alguien pudo haberlo visto).
 *
 * Desde la Fase 2 existe también la pantalla de administración, y las dos vías
 * comparten {@see RotarTokenDispositivo}: es la que hashea y la que deja la
 * auditoría. Con dos implementaciones, una de las dos acabaría olvidándose de una
 * de las dos cosas.
 */
class AsistenciaDispositivoCommand extends Command
{
    protected $signature = 'asistencia:dispositivo
                            {codigo : Código del lector, el que viaja en la cabecera X-Dispositivo}
                            {--nombre= : Dónde está físicamente (por defecto, el código)}
                            {--rotar : Si el lector ya existe, generarle un token NUEVO}';

    protected $description = 'Da de alta un lector biométrico de asistencia (o le rota el token)';

    public function handle(RotarTokenDispositivo $rotar): int
    {
        if (! config('asistencia.enabled')) {
            $this->warn('El módulo de asistencia está apagado (ASISTENCIA_ENABLED=false).');
            $this->line('El lector se puede registrar igual, pero sus endpoints responderán 404 hasta encenderlo.');
            $this->newLine();
        }

        $codigo = trim((string) $this->argument('codigo'));

        if ($codigo === '') {
            $this->error('El código no puede ir vacío.');

            return self::FAILURE;
        }

        $existente = AsistenciaDispositivo::query()->where('codigo', $codigo)->first();

        if ($existente !== null && ! $this->option('rotar')) {
            $this->error("Ya existe un lector con el código «{$codigo}».");
            $this->line('Para generarle un token nuevo (el anterior deja de servir): --rotar');

            return self::FAILURE;
        }

        // El token de PROVISIÓN del .env permite fijar desde configuración el
        // mismo valor que se va a quemar en el firmware. Si no está, lo genera el
        // servicio.
        $desdeEnv = (string) (config('asistencia.token_provision') ?? '');

        if ($existente !== null) {
            if (! $this->confirm("Se le va a rotar el token al lector «{$codigo}». El firmware actual dejará de autenticar. ¿Continuar?", false)) {
                $this->line('Sin cambios.');

                return self::SUCCESS;
            }

            // Mismo camino que la pantalla web: hashea y deja auditoría.
            $token = $rotar($existente, $desdeEnv !== '' ? $desdeEnv : null);
            $dispositivo = $existente;
            $this->info("Token rotado para «{$codigo}».");
        } else {
            $token = $desdeEnv !== '' ? $desdeEnv : AsistenciaDispositivo::generarToken();

            $dispositivo = AsistenciaDispositivo::create([
                'codigo' => $codigo,
                'nombre' => (string) ($this->option('nombre') ?: $codigo),
                'token_hash' => AsistenciaDispositivo::hashDeToken($token),
                'activo' => true,
            ]);
            $this->info("Lector «{$codigo}» dado de alta (id {$dispositivo->id}).");
        }

        $this->newLine();

        if ($desdeEnv !== '') {
            $this->line('Token tomado de ASISTENCIA_DISPOSITIVO_TOKEN (.env). No se muestra: ya lo tenés ahí.');
        } else {
            $this->line('Token del lector (se muestra UNA sola vez, copialo al firmware):');
            $this->newLine();
            $this->line('    '.$token);
        }

        $this->newLine();
        $this->line('Cabeceras que debe mandar el ESP32:');
        $this->line("    X-Dispositivo: {$dispositivo->codigo}");
        $this->line('    X-Dispositivo-Token: <el token de arriba>');

        return self::SUCCESS;
    }
}
