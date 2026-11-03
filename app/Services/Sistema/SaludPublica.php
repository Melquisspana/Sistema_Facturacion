<?php

namespace App\Services\Sistema;

use App\Services\Alertas\Avisador;
use App\Support\HoraNegocio;
use App\Support\WorkerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lo que mira la ruta pública /salud: ¿anda lo que hace falta para facturar?
 *
 * La respuesta la lee CUALQUIERA, así que cada motivo es un texto fijo: dice qué
 * cayó y nada más. Ni hosts, ni puertos, ni rutas del servidor, ni mensajes de
 * excepción, ni conteos de negocio. El detalle está en Salud del sistema, detrás
 * del login.
 *
 * A diferencia de {@see DiagnosticoSistemaService}, que nunca sale a la red para no
 * frenar el Dashboard, acá sí se pregunta en vivo a los firmadores y a la sonda de
 * hora, con un tiempo de espera corto: es lo que el vigilante de afuera tiene que
 * saber.
 */
class SaludPublica
{
    private const ESPERA_SEGUNDOS = 3;

    /**
     * Componente => null si anda, o el motivo si falla.
     *
     * @return array<string, ?string>
     */
    public function evaluar(): array
    {
        $componentes = [
            'base_de_datos' => $this->baseDeDatos(),
            'cola' => $this->cola(),
            'firmadores' => $this->firmadores(),
            'disco' => $this->disco(),
            'hora' => $this->hora(),
        ];

        // Con los avisos encendidos, el planificador tiene que estar corriendo: si se
        // para, los avisos internos callan sin que nadie se entere.
        if (config('alertas.enabled')) {
            $componentes['avisos'] = $this->avisos();
        }

        return $componentes;
    }

    private function baseDeDatos(): ?string
    {
        try {
            DB::select('select 1');

            return null;
        } catch (Throwable) {
            return 'La base de datos no responde.';
        }
    }

    private function cola(): ?string
    {
        $conexion = (string) config('queue.default');

        if ($conexion === 'sync') {
            return null;
        }

        try {
            if (WorkerHeartbeat::estado()['estado'] !== 'activo') {
                return 'El worker de la cola no da señales de vida.';
            }

            if (config("queue.connections.$conexion.driver") === 'database') {
                $masViejo = DB::table((string) config("queue.connections.$conexion.table", 'jobs'))
                    ->whereNull('reserved_at')->min('available_at');
                $limite = now()->subMinutes((int) config('alertas.salud.cola_trabada_minutos', 10))->getTimestamp();

                if ($masViejo !== null && (int) $masViejo < $limite) {
                    return 'Hay trabajos esperando en la cola hace demasiado tiempo.';
                }
            }

            return null;
        } catch (Throwable) {
            return 'No se pudo revisar la cola.';
        }
    }

    /**
     * Con SALUD_FIRMADORES se revisan esos, siempre. Sin él, el firmador de
     * config/dte.php y solo con la firma real encendida: en preparación puede estar
     * apagado y eso no es una falla.
     */
    private function firmadores(): ?string
    {
        $urls = array_values(array_filter(array_map('trim', explode(',', (string) config('alertas.salud.firmadores', '')))));

        if ($urls === []) {
            if (! config('dte.firma.enabled') || config('dte.firma.mock')) {
                return null;
            }

            $urls = [rtrim((string) config('dte.firmador.url'), '/').'/status'];
        }

        $caidos = [];
        foreach ($urls as $i => $url) {
            try {
                if (! Http::timeout(self::ESPERA_SEGUNDOS)->get($url)->successful()) {
                    $caidos[] = $i + 1;
                }
            } catch (Throwable) {
                $caidos[] = $i + 1;
            }
        }

        if ($caidos === []) {
            return null;
        }

        return count($urls) === 1
            ? 'El firmador no responde.'
            : 'No responde el firmador n.º '.implode(' y ', $caidos).'.';
    }

    private function disco(): ?string
    {
        $libre = @disk_free_space(storage_path());

        if ($libre === false) {
            return 'No se pudo leer el espacio libre del disco.';
        }

        $minimo = (int) config('alertas.salud.disco_minimo_mb', 2048) * 1024 * 1024;

        return $libre < $minimo ? 'Queda poco espacio en el disco.' : null;
    }

    /**
     * La zona tiene que ser la del negocio (issue #59) y el reloj no puede estar
     * corrido: Hacienda rechaza un DTE con la hora mal. Se compara con la cabecera
     * Date de la sonda de internet de la contingencia. Sin internet no se puede
     * comparar, y eso no es una falla del reloj.
     */
    private function hora(): ?string
    {
        if ((string) config('app.timezone') !== HoraNegocio::zona()) {
            return 'La zona horaria del sistema no es la de El Salvador.';
        }

        $sonda = (string) config('dte.contingencia.sonda_internet_url', '');

        if ($sonda === '') {
            return null;
        }

        try {
            $fecha = Http::timeout(self::ESPERA_SEGUNDOS)->head($sonda)->header('Date');
        } catch (Throwable) {
            return null;
        }

        if ($fecha === '') {
            return null;
        }

        $desfase = abs(now()->getTimestamp() - Carbon::parse($fecha)->getTimestamp());

        return $desfase > (int) config('alertas.salud.desfase_hora_segundos', 120)
            ? 'El reloj del servidor está corrido.'
            : null;
    }

    private function avisos(): ?string
    {
        try {
            $ultima = Cache::get(Avisador::CLAVE_ULTIMA_REVISION);
        } catch (Throwable) {
            $ultima = null;
        }

        $limite = now()->subMinutes((int) config('alertas.salud.avisos_sin_correr_minutos', 15))->getTimestamp();

        return $ultima === null || (int) $ultima < $limite
            ? 'Los avisos internos no están corriendo: revisar el planificador y ALERTAS_CORREO.'
            : null;
    }
}
