<?php

namespace App\Support\Dte;

use App\Enums\AmbienteHacienda;
use App\Models\Empresa;

/**
 * Verificaciones de COHERENCIA de la configuración fiscal. SOLO LECTURA y SOLO
 * DIAGNÓSTICO: no escribe nada, no toca .env, no hace HTTP, no corrige nada por su
 * cuenta. Devuelve checks con el mismo contrato que ya usan los preflight
 * ({clave, label, ok, detalle}) para poder mezclarlos sin adaptadores.
 *
 * Cubre tres incoherencias que hoy pueden convivir en silencio:
 *
 *  1. AMBIENTES CRUZADOS. Son dos ajustes distintos que nadie compara:
 *       - `dte.ambiente` (DTE_AMBIENTE, '00'/'01') es el ambiente que VIAJA DENTRO del
 *         JSON del DTE;
 *       - `dte.transmision.ambiente` (DTE_TRANSMISION_AMBIENTE, testing/produccion)
 *         decide A QUÉ cuenta y a qué host del MH se autentica.
 *     Cruzarlos produce los dos peores casos posibles: un JSON marcado producción
 *     enviado con credenciales de apitest, o un JSON de pruebas enviado a la cuenta
 *     real. Ninguno de los dos falla "solo"; hay que detectarlos antes.
 *
 *  2. NIT DE FIRMA vs NIT DEL EMISOR. `dte.firma.nit` (DTE_FIRMA_NIT) determina qué
 *     certificado `<NIT>.crt` usa el firmador; `empresas.nit` es el NIT que va en el
 *     bloque `emisor` del JSON. Si divergen, se firma un documento con el certificado
 *     de otro contribuyente. Se compara solo por dígitos (uno lleva guiones y el otro
 *     no) y NO se corrige nada automáticamente.
 *
 *  3. MOCKS EN PRODUCCIÓN. Con APP_ENV=production, cualquiera de los tres mocks
 *     (firmador, MH, invalidación) activo produce sellos y JWS FICTICIOS que en la UI
 *     se ven igual que los reales.
 */
class CoherenciaConfiguracionFiscal
{
    /**
     * Los tres checks juntos, en el mismo formato de los preflight existentes.
     *
     * @return array<int, array{clave: string, label: string, ok: bool, detalle: string}>
     */
    public static function checks(): array
    {
        return [
            self::checkAmbientes(),
            self::checkNitFirma(),
            self::checkMocksProduccion(),
        ];
    }

    /** Solo los checks que FALLAN (para resumir en avisos). */
    public static function problemas(): array
    {
        return array_values(array_filter(self::checks(), fn (array $c) => ! $c['ok']));
    }

    /** ¿La configuración fiscal es coherente en los tres frentes? */
    public static function todoCoherente(): bool
    {
        return self::problemas() === [];
    }

    // -----------------------------------------------------------------------
    // 1. Ambiente del JSON vs ambiente de transmisión
    // -----------------------------------------------------------------------

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    public static function checkAmbientes(): array
    {
        $codigo = self::codigoAmbiente();
        $jsonEsProduccion = $codigo === AmbienteHacienda::Produccion->value;
        $transEsProduccion = self::transmisionEsProduccion();

        $etiquetaJson = AmbienteHacienda::tryFrom($codigo)?->label() ?? 'desconocido';
        $etiquetaTrans = $transEsProduccion ? 'produccion' : 'testing';

        // Un código fuera de CAT-001 no es "incoherente": es inválido, y hay que decirlo
        // con esas palabras en vez de compararlo como si fuera pruebas.
        if (AmbienteHacienda::tryFrom($codigo) === null) {
            return self::check('coherencia_ambiente', 'Ambientes coherentes', false,
                'dte.ambiente="'.$codigo.'" no es un valor válido de CAT-001 (00 pruebas / 01 producción).');
        }

        $ok = $jsonEsProduccion === $transEsProduccion;

        if ($ok) {
            return self::check('coherencia_ambiente', 'Ambientes coherentes', true,
                'dte.ambiente='.$codigo.' ('.$etiquetaJson.') y dte.transmision.ambiente='.$etiquetaTrans.' concuerdan.');
        }

        $detalle = $jsonEsProduccion
            ? 'dte.ambiente=01 (producción) pero dte.transmision.ambiente=testing: '
              .'el documento se marcaría como PRODUCCIÓN y se enviaría con las credenciales de apitest.'
            : 'dte.ambiente=00 (pruebas) pero dte.transmision.ambiente=produccion: '
              .'se autenticaría contra la cuenta REAL del MH para enviar un documento marcado como prueba.';

        return self::check('coherencia_ambiente', 'Ambientes coherentes', false, $detalle);
    }

    // -----------------------------------------------------------------------
    // 2. NIT de firma vs NIT del emisor
    // -----------------------------------------------------------------------

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    public static function checkNitFirma(): array
    {
        $nitFirma = self::soloDigitos((string) config('dte.firma.nit', ''));
        $nitEmisor = self::soloDigitos((string) (self::empresaEmisora()?->nit ?? ''));

        // Sin ninguno de los dos no hay nada que comparar todavía: es el estado normal
        // mientras la firma sigue deshabilitada. No se reporta como incoherencia.
        if ($nitFirma === '' && $nitEmisor === '') {
            return self::check('coherencia_nit', 'NIT de firma coincide con el emisor', true,
                'sin configurar: DTE_FIRMA_NIT vacío y la empresa emisora no tiene NIT (nada que comparar todavía).');
        }

        if ($nitFirma === '') {
            return self::check('coherencia_nit', 'NIT de firma coincide con el emisor', false,
                'DTE_FIRMA_NIT está vacío pero la empresa emisora sí tiene NIT: el firmador no sabría qué certificado usar.');
        }

        if ($nitEmisor === '') {
            return self::check('coherencia_nit', 'NIT de firma coincide con el emisor', false,
                'DTE_FIRMA_NIT está configurado pero la empresa emisora no tiene NIT cargado: no se puede verificar que el certificado corresponda al emisor.');
        }

        $ok = $nitFirma === $nitEmisor;

        return self::check('coherencia_nit', 'NIT de firma coincide con el emisor', $ok,
            $ok
                ? 'DTE_FIRMA_NIT coincide con empresas.nit (comparado por dígitos).'
                : 'DTE_FIRMA_NIT y empresas.nit NO coinciden: se firmaría con el certificado de otro NIT. '
                  .'Corregir a mano cuál de los dos está mal — el sistema no lo cambia solo.');
    }

    // -----------------------------------------------------------------------
    // 3. Mocks activos en producción
    // -----------------------------------------------------------------------

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    public static function checkMocksProduccion(): array
    {
        $mocks = self::mocksActivos();

        if (! self::appEsProduccion()) {
            return self::check('mocks_produccion', 'Sin mocks en producción', true,
                $mocks === []
                    ? 'APP_ENV='.config('app.env').' y ningún mock activo.'
                    : 'APP_ENV='.config('app.env').' (no es producción): mocks activos permitidos ('.implode(', ', $mocks).').');
        }

        $ok = $mocks === [];

        return self::check('mocks_produccion', 'Sin mocks en producción', $ok,
            $ok
                ? 'APP_ENV=production y ningún mock activo.'
                : 'APP_ENV=production con mock(s) activo(s): '.implode(', ', $mocks)
                  .'. Un mock genera sellos y JWS FICTICIOS que en pantalla se ven como reales.');
    }

    /**
     * Nombres de los mocks activos (variable de entorno), para poder nombrarlos en el
     * diagnóstico sin que el llamador tenga que conocer las claves.
     *
     * @return array<int, string>
     */
    public static function mocksActivos(): array
    {
        $mocks = [
            'DTE_FIRMADOR_MOCK' => (bool) config('dte.firma.mock', false),
            'MH_MOCK' => (bool) config('dte.transmision.mock', false),
            'DTE_INVALIDACION_MOCK' => (bool) config('dte.invalidacion.mock', false),
        ];

        return array_keys(array_filter($mocks));
    }

    /** ¿La aplicación corre con APP_ENV=production? */
    public static function appEsProduccion(): bool
    {
        return strtolower((string) config('app.env')) === 'production';
    }

    // -----------------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------------

    private static function codigoAmbiente(): string
    {
        return trim((string) config('dte.ambiente', ''));
    }

    /**
     * Mismo criterio que DteTransmisionAuthService: cualquiera de estos rótulos cuenta
     * como producción. Se duplica a propósito y no se llama al servicio, para que este
     * verificador siga siendo puro (sin dependencias ni construcción de servicios).
     */
    private static function transmisionEsProduccion(): bool
    {
        $amb = strtolower(trim((string) config('dte.transmision.ambiente', 'testing')));

        return in_array($amb, ['produccion', 'production', 'prod', '01'], true);
    }

    /** Empresa emisora de referencia: la activa; si no hay, la primera cargada. */
    private static function empresaEmisora(): ?Empresa
    {
        return Empresa::query()->where('activo', true)->orderBy('id')->first()
            ?? Empresa::query()->orderBy('id')->first();
    }

    private static function soloDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    /** @return array{clave: string, label: string, ok: bool, detalle: string} */
    private static function check(string $clave, string $label, bool $ok, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'ok' => $ok, 'detalle' => $detalle];
    }
}
