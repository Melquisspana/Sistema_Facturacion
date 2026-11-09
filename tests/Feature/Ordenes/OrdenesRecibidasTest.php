<?php

namespace Tests\Feature\Ordenes;

use App\Enums\EstadoDte;
use App\Enums\PermisoSistema;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\OrdenCompraRecibida;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DtePdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Órdenes recibidas: la lista con facturada / sin facturar y su permiso, las órdenes
 * que se ofrecen al facturar y el «PDF + orden» de la ficha, sin que cambien el PDF
 * normal ni el correo. Órdenes, salas y clientes ficticios.
 */
class OrdenesRecibidasTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** @var array{estab: Establecimiento, pv: PuntoVenta, empresa: Empresa}|null */
    private ?array $emisor = null;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        OrdenCompraRecibida::olvidarInstalacion();
        $this->seedCatalogosDte();

        $this->cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true, 'correo' => 'cliente@cliente.example.com']);
        config(['ppq.cliente_default_id' => $this->cliente->id]);
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function sala(string $codigo, string $nombre = 'Sala de prueba'): ClienteSucursal
    {
        return ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'codigo' => $codigo, 'nombre' => $nombre]);
    }

    /** Orden recibida con un PDF ficticio de $paginas páginas en el disco. */
    private function orden(?string $numero, ?ClienteSucursal $sala = null, string $creacion = '2026-11-05', int $paginas = 1, ?string $pdf = null): OrdenCompraRecibida
    {
        $html = implode('<div style="page-break-before: always"></div>', array_fill(0, $paginas, '<pre>'.e(OrdenCompraParserTest::texto($numero ?? '0')).'</pre>'));
        $pdf ??= Pdf::loadHTML($html)->output();
        $ruta = OrdenCompraRecibida::CARPETA_PDF.'/'.hash('sha256', $pdf.$numero).'.pdf';
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $pdf);

        return OrdenCompraRecibida::create([
            'numero' => $numero,
            'sala_codigo' => $sala?->codigo,
            'cliente_sucursal_id' => $sala?->id,
            'fecha_creacion' => $creacion,
            'fecha_despacho' => '2026-11-07',
            'total' => '22.90',
            'pdf_path' => $ruta,
            'pdf_hash' => hash('sha256', $pdf),
            'gmail_message_id' => 'm-'.uniqid(),
            'estado' => $numero !== null ? OrdenCompraRecibida::LEIDA : OrdenCompraRecibida::SIN_LEER,
        ]);
    }

    private function ccf(string $orden, ?ClienteSucursal $sala = null, EstadoDte $estado = EstadoDte::Borrador): Dte
    {
        $this->emisor ??= $this->crearEmisorDte();
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $sala?->id,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
            'numero_orden_compra' => $orden,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]), cantidad: 2);

        if ($estado !== EstadoDte::Borrador) {
            $dte->numero_control = 'DTE-03-M001P001-000000000000050';
            $dte->codigo_generacion = 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D';
            $dte->estado = $estado;
            Dte::withoutEvents(fn () => $dte->save());
        }

        return $dte->refresh();
    }

    private function paginas(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    // --- Lista ---

    public function test_la_lista_muestra_sin_facturar_y_enlaza_las_facturadas(): void
    {
        $sala = $this->sala('0232', 'Sala de prueba norte');
        $pendiente = $this->orden('261102320010394', $sala);
        $facturada = $this->orden('2611023200103', $sala);
        $sinLeer = $this->orden(null);
        // El CCF lleva la orden con espacios: se compara normalizada.
        $ccf = $this->ccf(' 2611 0232 00103 ', $sala);
        $usuario = $this->usuario(['dte.ver']);

        $this->actingAs($usuario)->get(route('facturacion.ordenes-recibidas.index'))
            ->assertOk()
            ->assertSee('261102320010394')
            ->assertSee('Sin facturar')
            ->assertSee('Sala de prueba norte')
            ->assertSee('Sin leer')
            ->assertSee(route('facturacion.ordenes-recibidas.pdf', $sinLeer))
            ->assertDontSee(route('facturacion.ordenes-recibidas.pdf', $facturada))
            ->assertDontSee(route('facturacion.show', $ccf));

        $this->actingAs($usuario)->get(route('facturacion.ordenes-recibidas.index', ['ver' => 'todas']))
            ->assertOk()
            ->assertSee('2611023200103')
            ->assertSee(route('facturacion.show', $ccf))
            ->assertSee(route('facturacion.ordenes-recibidas.pdf', $pendiente));

        $this->assertSame([$pendiente->id], OrdenCompraRecibida::sinFacturar()->pluck('id')->all());
    }

    /** @return array<string, array{0: EstadoDte, 1: bool}> */
    public static function estadosDelCcf(): array
    {
        return [
            'rechazado: vuelve a pendiente' => [EstadoDte::Rechazado, true],
            'invalidado: vuelve a pendiente' => [EstadoDte::Invalidado, true],
            'borrador vigente: la esconde mientras se trabaja' => [EstadoDte::Borrador, false],
            'aceptado: facturada' => [EstadoDte::Aceptado, false],
        ];
    }

    /**
     * Una sola regla en la lista, en el enlace al CCF y en las que se ofrecen al facturar:
     * cuenta como facturada solo con un CCF no borrado que no esté invalidado ni rechazado.
     */
    #[DataProvider('estadosDelCcf')]
    public function test_la_orden_queda_pendiente_segun_el_estado_de_su_ccf(EstadoDte $estado, bool $pendiente): void
    {
        $sala = $this->sala('0232');
        $orden = $this->orden('261102320010394', $sala);
        $ccf = $this->ccf('261102320010394', $sala, $estado);

        $this->assertSame($pendiente ? [$orden->id] : [], OrdenCompraRecibida::sinFacturar()->pluck('id')->all());
        $this->assertSame($pendiente ? [] : [$ccf->id], array_map(fn (Dte $d) => $d->id, array_values(OrdenCompraRecibida::ccfPorNumero([$orden->numero]))));
        $this->assertSame($pendiente ? ['261102320010394'] : [], array_column(OrdenCompraRecibida::pendientesPorSucursal()[$sala->id] ?? [], 'numero'));
    }

    public function test_un_ccf_borrado_no_cuenta_como_facturada(): void
    {
        $sala = $this->sala('0232');
        $orden = $this->orden('261102320010394', $sala);
        $this->ccf('261102320010394', $sala)->delete();

        $this->assertSame([$orden->id], OrdenCompraRecibida::sinFacturar()->pluck('id')->all());
        $this->assertSame([], OrdenCompraRecibida::ccfPorNumero([$orden->numero]));
        $this->assertSame(['261102320010394'], array_column(OrdenCompraRecibida::pendientesPorSucursal()[$sala->id] ?? [], 'numero'));
    }

    public function test_filtra_por_mes(): void
    {
        $this->orden('261102320010394', null, '2026-11-05');
        $this->orden('261002320010001', null, '2026-10-20');

        $this->actingAs($this->usuario(['dte.ver']))
            ->get(route('facturacion.ordenes-recibidas.index', ['ver' => 'todas', 'mes' => '2026-10']))
            ->assertOk()
            ->assertSee('261002320010001')
            ->assertDontSee('261102320010394');

        $this->actingAs($this->usuario(['dte.ver']))
            ->get(route('facturacion.ordenes-recibidas.index', ['mes' => 'octubre']))
            ->assertSessionHasErrors('mes');
    }

    public function test_sin_permiso_de_facturacion_no_entra_ni_abre_el_pdf(): void
    {
        $orden = $this->orden('261102320010394');
        $sinPermiso = $this->usuario(['ppq.ver']);

        $this->actingAs($sinPermiso)->get(route('facturacion.ordenes-recibidas.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('facturacion.ordenes-recibidas.pdf', $orden))->assertForbidden();

        $this->actingAs($this->usuario(['dte.ver']))->get(route('facturacion.ordenes-recibidas.pdf', $orden))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // --- Al facturar ---

    public function test_al_facturar_se_ofrecen_solo_las_de_la_sala_y_sin_ccf(): void
    {
        $norte = $this->sala('0232');
        $sur = $this->sala('0045');
        // Llegó cuando la sala aún no tenía el código cargado: se resuelve ahora por código.
        $sinCodigo = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'codigo' => '0077']);
        $vieja = $this->orden('261102320010001', $norte, '2026-11-01');
        $nueva = $this->orden('261102320010002', $norte, '2026-11-05');
        $this->orden('261102320010003', $norte, '2026-11-04');
        $this->ccf('261102320010003', $norte);
        $this->orden('261100450010009', $sur);
        OrdenCompraRecibida::create([
            'numero' => '261100770010001', 'sala_codigo' => '0077', 'cliente_sucursal_id' => null,
            'pdf_path' => 'x.pdf', 'pdf_hash' => str_repeat('a', 64), 'gmail_message_id' => 'm-codigo', 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
        $this->orden(null);

        $respuesta = $this->actingAs($this->usuario(['dte.ver', 'dte.gestionar']))
            ->get(route('facturacion.create-ccf'))
            ->assertOk()
            ->assertSee('Órdenes recibidas de esta sala sin CCF');

        $porSala = $respuesta->viewData('ordenesPorSala');
        $this->assertSame([$nueva->numero, $vieja->numero], array_column($porSala[$norte->id], 'numero'));
        $this->assertSame(['261100450010009'], array_column($porSala[$sur->id], 'numero'));
        $this->assertSame(['261100770010001'], array_column($porSala[$sinCodigo->id], 'numero'));
        $this->assertCount(3, $porSala);
        $this->assertStringContainsString('Creada 05/11/2026', $porSala[$norte->id][0]['detalle']);
    }

    // --- PDF + orden ---

    public function test_pdf_con_orden_une_el_ccf_y_las_paginas_de_la_orden(): void
    {
        $sala = $this->sala('0232');
        $this->orden('261102320010394', $sala, paginas: 2);
        $dte = $this->ccf('261102320010394', $sala);
        $usuario = $this->usuario(['dte.ver']);

        $this->actingAs($usuario)->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('PDF + orden')
            ->assertSee(route('facturacion.pdf-con-orden', $dte));

        $union = $this->actingAs($usuario)->get(route('facturacion.pdf-con-orden', $dte))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();

        $paginasCcf = $this->paginas(app(DtePdfService::class)->bytes($dte));
        $this->assertSame($paginasCcf + 2, $this->paginas($union));
    }

    public function test_sin_orden_recibida_no_se_ofrece(): void
    {
        $dte = $this->ccf('261102320010394', $this->sala('0232'));
        $usuario = $this->usuario(['dte.ver']);

        $this->actingAs($usuario)->get(route('facturacion.show', $dte))->assertOk()->assertDontSee('PDF + orden');
        $this->actingAs($usuario)->get(route('facturacion.pdf-con-orden', $dte))->assertNotFound();
        $this->actingAs($this->usuario(['ppq.ver']))->get(route('facturacion.pdf-con-orden', $dte))->assertForbidden();
    }

    public function test_si_la_orden_no_se_puede_unir_avisa_y_ofrece_abrirlas_aparte(): void
    {
        $sala = $this->sala('0232');
        $this->orden('261102320010394', $sala, pdf: '%PDF-1.7 no es un pdf de verdad');
        $dte = $this->ccf('261102320010394', $sala);
        $usuario = $this->usuario(['dte.ver']);

        $this->actingAs($usuario)->get(route('facturacion.pdf-con-orden', $dte))
            ->assertRedirect(route('facturacion.show', $dte))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Abrí los dos por separado'));

        $this->actingAs($usuario)->get(route('facturacion.show', $dte))
            ->assertSee('Ver orden de compra')
            ->assertSee(route('facturacion.pdf', $dte));
    }

    public function test_el_pdf_normal_y_el_correo_siguen_siendo_solo_el_ccf(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $sala = $this->sala('0232');
        $this->orden('261102320010394', $sala, paginas: 2);
        $dte = $this->ccf('261102320010394', $sala, EstadoDte::Generado);
        $paginasCcf = $this->paginas(app(DtePdfService::class)->bytes($dte));

        $pdf = $this->actingAs($this->usuario(['dte.ver']))->get(route('facturacion.pdf', $dte))->assertOk()->getContent();
        $this->assertSame($paginasCcf, $this->paginas($pdf));

        $envio = $dte->envios()->create(['destinatario' => 'cliente@cliente.example.com', 'destinatarios' => ['cliente@cliente.example.com'], 'estado' => 'pendiente']);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn (DteCorreo $m) => $this->paginas($m->pdfBytes) === $paginasCcf);
    }
}
