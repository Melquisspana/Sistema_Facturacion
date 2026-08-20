<?php

namespace App\Ajustes\Definicion;

use App\Enums\PermisoSistema;

/**
 * Los tres niveles de ceremonia para guardar un ajuste.
 *
 *  N1 — Normal: guardar + auditoría. Permiso `configuracion.gestionar`.
 *  N2 — Confirmación: impacto alto; la UI debe pedir confirmación explícita.
 *       Mismo permiso, distinta ceremonia.
 *  N3 — Confirmación fuerte: impacto FISCAL. Exige el permiso separado
 *       `configuracion.critica`, y además (cuando exista la pantalla) frase
 *       exacta, reingreso de contraseña y precondiciones.
 *
 * Esta fase construye el nivel y el PERMISO. Los modales de N2/N3 son de la fase
 * siguiente: la metadata ya permite saber cuál corresponde a cada clave.
 */
enum NivelConfirmacion: string
{
    case N1 = 'n1';
    case N2 = 'n2';
    case N3 = 'n3';

    /** Permiso mínimo para ESCRIBIR un ajuste de este nivel. */
    public function permisoRequerido(): PermisoSistema
    {
        return match ($this) {
            self::N1, self::N2 => PermisoSistema::ConfiguracionGestionar,
            self::N3 => PermisoSistema::ConfiguracionCritica,
        };
    }

    /** ¿La UI debe pedir una confirmación explícita antes de guardar? */
    public function requiereConfirmacion(): bool
    {
        return $this !== self::N1;
    }

    /** ¿Exige la ceremonia fuerte (frase exacta + reautenticación)? */
    public function requiereCeremoniaFuerte(): bool
    {
        return $this === self::N3;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::N1 => 'Normal',
            self::N2 => 'Requiere confirmación',
            self::N3 => 'Requiere confirmación fuerte (fiscal)',
        };
    }
}
