<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Support\HoraNegocio;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Al generar un CCF de un cliente que exige orden de compra, la orden con otra forma
 * o la sala sin código AVISAN y piden confirmar; confirmado, se genera igual y queda
 * en la bitácora (#169). La auditoría lista los CCF cuya orden no cuadra. Órdenes
 * ficticias.
 */
class OrdenCompraFormaAvisoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** @var array{estab: Establecimiento, pv: PuntoVenta}|null */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Orden con la forma correcta para el mes de hoy. */
    private function ordenValida(): string
    {
        return HoraNegocio::hoy()->format('ym').'0123456789';
    }

    private function borrador(string $orden, ?string $codigoSala = '0123'): Dte
    {
        if ($this->emisor === null) {
            $this->emisor = $this->crearEmisorDte();
            Correlativo::create([
                'tipo_dte' => '03', 'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
        ['estab' => $estab, 'pv' => $pv] = $this->emisor;
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'codigo' => $codigoSala]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'numero_orden_compra' => $orden,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);

        return $dte->refresh();
    }

    public function test_orden_valida_genera_sin_pedir_confirmacion(): void
    {
        $dte = $this->borrador($this->ordenValida());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
    }

    public function test_orden_con_otra_forma_avisa_y_no_genera_sin_confirmar(): void
    {
        $dte = $this->borrador('2611012345678901');
        $usuario = $this->usuario('facturacion');

        $this->actingAs($usuario)->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->assertSee('tiene 16 dígitos y deben ser de 13 a 15')
            ->assertSeeText('Las órdenes de este cliente tienen de 13 a 15 números')
            ->assertDontSeeText('14 números')
            ->assertSee('Revisé la orden: generar igual');

        $this->actingAs($usuario)
            ->from(route('facturacion.edit', $dte))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.edit', $dte))
            ->assertSessionHasErrors('generar');

        $this->assertSame(EstadoDte::Borrador, $dte->refresh()->estado);
    }

    public function test_confirmar_deja_generar_y_queda_en_la_bitacora(): void
    {
        $dte = $this->borrador('2611512345678A');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte), ['confirmar_avisos_oc' => 1])
            ->assertRedirect(route('facturacion.show', $dte));

        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
        $registro = Activity::query()
            ->where('subject_type', $dte->getMorphClass())->where('subject_id', $dte->id)
            ->where('description', 'Generó el CCF confirmando los avisos de la orden de compra')
            ->sole();
        $this->assertSame('2611512345678A', $registro->properties['orden_compra']);
        $this->assertStringContainsString('caracteres que no son números', $registro->properties['avisos'][0]);
    }

    public function test_sala_sin_codigo_tambien_avisa(): void
    {
        $dte = $this->borrador($this->ordenValida(), codigoSala: null);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertSessionHasErrors('generar');
        $this->assertSame(EstadoDte::Borrador, $dte->refresh()->estado);
        $this->assertStringContainsString('no tiene cargado su código de sala', session('errors')->first('generar'));
    }

    public function test_orden_de_otra_sala_avisa(): void
    {
        // La orden trae la sala 0025 y la factura va para la 0250 (un 0 de más al copiarla).
        $dte = $this->borrador(HoraNegocio::hoy()->format('ym').'00250004665', codigoSala: '0250');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertSessionHasErrors('generar');
        $this->assertSame(EstadoDte::Borrador, $dte->refresh()->estado);
        $this->assertStringContainsString('es de la sala 0025 y la factura va para', session('errors')->first('generar'));
    }

    public function test_sala_con_codigo_que_no_es_de_calleja_no_compara(): void
    {
        $dte = $this->borrador($this->ordenValida(), codigoSala: 'S-101');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.show', $dte));
    }

    public function test_cliente_que_no_exige_orden_no_avisa(): void
    {
        $dte = $this->borrador('ABC');
        $dte->cliente->update(['requiere_orden_compra' => false]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.generar', $dte))
            ->assertRedirect(route('facturacion.show', $dte));
    }

    public function test_auditoria_lista_solo_los_ccf_cuya_orden_no_cuadra(): void
    {
        $malo = $this->borrador('2611012345678901');
        $bueno = $this->borrador($this->ordenValida());
        $this->borrador('99'); // borrador: todavía no se emitió
        $malo->forceFill(['estado' => EstadoDte::Generado->value, 'numero_interno' => 'INT-MALO'])->saveQuietly();
        $bueno->forceFill(['estado' => EstadoDte::Generado->value, 'numero_interno' => 'INT-BUENO'])->saveQuietly();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('auditoria.ordenes_compra'))
            ->assertOk()
            ->assertSee('INT-MALO')
            ->assertSee('tiene 16 dígitos y deben ser de 13 a 15')
            ->assertDontSee('INT-BUENO')
            ->assertDontSee('«99»', false);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('auditoria.ordenes_compra'))
            ->assertForbidden();
    }

    public function test_auditoria_lista_el_ccf_cuya_orden_es_de_otra_sala(): void
    {
        // Forma bien, pero la orden trae la sala 0025 y el CCF fue a la 0250.
        $otraSala = $this->borrador(HoraNegocio::hoy()->format('ym').'00250004665', codigoSala: '0250');
        $suSala = $this->borrador($this->ordenValida(), codigoSala: '0123');
        $otraSala->forceFill(['estado' => EstadoDte::Generado->value, 'numero_interno' => 'INT-OTRA-SALA'])->saveQuietly();
        $suSala->forceFill(['estado' => EstadoDte::Generado->value, 'numero_interno' => 'INT-SU-SALA'])->saveQuietly();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('auditoria.ordenes_compra'))
            ->assertOk()
            ->assertSee('INT-OTRA-SALA')
            ->assertSee('es de la sala 0025 y la factura va para')
            ->assertDontSee('INT-SU-SALA');
    }

    public function test_auditoria_lee_los_ajustes_lo_mismo_con_uno_que_con_muchos_ccf(): void
    {
        // Dónde va la sala en la orden es un ajuste: se lee una vez por pantalla, no una
        // vez por CCF. Cada lectura de ajustes consulta la huella de la caché (con el store
        // database, una consulta), así que se cuentan esas lecturas.
        $lecturas = 0;
        Event::listen(RetrievingKey::class, function (RetrievingKey $evento) use (&$lecturas): void {
            if ($evento->key === 'ajustes:huella') {
                $lecturas++;
            }
        });
        $admin = $this->usuario('administrador');
        $contar = function () use ($admin, &$lecturas): int {
            $lecturas = 0;
            $this->actingAs($admin)->get(route('auditoria.ordenes_compra'))->assertOk();

            return $lecturas;
        };
        $emitir = function (string $orden, string $sala, string $numero): void {
            $this->borrador($orden, codigoSala: $sala)
                ->forceFill(['estado' => EstadoDte::Generado->value, 'numero_interno' => $numero])->saveQuietly();
        };
        $otraSala = HoraNegocio::hoy()->format('ym').'00250004665';

        $emitir($otraSala, '0250', 'INT-1');
        $conUno = $contar();

        $emitir($otraSala, '0250', 'INT-2');
        $emitir($otraSala, '0250', 'INT-3');
        $emitir($this->ordenValida(), '0123', 'INT-4');
        $emitir($this->ordenValida(), '0123', 'INT-5');
        $conCinco = $contar();

        $this->assertGreaterThan(0, $conUno);
        $this->assertSame($conUno, $conCinco);
    }

    public function test_auditoria_explica_la_forma_real_de_la_orden(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('auditoria.ordenes_compra'))
            ->assertOk()
            ->assertSeeText('de 13 a 15 números')
            ->assertSeeText('el quinto es un 0')
            ->assertDontSeeText('14 números');
    }
}
