<?php

namespace Tests\Feature\Alertas;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPedidoWeb;
use App\Mail\AlertaSistemaCorreo;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoWeb;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Alertas\Avisador;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\QuitarItemPpq;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Segunda vuelta del probador de los vigilantes: que cada aviso tenga por dónde cerrarse
 * con lo que la oficina hace de verdad (quitar del PPQ, invalidar la nota, cargar el
 * sello, corregir el dato), el Inicio con varios avisos y la caché vaciada. Cada 5
 * minutos corre `alertas:revisar` y se cuentan los correos. Datos inventados.
 */
class ProbadorSegundaVueltaVigilantesTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const COBROS = 'CCF fuera de regla en Cobros';

    private const SELLOS = 'Documentos sin el sello de Hacienda';

    private const DATOS = 'Datos que no cuadran';

    private ?Cliente $cliente = null;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        config(['alertas.enabled' => true, 'alertas.correo' => 'avisos@example.test']);
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Mail::fake();
    }

    private function pasan(int $minutos): void
    {
        for ($i = 0; $i < $minutos; $i++) {
            if (now()->minute % 5 === 0) {
                Artisan::call('alertas:revisar');
            }
            $this->travel(1)->minutes();
        }
    }

    /** @return list<string> */
    private function correos(string $nombre): array
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)
            ->map(fn (AlertaSistemaCorreo $m) => $m->accion)->values()->all();
    }

    private function ultimo(string $nombre): AlertaSistemaCorreo
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)->last();
    }

    private function administrador(): User
    {
        Permission::findOrCreate('sistema.salud', 'web');

        return User::factory()->create(['activo' => true])->assignRole('administrador')->givePermissionTo('sistema.salud');
    }

    /** @return array<string, array<string, mixed>> los avisos abiertos por tipo */
    private function abiertos(): array
    {
        return collect(app(Avisador::class)->abiertos()['avisos'])->keyBy('tipo')->all();
    }

    // ───────────── Cobros ─────────────

    private function ccfEnPpq(): array
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Secreto PPQ']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
        $this->estab = $this->crearEmisorDte()['estab'];
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id, 'numero_control' => 'DTE-03-M001P002-000000000000201',
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-11-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-11-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/5001', 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-11-05', 'monto_albaran' => '98.95', 'sala_codigo' => '0017', 'numero_orden_compra' => '26110017000001']);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);

        return [$doc->refresh(), $lote];
    }

    public function test_un_ccf_invalidado_en_un_ppq_que_se_puede_editar_se_cierra_al_quitarlo_del_ppq(): void
    {
        [$doc, $lote] = $this->ccfEnPpq();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->presentacion_estado);
        // Hacienda acepta la invalidación (con su sello): el CCF sigue en el PPQ.
        $doc->dte->forceFill(['estado' => 'invalidado', 'fecha_invalidacion' => now(), 'sello_invalidacion' => '2026SELLOINV1'])->save();

        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::COBROS));
        $falla = $this->ultimo(self::COBROS);
        $this->assertSame('1 CCF invalidado sigue en cobro', $falla->hallazgo->titulo);
        $this->assertSame('Invalidados en cobro: 201.', $falla->hallazgo->detalle);
        $this->assertStringNotContainsString('Secreto', $falla->render());
        $this->assertSame([], $this->correos(self::SELLOS), 'Una invalidación con su sello no es «sin sello».');

        $this->actingAs($this->administrador())->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos', '1 CCF invalidado sigue en cobro', 'Si no se presentó, quitalo del PPQ', 'Revisar'])
            ->assertSee(route('cobros.index'));

        // La oficina hace lo que dice el aviso: lo quita del PPQ.
        $resultado = app(QuitarItemPpq::class)->quitar(PpqLote::find($lote->id), $lote->items()->first());
        $this->assertTrue($resultado['quitado']);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->refresh()->presentacion_estado);

        $this->pasan(65);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::COBROS), 'En una hora como mucho se va solo.');
        $this->assertArrayNotHasKey('cobros_fuera_de_regla', $this->abiertos());
    }

    // ───────────── Documentos sin sello ─────────────

    private function dte(array $override): Dte
    {
        $this->n++;
        $empresa = Empresa::create(['razon_social' => 'Empresa '.$this->n, 'ambiente' => '01', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill(array_merge([
            'tipo_dte' => '03', 'ambiente' => '01', 'estado' => 'aceptado',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE SECRETO SA'])->id,
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (300 + $this->n), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(), 'total_pagar' => 100,
            'sello_recepcion' => '2026SELLOREAL'.$this->n, 'fecha_procesamiento_mh' => now(),
        ], $override));
        $dte->save();

        return $dte;
    }

    public function test_la_nota_sobre_un_ccf_invalidado_pasa_a_sin_sello_de_invalidacion_y_se_cierra_con_el_sello(): void
    {
        $ccf = $this->dte(['estado' => 'invalidado', 'fecha_invalidacion' => now()->subDays(3), 'sello_invalidacion' => '2026SELLOINVCCF']);
        $nota = $this->dte(['tipo_dte' => '05', 'numero_control' => 'DTE-05-M001P001-000000000000045',
            'dte_relacionado_id' => $ccf->id, 'tipo_nota_credito' => 'devolucion_producto']);

        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::SELLOS));
        $this->assertSame('1 nota sigue sobre un CCF invalidado', $this->ultimo(self::SELLOS)->hallazgo->titulo);
        $this->assertSame('Notas sobre un CCF invalidado: 45.', $this->ultimo(self::SELLOS)->hallazgo->detalle);

        // Se invalida la nota, pero el sello de la invalidación todavía no llegó.
        $nota->forceFill(['estado' => 'invalidado', 'fecha_invalidacion' => now()])->save();
        $this->pasan(65);
        $this->assertSame(['falla'], $this->correos(self::SELLOS), 'No se cierra: ahora falta el sello de la invalidación.');
        $this->assertSame('1 documento invalidado no tiene el sello de invalidación', $this->abiertos()['dte_fuera_de_regla']['titulo'],
            'El Inicio dice lo que falta ahora, aunque no salga otro correo.');

        $nota->forceFill(['sello_invalidacion' => '2026SELLOINVNOTA'])->save();
        $this->pasan(65);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SELLOS));
    }

    public function test_un_sello_de_simulacion_en_produccion_cuenta_como_sin_sello(): void
    {
        $this->dte(['sello_recepcion' => 'mock-2026-sello-de-prueba']);
        $this->dte(['estado' => 'invalidado', 'fecha_invalidacion' => now(), 'sello_invalidacion' => 'MOCK-INV']);

        $this->pasan(6);

        $this->assertSame(['falla'], $this->correos(self::SELLOS));
        $this->assertSame('2 documentos no cuadran con Hacienda', $this->ultimo(self::SELLOS)->hallazgo->titulo);
        $this->assertSame('Sin sello: 301. Sin sello de invalidación: 302.', $this->ultimo(self::SELLOS)->hallazgo->detalle);
        $this->assertStringNotContainsString('mock', strtolower($this->ultimo(self::SELLOS)->render()));
    }

    // ───────────── Datos que no cuadran ─────────────

    public function test_un_cliente_repetido_avisa_igual_que_salud_del_sistema_y_se_cierra_al_corregirlo(): void
    {
        $repetidos = Cliente::factory()->count(2)->create(['nombre' => 'Cliente Secreto', 'num_documento' => '06141111111111']);
        $admin = $this->administrador();

        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::DATOS));
        $this->assertSame('Clientes duplicados por número de documento: 1.', $this->ultimo(self::DATOS)->hallazgo->detalle);
        $this->actingAs($admin)->get(route('admin.salud-sistema'))->assertOk()
            ->assertSee('Clientes duplicados por número de documento');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos', 'Hay datos que no cuadran', 'Clientes duplicados por número de documento: 1.', 'Revisar'])
            ->assertSee(route('admin.salud-sistema'))->assertDontSee('06141111111111');

        $repetidos->last()->forceFill(['num_documento' => '06142222222222'])->save();
        $this->pasan(65);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::DATOS));
    }

    // ───────────── El Inicio ─────────────

    public function test_varios_avisos_salen_del_mas_viejo_al_mas_nuevo(): void
    {
        $this->travel(-2)->days();
        PedidoWeb::create([
            'web_id' => 7, 'codigo' => 'LN-TEST-0000-0007', 'idioma' => 'es', 'pagado_en' => now(), 'alerta' => '',
            'total_centavos' => 345, 'moneda' => 'USD', 'cliente_nombre' => 'Elena Inventada', 'cliente_correo' => 'elena@example.test',
            'cliente_telefono' => '70000000', 'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [],
            'pasarela' => 'wompi', 'estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'No se encontró un producto.',
        ]);
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        $this->dte(['sello_recepcion' => '']);
        Cliente::factory()->count(2)->create(['num_documento' => '06141111111111']);

        $this->pasan(1);

        $html = $this->actingAs($this->administrador())->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['1 pedido web requiere atención', 'Desde 17/11/2026 10:00',
                '1 documento aceptado no tiene el sello de Hacienda', 'Desde 19/11/2026 10:00'])
            ->getContent();
        // La caja de avisos no nombra al cliente (la actividad reciente del Inicio sí lo muestra, y está bien).
        $this->assertSame(1, preg_match('/<section aria-labelledby="titulo-avisos".*?<\/section>/s', $html, $caja));
        foreach (['Elena', 'elena@', '70000000', 'CLIENTE SECRETO', '06141111111111'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $caja[0]);
        }
    }

    public function test_con_la_cache_vaciada_el_inicio_dice_que_los_avisos_estan_atrasados_y_vuelve_solo(): void
    {
        $this->dte(['sello_recepcion' => '']);
        $this->pasan(1);
        $admin = $this->administrador();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('1 documento aceptado no tiene el sello de Hacienda')->assertDontSee('Los avisos no se revisaron');

        Cache::flush();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Los avisos no se revisaron en los últimos 15 minutos: puede faltar alguno.')
            ->assertDontSee('Avisos abiertos');

        $this->pasan(5);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('1 documento aceptado no tiene el sello de Hacienda')->assertDontSee('Los avisos no se revisaron');
    }
}
