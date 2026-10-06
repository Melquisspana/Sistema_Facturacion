<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * La bandeja de Cobros con CIENTOS de documentos: paginación real, sin pérdidas ni
 * duplicados entre páginas, con filtros y cliente conservados, y la preparación del
 * archivo de quedan limitada a lo que se ve.
 *
 * Antes la bandeja cortaba en 500 y lo demás no existía para quien cobraba.
 */
class CobrosBandejaPaginacionTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private int $n = 0;

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

    /**
     * Documento con fecha creciente: el n-ésimo es el n-ésimo más antiguo. `$datos` solo
     * admite columnas asignables; los estados de pago o revisión van con forceFill.
     */
    private function documento(Cliente $cliente, array $datos = []): CobroDocumento
    {
        $this->n++;

        return CobroDocumento::create(array_merge([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => Carbon::parse('2026-01-01')->addDays($this->n)->toDateString(),
            'monto' => '123.74',
        ], $datos));
    }

    /** CCF LISTO: albarán de entrega completo, sin pagos ni revisión. */
    private function listo(Cliente $cliente): CobroDocumento
    {
        $documento = $this->documento($cliente);
        $dte = $this->dteVerificableCobros(
            $cliente,
            $documento->numero_control,
            $documento->fecha_emision->toDateString(),
            $documento->monto,
        );
        $documento->forceFill(['origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id])->save();
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.(5000 + $this->n),
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => '0017',
        ]);
        $documento->forceFill([
            'ppq_albaran_id' => $albaran->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ])->save();

        return $documento;
    }

    private function bandeja(Cliente $cliente, array $params = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())
            ->get(route('cobros.index', ['cliente_id' => $cliente->id] + $params));
    }

    // ------------------------------------------------------------------ paginación

    public function test_las_paginas_cubren_todo_sin_perder_ni_repetir(): void
    {
        $cliente = $this->cliente();
        $documentos = collect(range(1, 30))->map(fn () => $this->documento($cliente));

        $pagina1 = $this->bandeja($cliente)->assertOk();
        $pagina2 = $this->bandeja($cliente, ['page' => 2])->assertOk();

        $pagina1->assertSeeText('Mostrando 1–25 de 30');
        $pagina2->assertSeeText('Mostrando 26–30 de 30');

        foreach ($documentos as $i => $doc) {
            if ($i >= 5) {
                $pagina1->assertSee($doc->numero_control);
                $pagina2->assertDontSee($doc->numero_control);
            } else {
                $pagina1->assertDontSee($doc->numero_control);
                $pagina2->assertSee($doc->numero_control);
            }
        }

        // El orden por recencia se mantiene dentro de la página.
        $html = $pagina2->getContent();
        $this->assertLessThan(strpos($html, $documentos[0]->numero_control), strpos($html, $documentos[4]->numero_control));
    }

    public function test_los_filtros_y_el_cliente_viajan_entre_paginas_y_los_contadores_siguen_globales(): void
    {
        $cliente = $this->cliente();
        foreach (range(1, 3) as $_) {
            $this->documento($cliente); // pendientes: fuera del filtro
        }
        // El pago no es asignable en masa (se deriva de eventos): como en el resto de las
        // pruebas de Cobros, se fija con forceFill después de crear.
        $pagados = collect(range(1, 27))->map(function () use ($cliente) {
            $doc = $this->documento($cliente);
            $doc->forceFill(['pago_estado' => EstadoPagoCobro::Pagado->value, 'monto_pagado' => '123.74'])->save();

            return $doc;
        });

        $this->assertSame(27, CobroDocumento::where('pago_estado', EstadoPagoCobro::Pagado->value)->count(), 'Fixture: 27 guardados como pagados.');

        $pagina1 = $this->bandeja($cliente, ['pago' => 'pagado'])->assertOk();

        // Total de la lista filtrada frente a total del cliente, por separado.
        $this->assertSame(27, $pagina1->viewData('documentos')->total());
        $pagina1->assertSeeText('Mostrando 1–25 de 27');

        // El enlace a la página 2 conserva cliente y filtro.
        $enlace = $pagina1->viewData('documentos')->url(2);
        $this->assertStringContainsString('cliente_id='.$cliente->id, $enlace);
        $this->assertStringContainsString('pago=pagado', $enlace);

        $pagina2 = $this->get($enlace)->assertOk();
        $this->assertSame(2, $pagina2->viewData('documentos')->currentPage());
        $pagina2->assertSee($pagados[0]->numero_control);
        $pagina2->assertDontSee($pagados[26]->numero_control);
        $this->assertTrue($pagina2->viewData('documentos')->every(
            fn (CobroDocumento $d) => $d->pago_estado === EstadoPagoCobro::Pagado
        ), 'La página 2 respeta el filtro.');
    }

    public function test_una_pagina_fuera_de_rango_ofrece_volver_a_la_primera(): void
    {
        $cliente = $this->cliente();
        $this->documento($cliente);

        $respuesta = $this->bandeja($cliente, ['page' => 9, 'tipo' => '03'])->assertOk();

        $respuesta->assertSeeText('Esta página no existe');
        $respuesta->assertDontSeeText('No hay CCF aquí');
        $primera = $respuesta->viewData('documentos')->url(1);
        $this->assertStringContainsString('cliente_id='.$cliente->id, $primera);
        $this->assertStringContainsString('tipo=03', $primera);
        $respuesta->assertSee(e($primera), false);
    }

    // ------------------------------------------------------------------ preparación

    public function test_solo_se_marca_lo_listo_de_la_pagina_y_el_servidor_sigue_validando(): void
    {
        $cliente = $this->cliente();
        $bloqueados = collect(range(1, 25))->map(fn () => $this->documento($cliente)); // sin albarán
        $listo = $this->listo($cliente);                                                 // más reciente: página 1

        $pagina1 = $this->bandeja($cliente)->assertOk();
        $pagina1->assertSee('value="'.$listo->id.'"', false);
        $pagina1->assertSeeText('1 listo(s) en esta página');

        $pagina2 = $this->bandeja($cliente, ['page' => 2])->assertOk();
        // Sin casillas: lo marcado en la página 1 solo viaja oculto (selección entre páginas).
        $pagina2->assertDontSee('type="checkbox" name="documentos[]"', false);
        $pagina2->assertSeeText('0 listo(s) en esta página');
        $pagina1->assertSeeText('Crear PPQ con lo marcado');

        $usuario = $this->usuario();

        // Un bloqueado colado en el envío se rechaza entero: nada se prepara.
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.previa', ['cliente' => $cliente, 'page' => 1]), ['documentos' => [$listo->id, $bloqueados[0]->id]])
            ->assertSessionHasErrors('documentos');
        // Y sin pasar por la vista previa, tampoco.
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $cliente), ['documentos' => [$listo->id]])
            ->assertSessionHasErrors('documentos');
        $this->assertSame(0, CobroSolicitud::count());

        // Lo listo, solo, sí: vista previa y confirmación.
        $previa = $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.previa', ['cliente' => $cliente, 'page' => 1]), ['documentos' => [$listo->id]])
            ->assertOk();
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $cliente), ['previa' => $previa->viewData('token')])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CobroSolicitud::count());
        $this->assertNotNull($listo->refresh()->cobro_solicitud_id);
    }

    public function test_cada_fila_dice_si_esta_lista_o_su_primer_motivo(): void
    {
        $cliente = $this->cliente();
        $sinAlbaran = $this->documento($cliente);
        $historico = $this->listo($cliente);
        $historico->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Ya viajó en PPQ-JUNIO.'])->save();
        $nc = $this->documento($cliente, ['tipo_dte' => '05', 'numero_control' => 'DTE-05-M001P002-000000000000900']);
        $this->listo($cliente);

        $respuesta = $this->bandeja($cliente)->assertOk();

        $respuesta->assertSeeText('No entregado');
        $respuesta->assertSeeText('Anterior al seguimiento');
        $respuesta->assertSee(route('cobros.documentos.show', $sinAlbaran), false);
        // Las NC no son filas de la lista: van debajo de su CCF, o sueltas con tipo=05.
        $respuesta->assertDontSee('value="'.$nc->id.'"', false);
        $this->bandeja($cliente, ['tipo' => '05'])->assertOk()->assertSeeText('Va con su CCF');
        $respuesta->assertDontSee('value="'.$historico->id.'"', false);
        $respuesta->assertSeeText('1 listo(s) en esta página');
    }

    public function test_solo_lectura_ve_la_preparacion_pero_no_puede_marcar(): void
    {
        $cliente = $this->cliente();
        $this->listo($cliente);

        $this->bandeja($cliente, [], $this->usuario(RolSistema::Jefatura))
            ->assertOk()
            ->assertSeeText('Entregado · listo')
            ->assertDontSee('name="documentos[]"', false)
            ->assertDontSee('Crear PPQ con lo marcado')
            ->assertSeeText('Solo lectura');
    }
}
