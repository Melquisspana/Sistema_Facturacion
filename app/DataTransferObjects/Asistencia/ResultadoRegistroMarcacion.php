<?php

namespace App\DataTransferObjects\Asistencia;

use App\Enums\Asistencia\ResultadoMarcacion;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\RegistrarMarcacion;

/**
 * Lo que devuelve {@see RegistrarMarcacion}: QUÉ pasó,
 * no cómo se ve.
 *
 * El servicio no arma JSON ni códigos HTTP —eso es del controlador— y el
 * controlador no decide reglas de asistencia. Esa frontera es lo que permite que
 * mañana una pantalla web, un reporte o un segundo tipo de lector usen la misma
 * regla sin copiarla.
 */
class ResultadoRegistroMarcacion
{
    private function __construct(
        public readonly ResultadoMarcacion $estado,
        public readonly int $fingerprintId,
        public readonly ?AsistenciaEmpleado $empleado = null,
        /** La marcación recién escrita. Solo en el caso exitoso. */
        public readonly ?AsistenciaMarcacion $marcacion = null,
        /** La marcación previa que provocó la ventana de cortesía. */
        public readonly ?AsistenciaMarcacion $marcacionPrevia = null,
        /** Segundos que faltan para que la próxima marcación cuente. */
        public readonly ?int $esperaSegundos = null,
    ) {}

    public static function registrada(AsistenciaEmpleado $empleado, AsistenciaMarcacion $marcacion, int $fingerprintId): self
    {
        return new self(
            estado: ResultadoMarcacion::Registrada,
            fingerprintId: $fingerprintId,
            empleado: $empleado,
            marcacion: $marcacion,
        );
    }

    public static function huellaDesconocida(int $fingerprintId): self
    {
        return new self(estado: ResultadoMarcacion::HuellaDesconocida, fingerprintId: $fingerprintId);
    }

    public static function empleadoInactivo(AsistenciaEmpleado $empleado, int $fingerprintId): self
    {
        return new self(
            estado: ResultadoMarcacion::EmpleadoInactivo,
            fingerprintId: $fingerprintId,
            empleado: $empleado,
        );
    }

    public static function cooldown(
        AsistenciaEmpleado $empleado,
        AsistenciaMarcacion $previa,
        int $esperaSegundos,
        int $fingerprintId,
    ): self {
        return new self(
            estado: ResultadoMarcacion::Cooldown,
            fingerprintId: $fingerprintId,
            empleado: $empleado,
            marcacionPrevia: $previa,
            esperaSegundos: $esperaSegundos,
        );
    }
}
