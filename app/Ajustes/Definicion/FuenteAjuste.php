<?php

namespace App\Ajustes\Definicion;

/**
 * De dónde salió el valor que se está usando ahora mismo. Es la información que
 * SÍ puede mostrarse de un secreto: no su contenido, sino si está configurado y
 * desde dónde.
 */
enum FuenteAjuste: string
{
    /** Override en la tabla nueva `ajustes_sistema`. */
    case BaseDeDatos = 'base_de_datos';

    /** Override en la tabla `configuraciones` existente. */
    case BaseDeDatosLegacy = 'base_de_datos_legacy';

    /** config/*.php (que a su vez suele leer del .env). */
    case Configuracion = 'configuracion';

    /** El valor por defecto declarado en la definición. */
    case Defecto = 'defecto';

    /** No hay valor en ninguna parte. */
    case NoConfigurado = 'no_configurado';

    public function esOverride(): bool
    {
        return $this === self::BaseDeDatos || $this === self::BaseDeDatosLegacy;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::BaseDeDatos => 'Base de datos',
            self::BaseDeDatosLegacy => 'Base de datos (tabla anterior)',
            self::Configuracion => 'Archivo de configuración / .env',
            self::Defecto => 'Valor por defecto del sistema',
            self::NoConfigurado => 'Sin configurar',
        };
    }
}
