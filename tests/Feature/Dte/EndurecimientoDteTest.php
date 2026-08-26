<?php

namespace Tests\Feature\Dte;

use App\Enums\AmbienteHacienda;
use App\Exceptions\Dte\DteTransmisionException;
use App\Services\Dte\DteTransmisionAuthService;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FASE DE ENDURECIMIENTO — lo que ya no puede volver a torcerse.
 *
 * Tres cosas se arreglaron y las tres se pueden romper en silencio, que es la razón
 * de que estén acá y no en la revisión de nadie:
 *
 *  1. LAS URLs SE RESUELVEN EN UN SOLO SITIO. Antes había dos mecanismos y cuatro
 *     copias del mismo host escrito a mano. Cambiar un endpoint y olvidarse de una
 *     copia no daba error: seguía apuntando al sitio viejo.
 *  2. PRODUCCIÓN NO TIENE RESPALDO. Antes, sin DTE_PROD_USER, el login caía a la
 *     credencial legacy y transmitía igual. Un typo no fallaba: emitía.
 *  3. NINGUNA PRUEBA SALE A LA RED. Sin el candado global, un test sin `Http::fake()`
 *     hablaba de verdad con quien tuviera configurado el .env de esa máquina.
 *
 * NADA acá hace una petición real: el candado de {@see TestCase::bloquearHttpReal()}
 * está activo en toda la suite y varias pruebas lo comprueban a propósito.
 */
class EndurecimientoDteTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): DteTransmisionAuthService
    {
        return app(DteTransmisionAuthService::class);
    }

    /**
     * URL que el servicio de autenticación usaría AHORA MISMO, sin hacer HTTP.
     * `diagnostico()` es de solo lectura y ya expone la URL resuelta.
     */
    private function urlAuthResuelta(): string
    {
        return $this->auth()->diagnostico()['url'];
    }

    // ------------------------------------------------------- 1) AUTENTICACIÓN

    public function test_auth_ambiente_00_resuelve_la_url_de_apitest(): void
    {
        config(['dte.transmision.ambiente' => 'testing']);

        $this->assertSame(
            'https://apitest.dtes.mh.gob.sv/seguridad/auth',
            EndpointsHacienda::auth(AmbienteHacienda::Pruebas)
        );
        // Y el servicio que autentica resuelve exactamente lo mismo.
        $this->assertSame('https://apitest.dtes.mh.gob.sv/seguridad/auth', $this->urlAuthResuelta());
    }

    public function test_auth_ambiente_01_resuelve_la_url_de_produccion(): void
    {
        config(['dte.transmision.ambiente' => 'produccion']);

        $this->assertSame(
            'https://api.dtes.mh.gob.sv/seguridad/auth',
            EndpointsHacienda::auth(AmbienteHacienda::Produccion)
        );
        $this->assertSame('https://api.dtes.mh.gob.sv/seguridad/auth', $this->urlAuthResuelta());
    }

    // ----------------------------------------------------------- 2) RECEPCIÓN

    public function test_recepcion_ambiente_00_resuelve_la_url_de_apitest(): void
    {
        $this->assertSame(
            'https://apitest.dtes.mh.gob.sv/fesv/recepciondte',
            EndpointsHacienda::recepcion(AmbienteHacienda::Pruebas)
        );
    }

    public function test_recepcion_ambiente_01_resuelve_la_url_de_produccion(): void
    {
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/recepciondte',
            EndpointsHacienda::recepcion(AmbienteHacienda::Produccion)
        );
    }

    // -------------------------------------------------------- 3) INVALIDACIÓN

    public function test_invalidacion_ambiente_00_resuelve_la_url_de_apitest(): void
    {
        $this->assertSame(
            'https://apitest.dtes.mh.gob.sv/fesv/anulardte',
            EndpointsHacienda::anulacion(AmbienteHacienda::Pruebas)
        );
    }

    public function test_invalidacion_ambiente_01_resuelve_la_url_de_produccion(): void
    {
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/anulardte',
            EndpointsHacienda::anulacion(AmbienteHacienda::Produccion)
        );
    }

    /**
     * Los tres servicios comparten host por ambiente. Es la propiedad que se perdía
     * cuando cada uno traía su propia copia: bastaba con actualizar dos de tres.
     */
    public function test_los_tres_endpoints_comparten_el_host_de_su_ambiente(): void
    {
        foreach ([AmbienteHacienda::Pruebas, AmbienteHacienda::Produccion] as $ambiente) {
            $host = EndpointsHacienda::hostOficial($ambiente);

            foreach ([
                EndpointsHacienda::auth($ambiente),
                EndpointsHacienda::recepcion($ambiente),
                EndpointsHacienda::anulacion($ambiente),
            ] as $url) {
                $this->assertStringStartsWith($host.'/', $url);
                $this->assertStringEndsWith(rtrim($url, '/'), $url, 'Sin barra final: el manual exige la URL exacta.');
            }
        }
    }

    /** Pruebas y producción nunca resuelven a la misma dirección. */
    public function test_los_ambientes_nunca_resuelven_a_la_misma_url(): void
    {
        $this->assertNotSame(
            EndpointsHacienda::auth(AmbienteHacienda::Pruebas),
            EndpointsHacienda::auth(AmbienteHacienda::Produccion)
        );
        $this->assertNotSame(
            EndpointsHacienda::recepcion(AmbienteHacienda::Pruebas),
            EndpointsHacienda::recepcion(AmbienteHacienda::Produccion)
        );
        $this->assertNotSame(
            EndpointsHacienda::anulacion(AmbienteHacienda::Pruebas),
            EndpointsHacienda::anulacion(AmbienteHacienda::Produccion)
        );
    }

    /**
     * Un rótulo desconocido en DTE_TRANSMISION_AMBIENTE cae del lado seguro. Es el
     * caso del dedazo ("produccón", "PROD "): tiene que ir a pruebas, no a producción.
     */
    public function test_un_rotulo_de_ambiente_desconocido_cae_en_pruebas(): void
    {
        foreach (['', 'testing', 'qa', 'produccón', 'PRODUCCION_', null] as $rotulo) {
            $this->assertSame(
                AmbienteHacienda::Pruebas,
                EndpointsHacienda::ambienteDesdeRotulo($rotulo),
                'El rótulo '.var_export($rotulo, true).' no debe contar como producción.'
            );
        }

        // Y los rótulos que SÍ son producción siguen funcionando como antes.
        foreach (['produccion', 'production', 'prod', '01', 'PRODUCCION'] as $rotulo) {
            $this->assertSame(AmbienteHacienda::Produccion, EndpointsHacienda::ambienteDesdeRotulo($rotulo));
        }
    }

    // ------------------------------------------- 4) EL ENDPOINT NUNCA QUEDA VACÍO

    /**
     * El default de config/dte.php dejó de ser cadena vacía. Se mira el TEXTO del
     * archivo porque config() ya devuelve el valor resuelto del .env de la máquina,
     * que puede traer el suyo y taparía el default que se quiere fijar.
     */
    public function test_config_declara_un_default_no_vacio_para_recepcion(): void
    {
        $fuente = (string) file_get_contents(config_path('dte.php'));

        $this->assertStringContainsString(
            "'endpoint_recepcion' => env('DTE_TRANSMISION_ENDPOINT_RECEPCION', '/fesv/recepciondte')",
            $fuente
        );
    }

    /**
     * Y aunque alguien deje la variable vacía en el .env, la resolución no devuelve
     * un host suelto: eso significaba hacer POST contra la raíz del servicio.
     */
    public function test_recepcion_no_queda_vacia_aunque_la_ruta_configurada_este_vacia(): void
    {
        config([
            'dte.transmision.url_base' => '',
            'dte.transmision.endpoint_recepcion' => '',
        ]);

        foreach ([AmbienteHacienda::Pruebas, AmbienteHacienda::Produccion] as $ambiente) {
            $url = EndpointsHacienda::recepcion($ambiente);

            $this->assertNotSame('', $url);
            $this->assertSame(EndpointsHacienda::hostOficial($ambiente).'/fesv/recepciondte', $url);
        }
    }

    /** Lo mismo para auth y anulación: ninguna ruta vacía deja un host pelado. */
    public function test_ninguna_ruta_vacia_deja_un_host_sin_endpoint(): void
    {
        config([
            'dte.transmision.endpoint_auth' => '',
            'dte.transmision.endpoint_recepcion' => '',
            'dte.transmision.endpoint_anulacion' => '',
        ]);

        $ambiente = AmbienteHacienda::Pruebas;
        $host = EndpointsHacienda::hostOficial($ambiente);

        foreach ([
            EndpointsHacienda::auth($ambiente),
            EndpointsHacienda::recepcion($ambiente),
            EndpointsHacienda::anulacion($ambiente),
        ] as $url) {
            $this->assertNotSame($host, $url, 'Quedó el host sin ruta.');
            $this->assertNotSame($host.'/', $url);
            $this->assertStringStartsWith($host.'/', $url);
        }
    }

    // -------------------------------------- 5) OVERRIDES Y REFERENCIA OFICIAL

    /** El override por ambiente sigue mandando (lo usan los tests de invalidación). */
    public function test_el_override_por_ambiente_gana_sobre_el_host_oficial(): void
    {
        config(['dte.ambientes.00.anulacion_url' => 'https://apitest.dtes.mh.gob.sv/fesv/anulardte']);

        $this->assertSame(
            'https://apitest.dtes.mh.gob.sv/fesv/anulardte',
            EndpointsHacienda::anulacion(AmbienteHacienda::Pruebas)
        );
    }

    /**
     * Y por más que un override apunte a otro sitio, la referencia OFICIAL no se
     * mueve. Es contra ella que la invalidación productiva compara antes de enviar:
     * si el resolvedor y la referencia fueran lo mismo, la comprobación no comprobaría
     * nada.
     */
    public function test_la_referencia_oficial_ignora_cualquier_override(): void
    {
        config([
            'dte.transmision.url_base' => 'https://otro-host.example',
            'dte.ambientes.01.anulacion_url' => 'https://otro-host.example/fesv/anulardte',
            'dte.ambientes.01.auth_url' => 'https://otro-host.example/seguridad/auth',
        ]);

        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/anulardte',
            EndpointsHacienda::anulacionOficial(AmbienteHacienda::Produccion)
        );
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/seguridad/auth',
            EndpointsHacienda::authOficial(AmbienteHacienda::Produccion)
        );
        $this->assertSame(
            'https://api.dtes.mh.gob.sv/fesv/recepciondte',
            EndpointsHacienda::recepcionOficial(AmbienteHacienda::Produccion)
        );

        // La resuelta SÍ cambió: por eso la comparación tiene sentido.
        $this->assertNotSame(
            EndpointsHacienda::anulacion(AmbienteHacienda::Produccion),
            EndpointsHacienda::anulacionOficial(AmbienteHacienda::Produccion)
        );
    }

    // ------------------------------------------- 6) PRODUCCIÓN SIN RESPALDO

    /**
     * Producción sin sus credenciales propias: falla explícito y ANTES de cualquier
     * HTTP. `Http::assertNothingSent()` es la mitad importante — el fallo tiene que
     * ocurrir sin haber tocado la red.
     */
    public function test_produccion_sin_credenciales_propias_falla_explicito_y_sin_http(): void
    {
        Http::fake();
        config([
            'dte.transmision.enabled' => true,
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.token' => '',
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
        ]);

        try {
            $this->auth()->obtenerToken();
            $this->fail('Producción autenticó sin DTE_PROD_USER / DTE_PROD_PASSWORD.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('DTE_PROD_USER', $e->getMessage());
            $this->assertStringContainsString('DTE_PROD_PASSWORD', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * EL HALLAZGO CENTRAL. Con las legacy puestas y las propias vacías, antes se
     * autenticaba con la credencial vieja sin decir nada. Ahora no se autentica, y
     * no sale ni un byte a la red.
     */
    public function test_produccion_no_usa_las_credenciales_legacy(): void
    {
        Http::fake();
        config([
            'dte.transmision.enabled' => true,
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.token' => '',
            'dte.transmision.usuario_produccion' => '',
            'dte.transmision.password_produccion' => '',
            // Las LEGACY sí están puestas: antes bastaban para entrar.
            'dte.transmision.usuario_api' => 'usuario_legacy',
            'dte.transmision.password' => 'clave_legacy',
        ]);

        try {
            $this->auth()->obtenerToken();
            $this->fail('Producción cayó de vuelta a las credenciales legacy.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('DTE_PROD_USER', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }

        // El diagnóstico nombra el caso exacto, sin revelar ningún valor.
        $diagnostico = $this->auth()->diagnostico();
        $this->assertSame('legacy', $diagnostico['fuente_credenciales']);
        $this->assertStringNotContainsString('usuario_legacy', json_encode($diagnostico));
        $this->assertStringNotContainsString('clave_legacy', json_encode($diagnostico));
    }

    /** Falta solo la contraseña: mismo trato, mismo mensaje, tampoco hay HTTP. */
    public function test_produccion_con_usuario_pero_sin_password_tambien_falla(): void
    {
        Http::fake();
        config([
            'dte.transmision.enabled' => true,
            'dte.transmision.ambiente' => 'produccion',
            'dte.transmision.token' => '',
            'dte.transmision.usuario_produccion' => 'usuario_ficticio',
            'dte.transmision.password_produccion' => '',
        ]);

        try {
            $this->auth()->obtenerToken();
            $this->fail('Producción autenticó sin contraseña propia.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('DTE_PROD_PASSWORD', $e->getMessage());
            $this->assertStringNotContainsString('usuario_ficticio', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    /** Apitest conserva su comportamiento: tampoco tuvo nunca respaldo. */
    public function test_apitest_sin_credenciales_sigue_fallando_igual_que_antes(): void
    {
        Http::fake();
        config([
            'dte.transmision.enabled' => true,
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => '',
            'dte.transmision.usuario_testing' => '',
            'dte.transmision.password_testing' => '',
        ]);

        try {
            $this->auth()->obtenerToken();
            $this->fail('Apitest autenticó sin credenciales.');
        } catch (DteTransmisionException $e) {
            $this->assertStringContainsString('apitest', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }
    }

    // ---------------------------------------------- 7) NINGUNA PETICIÓN REAL

    /**
     * El candado global de la suite. Sin `Http::fake()`, cualquier petición saliente
     * revienta en vez de salir a la red. Esta prueba no mockea nada a propósito: es
     * la única forma de comprobar que el candado está puesto.
     */
    public function test_una_peticion_sin_fake_revienta_en_vez_de_salir_a_la_red(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.dtes.mh.gob.sv/seguridad/auth');
    }

    /** El candado cubre también al firmador local y a cualquier otro host. */
    public function test_el_candado_cubre_cualquier_host_no_solo_hacienda(): void
    {
        foreach (['http://localhost:8080/firmardocumento/status', 'https://example.com'] as $url) {
            try {
                Http::get($url);
                $this->fail('La petición a '.$url.' no fue bloqueada.');
            } catch (StrayRequestException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Con `Http::fake()` todo sigue funcionando igual: el candado no estorba. */
    public function test_con_http_fake_las_peticiones_siguen_funcionando(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK'], 200)]);

        $respuesta = Http::get('https://apitest.dtes.mh.gob.sv/seguridad/auth');

        $this->assertSame(200, $respuesta->status());
        $this->assertSame('OK', $respuesta->json('status'));
        Http::assertSentCount(1);
    }
}
