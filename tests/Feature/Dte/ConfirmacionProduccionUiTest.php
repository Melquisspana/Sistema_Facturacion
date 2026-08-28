<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\PreflightEmisionProduccion;
use App\Services\Dte\PreflightEmisionProduccionExportacion;
use App\Services\Dte\PreflightEmisionProduccionFactura;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tarjeta «Emitir en producción» (facturacion.show) — UI unificada para FCF (01),
 * CCF (03), NC (05) y FEX (11).
 *
 * Antes había DOS formularios de confirmación de producción: un modal propio que solo
 * veían 01/03/11 (contra `generar-transmitir-produccion`), cuyo resumen leía claves del
 * preflight que Factura y FEX no producen y que en el CCF mostraba el correlativo de un
 * sistema externo; y una segunda copia con su propia frase dentro del panel «Avanzado»
 * (contra `firmar-transmitir`), que era la única vía de la NC 05.
 *
 * Esta suite es de PRESENTACIÓN y CONTROL: no transmite nada y no relaja ningún candado.
 * La orquestación real y las barreras del servidor las siguen cubriendo
 * GenerarTransmitirProduccionTest, EmisionProduccionFacturaFexTest y los tests de
 * preflight; aquí se verifica que la UI llame exactamente a esa misma lógica.
 */
class ConfirmacionProduccionUiTest extends TestCase
{
    use RefreshDatabase;

    /** Los cuatro tipos que deben compartir la misma confirmación. */
    private const TIPOS = [
        TipoDte::Factura,
        TipoDte::CreditoFiscal,
        TipoDte::NotaCredito,
        TipoDte::FacturaExportacion,
    ];

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Mail::fake();
        Storage::fake('local');
        Storage::disk('local')->put('dte/json/x.json', '{"identificacion":{"numeroControl":"X"}}');

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
    }

    // ---------------------------------------------------------------- helpers

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Documento de PRODUCCIÓN (ambiente 01) generado y sin sello, del tipo indicado. */
    private function documento(TipoDte $tipo, string $estado = 'generado'): Dte
    {
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'tipo_dte' => $tipo->value,
            'estado' => $estado,
            'ambiente' => '01',
            'numero_control' => 'DTE-'.$tipo->value.'-M001P002-000000000001094',
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.'])->id,
            'fecha_emision' => '2026-07-20', 'hora_emision' => '10:00:00',
            'total_gravado' => 100, 'iva' => 13, 'total_pagar' => 113,
        ]);
        $dte->forceFill(['json_generado_path' => 'dte/json/x.json'])->save();

        return $dte;
    }

    /**
     * Deja los tres preflights de producción en verde. Solo afecta a los tipos que
     * TIENEN acción de producción (CCF/Factura/FEX): la NC 05 no usa preflight.
     */
    private function preflightsVerdes(): void
    {
        foreach ([PreflightEmisionProduccion::class, PreflightEmisionProduccionFactura::class, PreflightEmisionProduccionExportacion::class] as $clase) {
            $pf = Mockery::mock($clase);
            $pf->shouldReceive('evaluar')->andReturn(['puede' => true, 'checks' => [], 'faltantes' => []]);
            $pf->shouldReceive('resumen')->andReturn([]);
            $this->app->instance($clase, $pf);
        }
    }

    /** Preflights bloqueados, con una razón reconocible del propio sistema. */
    private function preflightsBloqueados(string $razon = 'Worker/cola activo'): void
    {
        foreach ([PreflightEmisionProduccion::class, PreflightEmisionProduccionFactura::class, PreflightEmisionProduccionExportacion::class] as $clase) {
            $pf = Mockery::mock($clase);
            $pf->shouldReceive('evaluar')->andReturn([
                'puede' => false,
                'checks' => [['ok' => false, 'label' => 'Worker de cola', 'detalle' => $razon]],
                'faltantes' => [$razon],
            ]);
            $pf->shouldReceive('resumen')->andReturn([]);
            $this->app->instance($clase, $pf);
        }
    }

    /**
     * Abre TODOS los candados de transmisión real (es la vía por la que emite la NC 05).
     * Son exactamente los que evalúa DteTransmisionService::evaluarCandados(): flags,
     * ambiente, endpoint productivo EXACTO y modo de operación. No se relaja ninguno:
     * se satisfacen todos para poder ver la UI habilitada.
     */
    private function candadosTransmisionAbiertos(): void
    {
        config([
            'dte.transmision.enabled' => true,
            'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false,
            'dte.transmision.allow_production' => true,
            'dte.transmision.ambiente' => 'produccion',
            // El endpoint debe ser el productivo EXACTO o el candado lo bloquea.
            'dte.transmision.url_base' => EndpointsHacienda::HOST_PRODUCCION,
            // En modo paralelo/respaldo la transmisión real queda bloqueada por diseño.
            'dte.transmision.modo_operacion' => 'principal',
            'dte.transmision.sistema_actual_activo' => false,
            'dte.firma.enabled' => true,
            'dte.firma.mock' => false,
            'dte.transmision.mock' => false,
        ]);
    }

    // ------------------------------- 1-4. Los cuatro tipos usan el componente común

    /**
     * FCF, CCF, NC y FEX renderizan la MISMA tarjeta de confirmación, con el mismo
     * título, el mismo resumen y la misma frase.
     */
    public function test_los_cuatro_tipos_usan_la_misma_confirmacion_de_produccion(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $dte = $this->documento($tipo);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte))
                ->assertOk()
                ->assertSee('id="emitir-produccion"', false)
                ->assertSee('Emitir en producción')
                // Mismo resumen para todos, y con los datos de ESTE documento.
                ->assertSee($dte->numero_control)
                ->assertSee($dte->codigo_generacion)
                ->assertSee('Calleja, S.A. de C.V.')
                ->assertSee('20/07/2026')
                ->assertSee('PRODUCCIÓN')
                // Misma advertencia y misma frase.
                ->assertSee('Este documento será firmado y transmitido realmente al Ministerio de Hacienda.')
                ->assertSee('EMITIR PRODUCCION');
        }

        Http::assertNothingSent();
    }

    /**
     * Cada tipo apunta a la ruta que YA le correspondía: los que tienen acción de
     * producción a `generar-transmitir-produccion`, la NC 05 a `firmar-transmitir`.
     * La UI no le inventa a la NC una acción que su policy no le da.
     */
    public function test_cada_tipo_conserva_su_ruta_de_emision(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach ([TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::FacturaExportacion] as $tipo) {
            $dte = $this->documento($tipo);
            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte))
                ->assertOk()
                ->assertSee(route('facturacion.generar-transmitir-produccion', $dte), false);
        }

        // NC 05: no está en DtePolicy::TIPOS_EMISION_PRODUCCION y conserva su vía.
        // Se comprueba que la tarjeta está HABILITADA y que su formulario apunta a
        // `firmar-transmitir`; ver esa ruta a secas no bastaría, porque el panel
        // «Avanzado» también la menciona.
        $nc = $this->documento(TipoDte::NotaCredito);
        $this->actingAs($admin)
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertSee('Emisión real habilitada')
            ->assertSee('name="confirmacion_emision"', false)
            ->assertSee('action="'.route('facturacion.firmar-transmitir', $nc).'"', false)
            ->assertDontSee(route('facturacion.generar-transmitir-produccion', $nc), false);

        Http::assertNothingSent();
    }

    // ------------------------------------------------ 5. Todos exigen la misma frase

    /**
     * La frase es EXACTAMENTE la misma en los cuatro tipos: sin variantes con tilde,
     * sin textos propios por tipo.
     */
    public function test_la_frase_de_confirmacion_es_identica_en_los_cuatro_tipos(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $contenido = $this->actingAs($admin)
                ->get(route('facturacion.show', $this->documento($tipo)))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('name="confirmacion_emision"', $contenido);
            $this->assertStringContainsString('EMITIR PRODUCCION', $contenido);
            // Ninguna variante legacy con tilde ni frase alternativa por tipo.
            $this->assertStringNotContainsString('EMITIR PRODUCCIÓN', $contenido);
        }

        Http::assertNothingSent();
    }

    /** El servidor sigue exigiendo la frase: sin ella no se transmite nada. */
    public function test_sin_la_frase_exacta_no_se_emite(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $ccf = $this->documento(TipoDte::CreditoFiscal);

        foreach ([[], ['confirmacion_emision' => ''], ['confirmacion_emision' => 'emitir produccion']] as $payload) {
            $this->actingAs($this->usuario('administrador'))
                ->post(route('facturacion.generar-transmitir-produccion', $ccf), $payload + ['barrera_conta' => 1])
                ->assertRedirect()
                ->assertSessionHas('error');
        }

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Generado, $ccf->fresh()->estado);
        $this->assertNull($ccf->fresh()->sello_recepcion);
    }

    /** La casilla de revisión sigue siendo obligatoria en la ruta que la exige. */
    public function test_sin_la_casilla_de_revision_no_se_emite(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $ccf = $this->documento(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.generar-transmitir-produccion', $ccf), ['confirmacion_emision' => 'EMITIR PRODUCCION'])
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Generado, $ccf->fresh()->estado);
    }

    // ---------------------------------- 6. Sin referencias a sistemas externos en la UI

    /**
     * La UI operativa de emisión no menciona el sistema de contingencia externo ni su
     * correlativo. Se buscan FRASES, no la subcadena «Conta» a secas: el menú lateral
     * tiene «Contabilidad», que no tiene nada que ver.
     *
     * Nota deliberada: `barrera_conta` sobrevive como NOMBRE del campo del formulario
     * porque es el payload que el controlador ya espera (DteController); renombrarlo
     * cambiaría el contrato de la petición. No es texto visible: por eso la
     * comprobación de lo visible usa assertDontSeeText, que ignora los atributos HTML.
     */
    public function test_la_ui_de_emision_no_menciona_sistemas_externos(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $respuesta = $this->actingAs($admin)
                ->get(route('facturacion.show', $this->documento($tipo)))
                ->assertOk();

            foreach (['Conta Portable', 'Conta:', 'en Conta', 'último correlativo Conta',
                'sistema anterior', 'sistema externo', 'correlativo externo', '1119'] as $frase) {
                $respuesta->assertDontSee($frase, false);
            }

            // Nada de esto en el TEXTO que el usuario lee (ignora atributos como el
            // name del campo del payload).
            $respuesta->assertDontSeeText('Conta Portable');
            $respuesta->assertDontSeeText('P001');
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------- 7-9. Candados y modo seguro

    /**
     * Con el preflight bloqueado no se ofrece un formulario aparentemente utilizable:
     * se muestra el bloqueo y las razones QUE YA DA el sistema, sin inventar ninguna.
     */
    public function test_preflight_bloqueado_muestra_razones_y_no_monta_el_formulario(): void
    {
        Http::fake();
        $this->preflightsBloqueados('Worker/cola activo');
        $this->candadosTransmisionAbiertos();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $this->documento(TipoDte::CreditoFiscal)))
            ->assertOk()
            ->assertSee('Emisión real bloqueada')
            ->assertSee('Worker/cola activo')
            ->assertSee('Botón deshabilitado')
            // Sin formulario: ni frase, ni casilla, ni acción.
            ->assertDontSee('name="confirmacion_emision"', false)
            ->assertDontSee('name="barrera_conta"', false)
            ->assertDontSee('Este documento será firmado y transmitido realmente al Ministerio de Hacienda.');

        Http::assertNothingSent();
    }

    /**
     * Modo seguro (candados de transmisión cerrados): la NC 05 —cuya vía depende de
     * esos candados— muestra el bloqueo con las razones de DteTransmisionService.
     */
    public function test_modo_seguro_bloquea_la_nc_y_muestra_sus_razones(): void
    {
        Http::fake();
        // Sin abrir candados: dry_run activo y transmisión deshabilitada por defecto.
        config(['dte.transmision.enabled' => false, 'dte.transmision.dry_run' => true]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $this->documento(TipoDte::NotaCredito)))
            ->assertOk()
            ->assertSee('Emisión real bloqueada')
            ->assertDontSee('name="confirmacion_emision"', false);

        Http::assertNothingSent();
    }

    /**
     * Un documento de PRUEBAS sigue mostrando la sección: el ambiente es un candado,
     * no un motivo para esconderla. Antes se ocultaba entera y el usuario se quedaba
     * viendo solo el panel «Avanzado», sin ninguna explicación de por qué no podía
     * emitir. Ahora aparece bloqueada y con la razón escrita.
     */
    public function test_documento_de_pruebas_muestra_la_tarjeta_bloqueada_con_su_razon(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $dte = $this->documento(TipoDte::CreditoFiscal);
        // El ambiente es inmutable en un DTE ya creado salvo por esta vía directa.
        Dte::whereKey($dte->id)->update(['ambiente' => '00']);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $dte->fresh()))
            ->assertOk()
            ->assertSee('id="emitir-produccion"', false)
            ->assertSee('Emitir en producción')
            ->assertSee('Emisión real bloqueada')
            ->assertSee('Este documento es de pruebas (ambiente 00)')
            // Bloqueada = sin formulario utilizable.
            ->assertDontSee('name="confirmacion_emision"', false)
            ->assertDontSee('name="barrera_conta"', false);

        Http::assertNothingSent();
    }

    /**
     * La sección existe para TODO documento elegible, esté el entorno abierto o no.
     * Es el caso real de la máquina de trabajo: apitest, dry-run, modo paralelo y
     * firma deshabilitada. Antes, con esa configuración, la ficha solo mostraba
     * «Avanzado · emisión manual paso a paso».
     */
    public function test_en_modo_seguro_la_seccion_sigue_existiendo_para_los_cuatro_tipos(): void
    {
        Http::fake();
        // Configuración equivalente a la del entorno de trabajo: todo cerrado.
        config([
            'dte.transmision.enabled' => false,
            'dte.transmision.real_confirmation' => false,
            'dte.transmision.dry_run' => true,
            'dte.transmision.allow_production' => false,
            'dte.transmision.ambiente' => 'testing',
            'dte.transmision.modo_operacion' => 'paralelo',
            'dte.firma.enabled' => false,
        ]);
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $dte = $this->documento($tipo);
            Dte::whereKey($dte->id)->update(['ambiente' => '00']);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte->fresh()))
                ->assertOk()
                ->assertSee('Emitir en producción')
                ->assertSee('Emisión real bloqueada')
                ->assertDontSee('name="confirmacion_emision"', false);
        }

        Http::assertNothingSent();
    }

    /**
     * Con candados cerrados se muestran las razones REALES del sistema, tal como las
     * produce DteTransmisionService::evaluarCandados(). No se inventa ninguna.
     */
    public function test_las_razones_de_bloqueo_son_las_del_sistema(): void
    {
        Http::fake();
        config([
            'dte.transmision.enabled' => false,
            'dte.transmision.real_confirmation' => false,
            'dte.transmision.dry_run' => true,
            'dte.transmision.modo_operacion' => 'paralelo',
        ]);
        $nc = $this->documento(TipoDte::NotaCredito);

        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertSee('Emisión real bloqueada');

        // Cada razón que devuelve el servicio debe estar en pantalla.
        foreach (app(\App\Services\Dte\DteTransmisionService::class)->evaluarCandados()['razones'] as $razon) {
            $respuesta->assertSee($razon);
        }

        Http::assertNothingSent();
    }

    /**
     * Un documento que ya NO está en el flujo de emisión sí oculta la sección: es el
     * único motivo legítimo para que desaparezca.
     */
    public function test_documento_aceptado_no_muestra_la_tarjeta(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $dte = $this->documento(TipoDte::CreditoFiscal);
        Dte::whereKey($dte->id)->update([
            'estado' => EstadoDte::Aceptado->value,
            'sello_recepcion' => '2026SELLOREALDEHACIENDA0000000000000000',
            'fecha_procesamiento_mh' => now(),
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $dte->fresh()))
            ->assertOk()
            ->assertDontSee('id="emitir-produccion"', false);

        Http::assertNothingSent();
    }

    /**
     * La tarjeta va ANTES del panel «Avanzado»: es la acción principal, no una opción
     * escondida en el acordeón de diagnóstico.
     */
    public function test_la_tarjeta_aparece_antes_del_panel_avanzado(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $contenido = $this->actingAs($admin)
                ->get(route('facturacion.show', $this->documento($tipo)))
                ->assertOk()
                ->getContent();

            $posTarjeta = strpos($contenido, 'id="emitir-produccion"');
            $posAvanzado = strpos($contenido, 'Avanzado · emisión manual paso a paso');

            $this->assertNotFalse($posTarjeta, 'La tarjeta de emisión debe estar presente.');
            $this->assertNotFalse($posAvanzado, 'El panel avanzado debe seguir existiendo.');
            $this->assertLessThan($posAvanzado, $posTarjeta,
                'La tarjeta «Emitir en producción» debe ir antes del panel «Avanzado».');

            // Y también antes del resto de bloques secundarios.
            foreach (['JSON y firma', 'Estado técnico DTE', 'Zona peligrosa'] as $bloque) {
                $pos = strpos($contenido, $bloque);
                if ($pos !== false) {
                    $this->assertLessThan($pos, $posTarjeta, "La tarjeta debe ir antes de «{$bloque}».");
                }
            }
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------ 11. Sin formulario legacy oculto

    /**
     * Una sola implementación operativa: el campo de la frase aparece EXACTAMENTE una
     * vez en la página. Antes había una segunda copia escondida dentro del panel
     * «Avanzado · emisión manual», con su propio aviso y su propio botón rojo.
     */
    public function test_no_queda_un_segundo_formulario_de_emision_escondido(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $tipo) {
            $contenido = $this->actingAs($admin)
                ->get(route('facturacion.show', $this->documento($tipo)))
                ->assertOk()
                ->getContent();

            $this->assertSame(1, substr_count($contenido, 'name="confirmacion_emision"'),
                'El campo de la frase de emisión debe existir una sola vez.');
            $this->assertLessThanOrEqual(1, substr_count($contenido, 'name="barrera_conta"'),
                'La casilla de revisión debe existir a lo sumo una vez.');
            // Y el panel avanzado ya no ofrece su propia emisión real.
            $this->assertStringNotContainsString('EMISIÓN REAL A PRODUCCIÓN HABILITADA', $contenido);
            $this->assertStringNotContainsString('EMITIR A PRODUCCIÓN', $contenido);
        }

        Http::assertNothingSent();
    }

    /**
     * BORRADOR de un tipo con acción de producción, en ambiente de PRUEBAS. Es el caso
     * cotidiano de la máquina de trabajo: el documento todavía no se ha generado y el
     * entorno es 00. El flujo de emisión SÍ aplica —generar, firmar y transmitir es
     * justamente lo que toca—, así que la sección debe verse bloqueada y con su razón,
     * no desaparecer. Antes se ocultaba porque la elegibilidad se leía solo de las dos
     * abilities, y ambas fallan aquí: generarTransmitirProduccion exige ambiente 01 y
     * firmarTransmitir exige el documento ya generado.
     */
    public function test_borrador_de_pruebas_muestra_la_tarjeta_bloqueada(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $admin = $this->usuario('administrador');

        foreach ([TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::FacturaExportacion] as $tipo) {
            $dte = $this->documento($tipo, 'borrador');
            Dte::whereKey($dte->id)->update(['ambiente' => '00']);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte->fresh()))
                ->assertOk()
                ->assertSee('Emitir en producción')
                ->assertSee('Emisión real bloqueada')
                ->assertSee('Este documento es de pruebas (ambiente 00)')
                ->assertDontSee('name="confirmacion_emision"', false);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ Permisos

    /** Sin permiso de emisión no hay tarjeta ni formulario. */
    public function test_sin_permiso_no_hay_tarjeta_de_produccion(): void
    {
        Http::fake();
        $this->preflightsVerdes();
        $this->candadosTransmisionAbiertos();
        $ccf = $this->documento(TipoDte::CreditoFiscal);

        foreach (['jefatura', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturacion.show', $ccf))
                ->assertOk()
                ->assertDontSee('Emitir en producción')
                ->assertDontSee('name="confirmacion_emision"', false);
        }

        Http::assertNothingSent();
    }
}
