<?php

namespace App\Services\DocumentosRecibidos;

/**
 * Resultado de una corrida de sincronización de compras.
 *
 * El punto entero de esta clase es que los seis desenlaces posibles se distingan. La
 * versión anterior devolvía siempre el mismo arreglo y un buzón caído terminaba
 * indistinguible de un buzón sin novedades — en verde, además.
 */
class ResumenSincronizacion
{
    /** Corrida entera, todos los días del rango recorridos completos. */
    public const COMPLETA = 'completa';

    /** Corrida bien, sin un solo correo nuevo. Distinto de que algo haya fallado. */
    public const SIN_NOVEDADES = 'sin_novedades';

    /** Algún día quedó truncado o a medias: hay trabajo pendiente, no es un error. */
    public const INCOMPLETA = 'incompleta';

    /** No se pudo llegar al buzón: red, servidor, carpeta. Se reintenta. */
    public const BUZON_INACCESIBLE = 'buzon_inaccesible';

    /** El servidor rechazó usuario/contraseña. Reintentar no sirve. */
    public const AUTENTICACION_FALLIDA = 'autenticacion_fallida';

    /** El buzón no está configurado todavía. */
    public const SIN_CONFIGURAR = 'sin_configurar';

    /** El UIDVALIDITY de la carpeta cambió: el progreso por UID ya no aplica. */
    public const UID_VALIDITY_CAMBIADO = 'uid_validity_cambiado';

    /**
     * @param  array<int, string>  $diasCompletos
     * @param  array<int, string>  $diasIncompletos  días truncados o con error
     */
    public function __construct(
        public readonly string $desenlace,
        public readonly string $carpeta,
        public readonly ?string $desde = null,
        public readonly ?string $hasta = null,
        public readonly int $correos = 0,
        public readonly int $nuevos = 0,
        public readonly int $duplicados = 0,
        public readonly int $descartados = 0,
        public readonly int $rechazados = 0,
        public readonly array $diasCompletos = [],
        public readonly array $diasIncompletos = [],
        public readonly ?string $error = null,
        public readonly bool $aplicado = false,
    ) {}

    public function exitosa(): bool
    {
        return in_array($this->desenlace, [self::COMPLETA, self::SIN_NOVEDADES], true);
    }

    /** ¿Falló de una forma que impide dar por leído el rango? */
    public function fallo(): bool
    {
        return in_array($this->desenlace, [
            self::BUZON_INACCESIBLE, self::AUTENTICACION_FALLIDA,
            self::SIN_CONFIGURAR, self::UID_VALIDITY_CAMBIADO,
        ], true);
    }

    public function etiqueta(): string
    {
        return match ($this->desenlace) {
            self::COMPLETA => 'Corrida completa',
            self::SIN_NOVEDADES => 'Sin novedades',
            self::INCOMPLETA => 'Quedaron días sin cerrar',
            self::BUZON_INACCESIBLE => 'Buzón inaccesible',
            self::AUTENTICACION_FALLIDA => 'Autenticación fallida',
            self::SIN_CONFIGURAR => 'Buzón sin configurar',
            self::UID_VALIDITY_CAMBIADO => 'El buzón se reconstruyó (UIDVALIDITY cambió)',
            default => $this->desenlace,
        };
    }

    /** Una línea para la pantalla, el log y la salida del comando. */
    public function mensaje(): string
    {
        if ($this->fallo()) {
            return $this->etiqueta().': '.($this->error ?? 'sin motivo');
        }

        $texto = sprintf(
            '%s (carpeta %s, %s a %s): %d correos revisados, %d nuevos, %d ya registrados, %d descartados (no-DTE), %d sin DTE legible.',
            $this->etiqueta(), $this->carpeta, $this->desde ?? '—', $this->hasta ?? '—',
            $this->correos, $this->nuevos, $this->duplicados, $this->descartados, $this->rechazados,
        );

        if ($this->diasIncompletos !== []) {
            $texto .= ' Días sin cerrar: '.implode(', ', $this->diasIncompletos).'.';
        }

        return $texto.' No se modificó ningún correo.';
    }

    /** @return array<string, mixed> */
    public function aArreglo(): array
    {
        return [
            'desenlace' => $this->desenlace,
            'carpeta' => $this->carpeta,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'correos' => $this->correos,
            'nuevos' => $this->nuevos,
            'duplicados' => $this->duplicados,
            'descartados' => $this->descartados,
            'rechazados' => $this->rechazados,
            'dias_completos' => count($this->diasCompletos),
            'dias_incompletos' => $this->diasIncompletos,
            'error' => $this->error,
            'aplicado' => $this->aplicado,
        ];
    }
}
