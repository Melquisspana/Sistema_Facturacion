<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\GeneracionException;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\SaldoMontoCcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * SALDO ACREDITABLE de un CCF frente a notas de crédito que ya no pueden llegar a
 * Hacienda.
 *
 * Nace del caso real CCF #145 / NC #150: la NC fue RECHAZADA por Hacienda
 * ("[resumen.montoTotalOperacion] CALCULO INCORRECTO") y archivada para sacarla de la
 * operación, pero sus líneas seguían consumiendo todo el saldo del CCF, dejando
 * imposible emitir la NC corregida.
 *
 * Regla (única fuente: SaldoMontoCcf):
 *  - INVALIDADA → libera saldo.
 *  - RECHAZADA **y ARCHIVADA** → libera saldo.
 *  - RECHAZADA sin archivar y BORRADOR → no reservan saldo.
 *  - generada / firmada / enviada / aceptada → consumen sin invalidación real.
 *
 * Archivar nunca modifica ni elimina la NC rechazada: solo cambia dónde se la ve.
 */
class DteSaldoAcreditableArchivadoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** CCF ACEPTADO por Hacienda con una línea gravada 10 × 10 (saldo inicial 10). */
    private function ccfAceptado(int $cantidad = 10): Dte
    {
        static $n = 0;
        $n++; // punto de venta propio por CCF: numero_interno es único por ambiente

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte('M001', 'P'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
        foreach (['03', '05'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $estab->id,
                'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: $cantidad);

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    /**
     * NC que acredita TODAS las unidades del CCF y queda en el estado indicado; deja el
     * saldo del CCF en 0 mientras siga consumiendo. `estado` está en la whitelist del
     * DteObserver, así que llevarla a su estado final es una actualización válida.
     */
    private function ncQueConsumeTodo(Dte $ccf, EstadoDte $estado): Dte
    {
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: (string) $ccf->lineas()->first()->cantidad);
        $nc->update(['estado' => $estado->value]);

        return $nc->refresh();
    }

    /** Archiva/desarchiva por la acción real de la aplicación, no tocando columnas a mano. */
    private function archivar(Dte $nc, bool $archivar = true): void
    {
        $ruta = $archivar ? 'facturacion.archivar' : 'facturacion.desarchivar';

        $this->actingAs($this->usuario())
            ->post(route($ruta, $nc))
            ->assertRedirect()
            ->assertSessionHas('status');

        $nc->refresh();
    }

    /** Intenta acreditar `$cantidad` en una NC nueva: la vía por la que se ve el saldo. */
    private function acreditarEnNcNueva(Dte $ccf, float|int $cantidad): DteLinea
    {
        $otra = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);

        return $this->borradores->acreditarLinea($otra, $ccf->lineas()->first(), cantidad: $cantidad);
    }

    // ---------- Qué consume y qué libera saldo ----------

    public function test_nc_rechazada_sin_archivar_libera_toda_la_linea(): void
    {
        $ccf = $this->ccfAceptado(5);
        $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);
        $this->assertSame('5.0000', (string) $this->acreditarEnNcNueva($ccf, 5)->cantidad);
    }

    public function test_invalidacion_mock_sigue_pesando_en_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Aceptado);
        $nc->update(['sello_invalidacion' => 'mOcK-INVAL-123']);
        $saldo = app(SaldoMontoCcf::class);
        $this->assertSame('0.00', $saldo->saldo($ccf));
        $this->assertSame($nc->total_pagar, $saldo->descontadoEnCobro([$ccf->id])[$ccf->id]);
        $otra = $this->borradores->crearNotaCredito($ccf);
        $otra->monto_total_operacion = '1';
        $this->assertNotNull($saldo->exceso($otra));
    }

    public function test_exceso_frena_gravado_aunque_quepa_en_total(): void
    {
        $ccf = $this->ccfAceptado();
        DB::table('dtes')->where('id', $ccf->id)->update(['total_exento' => '100', 'monto_total_operacion' => '213']);
        $ccf->refresh();
        $nc = $this->borradores->crearNotaCredito($ccf);
        $nc->monto_total_operacion = '114.13';
        $nc->total_gravado = '101';
        $this->assertStringContainsString('saldo gravado', app(SaldoMontoCcf::class)->exceso($nc) ?? '');
    }

    public function test_generar_segunda_nc_frena_exceso_sin_consumir_correlativo(): void
    {
        $ccf = $this->ccfAceptado();
        $primera = $this->borradores->crearNotaCredito($ccf);
        $segunda = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($primera, $ccf->lineas()->first(), 9);
        $this->borradores->acreditarLinea($segunda, $ccf->lineas()->first(), 9);
        app(DteGeneracionService::class)->generar($primera);
        $antes = Correlativo::where('tipo_dte', '05')->first()->ultimo_numero;
        try {
            app(DteGeneracionService::class)->generar($segunda);
            $this->fail('La segunda NC debe fallar.');
        } catch (GeneracionException $e) {
            $this->assertStringContainsString('CCF', $e->getMessage());
        }
        $this->assertSame(EstadoDte::Borrador, $segunda->refresh()->estado);
        $this->assertSame($antes, Correlativo::where('tipo_dte', '05')->first()->ultimo_numero);
    }

    public function test_generar_dos_borradores_frena_por_cantidad_de_linea(): void
    {
        $ccf = $this->ccfAceptado();
        $primera = $this->borradores->crearNotaCredito($ccf);
        $segunda = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($primera, $ccf->lineas()->first(), 10);
        $this->borradores->acreditarLinea($segunda, $ccf->lineas()->first(), 10);
        app(DteGeneracionService::class)->generar($primera);
        // Aísla el candado de cantidades del candado de importes.
        $segunda->update(['monto_total_operacion' => '0', 'total_gravado' => '0']);
        $this->expectException(GeneracionException::class);
        $this->expectExceptionMessage('La línea 1 del CCF');
        app(DteGeneracionService::class)->generar($segunda);
    }

    public function test_guarda_tope_excluye_lo_acreditado_en_esta_nota(): void
    {
        $ccf = $this->ccfAceptado(5);
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), 3);
        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))->assertOk()
            ->assertViewHas('lineasOriginales', fn ($lineas) => (float) $lineas->first()['tope'] === 5.0
                && (float) $lineas->first()['acreditado'] === 0.0
                && (float) $lineas->first()['en_esta_nc'] === 3.0);
    }

    public function test_establecer_cantidad_reemplaza_todas_las_acreditaciones_propias(): void
    {
        $ccf = $this->ccfAceptado(5);
        $nc = $this->borradores->crearNotaCredito($ccf);
        $original = $ccf->lineas()->first();
        $this->borradores->acreditarLinea($nc, $original, 2);
        $this->borradores->acreditarLinea($nc, $original, 1);
        $this->borradores->establecerCantidadAcreditada($nc, $original, 4);
        $this->assertCount(1, $nc->lineas()->get());
        $this->assertSame(4.0, (float) $nc->lineas()->sum('cantidad'));
        $this->borradores->acreditarLinea($nc, $original, 1);
        $this->borradores->establecerCantidadAcreditada($nc, $original, 0);
        $this->assertCount(0, $nc->lineas()->get());
    }

    public function test_editor_suma_las_acreditaciones_propias_de_la_misma_linea(): void
    {
        $ccf = $this->ccfAceptado(5);
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), 2);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), 1);
        $this->actingAs($this->usuario())->get(route('facturacion.edit', $nc))->assertOk()
            ->assertViewHas('lineasOriginales', fn ($lineas) => (float) $lineas->first()['en_esta_nc'] === 3.0
                && (float) $lineas->first()['tope'] === 5.0);
    }

    public function test_nc_rechazada_sin_archivar_no_reserva_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        $this->assertSame('10.00', $this->acreditarEnNcNueva($ccf, 1)->venta_gravada);
    }

    public function test_nc_rechazada_y_archivada_libera_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        $this->archivar($nc);

        // El CCF vuelve a tener sus 10 unidades disponibles, no una fracción.
        $linea = $this->acreditarEnNcNueva($ccf, 10);
        $this->assertSame('100.00', $linea->venta_gravada);
    }

    public function test_desarchivar_rechazada_no_reserva_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        $this->archivar($nc);
        $this->archivar($nc, archivar: false);

        $this->assertFalse($nc->refresh()->estaArchivado());

        $this->assertSame('10.00', $this->acreditarEnNcNueva($ccf, 1)->venta_gravada);
    }

    public function test_nc_invalidada_libera_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Invalidado);

        $this->assertFalse($nc->estaArchivado()); // libera por invalidación, no por archivado

        $linea = $this->acreditarEnNcNueva($ccf, 10);
        $this->assertSame('100.00', $linea->venta_gravada);
    }

    public function test_nc_aceptada_consume_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $this->ncQueConsumeTodo($ccf, EstadoDte::Aceptado);

        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->acreditarEnNcNueva($ccf, 1);
    }

    public function test_los_estados_en_curso_consumen_el_saldo(): void
    {
        foreach ([EstadoDte::Generado, EstadoDte::Firmado, EstadoDte::Enviado] as $estado) {
            $ccf = $this->ccfAceptado();
            $this->ncQueConsumeTodo($ccf, $estado);

            try {
                $this->acreditarEnNcNueva($ccf, 1);
                $this->fail('Una NC en estado '.$estado->value.' debe seguir consumiendo saldo.');
            } catch (SaldoAcreditableExcedidoException) {
                $this->assertTrue(true);
            }
        }
    }

    // ---------- El caso real: reemitir la NC tras archivar la rechazada ----------

    public function test_revertir_ccf_completo_funciona_despues_de_archivar_la_nc_rechazada(): void
    {
        $ccf = $this->ccfAceptado();
        $rechazada = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        // El rechazo es terminal: la reversión no depende del archivo.
        $this->assertCount(1, $this->borradores->revertirCcfCompleto($ccf, $this->usuario())->lineas);

        $this->archivar($rechazada);

        // Ya archivada, la reversión total reconstruye la NC corregida completa.
        $nueva = $this->borradores->revertirCcfCompleto($ccf->refresh(), $this->usuario());

        $this->assertCount(1, $nueva->lineas);
        $this->assertSame('10.0000', (string) $nueva->lineas->first()->cantidad);
        $this->assertSame($ccf->total_pagar, $nueva->total_pagar);
        $this->assertNotSame($rechazada->id, $nueva->id); // documento nuevo, no reuso
    }

    public function test_la_pantalla_de_la_nc_muestra_el_saldo_liberado(): void
    {
        $ccf = $this->ccfAceptado();
        $rechazada = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);
        $nueva = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);

        // Una rechazada no reserva, incluso antes de archivarla.
        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nueva))
            ->assertOk()
            ->assertViewHas('lineasOriginales', function ($lineas) {
                return (float) $lineas->first()['acreditado'] === 0.0
                    && (float) $lineas->first()['disponible'] === 10.0;
            });

        $this->archivar($rechazada);

        // …y archivada vuelve a ofrecer las 10 unidades.
        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nueva))
            ->assertOk()
            ->assertViewHas('lineasOriginales', function ($lineas) {
                return (float) $lineas->first()['acreditado'] === 0.0
                    && (float) $lineas->first()['disponible'] === 10.0;
            });
    }

    // ---------- La NC rechazada se conserva intacta ----------

    public function test_archivar_no_modifica_ni_elimina_la_nc_rechazada(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        $antes = $nc->only([
            'estado', 'numero_control', 'codigo_generacion', 'total_gravado', 'iva',
            'total_pagar', 'dte_relacionado_id', 'correlativo_id', 'json_generado_path',
        ]);
        $lineasAntes = $nc->lineas()->orderBy('id')
            ->get(['id', 'dte_id', 'numero_linea', 'cantidad', 'venta_gravada', 'dte_linea_original_id'])->toArray();
        $correlativosAntes = Correlativo::orderBy('id')->get(['id', 'tipo_dte', 'ambiente', 'ultimo_numero'])->toArray();

        $this->archivar($nc);
        $this->borradores->revertirCcfCompleto($ccf->refresh(), $this->usuario());

        $nc->refresh();
        $this->assertSame($antes, $nc->only(array_keys($antes)));
        $this->assertSame(EstadoDte::Rechazado, $nc->estado); // sigue rechazada, no "anulada"
        $this->assertEquals($lineasAntes, $nc->lineas()->orderBy('id')
            ->get(['id', 'dte_id', 'numero_linea', 'cantidad', 'venta_gravada', 'dte_linea_original_id'])->toArray());

        // Sigue existiendo (ni borrada ni soft-deleted) y su correlativo no se reutilizó.
        $this->assertDatabaseHas('dtes', ['id' => $nc->id, 'estado' => EstadoDte::Rechazado->value, 'archivado' => true]);
        $this->assertNotNull(Dte::withTrashed()->find($nc->id));
        $this->assertNull(Dte::withTrashed()->find($nc->id)->deleted_at);
        $this->assertEquals($correlativosAntes, Correlativo::orderBy('id')->get(['id', 'tipo_dte', 'ambiente', 'ultimo_numero'])->toArray());
    }
}
