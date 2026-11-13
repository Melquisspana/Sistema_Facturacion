<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La ficha de Cobros de un CCF (issue #167) se lee como la de Facturación —datos, líneas y
 * totales con las mismas piezas— y, en el mismo formato, todo lo del cobro: albarán,
 * quedan, PPQ, pagos de los TXT, saldo y estado. Datos ficticios.
 */
class CobrosFichaCcfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private Dte $ccf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Ficticio, S.A. de C.V.']);
        $sala = ClienteSucursal::create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala Ficticia Centro', 'codigo' => '0901']);

        $this->ccf = Dte::create([
            'establecimiento_id' => $this->crearEmisorDte()['estab']->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-000000000000777',
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '26090090100777',
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_gravado' => '100.00',
            'iva' => '13.00',
            'total_pagar' => '113.00',
        ]);
    }

    private function linea(int $numero, string $descripcion): void
    {
        // El CCF ya está aceptado y sus líneas son inmutables: el dato de prueba entra sin el observador.
        DteLinea::withoutEvents(fn () => DteLinea::create([
            'dte_id' => $this->ccf->id, 'numero_linea' => $numero, 'descripcion' => $descripcion,
            'cantidad' => 4, 'precio_unitario' => 2.5, 'venta_gravada' => 10,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]));
    }

    private function seguimiento(array $extra = []): CobroDocumento
    {
        return CobroDocumento::create($extra + [
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $this->ccf->id,
            'tipo_dte' => '03',
            'numero_control' => $this->ccf->numero_control,
            'fecha_emision' => '2026-09-10',
            'monto' => '113.00',
        ]);
    }

    private function ficha(CobroDocumento $documento, ?User $usuario = null)
    {
        // Lectura sin gestión. Jefatura ve la ficha sin los datos técnicos (prueba aparte).
        $usuario ??= User::factory()->create()->assignRole(RolSistema::Contabilidad->value);

        return $this->actingAs($usuario)->get(route('cobros.documentos.show', $documento));
    }

    public function test_muestra_el_ccf_como_la_ficha_del_documento(): void
    {
        $this->linea(1, 'Dulce de leche ficticio 250 g');

        $respuesta = $this->ficha($this->seguimiento())->assertOk();

        $respuesta->assertSeeText('Datos del documento');
        $respuesta->assertSee('DTE-03-M001P002-000000000000777');
        $respuesta->assertSeeText('10/09/2026');
        $respuesta->assertSeeText('Cliente Ficticio, S.A. de C.V.');
        $respuesta->assertSeeText('Sala Ficticia Centro');
        $respuesta->assertSee('26090090100777');
        $respuesta->assertSeeText('Aceptado');
        $respuesta->assertSeeText('Líneas');
        $respuesta->assertSeeText('Dulce de leche ficticio 250 g');
        $respuesta->assertSee('$2.50');
        $respuesta->assertSee('$113.00');
    }

    public function test_muestra_todo_lo_del_cobro_en_la_misma_ficha(): void
    {
        $albaran = PpqAlbaran::create([
            'dte_id' => $this->ccf->id, 'tipo_codigo' => 'AC01', 'numero_albaran' => '0901/00/4242',
            'sala_codigo' => '0901', 'fecha_albaran' => '2026-09-11', 'monto_albaran' => '113.00',
        ]);
        $solicitud = CobroSolicitud::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'SOL-FICTICIA-01', 'formato' => 'prueba',
            'archivo_nombre' => 'ficticio.xlsx',
        ]);
        $lote = PpqLote::create(['cliente_id' => $this->cliente->id, 'referencia' => 'PPQ-FICTICIO-9', 'fecha' => '2026-09-15', 'estado' => 'listo']);
        PpqItem::create([
            'ppq_lote_id' => $lote->id, 'dte_id' => $this->ccf->id, 'tipo_dte' => '03',
            'numero_control' => $this->ccf->numero_control, 'monto_dte' => '113.00',
        ]);
        $documento = $this->seguimiento(['ppq_albaran_id' => $albaran->id, 'cobro_solicitud_id' => $solicitud->id]);
        CobroEvento::create([
            'cobro_documento_id' => $documento->id, 'tipo' => 'pago', 'origen' => 'txt',
            'monto' => '100.00', 'fecha' => '2026-10-01', 'evidencia_nombre' => 'pagos-ficticios.txt',
        ]);
        $documento->recalcularPago();

        $respuesta = $this->ficha($documento->refresh())->assertOk();

        $respuesta->assertSeeText('Cobro');
        $respuesta->assertSee('0901/00/4242');
        $respuesta->assertSee('SOL-FICTICIA-01');
        $respuesta->assertSee(route('cobros.solicitudes.show', $solicitud), false);
        $respuesta->assertSee('PPQ-FICTICIO-9');
        $respuesta->assertSee(route('ppq.lotes.show', $lote), false);
        $respuesta->assertSee('pagos-ficticios.txt');
        $respuesta->assertSee('$100.00');
        $this->assertSame('13.00', $respuesta->viewData('cobro')['saldo']);
        $respuesta->assertSee('$13.00');
    }

    public function test_reconoce_el_ppq_viejo_por_numero_de_control_y_ejercicio(): void
    {
        $viejo = PpqLote::create(['cliente_id' => $this->cliente->id, 'referencia' => 'PPQ-VIEJO-1', 'fecha' => '2026-09-15', 'estado' => 'listo']);
        PpqItem::create([
            'ppq_lote_id' => $viejo->id, 'tipo_dte' => '03', 'ejercicio_control' => 2026,
            'numero_control' => 'DTE03M001P002000000000000777', 'monto_dte' => '113.00',
        ]);
        $otroAnio = PpqLote::create(['cliente_id' => $this->cliente->id, 'referencia' => 'PPQ-OTRO-ANIO', 'fecha' => '2025-09-15', 'estado' => 'listo']);
        PpqItem::create([
            'ppq_lote_id' => $otroAnio->id, 'tipo_dte' => '03', 'ejercicio_control' => 2025,
            'numero_control' => $this->ccf->numero_control, 'monto_dte' => '113.00',
        ]);

        $respuesta = $this->ficha($this->seguimiento())->assertOk();

        $respuesta->assertSee('PPQ-VIEJO-1');
        $respuesta->assertDontSee('PPQ-OTRO-ANIO');
    }

    public function test_sin_albaran_ppq_ni_pagos_lo_dice(): void
    {
        $respuesta = $this->ficha($this->seguimiento())->assertOk();

        $respuesta->assertSeeText('No está en ningún PPQ.');
        $respuesta->assertSeeText('Ningún pago informado.');
        $this->assertSame('113.00', $respuesta->viewData('cobro')['saldo']);
    }

    public function test_quien_solo_ve_cobros_ve_el_ccf_sin_enlaces_a_facturacion(): void
    {
        $this->linea(1, 'Producto ficticio');
        $usuario = User::factory()->create();
        $usuario->givePermissionTo(PermisoSistema::PpqVer->value);
        $this->assertFalse($usuario->can('view', $this->ccf));

        $respuesta = $this->ficha($this->seguimiento(), $usuario)->assertOk();

        $respuesta->assertSeeText('Producto ficticio');
        $respuesta->assertDontSee(route('facturacion.imprimir', $this->ccf), false);
    }

    public function test_sin_permiso_de_cobros_no_entra(): void
    {
        $this->ficha($this->seguimiento(), User::factory()->create())->assertForbidden();
    }

    public function test_las_lineas_no_suman_consultas(): void
    {
        $documento = $this->seguimiento();
        $this->linea(1, 'Producto ficticio 1');
        $usuario = User::factory()->create()->assignRole(RolSistema::Jefatura->value);

        $contar = function () use ($documento, $usuario): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ficha($documento->fresh(), $usuario)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $contar(); // la primera vuelta carga la caché de permisos
        $conUna = $contar();
        foreach (range(2, 6) as $n) {
            $this->linea($n, 'Producto ficticio '.$n);
        }

        $this->assertSame($conUna, $contar());
    }

    public function test_jefatura_ve_el_cobro_sin_datos_tecnicos_y_el_quedan_le_da_403(): void
    {
        $solicitud = CobroSolicitud::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'SOL-FICTICIA-02', 'formato' => 'prueba',
            'archivo_nombre' => 'ficticio.xlsx',
        ]);
        $documento = $this->seguimiento(['cobro_solicitud_id' => $solicitud->id]);
        $jefatura = User::factory()->create()->assignRole(RolSistema::Jefatura->value);

        $this->ficha($documento, $jefatura)->assertOk()
            ->assertSeeText('CCF 777')
            ->assertSeeText('Cliente Ficticio, S.A. de C.V.')
            ->assertSeeText('Sala Ficticia Centro')
            ->assertSee('$113.00')
            ->assertSee(route('facturacion.pdf', $this->ccf), false)
            ->assertDontSee($this->ccf->numero_control)
            ->assertDontSee('26090090100777')
            ->assertDontSeeText('Número de control')
            ->assertDontSeeText('Orden de compra')
            ->assertDontSeeText('ID técnico')
            ->assertDontSee(route('facturacion.imprimir', $this->ccf), false)
            ->assertDontSee('SOL-FICTICIA-02')
            ->assertDontSee(route('cobros.solicitudes.show', $solicitud), false)
            ->assertDontSeeText('(quedan)');

        $this->actingAs($jefatura)->get(route('cobros.solicitudes.show', $solicitud))->assertForbidden();
        $this->actingAs($jefatura)->post(route('cobros.solicitudes.descargar', $solicitud))->assertForbidden();

        $admin = User::factory()->create()->assignRole(RolSistema::Administrador->value);
        $this->ficha($documento, $admin)->assertOk()
            ->assertSee($this->ccf->numero_control)
            ->assertSee('26090090100777')
            ->assertSee(route('cobros.solicitudes.show', $solicitud), false)
            ->assertSee(route('facturacion.imprimir', $this->ccf), false);
        $this->actingAs($admin)->get(route('cobros.solicitudes.show', $solicitud))->assertOk();
    }

    public function test_jefatura_no_ve_codigo_ni_sello_del_documento_sin_dte(): void
    {
        $externo = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03', 'numero_control' => 'DTE-03-M001P002-000000000000555',
            'codigo_generacion' => 'CODIGO-FICTICIO-555', 'sello_recepcion' => 'SELLO-FICTICIO-555',
            'fecha_emision' => '2026-09-10', 'monto' => '50.00',
        ]);

        $this->ficha($externo, User::factory()->create()->assignRole(RolSistema::Jefatura->value))->assertOk()
            ->assertSeeText('CCF 555')
            ->assertDontSee('CODIGO-FICTICIO-555')
            ->assertDontSee('SELLO-FICTICIO-555')
            ->assertDontSee('DTE-03-M001P002-000000000000555');

        $this->ficha($externo, User::factory()->create()->assignRole(RolSistema::Administrador->value))->assertOk()
            ->assertSee('CODIGO-FICTICIO-555')
            ->assertSee('SELLO-FICTICIO-555');
    }
}
