<?php

namespace App\Console\Commands;

use App\Exceptions\Asistencia\RanuraOcupadaException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\AsignarHuella;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta un empleado y le asocia la ranura del sensor donde ya está guardada
 * su huella.
 *
 * Es el mínimo administrativo para que el módulo funcione sin pantallas: dar de
 * alta gente es poco frecuente y hacerlo desde la consola del servidor evita
 * construir hoy un CRUD con sus permisos, su auditoría y sus pruebas, que además
 * habría que rehacer cuando existan cargos y horarios.
 *
 * El alta de la PLANTILLA biométrica (poner el dedo en el AS608 y guardarla en la
 * ranura N) es un acto del sensor, no de Laravel: acá solo se anota que esa
 * ranura de ese lector corresponde a esta persona.
 *
 * No borra ni pisa nada: si la ranura tiene una asignación VIGENTE, se detiene y
 * lo dice. Que la ranura tenga asignaciones HISTÓRICAS —alguien que ya no está—
 * no estorba: eso es exactamente lo que permite reutilizarla.
 *
 * La asignación se delega en {@see AsignarHuella} y no se
 * escribe acá: es el único camino que comprueba la ranura y deja auditoría, y
 * tiene que ser el mismo desde la consola que desde la pantalla que viene.
 */
class AsistenciaEmpleadoCommand extends Command
{
    protected $signature = 'asistencia:empleado
                            {nombres : Nombres del empleado}
                            {apellidos : Apellidos del empleado}
                            {--dispositivo= : Código del lector donde está guardada la huella}
                            {--fingerprint= : Número de ranura del sensor (el que manda el ESP32)}
                            {--codigo= : Código de planilla/RRHH (opcional)}';

    protected $description = 'Da de alta un empleado de asistencia y le asocia una huella del lector';

    public function handle(AsignarHuella $asignar): int
    {
        $nombres = trim((string) $this->argument('nombres'));
        $apellidos = trim((string) $this->argument('apellidos'));

        if ($nombres === '' || $apellidos === '') {
            $this->error('Nombres y apellidos son obligatorios.');

            return self::FAILURE;
        }

        $codigoDispositivo = trim((string) $this->option('dispositivo'));
        $fingerprint = $this->option('fingerprint');
        $asociaHuella = $codigoDispositivo !== '' || $fingerprint !== null;

        $dispositivo = null;

        if ($asociaHuella) {
            if ($codigoDispositivo === '' || $fingerprint === null) {
                $this->error('Para asociar una huella hacen falta --dispositivo y --fingerprint juntos.');

                return self::FAILURE;
            }

            if (! ctype_digit((string) $fingerprint)) {
                $this->error('--fingerprint debe ser un número entero.');

                return self::FAILURE;
            }

            $fingerprint = (int) $fingerprint;

            $dispositivo = AsistenciaDispositivo::query()->where('codigo', $codigoDispositivo)->first();

            if ($dispositivo === null) {
                $this->error("No existe ningún lector con el código «{$codigoDispositivo}».");
                $this->line('Se da de alta con: php artisan asistencia:dispositivo <codigo>');

                return self::FAILURE;
            }

            // Se pregunta ANTES de mostrar el resumen para no hacer confirmar
            // algo que va a fallar. La comprobación de verdad la repite el
            // servicio al asignar, que es donde importa.
            if (! $asignar->ranuraLibre($dispositivo, $fingerprint)) {
                $this->error("La ranura {$fingerprint} del lector «{$codigoDispositivo}» ya tiene una asignación vigente.");
                $this->line('No se toca nada. Usá otra ranura, o liberá esa asignación primero');
                $this->line('(y borrá la plantilla en el sensor antes de reutilizarla).');

                return self::FAILURE;
            }
        }

        // Qué se va a crear, ANTES de crearlo. Dar de alta a una persona en un
        // sistema que después alimenta una planilla no debería pasar sin que
        // alguien lo lea.
        $this->newLine();
        $this->line('Se va a crear:');
        $this->line("  Empleado : {$nombres} {$apellidos}");
        $this->line('  Código   : '.($this->option('codigo') ?: '(ninguno)'));

        if ($asociaHuella) {
            $this->line("  Huella   : ranura {$fingerprint} del lector «{$dispositivo->codigo}» ({$dispositivo->nombre})");
        } else {
            $this->line('  Huella   : (ninguna todavía)');
        }

        $this->newLine();

        if (! $this->confirm('¿Crear estos registros?', false)) {
            $this->line('Sin cambios.');

            return self::SUCCESS;
        }

        try {
            $empleado = DB::transaction(function () use ($nombres, $apellidos, $asociaHuella, $dispositivo, $fingerprint, $asignar) {
                $empleado = AsistenciaEmpleado::create([
                    'codigo' => $this->option('codigo') ?: null,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'activo' => true,
                ]);

                if ($asociaHuella) {
                    $asignar($empleado, $dispositivo, $fingerprint);
                }

                return $empleado;
            });
        } catch (RanuraOcupadaException $e) {
            // Alguien tomó la ranura entre la comprobación de arriba y esto. La
            // transacción ya deshizo el empleado a medio crear.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Empleado creado (id {$empleado->id}): {$empleado->nombreCompleto()}");

        if ($asociaHuella) {
            $this->info("Huella asociada: ranura {$fingerprint} de «{$dispositivo->codigo}».");
        }

        return self::SUCCESS;
    }
}
