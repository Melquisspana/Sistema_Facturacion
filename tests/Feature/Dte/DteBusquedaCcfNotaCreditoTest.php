<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\BusquedaCcfParaNotaCredito;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * BUSCADOR del CCF relacionado al crear una Nota de Crédito. Sustituye al `<select>` que
 * embebía cientos de documentos en el HTML: ahora el autocomplete consulta al servidor.
 *
 * Lo que estas pruebas fijan:
 *  - el universo ofrecido NO se amplía (mismo `aceptadoRealMh()` de siempre);
 *  - un correlativo repetido en P001 y P002 devuelve LOS DOS (a diferencia de PPQ, que
 *    prioriza P002; acá esconder el P001 dejaría CCF históricos inacreditables);
 *  - el id elegido llega intacto al POST, y el backend sigue validándolo por su cuenta;
 *  - el cálculo de la NC (IVA, descuento, retención) queda idéntico.
 *
 * Nada de esto emite, firma ni transmite.
 */
class DteBusquedaCcfNotaCreditoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $p001;

    private PuntoVenta $p002;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $this->estab = $estab;
        $this->p001 = $pv;
        // Segundo punto de venta REAL de la serie: el correlativo se repite entre P001 y
        // P002, y esa es justo la ambigüedad que el buscador debe mostrar sin esconder nada.
        $this->p002 = PuntoVenta::create([
            'establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true,
        ]);

        foreach (['03', '05'] as $tipo) {
            foreach ([$this->p001, $this->p002] as $punto) {
                Correlativo::create([
                    'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $punto->id,
                    'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
                ]);
            }
        }
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /**
     * CCF con datos fiscales controlados. Se crea directo con el modelo (no por el flujo de
     * borrador) porque estas pruebas necesitan fijar el número de control al dígito para
     * comprobar la coincidencia exacta del correlativo.
     */
    private function ccf(int $correlativo, array $extra = []): Dte
    {
        $punto = $extra['punto_venta'] ?? $this->p001;
        unset($extra['punto_venta']);

        $serie = $this->estab->codigo.$punto->codigo;
        $secuencia = str_pad((string) $correlativo, 15, '0', STR_PAD_LEFT);

        return Dte::create(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $punto->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'numero_control' => "DTE-03-{$serie}-{$secuencia}",
            'numero_interno' => "INT-03-00-{$serie}-{$secuencia}",
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            // Aceptación REAL del MH: sello no-mock + huella de procesamiento.
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20',
            'hora_emision' => '10:00:00',
            'total_pagar' => 203.07,
        ], $extra));
    }

    /** @return array<int, int> ids devueltos por el endpoint, en orden */
    private function buscar(string $q, ?User $usuario = null): array
    {
        $r = $this->actingAs($usuario ?? $this->usuario())
            ->getJson(route('facturacion.nota-credito.buscar-ccf', ['q' => $q]));

        $r->assertOk()->assertJsonPath('ok', true);

        return array_column($r->json('resultados'), 'id');
    }

    // ---------- Búsqueda por cada campo ----------

    public function test_busca_por_correlativo(): void
    {
        $ccf = $this->ccf(1120);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar('1120'));
        // Los ceros de relleno son indiferentes: es el mismo correlativo.
        $this->assertSame([$ccf->id], $this->buscar('0001120'));
    }

    public function test_el_correlativo_no_arrastra_numeros_que_solo_lo_contienen(): void
    {
        $exacto = $this->ccf(986);
        $largo = $this->ccf(100986);

        // `986` es coincidencia EXACTA del correlativo: no debe traer el 100986.
        $this->assertSame([$exacto->id], $this->buscar('986'));
        $this->assertSame([$largo->id], $this->buscar('100986'));
    }

    public function test_busca_por_numero_de_control(): void
    {
        $ccf = $this->ccf(1120);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar($ccf->numero_control));
        $this->assertContains($ccf->id, $this->buscar('M001P001-000000000001120'));
    }

    public function test_busca_por_numero_interno(): void
    {
        $ccf = $this->ccf(1120);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar($ccf->numero_interno));
    }

    public function test_busca_por_orden_de_compra(): void
    {
        $ccf = $this->ccf(1120, ['numero_orden_compra' => '26070205005054']);
        $this->ccf(999, ['numero_orden_compra' => '99999999999999']);

        $this->assertSame([$ccf->id], $this->buscar('26070205005054'));
        $this->assertSame([$ccf->id], $this->buscar('260702'));
    }

    public function test_busca_por_cliente(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Súper Selectos, S.A. de C.V.',
            'nombre_comercial' => 'Multiplaza',
        ]);
        $ccf = $this->ccf(1120, ['cliente_id' => $cliente->id]);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar('Selectos'));
        $this->assertSame([$ccf->id], $this->buscar('Multiplaza'));
    }

    public function test_busca_por_sala(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id, 'nombre' => 'Sala Metrocentro', 'codigo' => 'SM07',
        ]);
        $ccf = $this->ccf(1120, ['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id]);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar('Metrocentro'));
        $this->assertSame([$ccf->id], $this->buscar('SM07'));
    }

    public function test_busca_por_codigo_de_generacion_y_sello(): void
    {
        $ccf = $this->ccf(1120);
        $this->ccf(999);

        $this->assertSame([$ccf->id], $this->buscar((string) $ccf->codigo_generacion));
        $this->assertSame([$ccf->id], $this->buscar((string) $ccf->sello_recepcion));
    }

    // ---------- P001 / P002 ----------

    public function test_un_correlativo_repetido_devuelve_p001_y_p002(): void
    {
        $enP001 = $this->ccf(1120, ['punto_venta' => $this->p001]);
        $enP002 = $this->ccf(1120, ['punto_venta' => $this->p002]);

        $ids = $this->buscar('1120');

        // A diferencia de PPQ, acá NO se esconde el P001: una NC puede tener que acreditar
        // el CCF histórico, así que los dos deben poder elegirse.
        $this->assertCount(2, $ids);
        $this->assertContains($enP001->id, $ids);
        $this->assertContains($enP002->id, $ids);
    }

    public function test_cada_resultado_trae_la_serie_para_distinguir_p001_de_p002(): void
    {
        $this->ccf(1120, ['punto_venta' => $this->p001]);
        $this->ccf(1120, ['punto_venta' => $this->p002]);

        $r = $this->actingAs($this->usuario())
            ->getJson(route('facturacion.nota-credito.buscar-ccf', ['q' => '1120']));

        $series = array_column($r->json('resultados'), 'serie');
        sort($series);
        $this->assertSame(['M001/P001', 'M001/P002'], $series);

        // Y el resto del contexto que hace falta para no confundir documentos.
        foreach ($r->json('resultados') as $fila) {
            foreach (['correlativo', 'numero_control', 'cliente_nombre', 'fecha', 'total', 'punto_venta'] as $clave) {
                $this->assertArrayHasKey($clave, $fila);
                $this->assertNotNull($fila[$clave], "Falta {$clave} en el resultado.");
            }
        }
        $this->assertSame('1120', $r->json('resultados.0.correlativo'));
    }

    // ---------- Universo ofrecido: NO se amplía ----------

    public function test_solo_ofrece_ccf_con_aceptacion_real_de_hacienda(): void
    {
        $valido = $this->ccf(1120);

        // Cada uno de estos NO debe aparecer, por el mismo criterio que ya usaba el select.
        $this->ccf(1121, ['tipo_dte' => TipoDte::Factura->value]);                 // no es CCF
        $this->ccf(1122, ['tipo_dte' => TipoDte::NotaCredito->value]);             // es una NC
        $this->ccf(1123, ['estado' => EstadoDte::Borrador->value]);                // sin emitir
        $this->ccf(1124, ['estado' => EstadoDte::Rechazado->value]);               // rechazado
        $this->ccf(1125, ['sello_recepcion' => 'MOCK-1234567890']);                // aceptación simulada
        $this->ccf(1126, ['fecha_procesamiento_mh' => null]);                      // sin huella del MH
        $this->ccf(1127, ['sello_recepcion' => '']);                               // sin sello

        $this->assertSame([$valido->id], $this->buscar(''));

        // Tampoco aparecen buscándolos por su propio correlativo.
        foreach (range(1121, 1127) as $correlativo) {
            $this->assertSame([], $this->buscar((string) $correlativo), "El correlativo {$correlativo} no debía ofrecerse.");
        }
    }

    public function test_limita_los_resultados_a_una_pagina(): void
    {
        foreach (range(1, 30) as $n) {
            $this->ccf($n);
        }

        // El buscador ya no devuelve un tope plano sino UNA PÁGINA: el resto se alcanza
        // avanzando, no ampliando la respuesta. Lo que se protege es que la respuesta
        // nunca crezca con el histórico del cliente.
        $this->assertCount(BusquedaCcfParaNotaCredito::POR_PAGINA, $this->buscar(''));
        $this->assertSame(10, BusquedaCcfParaNotaCredito::POR_PAGINA);
    }

    public function test_la_paginacion_avanza_sin_repetir_ni_saltear_documentos(): void
    {
        foreach (range(1, 25) as $n) {
            $this->ccf($n);
        }

        $usuario = $this->usuario();
        $vistos = [];

        foreach ([1, 2, 3] as $pagina) {
            $r = $this->actingAs($usuario)->getJson(
                route('facturacion.nota-credito.buscar-ccf', ['pagina' => $pagina])
            )->assertOk();

            $this->assertSame($pagina, $r->json('pagina'));
            $this->assertSame($pagina > 1, $r->json('hay_previa'));
            $vistos = array_merge($vistos, array_column($r->json('resultados'), 'id'));
        }

        // Tres páginas de 10 sobre 25 documentos: 25 distintos y ni uno repetido.
        $this->assertCount(25, $vistos);
        $this->assertSame(25, count(array_unique($vistos)));

        // Y la última página se anuncia como última.
        $this->assertFalse(
            $this->actingAs($usuario)
                ->getJson(route('facturacion.nota-credito.buscar-ccf', ['pagina' => 3]))
                ->json('hay_mas')
        );
    }

    public function test_cada_resultado_trae_lo_que_la_pantalla_debe_mostrar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
        $this->ccf(1120, [
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'numero_orden_compra' => 'OC-55210',
        ]);

        $fila = $this->actingAs($this->usuario())
            ->getJson(route('facturacion.nota-credito.buscar-ccf', ['q' => '1120']))
            ->assertOk()->json('resultados.0');

        // Los seis datos que negocio pidió ver en cada resultado.
        $this->assertNotNull($fila['numero']);
        $this->assertNotNull($fila['numero_control']);
        $this->assertSame('OC-55210', $fila['orden_compra']);
        $this->assertSame($sala->nombre, $fila['sala']);
        $this->assertSame('20/07/2026', $fila['fecha']);
        $this->assertSame('203.07', $fila['total']);
    }

    // ---------- Elegibilidad del CCF ----------

    public function test_no_se_ofrece_un_ccf_de_otro_ambiente(): void
    {
        $deEsteAmbiente = $this->ccf(1120);
        $dePruebasAjeno = $this->ccf(1121, ['ambiente' => '01']);

        $ids = $this->buscar('');

        $this->assertContains($deEsteAmbiente->id, $ids);
        $this->assertNotContains(
            $dePruebasAjeno->id,
            $ids,
            'Un CCF de otro ambiente no puede acreditarse: en producción esto sería un documento de pruebas.'
        );
    }

    public function test_no_se_ofrece_un_ccf_con_invalidacion_sellada_ni_archivado(): void
    {
        $vigente = $this->ccf(1120);
        $invalidado = $this->ccf(1121, ['sello_invalidacion' => '2026'.strtoupper(Str::random(36))]);
        $archivado = $this->ccf(1122, ['archivado' => true]);

        $ids = $this->buscar('');

        $this->assertSame([$vigente->id], $ids);
        $this->assertNotContains($invalidado->id, $ids);
        $this->assertNotContains($archivado->id, $ids);
    }

    public function test_el_buscador_se_acota_al_cliente_y_a_la_sala_del_contexto(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaA = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
        $salaB = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
        $otroCliente = Cliente::factory()->contribuyente()->create();

        $enSalaA = $this->ccf(1120, ['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $salaA->id]);
        $enSalaB = $this->ccf(1121, ['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $salaB->id]);
        $ajeno = $this->ccf(1122, ['cliente_id' => $otroCliente->id]);

        $usuario = $this->usuario();

        $consulta = function (array $params) use ($usuario) {
            return array_column(
                $this->actingAs($usuario)
                    ->getJson(route('facturacion.nota-credito.buscar-ccf', $params))
                    ->assertOk()->json('resultados'),
                'id'
            );
        };

        // Con sala: solo esa sala.
        $this->assertSame(
            [$enSalaA->id],
            $consulta(['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $salaA->id])
        );

        // Sin sala (otra sala del mismo cliente): las dos, pero nunca el otro cliente.
        $delCliente = $consulta(['cliente_id' => $cliente->id]);
        $this->assertContains($enSalaA->id, $delCliente);
        $this->assertContains($enSalaB->id, $delCliente);
        $this->assertNotContains($ajeno->id, $delCliente, 'Una NC nunca puede cruzar de cliente.');
    }

    // ---------- Pantalla ----------

    public function test_la_pantalla_muestra_el_buscador_y_conserva_el_select_de_respaldo(): void
    {
        $this->ccf(1120);

        $this->actingAs($this->usuario())->get(route('facturacion.create-nota-credito'))
            ->assertOk()
            ->assertSee('Buscar por correlativo', false)
            // El respaldo sin JS conserva EXACTAMENTE el name que viaja al POST.
            ->assertSee('name="dte_relacionado_id"', false)
            ->assertSee(route('facturacion.nota-credito.buscar-ccf'), false);
    }

    /**
     * El componente Alpine SOLÍA vivir dentro del atributo `x-data="{…}"`, delimitado por
     * comillas dobles: una comilla doble suelta en el JavaScript cerraba el atributo antes
     * de tiempo y el resto del componente caía al documento como TEXTO VISIBLE. Ya pasó
     * una vez, con un `querySelector('option[value="'…)` en seleccionarCcf.
     *
     * La pantalla unificada quitó la trampa de raíz: el componente se registra en un
     * <script> aparte (Alpine.data) y el atributo se reduce a una llamada de una línea, de
     * modo que el código puede usar las comillas que necesite. Este test protege el mismo
     * resultado que antes —que nada del componente llegue a la pantalla como texto— y
     * además que la estructura nueva se conserve, porque es lo que hace imposible la falla.
     *
     * assertSee() no sirve acá (el texto sigue estando en la respuesta): se PARSEA el HTML
     * y se mira el texto renderizado, que es lo que ve el usuario.
     */
    public function test_la_pantalla_no_imprime_el_codigo_de_alpine_como_texto(): void
    {
        $this->ccf(1120);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito'))->assertOk()->getContent();

        $doc = new \DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $xpath = new \DOMXPath($doc);

        // textContent incluye el contenido de los <script>, que acá es justamente donde el
        // componente DEBE estar. Se los quita antes de mirar, para juzgar solo lo visible.
        foreach (iterator_to_array($xpath->query('//script')) as $script) {
            $script->parentNode->removeChild($script);
        }
        $texto = $doc->textContent;

        foreach (['seleccionarCcf(', 'onCcfChange()', 'this.ccfs[', 'buscarCcf('] as $fragmento) {
            $this->assertStringNotContainsString(
                $fragmento,
                $texto,
                "El componente Alpine se está imprimiendo como texto: apareció «{$fragmento}» en el DOM."
            );
        }

        // El atributo x-data tiene que seguir siendo una sola llamada, sin cuerpo del
        // componente adentro: es lo que evita que una comilla doble lo parta. (El primer
        // <form> del documento es el de logout del layout: hay que buscar el que lleva x-data.)
        $form = $xpath->query('//form[@x-data]')->item(0);
        $this->assertNotNull($form, 'No se encontró el formulario con x-data.');
        $xData = $form->getAttribute('x-data');
        $this->assertStringStartsWith('ncFormulario(', $xData);
        $this->assertStringNotContainsString('=>', $xData, 'El cuerpo del componente volvió al atributo x-data.');
        $this->assertStringNotContainsString('"', $xData, 'El x-data no puede contener comillas dobles.');
    }

    public function test_la_pantalla_ya_no_embebe_cientos_de_ccf(): void
    {
        foreach (range(1, 60) as $n) {
            $this->ccf($n);
        }

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito'))->assertOk()->getContent();

        // Antes viajaban hasta 200 documentos; ahora solo la precarga del respaldo.
        $this->assertSame(
            BusquedaCcfParaNotaCredito::LIMITE,
            substr_count($html, 'DTE-03-M001P001-'),
            'La precarga del select de respaldo debe quedar acotada al límite del buscador.'
        );
    }

    public function test_el_ccf_preseleccionado_sigue_en_el_select_aunque_no_sea_reciente(): void
    {
        $viejo = $this->ccf(1, ['fecha_emision' => '2026-01-02']);
        foreach (range(100, 130) as $n) {
            $this->ccf($n, ['fecha_emision' => '2026-07-20']);
        }

        // Llega por ?ccf={id}: si no estuviera entre las opciones, el POST viajaría vacío.
        $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito', ['ccf' => $viejo->id]))
            ->assertOk()
            ->assertSee('value="'.$viejo->id.'"', false);
    }

    // ---------- El id elegido llega intacto y el backend lo revalida ----------

    public function test_el_id_elegido_en_el_buscador_crea_la_nc_contra_ese_ccf_exacto(): void
    {
        $otro = $this->ccf(999);
        $elegido = $this->ccf(1120);

        $ids = $this->buscar('1120');
        $this->assertSame([$elegido->id], $ids);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => TipoNotaCredito::ProntoPago->value,
                'dte_relacionado_id' => $ids[0],
                'cliente_id' => $elegido->cliente_id,
                'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->p001->id,
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame($elegido->id, (int) $nc->dte_relacionado_id);
        $this->assertNotSame($otro->id, (int) $nc->dte_relacionado_id);
        $this->assertSame((int) $elegido->cliente_id, (int) $nc->cliente_id);
    }

    public function test_el_backend_rechaza_un_ccf_no_aceptado_aunque_el_buscador_no_lo_ofrezca(): void
    {
        $borrador = $this->ccf(1130, ['estado' => EstadoDte::Borrador->value]);

        // El buscador no lo ofrece…
        $this->assertSame([], $this->buscar('1130'));

        // …y si alguien manda su id a mano, el servidor lo sigue rechazando.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => TipoNotaCredito::ProntoPago->value,
                'dte_relacionado_id' => $borrador->id,
                'cliente_id' => $borrador->cliente_id,
                'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->p001->id,
            ])->assertSessionHasErrors('dte_relacionado_id');

        $this->assertDatabaseMissing('dtes', [
            'tipo_dte' => TipoDte::NotaCredito->value,
            'dte_relacionado_id' => $borrador->id,
        ]);
    }

    public function test_el_endpoint_exige_el_mismo_permiso_que_la_pantalla(): void
    {
        $this->ccf(1120);

        foreach (['jefatura', 'contabilidad'] as $rol) {
            $usuario = $this->usuario($rol);

            // Sin `dte.gestionar` no se puede ni abrir el formulario ni usar el buscador.
            $this->actingAs($usuario)->get(route('facturacion.create-nota-credito'))->assertForbidden();
            $this->actingAs($usuario)
                ->getJson(route('facturacion.nota-credito.buscar-ccf', ['q' => '1120']))
                ->assertForbidden();
        }
    }

    public function test_el_endpoint_exige_sesion(): void
    {
        $this->getJson(route('facturacion.nota-credito.buscar-ccf', ['q' => '1120']))
            ->assertUnauthorized();
    }

    // ---------- Rendimiento ----------

    public function test_el_buscador_no_tiene_n_mas_uno(): void
    {
        // Cada CCF con SU cliente y SU sala: sin eager loading, más resultados = más consultas.
        foreach (range(1, 3) as $n) {
            $cliente = Cliente::factory()->contribuyente()->create();
            $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
            $this->ccf($n, ['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id]);
        }

        $usuario = $this->usuario();

        // Petición de calentamiento SIN medir: la primera resuelve la caché de permisos de
        // Spatie (4 consultas extra) y contaminaría la comparación.
        $this->actingAs($usuario)->getJson(route('facturacion.nota-credito.buscar-ccf'))->assertOk();

        $conPocos = $this->consultasDe($usuario);

        foreach (range(4, 18) as $n) {
            $cliente = Cliente::factory()->contribuyente()->create();
            $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);
            $this->ccf($n, ['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id]);
        }

        $conMuchos = $this->consultasDe($usuario);

        $this->assertCount(
            BusquedaCcfParaNotaCredito::POR_PAGINA,
            $this->buscar('', $usuario),
            'La página debe venir llena para que la comparación valga.'
        );
        $this->assertSame(
            $conPocos,
            $conMuchos,
            "El número de consultas creció con los resultados ({$conPocos} → {$conMuchos}): hay N+1."
        );
        // Forma esperada: 1 por los DTE + 1 por cada relación precargada
        // (cliente, sala, establecimiento, punto de venta).
        $this->assertSame(5, $conMuchos, 'El eager loading del buscador cambió de forma.');
    }

    /** Consultas que dispara una llamada al endpoint. */
    private function consultasDe(User $usuario): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        $this->actingAs($usuario)
            ->getJson(route('facturacion.nota-credito.buscar-ccf'))->assertOk();

        // Vacía los listeners para que la siguiente medición arranque limpia.
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $n;
    }

    // ---------- El cálculo de la NC no cambia ----------

    public function test_el_calculo_de_la_nc_permanece_identico(): void
    {
        // Caso de oro de la retención: CCF de agente de retención con 5 % de descuento.
        $cliente = Cliente::factory()->contribuyente()->create([
            'es_agente_retencion' => true,
            'descuento_global_default' => 5,
        ]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->p001->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => 112.25, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh());

        // El CCF se encuentra por el buscador…
        $this->assertContains($ccf->id, $this->buscar(''));

        // …y la NC creada con ese id da exactamente los mismos números de siempre.
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value, 'origen_averia' => 'entrega'], $this->usuario());
        $this->borradores->agregarLineaDesdeProducto($nc, $producto, cantidad: 1);
        $nc->refresh();

        $this->assertSame('112.25', (string) $nc->total_gravado);
        $this->assertSame('5.61', (string) $nc->total_descuento);
        $this->assertSame('13.86', (string) $nc->iva);
        $this->assertTrue((bool) $nc->aplica_retencion_iva);
        $this->assertSame('1.07', (string) $nc->iva_retenido);
        $this->assertSame('119.43', (string) $nc->total_pagar);
    }
}
