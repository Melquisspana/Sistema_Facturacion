<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Resumen\EstadoTarjeta;
use App\Enums\AmbienteHacienda;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CoherenciaConfiguracionFiscal;
use App\Support\Dte\EndpointsHacienda;

/**
 * Estado de la conexión con el Ministerio de Hacienda, listo para pintarse.
 *
 * SIN RED. Este objeto no inicia sesión, no hace ping y no transmite: responde
 * «qué hay configurado», que no es lo mismo que «funciona» y por eso la pantalla
 * lo dice con esas palabras. Comprobar de verdad es una ACCIÓN, tiene su botón y
 * queda registrada en el historial de verificaciones.
 *
 * SIN SECRETOS. De las credenciales solo salen dos cosas: si están, y de qué
 * juego de variables salen. Lo segundo importa más de lo que parece. Hubo un
 * respaldo silencioso —producción caía a las credenciales antiguas si no se
 * definían las nuevas—, así que un DTE_PROD_USER mal escrito no fallaba:
 * transmitía con la credencial vieja. Ese respaldo se eliminó; ahora producción
 * sin sus credenciales propias falla, y esta pantalla lo pinta como Error
 * (fuente 'legacy') en vez de dejarlo pasar como advertencia.
 *
 * LAS DOS PREGUNTAS QUE ESTA PANTALLA TIENE QUE SEPARAR
 * ------------------------------------------------------------------
 *   - qué ambiente viaja DENTRO del documento (`dte.ambiente`, 00/01);
 *   - contra qué cuenta del MH se autentica (`dte.transmision.ambiente`).
 *
 * Son dos ajustes distintos y nada los ata. Cruzados dan los dos peores casos
 * posibles: un documento marcado como producción enviado con credenciales de
 * pruebas, o uno de pruebas enviado a la cuenta real.
 */
class EstadoHaciendaApi
{
    /** Clave con la que se guarda la comprobación en el historial. */
    public const CLAVE_VERIFICACION = 'dte.hacienda.auth';

    public function __construct(private readonly DteTransmisionAuthService $auth) {}

    /**
     * @return array{
     *     ambiente_documento: string, ambiente_documento_etiqueta: string,
     *     ambiente_credenciales: string, es_produccion: bool,
     *     coherencia: array{clave: string, label: string, ok: bool, detalle: string},
     *     url_auth: string, url_recepcion: string,
     *     transmision_habilitada: bool,
     *     credenciales_configuradas: bool, fuente_credenciales: string, fuente_detalle: string,
     *     token_manual: bool, token_cacheado: bool, vigencia_horas: int,
     *     estado: EstadoTarjeta, resumen: string
     * }
     */
    public function paraPantalla(): array
    {
        $diagnostico = $this->auth->diagnostico();
        $ambiente = trim((string) config('dte.ambiente', ''));
        $coherencia = CoherenciaConfiguracionFiscal::checkAmbientes();

        [$estado, $resumen] = $this->veredicto($diagnostico['fuente_credenciales'], $coherencia['ok']);

        return [
            'ambiente_documento' => $ambiente,
            'ambiente_documento_etiqueta' => AmbienteHacienda::tryFrom($ambiente)?->label() ?? 'valor fuera de CAT-001',
            'ambiente_credenciales' => $diagnostico['ambiente'],
            'es_produccion' => $diagnostico['ambiente'] === 'produccion',
            'coherencia' => $coherencia,
            'url_auth' => $diagnostico['url'],
            'url_recepcion' => $this->urlRecepcion(),
            'transmision_habilitada' => $diagnostico['habilitada'],
            'credenciales_configuradas' => $diagnostico['usuario_configurado'] && $diagnostico['password_configurado'],
            'fuente_credenciales' => $diagnostico['fuente_credenciales'],
            'fuente_detalle' => $diagnostico['fuente_credenciales_detalle'],
            'token_manual' => $diagnostico['token_manual_configurado'],
            'token_cacheado' => $diagnostico['token_cacheado'],
            'vigencia_horas' => $diagnostico['vigencia_horas'],
            'estado' => $estado,
            'resumen' => $resumen,
        ];
    }

    /**
     * ¿Se puede pulsar «Probar conexión»? Y si no, POR QUÉ, con las mismas
     * palabras que usará el resultado si alguien lo intenta igual.
     *
     * La comprobación de verdad la hace {@see PruebaConexionHacienda}, que vuelve
     * a mirar todo esto; aquí se resuelve solo para poder desactivar el botón y
     * explicar el motivo antes de pulsarlo, en vez de después.
     *
     * @return array{puede: bool, razon: ?string}
     */
    public function pruebaDisponible(): array
    {
        if ($this->esProduccion()) {
            return ['puede' => false, 'razon' => 'El ambiente de credenciales es PRODUCCIÓN. La prueba desde esta pantalla solo se hace contra el ambiente de pruebas del Ministerio de Hacienda.'];
        }

        if (! (bool) config('dte.transmision.auth_test_real_enabled', false)) {
            return ['puede' => false, 'razon' => 'La prueba de acceso a pruebas está cerrada en el servidor (DTE_AUTH_TEST_REAL_ENABLED). Es un candado, no un error.'];
        }

        if ($this->auth->fuenteCredenciales() !== 'testing') {
            return ['puede' => false, 'razon' => 'Faltan las credenciales del ambiente de pruebas. Se configuran en el servidor (DTE_TEST_USER y DTE_TEST_PASSWORD).'];
        }

        if (! str_starts_with($this->auth->diagnostico()['url'], EndpointsHacienda::HOST_PRUEBAS)) {
            return ['puede' => false, 'razon' => 'La dirección de autenticación configurada no es la del ambiente de pruebas del Ministerio de Hacienda.'];
        }

        return ['puede' => true, 'razon' => null];
    }

    // ---------------------------------------------------------------- interno

    /**
     * Color y frase de la tarjeta. Se mira la FUENTE de las credenciales y no si
     * «hay usuario»: «hay usuario pero no contraseña» y «producción está usando
     * las credenciales antiguas» son estados distintos y ninguno es un simple sí
     * o no.
     *
     * @return array{0: EstadoTarjeta, 1: string}
     */
    private function veredicto(string $fuente, bool $coherente): array
    {
        if (! $coherente) {
            return [EstadoTarjeta::Error, 'El ambiente del documento y el de las credenciales no concuerdan.'];
        }

        return match ($fuente) {
            'ninguna' => [EstadoTarjeta::NoConfigurado, 'No hay credenciales configuradas para el ambiente activo.'],
            'parcial' => [EstadoTarjeta::Error, 'Configuración incompleta: hay usuario o contraseña, pero no las dos.'],
            'legacy' => [EstadoTarjeta::Error, 'Producción no tiene sus credenciales propias (DTE_PROD_USER / DTE_PROD_PASSWORD). Las antiguas ya no sirven de respaldo.'],
            'prod' => [EstadoTarjeta::Configurado, 'Credenciales de producción configuradas con sus propias variables.'],
            default => [EstadoTarjeta::Configurado, 'Credenciales del ambiente de pruebas configuradas.'],
        };
    }

    /**
     * Dirección EFECTIVA de recepción. Ya no se recalcula aquí: sale de
     * {@see EndpointsHacienda}, la misma fuente que usa {@see DteTransmisionService}
     * para transmitir. Antes eran dos copias del mismo cálculo, y una pantalla que
     * muestra una dirección distinta de la que se usa es peor que no mostrar ninguna.
     */
    private function urlRecepcion(): string
    {
        return EndpointsHacienda::recepcion(EndpointsHacienda::ambienteTransmision());
    }

    /** Mismo criterio que el servicio de autenticación: la resolución es una sola. */
    private function esProduccion(): bool
    {
        return EndpointsHacienda::ambienteTransmision()->esProduccion();
    }
}
