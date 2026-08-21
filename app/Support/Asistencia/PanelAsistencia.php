<?php

namespace App\Support\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\HoraOficial;
use Illuminate\Support\Carbon;

/**
 * Los conteos de la pantalla de inicio de Asistencia.
 *
 * Vive fuera del controlador por el mismo motivo que `PlantaDashboardQuery`: una
 * cifra que se calcula dentro de una vista o de un controlador no se puede
 * probar, y estas van a hacer falta otra vez —el módulo de Formatos consultará
 * personal y marcaciones—. Que la consulta tenga nombre propio es lo que permite
 * reutilizarla sin copiarla.
 *
 * TRES REGLAS
 * ------------------------------------------------------------------
 * 1. SOLO CUENTA LO QUE EXISTE. Nada de «tardanzas», «ausentes» ni «horas
 *    trabajadas»: esas cifras necesitan horarios, y los horarios no existen
 *    todavía. Un indicador inventado en una pantalla de inicio es peor que
 *    ninguno, porque nadie vuelve a dudar de él.
 * 2. NO ES UN REPORTE. «Marcaciones de hoy» es un conteo, no un historial:
 *    responde «¿el lector está registrando?», que es lo que se mira al entrar.
 *    El historial con filtros es otra fase y otra pantalla.
 * 3. SOLO LECTURA. Ni una escritura, ni una petición de red.
 */
class PanelAsistencia
{
    public function __construct(private readonly HoraOficial $horaOficial) {}

    /**
     * @return array{
     *     empleados_activos: int, empleados_total: int,
     *     empleados_sin_huella: int,
     *     huellas_activas: int,
     *     lectores_activos: int, lectores_total: int,
     *     ultima_conexion: ?Carbon,
     *     marcaciones_hoy: int, personas_hoy: int,
     *     fecha_hoy: string, zona: string
     * }
     */
    public function resumen(): array
    {
        $hoy = $this->horaOficial->fechaLocal($this->horaOficial->instante());

        $marcacionesHoy = AsistenciaMarcacion::query()->where('fecha_local', $hoy);

        return [
            'empleados_activos' => AsistenciaEmpleado::query()->where('activo', true)->count(),
            'empleados_total' => AsistenciaEmpleado::query()->count(),

            // Gente activa a la que todavía nadie le asignó una ranura: no puede
            // marcar aunque esté dada de alta. Es el hueco más común al montar el
            // módulo y el único «problema» que esta pantalla se permite señalar,
            // porque se deduce de los datos y no de una regla inventada.
            'empleados_sin_huella' => AsistenciaEmpleado::query()
                ->where('activo', true)
                ->whereDoesntHave('huellas', fn ($q) => $q->where('activo', true))
                ->count(),

            'huellas_activas' => AsistenciaHuella::query()->where('activo', true)->count(),

            'lectores_activos' => AsistenciaDispositivo::query()->where('activo', true)->count(),
            'lectores_total' => AsistenciaDispositivo::query()->count(),

            // Telemetría, no auditoría: cuándo se vio por última vez CUALQUIER
            // lector. Responde «¿sigue vivo el de la puerta?» sin abrir el listado.
            'ultima_conexion' => AsistenciaDispositivo::query()->max('ultima_conexion_at')
                ? Carbon::parse(AsistenciaDispositivo::query()->max('ultima_conexion_at'))
                : null,

            'marcaciones_hoy' => (clone $marcacionesHoy)->count(),
            'personas_hoy' => (clone $marcacionesHoy)->distinct('asistencia_empleado_id')->count('asistencia_empleado_id'),

            'fecha_hoy' => $hoy,
            'zona' => $this->horaOficial->zona(),
        ];
    }
}
