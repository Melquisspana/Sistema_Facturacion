<?php

namespace Tests\Feature\Facturacion;

use App\Enums\TipoDte;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Exportaciones\VincularFexALista;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Integridad del vínculo Lista ↔ Factura de exportación, atacada desde donde de
 * verdad se rompe: peticiones armadas a mano, parámetros de URL manipulados,
 * concurrencia y filas históricas.
 *
 * Ninguna de estas comprobaciones puede vivir en la vista. Ocultar un botón evita
 * el error honesto; no evita un `POST` con otro `dte_id`.
 */
class ListaEmpaqueIntegridadFexTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Payload MÍNIMO válido del formulario real de FEX. */
    private function datosFex(Cliente $cliente, array $extra = []): array
    {
        $this->seedCatalogosMh();

        return $extra + $this->emisor() + [
            'tipo_dte' => '11',
            'cliente_id' => $cliente->id,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ];
    }

    private function seedCatalogosMh(): void
    {
        if (CatalogoMh::query()->exists()) {
            return;
        }

        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
    }

    /** @return array{establecimiento_id: int, punto_venta_id: int} */
    private function emisor(): array
    {
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function dte(Cliente $cliente, array $extra = []): Dte
    {
        return Dte::create($extra + $this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value,
            'cliente_id' => $cliente->id,
            'estado' => 'generado',
            'ambiente' => '00',
            'numero_control' => 'DTE-11-M001P001-'.str_pad((string) random_int(1, 999999), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-09-01',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    /** @return array{cliente: Cliente, perfil: ExportacionCliente, lista: Exportacion} */
    private function escenario(array $extraLista = []): array
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'CAROLINAS WHOLESALE LLC']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);
        $lista = Exportacion::create($extraLista + [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        return ['cliente' => $cliente, 'perfil' => $perfil, 'lista' => $lista];
    }

    // ============================================ tipo, estado, cliente, ambiente

    public function test_rechaza_un_documento_que_no_es_factura_de_exportacion(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();

        $ccf = $this->dte(Cliente::factory()->contribuyente()->create(), [
            'tipo_dte' => TipoDte::CreditoFiscal->value,
        ]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $ccf->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $lista->fresh()->facturas());
    }

    /**
     * El caso de manipulación de URL más directo: la lista es de un importador y la
     * factura está emitida a otro. La vista nunca ofrecería ese `dte_id`; la petición
     * a mano sí puede mandarlo.
     */
    public function test_rechaza_una_factura_emitida_a_otro_cliente(): void
    {
        ['lista' => $lista] = $this->escenario();

        $otro = Cliente::factory()->exportacion()->create(['nombre' => 'SOLFI GROUP INC.']);
        $fexAjena = $this->dte($otro);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fexAjena->id])
            ->assertSessionHas('error');

        $lista->refresh();
        $this->assertCount(0, $lista->facturas());
        $this->assertNull($lista->dte_id);
    }

    /** @return list<array{0: string}> */
    public static function estadosNoVinculables(): array
    {
        return [
            'rechazado por Hacienda' => ['rechazado'],
            'invalidado' => ['invalidado'],
        ];
    }

    #[DataProvider('estadosNoVinculables')]
    public function test_rechaza_una_factura_anulada_o_rechazada(string $estado): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->dte($cliente, ['estado' => $estado]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $lista->fresh()->facturas());
    }

    public function test_rechaza_mezclar_ambientes_en_una_misma_lista(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();

        $pruebas = $this->dte($cliente, ['ambiente' => '00']);
        $produccion = $this->dte($cliente, ['ambiente' => '01']);

        $usuario = $this->usuario();
        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $pruebas->id])
            ->assertSessionHas('status');

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $produccion->id])
            ->assertSessionHas('error');

        $this->assertCount(1, $lista->fresh()->facturas());
    }

    public function test_una_lista_sin_cliente_del_directorio_no_admite_facturas(): void
    {
        $perfilSuelto = ExportacionCliente::create(['nombre' => 'SIN VINCULAR', 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfilSuelto->id,
            'cliente_nombre' => 'SIN VINCULAR',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $fex = $this->dte(Cliente::factory()->exportacion()->create());

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $lista->fresh()->facturas());
    }

    // ================================================== unicidad y duplicación

    public function test_vincular_dos_veces_la_misma_factura_es_idempotente(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->dte($cliente);
        $usuario = $this->usuario();

        foreach ([1, 2] as $_) {
            $this->actingAs($usuario)
                ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->count());
        $this->assertCount(1, $lista->fresh()->facturas());
    }

    public function test_una_factura_que_ya_es_dte_id_de_otra_lista_no_se_puede_robar(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil, 'lista' => $primera] = $this->escenario();
        $fex = $this->dte($cliente);

        // Estado de una instalación a medio migrar: columna puesta, pivote vacío.
        DB::table('exportaciones')->where('id', $primera->id)->update(['dte_id' => $fex->id]);

        $segunda = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-02',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $segunda), ['dte_id' => $fex->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $segunda->fresh()->facturas());
        $this->assertSame($fex->id, $primera->fresh()->dte_id);
    }

    /**
     * Concurrencia: dos peticiones intentan vincular la MISMA factura a dos listas a
     * la vez. Gane la que gane, solo puede quedar una.
     */
    public function test_dos_intentos_simultaneos_no_dejan_la_factura_en_dos_listas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil, 'lista' => $primera] = $this->escenario();
        $segunda = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-02',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $fex = $this->dte($cliente);

        $servicio = app(VincularFexALista::class);
        $exitos = 0;

        foreach ([$primera, $segunda] as $lista) {
            try {
                $servicio->vincular($lista, $fex);
                $exitos++;
            } catch (ValidationException) {
                // El segundo pierde: es lo correcto.
            }
        }

        $this->assertSame(1, $exitos, 'solo una de las dos listas puede quedarse con la factura');
        $this->assertSame(1, DB::table('exportacion_dte')->where('dte_id', $fex->id)->count());
    }

    // ==================================== invariante de la columna de compatibilidad

    /**
     * `exportaciones.dte_id` SIEMPRE apunta a una factura que está en el pivote de esa
     * lista, o a NULL. Se recorre el ciclo completo —vincular, vincular otra, quitar la
     * principal, quitar la última— comprobando el invariante en cada paso.
     */
    public function test_la_columna_de_compatibilidad_nunca_se_desincroniza(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $primera = $this->dte($cliente);
        $segunda = $this->dte($cliente);
        $usuario = $this->usuario();

        $comprobar = function () use ($lista) {
            $lista->refresh();
            $enPivote = DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->pluck('dte_id')->all();

            if ($lista->dte_id === null) {
                $this->assertSame([], $enPivote, 'sin dte_id no puede haber vínculos en el pivote');

                return;
            }

            $this->assertContains($lista->dte_id, $enPivote, 'dte_id apunta a un vínculo que no existe');
            $this->assertSame(
                1,
                DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->where('principal', true)->count(),
                'debe haber exactamente una factura principal'
            );
            $this->assertSame(
                $lista->dte_id,
                DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->where('principal', true)->value('dte_id'),
                'dte_id y la principal del pivote tienen que coincidir'
            );
        };

        $comprobar();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $primera->id]);
        $comprobar();
        $this->assertSame($primera->id, $lista->fresh()->dte_id);

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $segunda->id]);
        $comprobar();
        $this->assertSame($primera->id, $lista->fresh()->dte_id, 'la principal no se reasigna al vincular la segunda');

        // Quitar la principal: la columna pasa a la que queda.
        $this->actingAs($usuario)->delete(route('facturacion.listas.facturas.desvincular', [$lista, $primera]));
        $comprobar();
        $this->assertSame($segunda->id, $lista->fresh()->dte_id);

        // Quitar la última: vuelve a NULL.
        $this->actingAs($usuario)->delete(route('facturacion.listas.facturas.desvincular', [$lista, $segunda]));
        $comprobar();
        $this->assertNull($lista->fresh()->dte_id);
    }

    // ================================================ finalizar exige factura viva

    public function test_no_se_finaliza_con_una_unica_factura_rechazada(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->dte($cliente);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id]);

        // La factura se rechaza DESPUÉS de vincularla: es el caso real.
        $fex->update(['estado' => 'rechazado']);

        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('error');

        $lista->refresh();
        $this->assertFalse($lista->estaFinalizada());
        // Sigue vinculada: es parte del historial y explica qué pasó.
        $this->assertCount(1, $lista->facturas());
        $this->assertCount(0, $lista->facturasVigentes());
    }

    public function test_se_finaliza_si_queda_al_menos_una_factura_vigente(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $rechazada = $this->dte($cliente);
        $buena = $this->dte($cliente);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $rechazada->id]);
        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $buena->id]);
        $rechazada->update(['estado' => 'rechazado']);

        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('status');

        $this->assertTrue($lista->fresh()->estaFinalizada());
    }

    // ======================================= crear la FEX desde ?lista= sin trampas

    /**
     * El formulario de FEX permite elegir el receptor. Si alguien abre el formulario
     * con `?lista=` de un importador y guarda la factura a nombre de OTRO, la factura
     * se crea igual —es válida— pero NO queda vinculada, y se avisa.
     */
    public function test_crear_la_fex_desde_lista_no_vincula_si_se_cambio_el_cliente(): void
    {
        ['lista' => $lista] = $this->escenario();
        $otro = Cliente::factory()->exportacion()->create(['nombre' => 'SOLFI GROUP INC.']);

        $resp = $this->actingAs($this->usuario())
            ->post(route('facturacion.store-exportacion'), $this->datosFex($otro, ['lista_id' => $lista->id]));

        $resp->assertSessionDoesntHaveErrors()->assertRedirect();

        $lista->refresh();
        $this->assertCount(0, $lista->facturas(), 'la factura es de otro receptor: no puede vincularse');
        $this->assertNull($lista->dte_id);
        $this->assertStringContainsString('No se pudo vincular', (string) session('status'));

        // La factura SÍ se creó: ya es un documento válido y perderla sería peor.
        $this->assertSame(1, Dte::where('tipo_dte', TipoDte::FacturaExportacion->value)->count());
    }

    public function test_crear_la_fex_desde_lista_vincula_cuando_el_cliente_coincide(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-exportacion'), $this->datosFex($cliente, ['lista_id' => $lista->id]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $lista->refresh();
        $this->assertCount(1, $lista->facturas());
        $this->assertNotNull($lista->dte_id);
        $this->assertStringContainsString('vinculada a la lista de empaque', (string) session('status'));
    }

    public function test_una_lista_finalizada_no_admite_facturas_nuevas_ni_desvincular(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $primera = $this->dte($cliente);
        $otra = $this->dte($cliente);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $primera->id]);
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista));

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $otra->id])
            ->assertSessionHas('error');
        $this->actingAs($usuario)->delete(route('facturacion.listas.facturas.desvincular', [$lista, $primera]))
            ->assertSessionHas('error');

        $this->assertCount(1, $lista->fresh()->facturas());
    }
}
