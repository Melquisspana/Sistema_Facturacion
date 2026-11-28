<?php

namespace App\Console\Commands;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\User;
use App\Services\Asistencia\LimpiezaSensor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Muestra las huellas sueltas del sensor de un lector y, con --confirmar, deja
 * pendiente que el lector las borre.
 *
 * Es a mano y en dos pasos a propósito: borrar una plantilla del AS608 no tiene
 * vuelta atrás, y el sistema nunca debe decidir solo vaciar un sensor (por
 * ejemplo, después de restaurar una base vieja).
 */
class AsistenciaLimpiarSensorCommand extends Command
{
    protected $signature = 'asistencia:limpiar-sensor
                            {codigo : Código del lector}
                            {--confirmar : Deja pendiente que el lector borre las huellas sueltas}
                            {--usuario= : Correo del usuario activo que queda como responsable (obligatorio con --confirmar)}';

    protected $description = 'Muestra las huellas sueltas del sensor de un lector (con --confirmar, deja pendiente borrarlas)';

    public function handle(LimpiezaSensor $limpieza): int
    {
        $codigo = trim((string) $this->argument('codigo'));
        $lector = AsistenciaDispositivo::query()->where('codigo', $codigo)->first();

        if ($lector === null) {
            $this->error("No hay ningún lector con el código «{$codigo}».");

            return self::FAILURE;
        }

        $sueltas = $limpieza->sueltas($lector);
        $deBaja = $limpieza->deBaja($lector);

        $this->line('Huellas sueltas ('.count($sueltas).'): '.$this->lista($sueltas));
        $this->line('Dadas de baja, no se borran ('.count($deBaja).'): '.$this->lista($deBaja));

        // La lista sale del último índice que mandó el lector. Si es viejo, puede
        // no ser lo que hoy tiene el sensor.
        $indice = $lector->indice_sincronizado_at;
        $indiceViejo = $indice === null || $indice->lt(Carbon::now()->subDay());

        if ($indiceViejo) {
            $this->warn($indice === null
                ? 'El lector todavía no mandó su índice.'
                : 'El último índice del lector es del '.$indice->format('d/m/Y H:i').'.');
            $this->warn('Reiniciá el lector para que mande uno nuevo y volvé a correr este comando.');
        }

        if (! $this->option('confirmar')) {
            if ($sueltas !== [] && ! $indiceViejo) {
                $this->newLine();
                $this->line("Para que el lector las borre: php artisan asistencia:limpiar-sensor {$lector->codigo} --confirmar --usuario=<correo>");
            }

            return self::SUCCESS;
        }

        if ($indiceViejo) {
            return self::FAILURE;
        }

        $responsable = User::query()
            ->where('email', (string) $this->option('usuario'))
            ->where('activo', true)
            ->first();

        if ($responsable === null) {
            $this->error('Indicá con --usuario el correo de un usuario activo, que queda como responsable.');

            return self::FAILURE;
        }

        if ($sueltas === []) {
            $this->info('No hay huellas sueltas que borrar.');

            return self::SUCCESS;
        }

        $limpieza->programar($lector, $sueltas, $responsable);

        $this->info('Quedó pendiente borrar '.count($sueltas).' huellas sueltas. Vence en '.LimpiezaSensor::DIAS_PENDIENTE.' días.');
        $this->line('Reiniciá el lector: al arrancar las borra y vuelve a mandar su índice.');

        return self::SUCCESS;
    }

    /** @param  array<int, int>  $ranuras */
    private function lista(array $ranuras): string
    {
        return $ranuras === [] ? 'ninguna' : implode(', ', $ranuras);
    }
}
