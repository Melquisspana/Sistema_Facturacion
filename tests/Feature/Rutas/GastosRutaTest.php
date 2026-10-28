<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaGasto;
use App\Models\User;
use App\Services\Rutas\CierreDia;
use App\Services\Rutas\ParticipantesSalida;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GastosRutaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $oficina;

    private PersonalRuta $persona;

    private PersonalRuta $otra;

    private SalidaRuta $salida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(18, 0));
        Storage::fake('local');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos', 'user_id' => $this->vendedor->id]);
        $this->otra = PersonalRuta::create(['nombre' => 'Ana']);
        $this->salida = SalidaRuta::create(['ruta_id' => Ruta::create(['nombre' => 'Centro'])->id, 'fecha_inicio' => HoraNegocio::fechaHoy(), 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($this->salida, [$this->persona->id, $this->otra->id], null);
        $this->actingAs($this->vendedor);
    }

    private function alta(array $datos = [], string $prefijo = 'rutas.mi-salida.')
    {
        return $this->postJson(route($prefijo.'gastos.store', $this->salida), array_replace(['concepto' => 'parqueo', 'monto' => '30.10'], $datos));
    }

    public function test_alta_con_foto_privada_metadatos_y_persona_fecha_forzadas(): void
    {
        $foto = UploadedFile::fake()->image('recibo.jpg');
        $hash = hash_file('sha256', $foto->getRealPath());
        $bytes = $foto->getSize();
        $this->alta(['foto' => $foto, 'personal_id' => $this->otra->id, 'fecha' => '2020-01-01'])->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $this->assertSame($this->persona->id, $gasto->personal_id);
        $this->assertSame(HoraNegocio::fechaHoy(), $gasto->fecha->toDateString());
        $this->assertSame('image/jpeg', $gasto->archivo_mime);
        $this->assertSame($bytes, $gasto->archivo_bytes);
        $this->assertSame($hash, $gasto->archivo_sha256);
        $this->assertMatchesRegularExpression('~^rutas/gastos/'.$this->salida->id.'/[a-f0-9-]+\.jpg$~', $gasto->archivo_ruta);
        Storage::disk('local')->assertExists($gasto->archivo_ruta);
        $this->get(route('rutas.mi-salida.gastos.foto', [$this->salida, $gasto]))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'inline');
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertOk()->assertSee('Gastos de hoy')->assertSee('Tomar foto');
    }

    public function test_validaciones_de_monto_concepto_detalle_y_archivo(): void
    {
        foreach (['0', '-1', '10000', '1.001'] as $monto) {
            $this->alta(['monto' => $monto])->assertUnprocessable()->assertJsonValidationErrors('monto');
        }
        $this->alta(['concepto' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('detalle');
        $this->alta(['concepto' => 'otro', 'detalle' => 'ab'])->assertUnprocessable();
        $this->alta(['concepto' => 'invalido'])->assertUnprocessable()->assertJsonValidationErrors('concepto');
        $this->alta(['foto' => UploadedFile::fake()->create('x.jpg', 10, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors('foto');
        $this->alta(['foto' => UploadedFile::fake()->image('grande.jpg')->size(10241)])->assertUnprocessable()->assertJsonValidationErrors('foto');
        $this->assertSame(0, SalidaRutaGasto::count());
        $this->alta(['concepto' => 'otro', 'detalle' => 'Reparación menor'])->assertRedirect();
        $this->assertNull(SalidaRutaGasto::sole()->archivo_ruta);
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertSee('Sin comprobante');
    }

    public function test_acceso_ajeno_scope_y_login(): void
    {
        $this->alta(['foto' => UploadedFile::fake()->image('r.png')])->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $otro = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->actingAs($otro);
        $this->alta()->assertForbidden();
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
        $this->get(route('rutas.mi-salida.gastos.foto', [$this->salida, $gasto]))->assertForbidden();
        $otraSalida = SalidaRuta::create(['ruta_id' => $this->salida->ruta_id, 'fecha_inicio' => HoraNegocio::fechaHoy(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->actingAs($this->oficina);
        $this->get(route('rutas.salidas.gastos.foto', [$otraSalida, $gasto]))->assertNotFound();
        $this->deleteJson(route('rutas.salidas.gastos.destroy', [$otraSalida, $gasto]))->assertNotFound();
        auth()->logout();
        $this->get(route('rutas.mi-salida.gastos.foto', [$this->salida, $gasto]))->assertRedirect(route('login'));
        $this->post(route('rutas.mi-salida.gastos.store', $this->salida))->assertRedirect(route('login'));
    }

    public function test_oficina_fecha_participante_y_estado(): void
    {
        $this->actingAs($this->oficina);
        $this->salida->update(['estado' => EstadoSalidaRuta::Finalizada]);
        $this->alta(['fecha' => '2026-10-27', 'personal_id' => $this->otra->id], 'rutas.salidas.')->assertRedirect();
        $this->assertSame('2026-10-27', SalidaRutaGasto::sole()->fecha->toDateString());
        $this->assertSame($this->otra->id, SalidaRutaGasto::sole()->personal_id);
        $this->alta(['fecha' => '2026-10-29'], 'rutas.salidas.')->assertUnprocessable();
        $ajeno = PersonalRuta::create(['nombre' => 'Ajeno']);
        $this->alta(['personal_id' => $ajeno->id], 'rutas.salidas.')->assertUnprocessable();
        foreach ([EstadoSalidaRuta::Cancelada, EstadoSalidaRuta::Planificada] as $estado) {
            $this->salida->update(['estado' => $estado]);
            $this->alta([], 'rutas.salidas.')->assertForbidden();
        }
        $this->actingAs($this->vendedor);
        $this->alta()->assertForbidden();
    }

    public function test_dia_solo_gastos_cierre_reapertura_recierre_y_pdf(): void
    {
        $this->alta()->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $this->get(route('rutas.mi-salida.show', $this->salida))->assertSee('Cerrar el día');
        $this->postJson(route('rutas.mi-salida.cerrar', $this->salida))->assertRedirect();
        $cierre = $this->salida->cierreDelDia();
        $this->assertSame('30.10', $cierre->gastos_total);
        $this->assertSame('-30.10', $cierre->a_entregar);
        $this->get(route('rutas.mi-salida.cierre', $this->salida))->assertOk()->assertSee('A reintegrar al vendedor $30.10')->assertSee('Sin comprobante');
        $this->alta()->assertForbidden();
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
        $this->actingAs($this->oficina);
        $this->alta([], 'rutas.salidas.')->assertForbidden();
        $this->deleteJson(route('rutas.salidas.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
        $this->post(route('rutas.salidas.reabrir', [$this->salida, HoraNegocio::fechaHoy()]), ['motivo' => 'Corregir gastos'])->assertRedirect();
        $this->actingAs($this->vendedor);
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertRedirect();
        $this->alta(['monto' => '0.05'])->assertRedirect();
        $this->postJson(route('rutas.mi-salida.cerrar', $this->salida))->assertRedirect();
        $this->assertSame('0.05', $cierre->refresh()->gastos_total);
        $this->assertSame('-0.05', $cierre->a_entregar);
        $html = view('rutas.cierres.pdf', ['salida' => $this->salida, 'fecha' => HoraNegocio::fechaHoy(), 'cierre' => $cierre, 'entregas' => collect(), 'empresa' => null])->render();
        foreach (['Parqueo', 'Sin comprobante', 'Efectivo cobrado $0.00', 'gastos $0.05', '= efectivo a entregar $-0.05', 'A reintegrar al vendedor $0.05'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->get(route('rutas.mi-salida.liquidacion', [$this->salida, HoraNegocio::fechaHoy()]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_borrar_solo_propios_oficina_cualquiera_archivo_despues_del_commit(): void
    {
        $this->actingAs($this->oficina);
        $this->alta(['foto' => UploadedFile::fake()->image('r.jpg')], 'rutas.salidas.')->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $this->actingAs($this->vendedor)->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
        DB::beginTransaction();
        $this->actingAs($this->oficina)->deleteJson(route('rutas.salidas.gastos.destroy', [$this->salida, $gasto]))->assertRedirect();
        // La transacción adicional mantiene pendiente el callback de la petición.
        Storage::disk('local')->assertExists($gasto->archivo_ruta);
        DB::commit();
        Storage::disk('local')->assertMissing($gasto->archivo_ruta);
        $this->assertDatabaseMissing('salida_ruta_gastos', ['id' => $gasto->id]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'salida_ruta_gasto', 'description' => 'borró el gasto']);
    }

    public function test_suma_en_centavos_y_fecha_del_gasto(): void
    {
        $this->alta()->assertRedirect();
        $this->actingAs($this->oficina);
        $this->alta(['fecha' => '2026-10-27', 'monto' => '99.99'], 'rutas.salidas.')->assertRedirect();
        $this->assertSame(3010, app(CierreDia::class)->gastos($this->salida, HoraNegocio::fechaHoy())->sum(fn ($gasto) => CierreDia::centavos($gasto->monto)));
        $this->assertSame('70.15', CierreDia::decimal(CierreDia::centavos('100.25') - CierreDia::centavos('30.10')));
    }

    public function test_cierre_resta_gastos_del_efectivo_y_no_de_otras_fechas(): void
    {
        $cliente = Cliente::factory()->create();
        $sala = $cliente->sucursales()->create(['nombre' => 'Sala centro']);
        $empresa = Empresa::create(['razon_social' => 'Pruebas', 'ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create(['establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01', 'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id, 'numero_control' => 'DTE-03-M001P002-000000000000999', 'fecha_emision' => HoraNegocio::fechaHoy(), 'hora_emision' => '10:00:00', 'total_pagar' => '100.25']);
        $entrega = $this->salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id, 'resultado' => 'entregado', 'fecha_resultado' => now(), 'monto_esperado' => '100.25', 'monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']);
        $this->alta()->assertRedirect();
        $this->actingAs($this->oficina);
        $this->alta(['fecha' => '2026-10-27', 'monto' => '99.99'], 'rutas.salidas.')->assertRedirect();
        $this->postJson(route('rutas.salidas.cerrar', $this->salida), ['entrega_dinero_personal_id' => $this->persona->id, 'entregas' => [$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]])->assertRedirect();
        $this->assertSame('30.10', $this->salida->cierreDelDia()->gastos_total);
        $this->assertSame('70.15', $this->salida->cierreDelDia()->a_entregar);
        $this->post(route('rutas.salidas.reabrir', [$this->salida, HoraNegocio::fechaHoy()]), ['motivo' => 'Faltaba un gasto'])->assertRedirect();
        $this->alta(['monto' => '100.00'], 'rutas.salidas.')->assertRedirect();
        $this->postJson(route('rutas.salidas.cerrar', $this->salida), ['entrega_dinero_personal_id' => $this->persona->id, 'entregas' => [$entrega->id => ['monto_cobrado' => '100.25', 'forma_cobro' => 'efectivo']]])->assertRedirect();
        $this->assertSame('-29.85', $this->salida->cierreDelDia()->a_entregar);
        $this->get(route('rutas.salidas.cierre', $this->salida))->assertOk()->assertSee('A reintegrar al vendedor $29.85');
    }

    public function test_rollback_de_borrado_conserva_archivo_y_gasto(): void
    {
        $this->alta(['foto' => UploadedFile::fake()->image('recibo.png')])->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        DB::beginTransaction();
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertRedirect();
        Storage::disk('local')->assertExists($gasto->archivo_ruta);
        DB::rollBack();
        $this->assertDatabaseHas('salida_ruta_gastos', ['id' => $gasto->id]);
        Storage::disk('local')->assertExists($gasto->archivo_ruta);
        $this->actingAs($this->oficina)->deleteJson(route('rutas.salidas.gastos.destroy', [$this->salida, $gasto]))->assertRedirect();
        Storage::disk('local')->assertMissing($gasto->archivo_ruta);
    }

    public function test_error_al_registrar_revierte_gasto_y_limpia_foto(): void
    {
        SalidaRutaGasto::creating(function () {
            throw new \RuntimeException('Fallo de prueba antes de insertar');
        });
        $this->withoutExceptionHandling();
        try {
            $this->alta(['foto' => UploadedFile::fake()->image('recibo.jpg')]);
            $this->fail('Debió fallar la inserción del gasto.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo de prueba antes de insertar', $e->getMessage());
        } finally {
            SalidaRutaGasto::flushEventListeners();
        }
        $this->assertSame(0, SalidaRutaGasto::count());
        $this->assertSame([], Storage::disk('local')->allFiles('rutas/gastos'));
    }

    public function test_foto_oficina_con_solo_permiso_ver_y_vendedor_salida_finalizada(): void
    {
        $this->alta(['foto' => UploadedFile::fake()->image('recibo.webp')])->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $this->salida->update(['estado' => EstadoSalidaRuta::Finalizada]);
        $this->get(route('rutas.mi-salida.gastos.foto', [$this->salida, $gasto]))->assertOk();
        $this->alta()->assertForbidden();
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
        $lector = User::factory()->create(['activo' => true])->givePermissionTo('rutas.ver');
        $this->actingAs($lector)->get(route('rutas.salidas.gastos.foto', [$this->salida, $gasto]))->assertOk();
        $this->alta([], 'rutas.salidas.')->assertForbidden();
        $this->deleteJson(route('rutas.salidas.gastos.destroy', [$this->salida, $gasto]))->assertForbidden();
    }

    public function test_vendedor_borra_un_gasto_propio_de_dia_anterior_abierto(): void
    {
        $this->alta()->assertRedirect();
        $gasto = SalidaRutaGasto::sole();
        $this->travel(1)->days();
        $this->deleteJson(route('rutas.mi-salida.gastos.destroy', [$this->salida, $gasto]))->assertRedirect();
        $this->assertDatabaseMissing('salida_ruta_gastos', ['id' => $gasto->id]);
    }

    public function test_migracion_reversible(): void
    {
        $archivos = glob(database_path('migrations/*create_salida_ruta_gastos_table.php'));
        $this->assertCount(1, $archivos);
        $migracion = require $archivos[0];
        $migracion->down();
        $this->assertFalse(Schema::hasTable('salida_ruta_gastos'));
        $migracion->up();
        $this->assertTrue(Schema::hasColumns('salida_ruta_gastos', ['archivo_ruta', 'archivo_mime', 'archivo_bytes', 'archivo_sha256']));
    }
}
