<?php

namespace Tests\Feature\Seguridad;

use App\Enums\AmbienteHacienda;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LA SUITE DA EL MISMO RESULTADO EN CUALQUIER MÁQUINA, Y NO PUEDE HABLAR CON HACIENDA.
 *
 * Las dos cosas se prueban juntas porque son la misma preocupación vista de dos lados:
 * qué entra en la corrida desde fuera (el `.env` de quien la ejecuta) y qué sale de
 * ella (tráfico real). El ensayo de despliegue enseñó que lo primero no estaba
 * cerrado: las direcciones de los servicios del MH se resolvían leyendo el entorno de
 * la máquina, así que una PC con un proxy en `DTE_TRANSMISION_URL` o con un host
 * copiado a mano podía poner en rojo —o en verde— pruebas que no dependen de eso.
 *
 * Lo que se fija acá NO es que las URLs sean correctas según el Ministerio: es que la
 * suite las resuelve SIEMPRE igual, y que ninguna prueba puede llegar a ellas.
 */
class ReproducibilidadSuiteTest extends TestCase
{
    /** Los cuatro servicios del MH, en los dos ambientes. */
    private function endpointsOficiales(): array
    {
        $urls = [];

        foreach ([AmbienteHacienda::Pruebas, AmbienteHacienda::Produccion] as $ambiente) {
            $urls['auth '.$ambiente->value] = EndpointsHacienda::authOficial($ambiente);
            $urls['recepción '.$ambiente->value] = EndpointsHacienda::recepcionOficial($ambiente);
            $urls['consulta '.$ambiente->value] = EndpointsHacienda::consultaOficial($ambiente);
            $urls['anulación '.$ambiente->value] = EndpointsHacienda::anulacionOficial($ambiente);
        }

        return $urls;
    }

    /**
     * La resolución NO depende del `.env` de la máquina: `phpunit.xml` fija las claves
     * que la gobiernan, y el resultado tiene que coincidir con las constantes oficiales
     * incorporadas. Si alguien quita esas claves del XML, esta prueba se pone roja en la
     * primera máquina que tenga un valor distinto en su `.env` — que es exactamente el
     * aviso que faltaba.
     */
    public function test_los_endpoints_se_resuelven_igual_en_cualquier_maquina(): void
    {
        $pruebas = AmbienteHacienda::Pruebas;
        $produccion = AmbienteHacienda::Produccion;

        $this->assertSame(EndpointsHacienda::authOficial($pruebas), EndpointsHacienda::auth($pruebas));
        $this->assertSame(EndpointsHacienda::recepcionOficial($pruebas), EndpointsHacienda::recepcion($pruebas));
        $this->assertSame(EndpointsHacienda::consultaOficial($pruebas), EndpointsHacienda::consulta($pruebas));
        $this->assertSame(EndpointsHacienda::anulacionOficial($pruebas), EndpointsHacienda::anulacion($pruebas));

        $this->assertSame(EndpointsHacienda::authOficial($produccion), EndpointsHacienda::auth($produccion));
        $this->assertSame(EndpointsHacienda::recepcionOficial($produccion), EndpointsHacienda::recepcion($produccion));
        $this->assertSame(EndpointsHacienda::consultaOficial($produccion), EndpointsHacienda::consulta($produccion));
        $this->assertSame(EndpointsHacienda::anulacionOficial($produccion), EndpointsHacienda::anulacion($produccion));

        // Y el host que se sustituiría en AMBOS ambientes a la vez queda sin fijar.
        $this->assertSame('', trim((string) config('dte.transmision.url_base')));
    }

    /**
     * Ninguna prueba puede hablar con el Ministerio. El candado es
     * `Http::preventStrayRequests()` en {@see TestCase::bloquearHttpReal()}: toda
     * petición que no tenga un `Http::fake()` que la cubra revienta en vez de salir.
     */
    public function test_ninguna_peticion_a_hacienda_puede_salir_de_la_suite(): void
    {
        foreach ($this->endpointsOficiales() as $servicio => $url) {
            try {
                Http::get($url);
                $this->fail('La petición a '.$servicio.' ('.$url.') salió de la suite sin que nadie la parara.');
            } catch (StrayRequestException $e) {
                $this->assertStringContainsString($url, $e->getMessage(), $servicio);
            }
        }
    }

    /** El firmador local tampoco: es la otra dirección a la que el sistema llama. */
    public function test_tampoco_se_puede_llamar_al_firmador(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::post((string) config('dte.firmador.url'), []);
    }

    /**
     * El candado anterior solo cubre lo que pasa por el facade `Http`. Esta prueba
     * comprueba que no hay ninguna otra puerta: ni cURL a pelo, ni un cliente Guzzle
     * propio, ni sockets. Es una revisión estática del código de la aplicación, y falla
     * el día que alguien abra una segunda vía que el candado no vería.
     */
    public function test_la_aplicacion_no_tiene_ninguna_otra_via_de_salida_a_la_red(): void
    {
        $prohibidos = ['curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'new GuzzleHttp\\Client'];
        $encontrados = [];

        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($archivos as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = (string) file_get_contents($archivo->getPathname());

            foreach ($prohibidos as $aguja) {
                if (str_contains($codigo, $aguja)) {
                    $encontrados[] = basename($archivo->getPathname()).' → '.$aguja;
                }
            }
        }

        $this->assertSame([], $encontrados, 'Hay salida a la red fuera del facade Http, que es lo único que el candado de la suite puede cerrar.');
    }

    /**
     * Firma, transmisión y producción arrancan CERRADAS, sin importar el `.env` de la
     * máquina. Un test que necesite alguna las abre con `config()->set()`.
     */
    public function test_la_suite_arranca_con_firma_transmision_y_produccion_deshabilitadas(): void
    {
        $this->assertFalse((bool) config('dte.firma.enabled'), 'firma');
        $this->assertFalse((bool) config('dte.transmision.enabled'), 'transmisión');
        $this->assertFalse((bool) config('dte.transmision.test_enabled'), 'transmisión de pruebas');
        $this->assertFalse((bool) config('dte.transmision.allow_production'), 'producción');
        $this->assertFalse((bool) config('dte.transmision.real_confirmation'), 'confirmación real');
        $this->assertTrue((bool) config('dte.transmision.dry_run'), 'dry run');
        $this->assertSame('00', (string) config('dte.ambiente'), 'ambiente CAT-001');
    }

    /**
     * El límite de memoria lo fija `tests/bootstrap.php`, cargado desde `phpunit.xml`.
     * Antes había que acordarse de `php -d memory_limit=...`, y `php artisan test` ni
     * siquiera lo admite: en una máquina con los 128 MB por defecto la suite moría a
     * media corrida con un fatal que no señalaba ninguna regresión.
     */
    public function test_el_limite_de_memoria_de_la_suite_esta_fijado_por_configuracion(): void
    {
        $limite = (string) ini_get('memory_limit');

        $this->assertSame('1G', $limite, 'la suite tiene que fijar su propia memoria, no heredar la del php.ini de la máquina');
    }
}
