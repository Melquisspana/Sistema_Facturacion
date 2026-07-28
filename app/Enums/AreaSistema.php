<?php

namespace App\Enums;

use App\Models\User;

/**
 * Áreas de trabajo del sistema. Un «área» NO es una entidad de base de datos, ni
 * un tenant, ni una columna: es una AGRUPACIÓN DE PRESENTACIÓN derivada de los
 * permisos que el usuario ya tiene. Este enum es la fuente única de verdad de
 * qué áreas existen, cómo se llaman, con qué permiso se entra a cada una, dónde
 * aterrizan y si están habilitadas.
 *
 * Reglas que NO se pueden romper:
 *
 *  1. Esto es PRESENTACIÓN. La autorización real vive en el middleware de cada
 *     grupo de rutas (`permission:` + `modulo.planta`) y en las policies. El
 *     selector superior y la sidebar solo deciden qué se DIBUJA.
 *  2. El área activa se deriva de la URL ({@see activaDesdeRequest()}), nunca de
 *     la sesión. Un `session('area')` como candado sería trivialmente evadible.
 *  3. `Facturacion` usa `dte.ver` como permiso de entrada: es el permiso que ya
 *     tienen los cuatro roles históricos, así que su navegación no cambia.
 *
 * Nombre técnico «planta» vs. etiqueta visible «Producción»: ver config/planta.php.
 */
enum AreaSistema: string
{
    case Facturacion = 'facturacion';
    case Planta = 'planta';

    /** Etiqueta visible en la UI (selector superior y sidebar). */
    public function label(): string
    {
        return match ($this) {
            self::Facturacion => 'Facturación',
            self::Planta => 'Producción',
        };
    }

    /** Permiso de ENTRADA al área (no el de gestionar dentro de ella). */
    public function permiso(): string
    {
        return match ($this) {
            self::Facturacion => PermisoSistema::DteVer->value,
            self::Planta => PermisoSistema::PlantaVer->value,
        };
    }

    /** Nombre de la ruta a la que aterriza el área. */
    public function rutaInicio(): string
    {
        return match ($this) {
            self::Facturacion => 'dashboard',
            self::Planta => 'planta.dashboard',
        };
    }

    /** Nombre del icono en <x-sidebar-icon>. */
    public function icono(): string
    {
        return match ($this) {
            self::Facturacion => 'facturacion',
            self::Planta => 'planta',
        };
    }

    /**
     * ¿El módulo del área está encendido? Facturación es el núcleo del sistema y
     * no tiene interruptor; Planta se apaga con PLANTA_ENABLED (config/planta.php).
     */
    public function habilitada(): bool
    {
        return match ($this) {
            self::Facturacion => true,
            self::Planta => (bool) config('planta.enabled'),
        };
    }

    /**
     * Áreas que el usuario PUEDE VER: módulo encendido Y permiso de entrada.
     * Solo consulta permisos (Spatie los cachea) y config: NO toca la base de datos.
     *
     * @return array<int, self> en orden de prioridad de aterrizaje
     */
    public static function visiblesPara(?User $usuario): array
    {
        if ($usuario === null) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $area) => $area->habilitada() && $usuario->can($area->permiso())
        ));
    }

    /**
     * Áreas a las que el usuario PERTENECE por permisos, ignorando el interruptor
     * del módulo. Sirve para distinguir «este usuario no tiene ninguna área»
     * (usuario sin rol) de «su área existe pero está apagada» (rol produccion con
     * PLANTA_ENABLED=false), que reciben tratos distintos en el dashboard.
     *
     * @return array<int, self>
     */
    public static function potencialesPara(?User $usuario): array
    {
        if ($usuario === null) {
            return [];
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $area) => $usuario->can($area->permiso())
        ));
    }

    /** Área principal (primera visible) o null si el usuario no ve ninguna. */
    public static function principalPara(?User $usuario): ?self
    {
        return self::visiblesPara($usuario)[0] ?? null;
    }

    /**
     * Área activa según la URL actual (nunca según la sesión). Cualquier ruta que
     * no sea `planta.*` pertenece a Facturación, que es el resto del sistema.
     */
    public static function activaDesdeRequest(): self
    {
        return request()->routeIs('planta.*') ? self::Planta : self::Facturacion;
    }
}
