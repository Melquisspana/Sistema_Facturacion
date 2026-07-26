<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
 * Regla (única fuente: `Dte::scopeConsumeSaldoAcreditable()`):
 *  - INVALIDADA → libera saldo.
 *  - RECHAZADA **y ARCHIVADA** → libera saldo.
 *  - RECHAZADA sin archivar → SIGUE consumiendo (puede corregirse y reintentarse).
 *  - borrador / generada / firmada / enviada / aceptada → consumen siempre.
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
    private function ccfAceptado(): Dte
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
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);

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
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 10);
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

    public function test_nc_rechazada_sin_archivar_sigue_consumiendo_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        // Puede corregirse y reintentarse: el saldo sigue reservado hasta archivarla.
        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->acreditarEnNcNueva($ccf, 1);
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

    public function test_desarchivar_vuelve_a_consumir_el_saldo(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncQueConsumeTodo($ccf, EstadoDte::Rechazado);

        $this->archivar($nc);
        $this->archivar($nc, archivar: false);

        $this->assertFalse($nc->refresh()->estaArchivado());

        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->acreditarEnNcNueva($ccf, 1);
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
        foreach ([EstadoDte::Borrador, EstadoDte::Generado, EstadoDte::Firmado, EstadoDte::Enviado] as $estado) {
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

        // Antes de archivar, el CCF no tiene saldo y la reversión total falla.
        try {
            $this->borradores->revertirCcfCompleto($ccf, $this->usuario());
            $this->fail('Con la NC rechazada sin archivar no debería quedar saldo que revertir.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
        }

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

        // Con la rechazada sin archivar la pantalla no ofrece saldo…
        $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nueva))
            ->assertOk()
            ->assertViewHas('lineasOriginales', function ($lineas) {
                return (string) $lineas->first()['acreditado'] === '10'
                    && (float) $lineas->first()['disponible'] === 0.0;
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
