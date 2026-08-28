<?php

namespace Tests\Feature\Dte;

use App\Enums\AmbienteHacienda;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Models\Dte;
use App\Services\Dte\DteConsultaService;
use App\Services\Dte\DteTransmisionAuthService;
use App\Services\Dte\DteTransmisionService;
use App\Support\Dte\CandadoEndpointOficial;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CANDADO DEL ENDPOINT OFICIAL — la misma regla, comprobada en los servicios del MH que
 * la aplican en línea: autenticación, recepción y consulta. (La invalidación tiene la
 * suya en {@see DteInvalidacionProduccionTest}, que ya cuenta con sus helpers de evento
 * y documento aceptado.)
 *
 * LO QUE SE FIJA AQUÍ. El aviso del Ministerio de Hacienda es que solo se consuman los
 * endpoints publicados, y eso no distingue ambientes: un envío a apitest sale de la
 * máquina y lleva credenciales y token reales igual que uno a producción. Así que las
 * dos URL oficiales se aceptan, y CUALQUIER otra cosa se bloquea —incluido el host de
 * pruebas cuando el ambiente es producción, y al revés—.
 *
 * Y SE BLOQUEA ANTES DE GASTAR NADA: ni petición de token, ni credenciales en vuelo, ni
 * un solo HTTP. Cada prueba lo comprueba con `Http::assertNothingSent()`, y las de
 * consulta además cuentan las llamadas al servicio de autenticación.
 *
 * NINGUNA PETICIÓN SALE A LA RED: el candado global de {@see TestCase}
 * (Http::preventStrayRequests) lo garantiza.
 */
class EndpointOficialHaciendaTest extends TestCase
{
    /**
     * Hosts que NO son el oficial del ambiente, incluido el clásico subdominio que
     * "empieza igual" —el que dejaba pasar el viejo str_starts_with de urlEsTesting()—.
     *
     * @return array<int, string>
     */
    private function hostsEnganosos(AmbienteHacienda $ambiente): array
    {
        $oficial = EndpointsHacienda::hostOficial($ambiente);
        $otro = EndpointsHacienda::hostOficial(
            $ambiente->esProduccion() ? AmbienteHacienda::Pruebas : AmbienteHacienda::Produccion
        );

        return [
            $oficial.'.impostor.test',              // empieza por el host oficial, es otro dominio
            $oficial.':8443',                       // puerto
            str_replace('https://', 'http://', $oficial), // esquema
            str_replace('.dtes.', '-dtes.', $oficial),    // parecido
            $otro,                                  // el host del OTRO ambiente
        ];
    }

    /** Deja la transmisión habilitada con credenciales ficticias (nunca reales). */
    private function habilitar(AmbienteHacienda $ambiente): void
    {
        Cache::flush();
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', true);
        config()->set('dte.transmision.ambiente', $ambiente->esProduccion() ? 'produccion' : 'testing');
        config()->set('dte.transmision.endpoint_auth', '');
        config()->set('dte.transmision.endpoint_recepcion', '');
        config()->set('dte.transmision.endpoint_consulta', '');
        // Credenciales ficticias en los dos ambientes: lo que se prueba es que NO salgan.
        config()->set('dte.transmision.usuario_testing', 'usuario-ficticio');
        config()->set('dte.transmision.password_testing', 'clave-ficticia');
        config()->set('dte.transmision.usuario_produccion', 'usuario-ficticio');
        config()->set('dte.transmision.password_produccion', 'clave-ficticia');
        config()->set('dte.transmision.token', '');   // sin override: fuerza el login
    }

    private function ambientes(): array
    {
        return [AmbienteHacienda::Pruebas, AmbienteHacienda::Produccion];
    }

    // ------------------------------------------------------------------ AUTENTICACIÓN

    /** El login procede contra la URL oficial de CADA ambiente. */
    public function test_auth_procede_con_el_endpoint_oficial_de_cada_ambiente(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            $this->habilitar($ambiente);
            config()->set('dte.transmision.url_base', EndpointsHacienda::hostOficial($ambiente));
            Http::fake(['*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer T']], 200)]);

            $token = app(DteTransmisionAuthService::class)->obtenerToken();

            $this->assertSame('Bearer T', $token);
            Http::assertSent(fn ($request) => $request->url()
                === EndpointsHacienda::authOficial($ambiente));
        }
    }

    /**
     * Host engañoso: el login se corta ANTES de mandar usuario y contraseña. Este es el
     * caso que el viejo `str_starts_with` de urlEsTesting() dejaba pasar.
     */
    public function test_auth_bloquea_todo_host_enganoso_sin_enviar_credenciales(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            foreach ($this->hostsEnganosos($ambiente) as $host) {
                $this->habilitar($ambiente);
                config()->set('dte.transmision.url_base', $host);
                Http::fake();

                try {
                    app(DteTransmisionAuthService::class)->obtenerToken();
                    $this->fail("El login debe bloquearse con el host {$host} ({$ambiente->value}).");
                } catch (DteTransmisionDeshabilitadaException $e) {
                    $this->assertStringContainsString('autenticación', $e->getMessage());
                }

                Http::assertNothingSent();
            }
        }
    }

    /** Ruta alterada sobre el host oficial: mismo bloqueo. */
    public function test_auth_bloquea_rutas_alteradas(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            foreach (['/seguridad/auth/extra', '/seguridad/auth?x=1', '/fesv/recepciondte'] as $ruta) {
                $this->habilitar($ambiente);
                config()->set('dte.transmision.url_base', EndpointsHacienda::hostOficial($ambiente));
                config()->set('dte.transmision.endpoint_auth', $ruta);
                Http::fake();

                try {
                    app(DteTransmisionAuthService::class)->obtenerToken();
                    $this->fail("El login debe bloquearse con la ruta {$ruta}.");
                } catch (DteTransmisionDeshabilitadaException $e) {
                    $this->assertStringContainsString('autenticación', $e->getMessage());
                }

                Http::assertNothingSent();
            }
        }
    }

    /**
     * `urlEsTesting()` (la puerta de `dte:auth-test`) ya no se conforma con que la URL
     * EMPIECE por el host de apitest.
     */
    public function test_la_puerta_de_auth_test_no_acepta_un_subdominio_que_empieza_igual(): void
    {
        $this->habilitar(AmbienteHacienda::Pruebas);
        config()->set('dte.transmision.auth_test_real_enabled', true);
        config()->set('dte.transmision.url_base', EndpointsHacienda::HOST_PRUEBAS.'.impostor.test');
        Http::fake();

        $r = app(DteTransmisionAuthService::class)->pruebaAuthTesting();

        $this->assertTrue($r['bloqueado']);
        $this->assertStringContainsString('no es el ambiente de pruebas', (string) $r['razon']);
        $this->assertFalse($r['token_obtenido']);
        Http::assertNothingSent();
    }

    // ----------------------------------------------------------------------- RECEPCIÓN

    /** Recepción acepta la URL oficial de cada ambiente y ninguna otra. */
    public function test_recepcion_exige_su_endpoint_oficial_en_los_dos_ambientes(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            $this->habilitar($ambiente);
            config()->set('dte.transmision.url_base', EndpointsHacienda::hostOficial($ambiente));
            Http::fake();

            $candados = app(DteTransmisionService::class)->evaluarCandados();
            $this->assertStringNotContainsString('endpoint de recepción', implode(' ', $candados['razones']));

            foreach ($this->hostsEnganosos($ambiente) as $host) {
                config()->set('dte.transmision.url_base', $host);

                $candados = app(DteTransmisionService::class)->evaluarCandados();

                $this->assertTrue($candados['bloqueado'], "Debe bloquear {$host}");
                $this->assertStringContainsString('endpoint de recepción', implode(' ', $candados['razones']));
            }

            Http::assertNothingSent();
        }
    }

    /** Y una ruta alterada sobre el host bueno tampoco pasa. */
    public function test_recepcion_bloquea_rutas_alteradas(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            foreach (['/fesv/recepciondte/v2', '/fesv/recepciondte?forzar=1', '/fesv/recepcionlote'] as $ruta) {
                $this->habilitar($ambiente);
                config()->set('dte.transmision.url_base', EndpointsHacienda::hostOficial($ambiente));
                config()->set('dte.transmision.endpoint_recepcion', $ruta);
                Http::fake();

                $candados = app(DteTransmisionService::class)->evaluarCandados();

                $this->assertTrue($candados['bloqueado'], "Debe bloquear la ruta {$ruta}");
                $this->assertStringContainsString('endpoint de recepción', implode(' ', $candados['razones']));
                Http::assertNothingSent();
            }
        }
    }

    // ------------------------------------------------------------------------ CONSULTA

    /**
     * La consulta se corta antes incluso de mirar el documento: se le pasa un DTE vacío
     * —sin NIT ni código de generación, lo que normalmente daría un error de datos— y lo
     * que salta es el candado del endpoint. Ese orden es la prueba de que la validación
     * ocurre antes de armar nada y antes de pedir el token.
     */
    public function test_consulta_bloquea_hosts_enganosos_antes_de_mirar_el_documento(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            foreach ($this->hostsEnganosos($ambiente) as $host) {
                $this->habilitar($ambiente);
                config()->set('dte.transmision.url_base', $host);
                Http::fake();

                try {
                    app(DteConsultaService::class)->consultar(new Dte());
                    $this->fail("La consulta debe bloquearse con el host {$host}.");
                } catch (DteTransmisionDeshabilitadaException $e) {
                    $this->assertStringContainsString('endpoint de consulta', $e->getMessage());
                }

                Http::assertNothingSent();
            }
        }
    }

    /** Ruta alterada de consulta: mismo bloqueo, en los dos ambientes. */
    public function test_consulta_bloquea_rutas_alteradas(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            foreach ([
                '/fesv/recepcion/consultadte?x=1',
                '/fesv/recepcion/consultadte#frag',
                '/fesv/recepcion/consultadtelote',
            ] as $ruta) {
                $this->habilitar($ambiente);
                config()->set('dte.transmision.url_base', EndpointsHacienda::hostOficial($ambiente));
                config()->set('dte.transmision.endpoint_consulta', $ruta);
                Http::fake();

                try {
                    app(DteConsultaService::class)->consultar(new Dte());
                    $this->fail("La consulta debe bloquearse con la ruta {$ruta}.");
                } catch (DteTransmisionDeshabilitadaException $e) {
                    $this->assertStringContainsString('endpoint de consulta', $e->getMessage());
                }

                Http::assertNothingSent();
            }
        }
    }

    // -------------------------------------------------------------- EL CANDADO EN SÍ

    /** La comparación es de igualdad exacta: la URL oficial pasa, sus variantes no. */
    public function test_el_candado_solo_acepta_la_cadena_exacta(): void
    {
        foreach ($this->ambientes() as $ambiente) {
            $oficial = EndpointsHacienda::consultaOficial($ambiente);

            $this->assertNull(CandadoEndpointOficial::razon(
                $ambiente, CandadoEndpointOficial::CONSULTA, $oficial, $oficial
            ));

            foreach ([
                $oficial.'/',
                $oficial.'?x=1',
                $oficial.'#frag',
                strtoupper($oficial),
                ' '.$oficial,
                str_replace('https://', 'https://user@', $oficial),
            ] as $variante) {
                $this->assertNotNull(
                    CandadoEndpointOficial::razon($ambiente, CandadoEndpointOficial::CONSULTA, $oficial, $variante),
                    "No debe aceptarse: {$variante}"
                );
            }
        }
    }

    /** El mensaje nombra el ambiente correcto, para que el operador sepa qué corregir. */
    public function test_el_mensaje_distingue_produccion_de_apitest(): void
    {
        $razonProd = CandadoEndpointOficial::razon(
            AmbienteHacienda::Produccion, CandadoEndpointOficial::RECEPCION,
            EndpointsHacienda::recepcionOficial(AmbienteHacienda::Produccion), 'https://otro.test'
        );
        $razonTest = CandadoEndpointOficial::razon(
            AmbienteHacienda::Pruebas, CandadoEndpointOficial::RECEPCION,
            EndpointsHacienda::recepcionOficial(AmbienteHacienda::Pruebas), 'https://otro.test'
        );

        $this->assertStringContainsString('productivo exacto', (string) $razonProd);
        $this->assertStringContainsString('de apitest exacto', (string) $razonTest);
    }
}
