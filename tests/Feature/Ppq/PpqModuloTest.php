<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\OrdenCompra;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PpqModuloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Support\Sala::olvidarCache(); // el caché estático no debe filtrar nombres entre tests
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    private function crearCcf(string $numeroControl, ?string $oc = '260600232002345', float $monto = 113.58): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => $numeroControl,
            'numero_orden_compra' => $oc,
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => $monto,
        ]);
    }

    public function test_helper_extrae_sala_y_ultimos_digitos(): void
    {
        // Sala = 4 dígitos justo después del prefijo YYMM, conservando el cero inicial.
        $this->assertSame('0260', OrdenCompra::salaDesde('2606026002401'));
        $this->assertSame('0236', OrdenCompra::salaDesde('26060236004586'));
        $this->assertSame('0039', OrdenCompra::salaDesde('26050039004820'));
        $this->assertSame('0230', OrdenCompra::salaDesde('26050230001794'));
        $this->assertNull(OrdenCompra::salaDesde('123'));
        $this->assertSame('0986', OrdenCompra::ultimosDigitos('DTE-03-M001P001-0000000000000986'));
        // Sin sucursal con ese código en la BD, la etiqueta es solo el código.
        $this->assertSame('0260', \App\Support\Sala::etiqueta('0260'));
        $this->assertNull(\App\Support\Sala::nombre('0260'));
    }

    public function test_sala_muestra_nombre_cuando_la_sucursal_tiene_codigo(): void
    {
        $this->calleja()->sucursales()->create(['nombre' => 'Selectos Santa Rosa', 'codigo' => '0230']);

        $this->assertSame('Selectos Santa Rosa', \App\Support\Sala::nombre('0230'));
        $this->assertSame('0230 - Selectos Santa Rosa', \App\Support\Sala::etiqueta('0230'));
        $this->assertSame('0231', \App\Support\Sala::etiqueta('0231')); // sin mapeo -> solo código
    }

    public function test_comando_asigna_codigo_de_sala_a_una_sucursal(): void
    {
        $calleja = $this->calleja();
        $sucursal = $calleja->sucursales()->create(['nombre' => 'Selectos Merliot']);

        $this->artisan('ppq:sala-codigo', ['codigo' => '236', '--id' => $sucursal->id, '--cliente' => $calleja->id])
            ->assertSuccessful();

        $this->assertSame('0236', $sucursal->refresh()->codigo);
    }

    public function test_buscar_albaran_por_fecha_renderiza_y_permite_sin_albaran(): void
    {
        $admin = $this->usuario('administrador');
        PpqLote::create(['referencia' => 'L', 'fecha' => now(), 'estado' => 'borrador']);

        $this->actingAs($admin)->get(route('ppq.albaranes_por_fecha', [
            'origen' => 'gmail', 'numero_control' => 'DTE-03-Z', 'tipo_dte' => '03',
            'numero_orden_compra' => '2606026002401', 'monto_dte' => 50.00, 'fecha' => '2026-06-10', 'q' => '0986',
        ]))
            ->assertOk()
            ->assertSee('DTE-03-Z')
            ->assertSee('0260')               // sala derivada de la OC
            ->assertSee('Agregar sin albarán');
    }

    public function test_numero_albaran_limpio(): void
    {
        $sucio = 'Albarán AC01/0230 /00 /2878 - ELSA FIDELINA HERNANDEZ DE ESPAÑA Fecha: 03/06/2026';
        $this->assertSame('AC01/0230/00/2878', \App\Support\Albaran::numeroLimpio($sucio));
    }

    public function test_excel_exporta_oc_y_sala_como_texto(): void
    {
        $admin = $this->usuario('administrador');
        $lote = PpqLote::create(['referencia' => 'X', 'fecha' => now(), 'estado' => 'borrador']);
        // Item de Gmail con OC que en número daría notación científica.
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail', 'numero_control' => 'DTE-03-X', 'numero_orden_compra' => '26050230001794', 'monto_dte' => 100.0,
        ]);

        $ruta = app(\App\Services\Ppq\ExcelCallejaExporter::class)->generar($lote->fresh());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getActiveSheet();

        $this->assertSame('26050230001794', (string) $hoja->getCell('A2')->getValue()); // OC completa, no científica
        $this->assertSame('0230', (string) $hoja->getCell('I2')->getValue());            // sin sucursal: cae al código
        @unlink($ruta);
    }

    public function test_nombre_archivo_excel_es_codigo_proveedor_mas_fecha_hora(): void
    {
        config(['ppq.codigo_proveedor' => '001065']);
        $lote = PpqLote::create(['referencia' => 'No importa', 'fecha' => now(), 'estado' => 'borrador']);

        $nombre = app(\App\Services\Ppq\ExcelCallejaExporter::class)->nombreArchivo($lote);
        $esperado = '001065'.now('America/El_Salvador')->format('YmdHi').'.xlsx';

        // {codigo}{YYYYMMDDHHmm}.xlsx — sin guiones, espacios, underscores ni "PPQ".
        $this->assertSame($esperado, $nombre);
        $this->assertMatchesRegularExpression('/^001065\d{12}\.xlsx$/', $nombre);
    }

    public function test_excel_usa_nombre_de_sala_y_columna_diferencia(): void
    {
        $admin = $this->usuario('administrador');
        // La sala 0230 (OC offset 5, largo 4) tiene nombre comercial registrado.
        $this->calleja()->sucursales()->create(['nombre' => 'Súper Selectos La Sultana', 'codigo' => '0230']);
        $lote = PpqLote::create(['referencia' => 'X', 'fecha' => now(), 'estado' => 'borrador']);

        // CCF de 100 con albarán de 90 (fecha 15/06/2026) → diferencia 10.
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail', 'numero_control' => 'DTE-03-X', 'numero_orden_compra' => '26050230001794',
            'monto_dte' => 100.0, 'monto_albaran' => 90.0, 'numero_albaran' => 'AC01/0230/00/1',
            'fecha_albaran' => '2026-06-15', 'sin_albaran' => '0',
        ]);

        \App\Support\Sala::olvidarCache();
        $ruta = app(\App\Services\Ppq\ExcelCallejaExporter::class)->generar($lote->fresh());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getActiveSheet();

        $this->assertSame('Súper Selectos La Sultana', (string) $hoja->getCell('I2')->getValue()); // nombre, no código
        $this->assertSame('15/06/2026', (string) $hoja->getCell('C2')->getValue());                 // fecha d/m/Y
        $this->assertSame('Diferencia (CCF − albarán)', (string) $hoja->getCell('J1')->getValue());
        $this->assertEqualsWithDelta(10.0, (float) $hoja->getCell('J2')->getValue(), 0.001);
        @unlink($ruta);
    }

    public function test_consulta_no_accede_y_roles_de_cobro_si(): void
    {
        $this->actingAs($this->usuario('consulta'))->get(route('ppq.index'))->assertForbidden();
        $this->actingAs($this->usuario('facturacion'))->get(route('ppq.index'))->assertOk();
        $this->actingAs($this->usuario('contador'))->get(route('ppq.lotes.index'))->assertOk();
    }

    public function test_crea_lote(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('ppq.lotes.store'), [
                'referencia' => 'PPQ Calleja Test',
                'fecha' => now()->format('Y-m-d'),
                'estado' => 'borrador',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ppq_lotes', ['referencia' => 'PPQ Calleja Test', 'estado' => 'borrador']);
    }

    public function test_busqueda_por_ultimos_4_encuentra_el_ccf(): void
    {
        $this->crearCcf('DTE-03-M001P001-0000000000000986');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('ppq.index', ['q' => '0986']))
            ->assertOk()
            ->assertSee('0000000000000986', false);
    }

    public function test_busqueda_con_lote_activo_apunta_directo_a_ese_lote(): void
    {
        $this->crearCcf('DTE-03-M001P001-0000000000000986');
        $lote = PpqLote::create(['referencia' => 'PPQ activo', 'fecha' => now(), 'estado' => 'borrador']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('ppq.index', ['q' => '0986', 'lote' => $lote->id]))
            ->assertOk()
            ->assertSee('Estás agregando documentos al lote')        // banner de contexto
            ->assertSee('name="lote" value="'.$lote->id.'"', false); // el form apunta directo al lote
    }

    public function test_lote_no_editable_no_queda_como_activo(): void
    {
        $this->crearCcf('DTE-03-M001P001-0000000000000986');
        $lote = PpqLote::create(['referencia' => 'Cerrado', 'fecha' => now(), 'estado' => 'pagado']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('ppq.index', ['q' => '0986', 'lote' => $lote->id]))
            ->assertOk()
            ->assertDontSee('Estás agregando documentos al lote'); // se ignora: cae al flujo normal
    }

    public function test_busqueda_por_defecto_solo_ccf_y_filtra_nc(): void
    {
        $this->crearCcf('DTE-03-M001P001-0000000000000340');
        Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id, 'tipo_dte' => '05', 'estado' => 'aceptado',
            'cliente_id' => $this->calleja()->id, 'numero_control' => 'DTE-05-M001P001-0000000000000340',
            'numero_orden_compra' => '260600232002345', 'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'), 'total_pagar' => 20.0,
        ]);
        $user = $this->usuario('facturacion');

        // Por defecto = CCF: aparece el CCF, NO la NC (aunque compartan los 4 dígitos).
        $this->actingAs($user)->get(route('ppq.index', ['q' => '0340']))
            ->assertOk()
            ->assertSee('DTE-03-M001P001-0000000000000340', false)
            ->assertDontSee('DTE-05-M001P001-0000000000000340', false);

        // Modo NC: aparece la NC, NO el CCF.
        $this->actingAs($user)->get(route('ppq.index', ['q' => '0340', 'tipo' => '05']))
            ->assertOk()
            ->assertSee('DTE-05-M001P001-0000000000000340', false)
            ->assertDontSee('DTE-03-M001P001-0000000000000340', false);
    }

    public function test_items_ordenados_ccf_primero_luego_nc_por_correlativo(): void
    {
        $lote = PpqLote::create(['referencia' => 'Orden', 'fecha' => now(), 'estado' => 'borrador']);
        foreach ([
            ['03', 'DTE-03-M001P001-000000000001000'],
            ['05', 'DTE-05-M001P001-000000000000340'],
            ['03', 'DTE-03-M001P001-000000000000970'],
            ['05', 'DTE-05-M001P001-000000000000341'],
        ] as [$t, $ctrl]) {
            PpqItem::create(['ppq_lote_id' => $lote->id, 'tipo_dte' => $t, 'numero_control' => $ctrl, 'monto_dte' => 1]);
        }

        $orden = $lote->fresh()->itemsOrdenados()->map(fn ($i) => $i->numero_control)->all();

        // CCF primero (970, 1000) y luego NC (340, 341); la NC 340 va DESPUÉS de los CCF.
        $this->assertSame([
            'DTE-03-M001P001-000000000000970',
            'DTE-03-M001P001-000000000001000',
            'DTE-05-M001P001-000000000000340',
            'DTE-05-M001P001-000000000000341',
        ], $orden);
    }

    public function test_agrega_ccf_al_lote_con_snapshots_y_bloquea_duplicado(): void
    {
        $admin = $this->usuario('administrador');
        $ccf = $this->crearCcf('DTE-03-M001P001-0000000000000986');
        $lote = PpqLote::create(['referencia' => 'L1', 'fecha' => now(), 'estado' => 'borrador']);

        $this->actingAs($admin)
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id])
            ->assertRedirect();

        $item = PpqItem::firstOrFail();
        $this->assertSame($lote->id, $item->ppq_lote_id);
        $this->assertSame('113.58', (string) $item->monto_dte);            // snapshot del monto
        $this->assertSame('260600232002345', $item->numero_orden_compra);  // snapshot de la OC

        // Duplicado del mismo CCF en el mismo lote: se rechaza, no se duplica.
        $this->actingAs($admin)
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id])
            ->assertSessionHas('error');

        $this->assertSame(1, PpqItem::where('ppq_lote_id', $lote->id)->where('dte_id', $ccf->id)->count());
    }

    public function test_historial_ppq_responde(): void
    {
        $user = $this->usuario('facturacion');
        $this->actingAs($user)->get(route('ppq.index'))->assertOk();
        $this->actingAs($user)->get(route('ppq.lotes.index'))->assertOk()->assertSee('Historial PPQ', false);
    }

    public function test_genera_excel_calleja_con_items(): void
    {
        $admin = $this->usuario('administrador');
        $ccf = $this->crearCcf('DTE-03-M001P001-0000000000000986');
        $lote = PpqLote::create(['referencia' => 'Excel', 'fecha' => now(), 'estado' => 'borrador']);
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id]);

        $resp = $this->actingAs($admin)->get(route('ppq.lotes.excel', $lote));
        $resp->assertOk();
        $this->assertStringContainsString('spreadsheet', strtolower($resp->headers->get('content-type')));
    }

    public function test_excel_vacio_redirige_con_error(): void
    {
        $admin = $this->usuario('administrador');
        $lote = PpqLote::create(['referencia' => 'Vacio', 'fecha' => now(), 'estado' => 'borrador']);

        $this->actingAs($admin)->get(route('ppq.lotes.excel', $lote))
            ->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('error');
    }

    public function test_agrega_ccf_de_gmail_como_snapshot(): void
    {
        $admin = $this->usuario('administrador');
        $lote = PpqLote::create(['referencia' => 'G', 'fecha' => now(), 'estado' => 'borrador']);

        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail',
            'numero_control' => 'DTE-03-M001P001-000000000000986',
            'codigo_generacion' => 'GEN-1',
            'sello_recepcion' => 'SELLO-1',
            'tipo_dte' => '03',
            'numero_orden_compra' => '260600232002345',
            'monto_dte' => 146.56,
            'numero_albaran' => 'ALB-77',
            'monto_albaran' => 140.00,
        ])->assertRedirect();

        $this->assertDatabaseHas('ppq_items', [
            'ppq_lote_id' => $lote->id, 'origen' => 'gmail', 'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000986',
        ]);
        $this->assertDatabaseHas('ppq_albaranes', ['numero_albaran' => 'ALB-77', 'origen' => 'gmail']);
    }

    public function test_nc_resta_en_el_lote_con_albaran_manual(): void
    {
        $admin = $this->usuario('administrador');
        $lote = PpqLote::create(['referencia' => 'NC', 'fecha' => now(), 'estado' => 'borrador']);

        // CCF (suma) con su albarán automático de Gmail.
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail', 'numero_control' => 'DTE-03-A', 'tipo_dte' => '03',
            'numero_orden_compra' => '26060236004586', 'monto_dte' => 100.00,
            'numero_albaran' => 'AC01/0236/00/6359', 'monto_albaran' => 100.00, 'sin_albaran' => '0',
        ])->assertRedirect();

        // NC (resta) con albarán capturado a MANO (misma OC que el CCF).
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail', 'numero_control' => 'DTE-05-B', 'tipo_dte' => '05',
            'numero_orden_compra' => '26060236004586', 'monto_dte' => 30.00,
            'numero_albaran' => 'AC01/0236/00/9999', 'fecha_albaran' => '2026-06-10',
            'monto_albaran' => 30.00, 'observaciones' => 'avería', 'sin_albaran' => '0',
        ])->assertRedirect();

        // El albarán de la NC se registra como manual; el item guarda observaciones.
        $this->assertDatabaseHas('ppq_albaranes', ['numero_albaran' => 'AC01/0236/00/9999', 'origen' => 'manual']);
        $this->assertDatabaseHas('ppq_items', ['numero_control' => 'DTE-05-B', 'observaciones' => 'avería']);

        $lote->refresh()->load('items');
        $nc = $lote->items->firstWhere('numero_control', 'DTE-05-B');
        $this->assertTrue($nc->esNc());
        $this->assertSame(-30.0, $nc->montoDteConSigno());     // la NC resta
        $this->assertSame(-30.0, $nc->montoAlbaranConSigno());

        // Total neto del lote: 100 (CCF) - 30 (NC) = 70.
        $this->assertSame(70.0, $lote->totalMontoDte());
    }

    public function test_gmail_no_configurado_no_esta_disponible(): void
    {
        config(['ppq.gmail.enabled' => false]);
        $this->assertFalse(app(\App\Services\Ppq\GmailClient::class)->disponible());
    }

    public function test_variantes_de_numero_incluyen_padded(): void
    {
        $variantes = app(\App\Services\Ppq\GmailClient::class)->variantesNumero('1011');

        // El usuario escribe solo "1011"; el sistema genera las variantes que Gmail necesita.
        $this->assertSame('1011', $variantes[0]);                 // se prueba primero el crudo
        $this->assertContains('000000000001011', $variantes);     // padded a 15 (la que matchea)
        $this->assertContains('0000000000001011', $variantes);    // padded a 16
    }

    public function test_no_admite_cambios_si_el_lote_no_es_editable(): void
    {
        $admin = $this->usuario('administrador');
        $ccf = $this->crearCcf('DTE-03-M001P001-0000000000000987');
        $lote = PpqLote::create(['referencia' => 'L2', 'fecha' => now(), 'estado' => 'pagado']);

        $this->actingAs($admin)
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $ccf->id])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('ppq_items', ['ppq_lote_id' => $lote->id, 'dte_id' => $ccf->id]);
    }

    public function test_nombre_de_sala_se_resuelve_por_relacion_y_se_snapshotea(): void
    {
        $admin = $this->usuario('administrador');
        // Sucursal de Calleja con su nombre comercial, enlazada al CCF.
        $sucursal = $this->calleja()->sucursales()->create(['nombre' => 'Súper Selectos La Sultana']);
        $ccf = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id, 'tipo_dte' => '03', 'estado' => 'aceptado',
            'cliente_id' => $this->calleja()->id, 'cliente_sucursal_id' => $sucursal->id,
            'numero_control' => 'DTE-03-M001P001-0000000000000986', 'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(), 'hora_emision' => now()->format('H:i:s'), 'total_pagar' => 113.58,
        ]);

        $lote = PpqLote::create(['referencia' => 'L', 'fecha' => now(), 'estado' => 'borrador']);

        // Se agrega desde el flujo Gmail SIN sala_nombre: el controller debe resolverlo por la
        // relación del DTE local (match por número de control) y snapshotearlo en el item.
        $this->actingAs($admin)->post(route('ppq.lotes.items.store', $lote), [
            'origen' => 'gmail', 'numero_control' => 'DTE-03-M001P001-0000000000000986',
            'codigo_generacion' => $ccf->codigo_generacion, 'tipo_dte' => '03', 'monto_dte' => 113.58,
        ])->assertRedirect();

        $item = PpqItem::firstOrFail();
        $this->assertSame('Súper Selectos La Sultana', $item->sala_nombre);          // snapshot
        $this->assertSame('Súper Selectos La Sultana', $item->salaDescripcion());     // accessor
    }

    public function test_resolver_de_sala_encuentra_nombre_por_orden_de_compra(): void
    {
        $sucursal = $this->calleja()->sucursales()->create(['nombre' => 'Súper Selectos Ilobasco']);
        // CCF local SIN número de control/código (datos parciales), pero con OC y sucursal.
        Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id, 'tipo_dte' => '03', 'estado' => 'aceptado',
            'cliente_id' => $this->calleja()->id, 'cliente_sucursal_id' => $sucursal->id,
            'numero_orden_compra' => '26060218001234', 'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'), 'total_pagar' => 50.0,
        ]);

        $resolver = app(\App\Services\Ppq\SalaResolver::class);
        // Solo con la OC (como llega un CCF de Gmail) ya resuelve el nombre comercial.
        $this->assertSame('Súper Selectos Ilobasco', $resolver->nombre('26060218001234'));
        $this->assertNull($resolver->nombre('99999999999')); // OC desconocida
    }

    public function test_sala_sin_nombre_muestra_texto_de_respaldo(): void
    {
        $item = new PpqItem(['numero_orden_compra' => '26050230001794']); // sala 0230, sin sucursal
        $this->assertSame('Sala 0230 sin nombre registrado', $item->salaDescripcion());
    }

    public function test_helper_fecha_formatea_dmy_y_tolera_formatos(): void
    {
        $this->assertSame('15/06/2026', \App\Support\Fecha::dmy('2026-06-15'));        // ISO → d/m/Y
        $this->assertSame('05/06/2026', \App\Support\Fecha::dmy('05/06/2026'));        // ya d/m/Y: se respeta (no se lee como m/d)
        $this->assertSame('20/06/2026', \App\Support\Fecha::dmy(\Illuminate\Support\Carbon::parse('2026-06-20')));
        $this->assertNull(\App\Support\Fecha::dmy(''));
        $this->assertNull(\App\Support\Fecha::dmy(null));
    }

    public function test_parser_txt_lee_formato_real_calleja(): void
    {
        $parser = new \App\Services\Ppq\ConciliacionTxtParser();
        $filas = $parser->parse(
            "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n".
            "001065;ELSA FIDELINA HERNANDEZ DE ESPAÑA;QD;PPQ/19891;;-121.98\n".
            "001065;ELSA … ESPAÑA;NC;DTE05M001P001000000000000339;08-JUN-26;-5.3\n".
            "001065;ELSA … ESPAÑA;CF;DTE03M001P001000000000000967;05-JUN-26;126.44\n"
        );

        $this->assertCount(3, $filas); // el encabezado se descarta

        $this->assertSame('QD', $filas[0]['tipo']);
        $this->assertSame('PPQ19891', $filas[0]['numeroNorm']);
        $this->assertEqualsWithDelta(-121.98, $filas[0]['valor'], 0.001);

        $this->assertSame('NC', $filas[1]['tipo']);
        $this->assertSame('2026-06-08', $filas[1]['fecha']); // 08-JUN-26 → Y-m-d

        $this->assertSame('CF', $filas[2]['tipo']);
        $this->assertSame('DTE03M001P001000000000000967', $filas[2]['numeroNorm']);
        $this->assertEqualsWithDelta(126.44, $filas[2]['valor'], 0.001);
    }

    public function test_conciliacion_txt_marca_pagado_solo_si_aparece_como_cf(): void
    {
        $admin = $this->usuario('administrador');
        $lote = PpqLote::create(['referencia' => 'Concilia', 'fecha' => now(), 'estado' => 'listo']);
        // CCF 967 (aparece en TXT, dif 0.44), CCF 999 (NO aparece → pendiente), NC 339 (aparece).
        $ccfPagado = PpqItem::create(['ppq_lote_id' => $lote->id, 'tipo_dte' => '03', 'numero_control' => 'DTE-03-M001P001-000000000000967', 'monto_dte' => 126.00]);
        $ccfPend = PpqItem::create(['ppq_lote_id' => $lote->id, 'tipo_dte' => '03', 'numero_control' => 'DTE-03-M001P001-000000000000999', 'monto_dte' => 200.00]);
        $nc = PpqItem::create(['ppq_lote_id' => $lote->id, 'tipo_dte' => '05', 'numero_control' => 'DTE-05-M001P001-000000000000339', 'monto_dte' => 5.30]);

        // El número en el TXT viene sin guiones; debe casar igual. Incluye un QD y un CF ajeno (965).
        $contenido = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."001065;ELSA;QD;PPQ/19891;;-121.98\n"
            ."001065;ELSA;NC;DTE05M001P001000000000000339;08-JUN-26;-5.30\n"
            ."001065;ELSA;CF;DTE03M001P001000000000000967;05-JUN-26;126.44\n"
            ."001065;ELSA;CF;DTE03M001P001000000000000965;05-JUN-26;178.14\n";
        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('pagos.txt', $contenido);

        $resp = $this->actingAs($admin)
            ->post(route('ppq.lotes.conciliar', $lote), ['archivo' => $archivo])
            ->assertOk();

        $resp->assertViewHas('reporte', function ($r) {
            return count($r['ccfPagados']) === 1
                && count($r['ccfPendientes']) === 1
                && count($r['ncAplicadas']) === 1
                && count($r['ncPendientes']) === 0
                && count($r['ajustesQd']) === 1
                && count($r['noEnPpq']) === 1                          // el CF 965 ajeno
                && abs($r['ccfPagados'][0]['diferencia'] - (-0.44)) < 0.001; // 126.00 − 126.44
        });

        // Persistencia: pagado/aplicada solo por aparecer en el TXT.
        $this->assertSame('pagado', $ccfPagado->refresh()->conciliacion_estado);
        $this->assertSame('2026-06-05', $ccfPagado->fecha_pago->format('Y-m-d'));
        $this->assertEqualsWithDelta(126.44, (float) $ccfPagado->monto_pagado, 0.001);

        $this->assertNull($ccfPend->refresh()->conciliacion_estado);   // NO pagado: no está en el TXT
        $this->assertSame('aplicada', $nc->refresh()->conciliacion_estado);
    }
}
