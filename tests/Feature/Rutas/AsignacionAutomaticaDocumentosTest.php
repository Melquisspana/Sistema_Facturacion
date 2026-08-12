<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\AsignadorAutomaticoDocumentos;
use App\Services\Rutas\AsignadorDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La regla de asociación automática, probada por su parte más importante: TODO lo
 * que NO hace.
 *
 * El sistema solo asocia cuando hay una única lectura posible. Cero salidas en
 * curso, dos salidas en curso, sala sin ruta, documento que ya tiene dueño: en
 * todos esos casos se queda quieto y deja la decisión a una persona. Un módulo que
 * adivina bien el 90% de las veces produce un 10% de asignaciones falsas que nadie
 * revisa, y eso es peor que no automatizar nada.
 */
class AsignacionAutomaticaDocumentosTest extends TestCase
{
    use RefreshDatabase;

    /** Emisor mínimo: los DTE de estas pruebas solo se consultan, nunca se emiten. */
    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

            return Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'activo' => true,
            ]);
        });
    }

    /** Serie viva: la única que se asocia sola. */
    private function p002(): PuntoVenta
    {
        return PuntoVenta::firstOrCreate(
            ['establecimiento_id' => $this->establecimiento()->id, 'codigo' => 'P002'],
            ['nombre' => 'Sistema nuevo', 'activo' => true],
        );
    }

    /** Serie histórica de Conta Portable: nunca se asocia sola. */
    private function p001(): PuntoVenta
    {
        return PuntoVenta::firstOrCreate(
            ['establecimiento_id' => $this->establecimiento()->id, 'codigo' => 'P001'],
            ['nombre' => 'Conta Portable', 'activo' => true],
        );
    }

    private function sala(?Ruta $ruta, string $nombre = 'Selectos San Miguel'): ClienteSucursal
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
            'codigo' => substr(md5($nombre), 0, 4),
            'ruta_id' => $ruta?->id,
        ]);
    }

    private function salida(Ruta $ruta, EstadoSalidaRuta $estado): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => $estado,
        ]);
    }

    /**
     * CCF de prueba. `$extra` va a la IZQUIERDA del `+`: en PHP el operando
     * izquierdo gana, y es la única forma de que un override pise el valor base.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ccf(?ClienteSucursal $sala, string $control, ?PuntoVenta $pv = null, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'punto_venta_id' => ($pv ?? $this->p002())->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    private function automatico(): AsignadorAutomaticoDocumentos
    {
        return app(AsignadorAutomaticoDocumentos::class);
    }

    // ====================================== 4) una única salida en curso

    public function test_asocia_cuando_hay_una_unica_salida_en_curso_de_la_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001001');

        $resultado = $this->automatico()->asignar($ccf);

        $this->assertSame(AsignadorAutomaticoDocumentos::ASIGNADO, $resultado['estado']);
        $this->assertSame($salida->id, SalidaRutaDocumento::sole()->salida_ruta_id);
        // Queda marcado como automático: distinguible de lo que hizo una persona.
        $this->assertTrue(SalidaRutaDocumento::sole()->asignacion_automatica);

        $this->assertTrue(Activity::where('log_name', 'salida_documento')
            ->where('description', 'asoció automáticamente el documento a la salida')->exists());
    }

    // ====================================== 5) cero salidas en curso

    public function test_no_asocia_si_la_ruta_no_tiene_ninguna_salida_en_curso(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001002');

        $resultado = $this->automatico()->asignar($ccf);

        $this->assertSame(AsignadorAutomaticoDocumentos::SIN_SALIDA_EN_CURSO, $resultado['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_una_salida_solo_planificada_no_cuenta_como_en_curso(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::Planificada);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001003');

        // Planificada = todavía no salió nadie. Meterle documentos sería inventar
        // que el viaje ya está ocurriendo.
        $this->assertSame(AsignadorAutomaticoDocumentos::SIN_SALIDA_EN_CURSO, $this->automatico()->asignar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ====================================== 6) dos salidas en curso

    public function test_con_dos_salidas_en_curso_de_la_misma_ruta_no_adivina(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001004');

        $resultado = $this->automatico()->asignar($ccf);

        $this->assertSame(AsignadorAutomaticoDocumentos::VARIAS_SALIDAS_EN_CURSO, $resultado['estado']);
        $this->assertNull($resultado['salida']);
        $this->assertSame(0, SalidaRutaDocumento::count());

        // Y es una EXCEPCIÓN, no un "no aplica": alguien tiene que resolverla.
        $this->assertTrue(AsignadorAutomaticoDocumentos::motivosDeExcepcion()
            ->contains(AsignadorAutomaticoDocumentos::VARIAS_SALIDAS_EN_CURSO));
    }

    // ====================================== 7) documento con dueño

    public function test_nunca_mueve_un_documento_que_ya_tiene_salida(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $primera = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001005');

        // Ya está en la primera salida (asignada a mano por alguien).
        app(AsignadorDocumentos::class)->agregarDte($primera, $ccf, null);

        // Ahora aparece una segunda salida en curso y se vuelve a barrer.
        $segunda = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $resultado = $this->automatico()->asignar($ccf);

        $this->assertSame(AsignadorAutomaticoDocumentos::YA_ASIGNADO, $resultado['estado']);
        $this->assertSame(1, SalidaRutaDocumento::count());
        $this->assertSame($primera->id, SalidaRutaDocumento::sole()->salida_ruta_id);
        $this->assertSame(0, SalidaRutaDocumento::where('salida_ruta_id', $segunda->id)->count());
    }

    // ====================================== resto de negativas

    public function test_no_asocia_un_ccf_sin_sala(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf(null, 'DTE-03-M001P002-000000000001006');

        $this->assertSame(AsignadorAutomaticoDocumentos::SIN_SUCURSAL, $this->automatico()->asignar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_no_asocia_si_la_sala_no_pertenece_a_ninguna_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $salaSuelta = $this->sala(null, 'Sala sin ruta');
        $ccf = $this->ccf($salaSuelta, 'DTE-03-M001P002-000000000001007');

        $this->assertSame(AsignadorAutomaticoDocumentos::SUCURSAL_SIN_RUTA, $this->automatico()->asignar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_la_serie_historica_p001_no_se_asocia_sola(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P001-000000000001008', $this->p001());

        $this->assertSame(AsignadorAutomaticoDocumentos::SERIE_NO_AUTOMATICA, $this->automatico()->asignar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_un_ccf_archivado_no_se_asocia(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001009', null, ['archivado' => true, 'estado' => 'rechazado']);

        $this->assertSame(AsignadorAutomaticoDocumentos::NO_ES_CCF_VIGENTE, $this->automatico()->asignar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ====================================== el barrido y el comando

    public function test_evaluar_no_escribe_nada(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000001010');

        $this->assertSame(AsignadorAutomaticoDocumentos::ASIGNADO, $this->automatico()->evaluar($ccf)['estado']);
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    public function test_el_comando_en_seco_no_escribe_y_con_aplicar_si(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $this->ccf($sala, 'DTE-03-M001P002-000000000001011');

        $this->artisan('rutas:asociar-documentos')->assertSuccessful();
        $this->assertSame(0, SalidaRutaDocumento::count());

        $this->artisan('rutas:asociar-documentos --aplicar')->assertSuccessful();
        $this->assertSame(1, SalidaRutaDocumento::count());
    }

    public function test_el_boton_de_la_pantalla_solo_esta_para_salidas_en_curso(): void
    {
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $planificada = $this->salida($ruta, EstadoSalidaRuta::Planificada);
        $this->ccf($sala, 'DTE-03-M001P002-000000000001012');

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.asociar-automatico', $planificada))
            ->assertForbidden();

        $this->assertSame(0, SalidaRutaDocumento::count());

        $enCurso = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.asociar-automatico', $enCurso))
            ->assertRedirect();

        $this->assertSame($enCurso->id, SalidaRutaDocumento::sole()->salida_ruta_id);
    }
}
