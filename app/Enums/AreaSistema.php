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
    // Gastos va DESPUÉS de Facturación a propósito: `visiblesPara()` devuelve en
    // orden y el primero decide dónde aterriza cada quien. Los cuatro roles
    // históricos tienen `dte.ver`, así que siguen aterrizando en Facturación
    // exactamente como antes.
    case Gastos = 'gastos';
    case Planta = 'planta';
    case Rutas = 'rutas';
    case Asistencia = 'asistencia';

    /**
     * Etiqueta visible en la UI (selector superior y sidebar). Es lo ÚNICO
     * presentacional de este enum: cambiarla no toca el value del case, ni el
     * permiso de entrada, ni la ruta de aterrizaje, ni el prefijo de las URL.
     *
     * `Rutas` se llamó «Cobros» mientras cargaba la custodia del CCF físico. Desde el
     * 26/09/2026 es el módulo de rutas (salidas, salas, vendedores) y el cobro vive en
     * Cobros Calleja, dentro de Facturación.
     */
    public function label(): string
    {
        return match ($this) {
            self::Facturacion => 'Facturación',
            // «Gastos y pagos» agrupa DOS módulos —Gastos y Planilla— bajo un solo
            // nombre. Planilla dejó de ser área propia porque el selector ya tenía
            // demasiadas puertas y ninguna decía a quién le tocaba cuál.
            self::Gastos => 'Gastos y pagos',
            self::Planta => 'Producción',
            self::Rutas => 'Rutas',
            self::Asistencia => 'Asistencia',
        };
    }

    /**
     * Las PUERTAS del área: cada una es un módulo con su interruptor, su permiso de
     * entrada y su pantalla de aterrizaje.
     *
     * Casi todas las áreas tienen UNA. «Gastos y pagos» tiene DOS, porque agrupa dos
     * módulos, y eso obliga a razonar por puerta y no por área:
     *
     *  - Quien solo tiene `gastos.ver` entra y aterriza en «Por pagar». No ve una sola
     *    fila de Planilla.
     *  - Quien solo tiene `planilla.ver` —sin `gastos.ver`— **también entra**, y
     *    aterriza en Planillas. Si el área exigiera `gastos.ver`, juntar la navegación
     *    le habría quitado el acceso que ya tenía, que es lo contrario de simplificar.
     *  - Y si un módulo está apagado, su puerta no existe: el área sigue en pie por la
     *    otra.
     *
     * @return array<int, array{permiso: string, habilitada: bool, ruta: string}>
     */
    public function puertas(): array
    {
        return match ($this) {
            self::Facturacion => [
                ['permiso' => PermisoSistema::DteVer->value, 'habilitada' => true, 'ruta' => 'dashboard'],
            ],
            self::Gastos => [
                // Gastos primero: es la puerta ancha y donde aterriza casi todo el mundo.
                // Y esa puerta es el PANEL: qué se debe, qué viene, qué falta completar y
                // qué se pagó, de una vez. El listado con búsqueda y filtros sigue estando
                // (`gastos.index`, en /gastos/listado), pero no es por donde se entra.
                ['permiso' => PermisoSistema::GastosVer->value,
                    'habilitada' => (bool) config('gastos.enabled'), 'ruta' => 'gastos.panel'],
                ['permiso' => PermisoSistema::PlanillaVer->value,
                    'habilitada' => (bool) config('planilla.enabled'), 'ruta' => 'planilla.index'],
            ],
            self::Planta => [
                ['permiso' => PermisoSistema::PlantaVer->value,
                    'habilitada' => (bool) config('planta.enabled'), 'ruta' => 'planta.dashboard'],
            ],
            self::Rutas => [
                ['permiso' => PermisoSistema::RutasVer->value, 'habilitada' => true, 'ruta' => 'rutas.dashboard'],
            ],
            self::Asistencia => [
                ['permiso' => PermisoSistema::AsistenciaVer->value,
                    'habilitada' => (bool) config('asistencia.enabled'), 'ruta' => 'asistencia.dashboard'],
            ],
        };
    }

    /**
     * Permisos que abren el área, cualquiera de ellos. Es la lista de puertas vista
     * desde el lado del permiso.
     *
     * @return array<int, string>
     */
    public function permisos(): array
    {
        return array_column($this->puertas(), 'permiso');
    }

    /**
     * Aterrizaje por defecto del área: la primera puerta.
     *
     * Para Gastos y pagos es «Por pagar», porque lo primero que se pregunta al entrar
     * es qué falta pagar, no cómo registrar algo nuevo. Cuando hay un usuario a la
     * vista, lo correcto es {@see rutaInicioPara()}: mandar a /gastos a alguien que solo
     * tiene permisos de planilla sería mandarlo a un 403.
     */
    public function rutaInicio(): string
    {
        return $this->puertas()[0]['ruta'];
    }

    /**
     * Aterrizaje del área PARA ESTE USUARIO: la primera puerta abierta que alcance.
     *
     * Si no alcanza ninguna cae al aterrizaje por defecto; quien llame ya decidió que
     * puede ver el área, y aquí no se toma esa decisión otra vez.
     */
    public function rutaInicioPara(?User $usuario): string
    {
        foreach ($this->puertas() as $puerta) {
            if ($puerta['habilitada'] && $usuario?->can($puerta['permiso'])) {
                return $puerta['ruta'];
            }
        }

        return $this->rutaInicio();
    }

    /** Nombre del icono en <x-sidebar-icon>. */
    public function icono(): string
    {
        return match ($this) {
            self::Facturacion => 'facturacion',
            self::Gastos => 'contabilidad',
            self::Planta => 'planta',
            self::Rutas => 'rutas',
            self::Asistencia => 'asistencia',
        };
    }

    /**
     * ¿El módulo del área está encendido? Facturación es el núcleo del sistema y
     * no tiene interruptor; Planta se apaga con PLANTA_ENABLED (config/planta.php).
     *
     * Rutas tampoco lleva interruptor: quien la ve se decide solo por el permiso
     * `rutas.ver` (administrador, facturación y jefatura). Un flag de config sería
     * una segunda llave para la misma puerta.
     *
     * Asistencia SÍ lo lleva, y por el mismo motivo que Planta: el módulo depende
     * de un lector físico. En un servidor sin ESP32 no hay nada que administrar, y
     * `ASISTENCIA_ENABLED=false` (el valor por defecto) tiene que dejar el área
     * fuera del selector Y sus rutas en 404 —eso último lo impone el middleware
     * `modulo.asistencia`, no este método—.
     */
    public function habilitada(): bool
    {
        // Con varias puertas basta una abierta: «Gastos y pagos» sigue en pie si
        // Gastos está apagado pero Planilla encendido, y al revés.
        foreach ($this->puertas() as $puerta) {
            if ($puerta['habilitada']) {
                return true;
            }
        }

        return false;
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

        // Puerta por puerta, no área por área: hace falta que UNA esté encendida y que
        // el usuario tenga EL permiso DE ESA MISMA puerta. Comprobar «área encendida» y
        // «algún permiso del área» por separado dejaría entrar a quien tiene el permiso
        // de una puerta apagada.
        return array_values(array_filter(
            self::cases(),
            fn (self $area) => array_filter(
                $area->puertas(),
                fn (array $puerta) => $puerta['habilitada'] && $usuario->can($puerta['permiso'])
            ) !== []
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
            fn (self $area) => $usuario->canAny($area->permisos())
        ));
    }

    /** Área principal (primera visible) o null si el usuario no ve ninguna. */
    public static function principalPara(?User $usuario): ?self
    {
        return self::visiblesPara($usuario)[0] ?? null;
    }

    /**
     * Área activa según la URL actual (nunca según la sesión). Cualquier ruta que
     * no sea `planta.*` ni `rutas.*` pertenece a Facturación, que es el resto del
     * sistema.
     */
    public static function activaDesdeRequest(): self
    {
        return match (true) {
            // `planilla.*` pinta la barra de «Gastos y pagos»: es la misma área.
            request()->routeIs('gastos.*'), request()->routeIs('planilla.*') => self::Gastos,
            request()->routeIs('planta.*') => self::Planta,
            request()->routeIs('rutas.*') => self::Rutas,
            // `asistencia.*` son las pantallas web. Los endpoints del lector se
            // llaman `api.asistencia.*` y no entran acá: no tienen sesión, no
            // dibujan barra lateral y nunca llegan a esta función.
            request()->routeIs('asistencia.*') => self::Asistencia,
            default => self::Facturacion,
        };
    }
}
