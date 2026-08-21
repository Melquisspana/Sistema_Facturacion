<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Resumen\EstadoTarjeta;
use App\Support\Dte\CoherenciaConfiguracionFiscal;

/**
 * Estado del firmador y del certificado, listo para pintarse. SIN RED y SIN
 * SECRETOS, por los mismos motivos que {@see EstadoHaciendaApi}.
 *
 * QUÉ NO PUEDE DECIR ESTA PANTALLA, Y POR QUÉ NO ES UN OLVIDO
 * ------------------------------------------------------------------
 * No muestra la huella SHA-256 del certificado, ni su fecha de emisión, ni su
 * vencimiento. No es que falte implementarlo: **el certificado no vive en esta
 * aplicación**. El archivo `<NIT>.crt` lo custodia el firmador oficial del MH
 * —un servicio Java aparte— y la aplicación nunca lo abre: le manda el NIT y la
 * contraseña, y recibe el documento firmado. Su interfaz son dos rutas
 * (`/firmardocumento` y `/status`) y ninguna devuelve datos del certificado.
 *
 * Para poder calcular la huella y el vencimiento habría que subir el `.crt` a
 * esta aplicación por la web, y eso es exactamente lo que no conviene hacer:
 * pondría el material de firma en un segundo sitio, accesible desde una sesión
 * de navegador, a cambio de tres datos informativos. Lo que sí se puede hacer
 * sin mover el certificado es comprobar que el firmador responde y que procesa
 * una firma —con un documento inventado— y eso es lo que hacen los botones.
 *
 * Lo que sí se comprueba, y es lo que de verdad rompe documentos: que el NIT del
 * certificado coincida con el del emisor. Si divergen, se firma cada documento
 * con el certificado de otro contribuyente.
 */
class EstadoFirmador
{
    /** Clave con la que se guarda la comprobación en el historial. */
    public const CLAVE_VERIFICACION = 'dte.firmador';

    /**
     * @return array{
     *     url: string, timeout: int, nit: string,
     *     firma_habilitada: bool, mock: bool,
     *     password_configurada: bool,
     *     coherencia_nit: array{clave: string, label: string, ok: bool, detalle: string},
     *     estado: EstadoTarjeta, resumen: string,
     *     certificado_nota: string
     * }
     */
    public function paraPantalla(): array
    {
        $habilitada = (bool) config('dte.firma.enabled', false);
        $mock = (bool) config('dte.firma.mock', false);
        $coherencia = CoherenciaConfiguracionFiscal::checkNitFirma();

        [$estado, $resumen] = $this->veredicto($habilitada, $mock, $coherencia['ok']);

        return [
            'url' => (string) config('dte.firmador.url', ''),
            'timeout' => (int) config('dte.firma.timeout', 10),
            'nit' => trim((string) config('dte.firma.nit', '')),
            'firma_habilitada' => $habilitada,
            'mock' => $mock,
            'password_configurada' => filled(config('dte.firma.cert_password')),
            'coherencia_nit' => $coherencia,
            'estado' => $estado,
            'resumen' => $resumen,
            'certificado_nota' => 'El certificado no está en esta aplicación: lo custodia el firmador del Ministerio de Hacienda, que solo recibe el NIT y la contraseña. Por eso aquí no hay huella digital ni fecha de vencimiento, y por eso el archivo .crt no se sube por la web.',
        ];
    }

    /**
     * ¿Se puede pulsar «Probar firma»? La prueba usa un documento INVENTADO, un
     * NIT de relleno y una contraseña falsa, así que no depende de que la firma
     * esté habilitada — solo de que haya una dirección a la que preguntar.
     *
     * @return array{puede: bool, razon: ?string}
     */
    public function pruebaDisponible(): array
    {
        if (blank(config('dte.firmador.url'))) {
            return ['puede' => false, 'razon' => 'No hay una dirección del firmador configurada en el servidor.'];
        }

        return ['puede' => true, 'razon' => null];
    }

    /**
     * @return array{0: EstadoTarjeta, 1: string}
     */
    private function veredicto(bool $habilitada, bool $mock, bool $nitCoherente): array
    {
        if ($habilitada && $mock) {
            return [EstadoTarjeta::Error, 'La firma está habilitada EN MODO SIMULADO: los documentos llevan una firma ficticia que en pantalla se ve como real.'];
        }

        if (! $nitCoherente) {
            return [EstadoTarjeta::Advertencia, 'El NIT del certificado y el del emisor no cuadran. Revisar cuál de los dos está mal.'];
        }

        if (! $habilitada) {
            return [EstadoTarjeta::Desactivado, 'Firma deshabilitada: el sistema genera el documento pero no lo firma.'];
        }

        return [EstadoTarjeta::Activo, 'Firma habilitada con el firmador local del Ministerio de Hacienda.'];
    }
}
