<?php

namespace App\Console\Commands;

use App\Models\Planilla\PlanillaEmpleado;
use App\Services\Planilla\NombreUnico;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revisa que cada persona tenga un solo nombre: el de su ficha de Personal.
 *
 * Lista a quienes tienen una copia distinta en Asistencia, Rutas o su usuario (por
 * ejemplo, enlazadas antes de que el nombre se unificara al enlazar). Por defecto NO
 * escribe nada. Con `--aplicar` iguala las copias a la ficha, todo en una transacción, y
 * deja en la bitácora de cada persona qué decía antes.
 *
 * Muestra nombres porque es para que la administración los revise en el servidor; no
 * muestra documentos ni sueldos.
 */
class PersonalNombresDistintosCommand extends Command
{
    private const MODULOS = ['asistencia' => 'Asistencia', 'rutas' => 'Rutas', 'usuario' => 'Usuario'];

    protected $signature = 'personal:nombres-distintos
        {--aplicar : Iguala las copias al nombre de la ficha (sin esto solo muestra)}';

    protected $description = 'Lista a quienes tienen otro nombre en Asistencia, Rutas o su usuario que en su ficha de Personal';

    public function handle(NombreUnico $nombres): int
    {
        if (! PlanillaEmpleado::enUso()) {
            $this->info('Planilla no está en uso: no hay fichas de Personal que revisar.');

            return self::SUCCESS;
        }

        $distintas = PlanillaEmpleado::with(['asistencia', 'personalRuta', 'usuario'])->orderBy('id')->get()
            ->mapWithKeys(fn (PlanillaEmpleado $empleado) => [$empleado->id => [$empleado, $nombres->diferencias($empleado)]])
            ->filter(fn (array $fila) => $fila[1] !== []);

        if ($distintas->isEmpty()) {
            $this->info('Todas las copias coinciden con su ficha de Personal.');

            return self::SUCCESS;
        }

        $filas = [];
        foreach ($distintas as [$empleado, $diferencias]) {
            foreach ($diferencias as $donde => $diferencia) {
                $filas[] = [$empleado->id, $empleado->nombre, self::MODULOS[$donde], $diferencia['actual']];
            }
        }
        $this->table(['Ficha', 'Nombre en la ficha', 'Dónde', 'Dice'], $filas);

        if (! $this->option('aplicar')) {
            $this->info($distintas->count().' persona(s) con otro nombre. No se escribió nada. Usá --aplicar para igualarlas a su ficha.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($distintas, $nombres) {
            foreach ($distintas as [$empleado, $diferencias]) {
                $nombres->repartir($empleado);
                activity('empleado')->performedOn($empleado)
                    ->withProperties(['antes' => array_map(fn (array $d) => $d['actual'], $diferencias)])
                    ->log('igualó su nombre en '.implode(', ', array_map(fn ($d) => self::MODULOS[$d], array_keys($diferencias))).' al de su ficha');
            }
        });
        $this->info($distintas->count().' persona(s) igualadas a su ficha de Personal.');

        return self::SUCCESS;
    }
}
