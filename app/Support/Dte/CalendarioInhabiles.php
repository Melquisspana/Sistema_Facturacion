<?php

namespace App\Support\Dte;

use App\Facades\Ajustes;
use InvalidArgumentException;

/**
 * Días inhábiles del MH por año (decisión 0005, issue #49).
 *
 * Manda lo cargado en Configuración → Feriados de Hacienda. Un año está cargado ahí
 * si tiene alguna fecha, si está marcado como completo o si es un año del archivo
 * que ya se cambió en la pantalla. Para un año que no lo está, el respaldo es
 * `config('dte.invalidacion.dias_inhabiles')`, donde tener la lista del año
 * significa que el año está completo. Si no hay ninguno, el año no tiene
 * calendario y el plazo se calcula de lunes a viernes, sin feriados.
 */
final class CalendarioInhabiles
{
    /** Todas las fechas cargadas en Configuración, de todos los años (AAAA-MM-DD). */
    public const FECHAS = 'dte.invalidacion.dias_inhabiles';

    /** Años que Configuración marca con el calendario completo (AAAA). */
    public const COMPLETOS = 'dte.invalidacion.anios_completos';

    /**
     * Años del archivo que ya se cambiaron en Configuración (AAAA). Para ellos vale
     * solo Configuración, aunque queden sin fechas y sin marcar: si no, vaciarlos o
     * desmarcarlos haría volver el calendario del archivo, distinto de lo confirmado.
     */
    public const EN_CONFIGURACION = 'dte.invalidacion.anios_en_configuracion';

    /** Tope de días por año. El calendario 2026 del MH trae 26. */
    public const MAXIMO_POR_ANIO = 60;

    /**
     * Calendario vigente del año, o null si no tiene.
     *
     * @return array{fechas: array<int, string>, completo: bool, en_configuracion: bool}|null
     */
    public function delAnio(string $anio): ?array
    {
        // Siempre se valida el archivo: una fecha mal escrita ahí no se ignora, aunque
        // ese año lo tape Configuración.
        $archivo = $this->delArchivo();

        $fechas = array_values(array_filter(
            Ajustes::lista(self::FECHAS),
            static fn (string $fecha) => str_starts_with($fecha, $anio.'-'),
        ));
        $completo = in_array($anio, Ajustes::lista(self::COMPLETOS), true);

        if ($fechas !== [] || $completo || in_array($anio, Ajustes::lista(self::EN_CONFIGURACION), true)) {
            foreach ($fechas as $fecha) {
                if (! self::esFecha($fecha)) {
                    throw new InvalidArgumentException('Configuración inválida: fecha «'.$fecha.'» en Feriados de Hacienda. Se exige AAAA-MM-DD.');
                }
            }

            return ['fechas' => $fechas, 'completo' => $completo, 'en_configuracion' => true];
        }

        if (array_key_exists($anio, $archivo)) {
            $fechas = array_values(array_unique($archivo[$anio]));
            sort($fechas);

            return ['fechas' => $fechas, 'completo' => true, 'en_configuracion' => false];
        }

        return null;
    }

    /**
     * Años que tienen algo, en Configuración o en el archivo.
     *
     * @return array<int, string>
     */
    public function anios(): array
    {
        $anios = array_merge(
            array_map(static fn (string $fecha) => substr($fecha, 0, 4), Ajustes::lista(self::FECHAS)),
            Ajustes::lista(self::COMPLETOS),
            Ajustes::lista(self::EN_CONFIGURACION),
            array_map('strval', array_keys($this->delArchivo())),
        );

        return array_values(array_unique($anios));
    }

    /**
     * Lo que hay que guardar para que `$anio` quede con estas fechas y esta marca. Los
     * otros años quedan como están. Una lista vacía se guarda como «sin valor». Un año
     * del archivo queda anotado en EN_CONFIGURACION, para que no vuelva al archivo.
     *
     * @param  array<int, string>  $fechas
     * @return array<string, array<int, string>|null>
     */
    public function propuesta(string $anio, array $fechas, bool $completo): array
    {
        $todas = array_merge(
            array_filter(Ajustes::lista(self::FECHAS), static fn (string $fecha) => ! str_starts_with($fecha, $anio.'-')),
            $fechas,
        );
        sort($todas);

        $completos = array_diff(Ajustes::lista(self::COMPLETOS), [$anio]);
        if ($completo) {
            $completos[] = $anio;
        }
        sort($completos);

        $enConfiguracion = array_diff(Ajustes::lista(self::EN_CONFIGURACION), [$anio]);
        if (array_key_exists($anio, $this->delArchivo())) {
            $enConfiguracion[] = $anio;
        }
        sort($enConfiguracion);

        return [
            self::FECHAS => $todas === [] ? null : array_values($todas),
            self::COMPLETOS => $completos === [] ? null : array_values($completos),
            self::EN_CONFIGURACION => $enConfiguracion === [] ? null : array_values($enConfiguracion),
        ];
    }

    public static function esFecha(string $fecha): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @return array<int|string, array<int, string>> */
    private function delArchivo(): array
    {
        $calendario = config('dte.invalidacion.dias_inhabiles', []);
        if (! is_array($calendario)) {
            throw new InvalidArgumentException('Configuración inválida: dte.invalidacion.dias_inhabiles debe ser un arreglo por año.');
        }
        foreach ($calendario as $anio => $fechas) {
            if (! preg_match('/^\d{4}$/D', (string) $anio) || ! is_array($fechas) || ! array_is_list($fechas)) {
                throw new InvalidArgumentException('Configuración inválida: dte.invalidacion.dias_inhabiles.'.$anio.' debe ser una lista de fechas Y-m-d.');
            }
            foreach ($fechas as $fecha) {
                if (! is_string($fecha) || ! self::esFecha($fecha) || substr($fecha, 0, 4) !== (string) $anio) {
                    throw new InvalidArgumentException('Configuración inválida: fecha en dte.invalidacion.dias_inhabiles.'.$anio.'. Se exige Y-m-d del mismo año.');
                }
            }
        }

        return $calendario;
    }
}
