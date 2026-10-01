<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Ppq\NcExportacionAutorizacionTest;
use Tests\TestCase;

/**
 * La BANDEJA de Cobros Calleja: qué se ve, en qué orden y qué no se pisa.
 *
 * Las tres cosas que protege:
 *
 *  1. una factura SIN albarán sigue visible —es justo la que hay que resolver—;
 *  2. lo más viejo va primero, porque es lo que más riesgo tiene de no cobrarse;
 *  3. guardar una observación NO toca los estados, y actualizar un estado no borra la
 *     observación.
 *
 * No emite documentos ni manda correos.
 */
class CobrosBandejaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los roles se siembran desde el CATÁLOGO REAL y no a mano: si mañana cambia quién
     * puede gestionar cobros, esta prueba lo refleja en vez de seguir verde contra un
     * reparto inventado. Mismo criterio que {@see NcExportacionAutorizacionTest}.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);

        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);

        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    private function documento(Cliente $cliente, array $datos = []): CobroDocumento
    {
        static $n = 0;
        $n++;

        return CobroDocumento::create(array_merge([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-09-01',
            'monto' => '100.00',
        ], $datos));
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · la bandeja muestra los seis estados que pidió operación, y una factura sin
     * albarán sigue a la vista con su motivo.
     */
    public function test_dorada_la_bandeja_muestra_todos_los_estados_y_lo_incompleto(): void
    {
        $cliente = $this->cliente();

        $sinPresentar = $this->documento($cliente, ['fecha_emision' => '2026-07-01']);
        $sinAlbaran = $this->documento($cliente, ['fecha_emision' => '2026-07-05']);
        $pagada = $this->documento($cliente, ['fecha_emision' => '2026-08-01']);
        $pagada->forceFill([
            'pago_estado' => EstadoPagoCobro::Pagado->value,
            'monto_pagado' => '100.00',
        ])->save();
        $parcial = $this->documento($cliente, ['fecha_emision' => '2026-08-10']);
        $parcial->forceFill([
            'pago_estado' => EstadoPagoCobro::Parcial->value,
            'monto_pagado' => '40.00',
        ])->save();

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id]))
            ->assertOk();

        // Pestañas por etapa y por mes, con su conteo.
        $respuesta->assertSeeText('No entregados');
        $respuesta->assertSeeText('Entregados, por presentar');
        $respuesta->assertSeeText('En PPQ / presentados');
        $this->assertSame(2, $respuesta->viewData('etapas')['no_entregados']);
        $this->assertSame(2, $respuesta->viewData('etapas')['pagados']);
        $respuesta->assertSeeText('Julio 2026');
        $respuesta->assertSeeText('Agosto 2026');
        $respuesta->assertSeeText('Pagado');
        $respuesta->assertDontSeeText('Pago parcial');

        // La factura sin albarán se ve, y se dice qué le falta.
        $respuesta->assertSee($sinAlbaran->numero_control);
        $respuesta->assertSeeText('No entregado');

        // Por mes: solo lo de julio.
        $julio = $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id, 'mes' => '2026-07']))
            ->assertOk();
        $julio->assertSee($sinPresentar->numero_control);
        $julio->assertDontSee($pagada->numero_control);
    }

    /** Los CCF recientes van primero, incluso sin albarán y sin presentar. */
    public function test_los_pendientes_se_ordenan_por_recencia(): void
    {
        $cliente = $this->cliente();

        $nueva = $this->documento($cliente, ['fecha_emision' => '2026-09-15']);
        $vieja = $this->documento($cliente, ['fecha_emision' => '2026-06-02']);
        $media = $this->documento($cliente, ['fecha_emision' => '2026-08-01']);

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id]))
            ->assertOk();

        $html = $respuesta->getContent();
        $posVieja = strpos($html, $vieja->numero_control);
        $posMedia = strpos($html, $media->numero_control);
        $posNueva = strpos($html, $nueva->numero_control);

        $this->assertLessThan($posMedia, $posNueva, 'La más reciente va primero.');
        $this->assertLessThan($posVieja, $posMedia);
    }

    /**
     * DORADA · la observación y los estados conviven: guardar una no toca los otros, y
     * cambiar un estado no borra la observación.
     */
    public function test_dorada_la_observacion_y_los_estados_no_se_pisan(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente);
        $documento->forceFill([
            'presentacion_estado' => EstadoPresentacionCobro::Presentada->value,
            'pago_estado' => EstadoPagoCobro::Parcial->value,
            'monto_pagado' => '40.00',
        ])->save();

        $this->actingAs($this->usuario())
            ->put(route('cobros.documentos.observacion', $documento), [
                'observaciones' => 'Calleja pidió el albarán físico.',
            ])
            ->assertRedirect();

        $documento->refresh();
        $this->assertSame('Calleja pidió el albarán físico.', $documento->observaciones);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $documento->presentacion_estado, 'La presentación no cambió.');
        $this->assertSame(EstadoPagoCobro::Parcial, $documento->pago_estado, 'El pago no cambió.');
        $this->assertSame(0, bccomp('40.00', (string) $documento->monto_pagado, 2));

        // Y al revés: mover el pago no borra la observación.
        $documento->forceFill(['pago_estado' => EstadoPagoCobro::Pagado->value, 'monto_pagado' => '100.00'])->save();
        $this->assertSame('Calleja pidió el albarán físico.', $documento->refresh()->observaciones);
    }

    // ------------------------------------------------------------------ filtros

    /** Los filtros acotan lo que se ve. */
    public function test_los_filtros_acotan_pero_los_contadores_cuentan_el_total(): void
    {
        $cliente = $this->cliente();

        $pendiente = $this->documento($cliente);
        $pagada = $this->documento($cliente);
        $pagada->forceFill(['pago_estado' => EstadoPagoCobro::Pagado->value, 'monto_pagado' => '100.00'])->save();

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id, 'pago' => 'pagado']))
            ->assertOk();

        $respuesta->assertSee($pagada->numero_control);
        $respuesta->assertDontSee($pendiente->numero_control);
        $respuesta->assertSeeText('Mostrando 1–1 de 1');
    }

    /** Se puede filtrar por lo que requiere revisión histórica. */
    public function test_se_puede_filtrar_lo_que_requiere_revision_historica(): void
    {
        $cliente = $this->cliente();

        $normal = $this->documento($cliente);
        $historico = $this->documento($cliente);
        $historico->forceFill([
            'revisar_historico' => true,
            'revisar_historico_motivo' => 'Ya viajó en el lote PPQ anterior.',
        ])->save();

        $this->actingAs($this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id, 'revisar' => '1']))
            ->assertOk()
            ->assertSee($historico->numero_control)
            ->assertDontSee($normal->numero_control);
    }

    // ------------------------------------------------------------------ permisos

    /** Solo lectura sin `ppq.gestionar`: se ve la bandeja pero no los botones que escriben. */
    public function test_solo_lectura_no_ve_las_acciones_de_escritura(): void
    {
        $cliente = $this->cliente();
        $this->documento($cliente);

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->get(route('cobros.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertDontSee('Traer documentos aceptados')
            ->assertDontSee('Preparar archivo de quedan con lo marcado')
            ->assertDontSee('name="documentos[]"', false);
    }

    /** Sin `ppq.gestionar` no se puede escribir, aunque se llame a la ruta directamente. */
    public function test_solo_lectura_no_puede_sincronizar(): void
    {
        $cliente = $this->cliente();

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->post(route('cobros.sincronizar', $cliente))
            ->assertForbidden();
    }

    /**
     * Sin `ppq.ver` no se entra. Se usa PRODUCCIÓN porque es el rol que el catálogo aísla a
     * propósito del área fiscal; facturación sí gestiona cobros, y probar con ella diría lo
     * contrario de lo que el sistema hace.
     */
    public function test_sin_permiso_no_se_entra(): void
    {
        $sinPermiso = $this->usuario(RolSistema::Produccion);
        $this->assertFalse($sinPermiso->can(PermisoSistema::PpqVer->value));

        $this->actingAs($sinPermiso)
            ->get(route('cobros.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ alta

    /** Incorporar a mano el mismo documento dos veces no se puede: sería cobrarlo dos veces. */
    public function test_no_se_puede_incorporar_dos_veces_el_mismo_documento(): void
    {
        $cliente = $this->cliente();
        $alta = app(AltaCobrosService::class);

        $alta->incorporar($cliente, [
            'numero_control' => 'DTE-03-M001P001-000000000090060',
            'monto' => '200.00',
        ]);

        $this->expectException(ValidationException::class);

        // El mismo número escrito SIN guiones: la identidad es la misma.
        $alta->incorporar($cliente, [
            'numero_control' => 'DTE03M001P001000000000090060',
            'monto' => '200.00',
        ]);
    }

    /** El establecimiento y el punto de venta se extraen del número y no se pierden. */
    public function test_el_establecimiento_y_el_punto_de_venta_se_conservan(): void
    {
        $cliente = $this->cliente();

        $documento = app(AltaCobrosService::class)->incorporar($cliente, [
            'numero_control' => 'DTE-03-M001P002-000000000090059',
            'monto' => '100.00',
        ]);

        $this->assertSame('M001', $documento->establecimiento_codigo);
        $this->assertSame('P002', $documento->punto_venta_codigo);
        $this->assertSame('DTE03M001P002000000000090059', $documento->numero_control_norm);
    }

    /**
     * Un documento viejo sin antecedentes se marca para REVISIÓN HISTÓRICA, no se declara
     * pendiente: no consta que nadie lo haya presentado ni cobrado.
     */
    public function test_lo_viejo_sin_antecedentes_se_marca_para_revision_historica(): void
    {
        $cliente = $this->cliente();

        $viejo = $this->documento($cliente, ['fecha_emision' => Carbon::today()->subMonths(6)->toDateString()]);
        $nuevo = $this->documento($cliente, ['fecha_emision' => Carbon::today()->toDateString()]);

        $marcados = app(AltaCobrosService::class)->marcarRevisionHistorica($cliente);

        $this->assertSame(1, $marcados);
        $this->assertTrue($viejo->refresh()->revisar_historico);
        $this->assertStringContainsString('no consta si se presentó', (string) $viejo->revisar_historico_motivo);
        $this->assertFalse($nuevo->refresh()->revisar_historico, 'Lo emitido hoy sí nació con seguimiento.');
    }

    /** Cerrar la revisión histórica deja la conclusión en las observaciones. */
    public function test_cerrar_la_revision_historica_deja_constancia(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente);
        $documento->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Sin antecedentes.'])->save();

        $this->actingAs($this->usuario())
            ->put(route('cobros.documentos.revisado', $documento), [
                'nota' => 'Cobrada en el lote de junio.',
                'evidencia' => 'Conciliación del lote de junio.',
                'decision' => 'mantener_bloqueo',
            ])
            ->assertRedirect();

        $documento->refresh();
        $this->assertTrue($documento->revisar_historico, 'Un cobro histórico no autoriza volver a presentar la factura.');
        $this->assertStringContainsString('Cobrada en el lote de junio.', (string) $documento->observaciones);
        $this->assertStringContainsString('Revisión histórica', (string) $documento->observaciones);
    }

    // ------------------------------------------------------------------ ficha

    /** La ficha muestra la historia del documento y sus dos ejes. */
    public function test_la_ficha_muestra_la_historia_y_los_dos_ejes(): void
    {
        $cliente = $this->cliente();
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/5131',
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '26090017003463',
            'sala_codigo' => '0017',
        ]);
        $documento = $this->documento($cliente, ['monto' => '123.74']);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $this->actingAs($this->usuario())
            ->get(route('cobros.documentos.show', $documento))
            ->assertOk()
            ->assertSee($documento->numero_control)
            ->assertSee('AC01/0017/00/5131')
            ->assertSee('Presentación')
            ->assertSee('Pago')
            ->assertSee('Historia del documento')
            ->assertSee('M001')
            ->assertSee('P002');
    }

    /** La auditoría de vinculación en seco no escribe nada. */
    public function test_la_auditoria_de_vinculacion_no_escribe_nada(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente);

        $this->actingAs($this->usuario())
            ->get(route('cobros.vinculacion', $cliente))
            ->assertOk()
            ->assertSee('ensayo en seco', false)
            ->assertSee('nunca crean un vínculo', false);

        $documento->refresh();
        $this->assertNull($documento->ppq_albaran_id);
        $this->assertNull($documento->vinculado_en);
    }
}
