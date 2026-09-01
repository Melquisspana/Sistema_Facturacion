<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\FuncionPersonalRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\AsignadorDocumentos;
use App\Services\Rutas\BandejaExcepciones;
use App\Services\Rutas\Custodia;
use App\Services\Rutas\ParticipantesSalida;
use App\Support\PpqElegibilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El catálogo de personal, las pantallas nuevas y la bandeja de excepciones.
 *
 * La bandeja es la mitad que hace útil a la otra: una bitácora que nadie mira no evita que
 * un papel se pierda. Lo que se prueba acá es sobre todo qué NO entra en ella —esperar el
 * albarán es normal, y una bandeja llena de ruido se deja de mirar—.
 */
class PersonalYExcepcionesTest extends TestCase
{
    use RefreshDatabase;

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

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function sala(?Ruta $ruta): ClienteSucursal
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create(['nombre' => 'Selectos San Miguel', 'codigo' => '0232', 'ruta_id' => $ruta?->id]);
    }

    private function documento(SalidaRuta $salida, ClienteSucursal $sala, string $control, string $oc = '260602320012345'): SalidaRutaDocumento
    {
        $dte = Dte::create([
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => $control,
            'numero_orden_compra' => $oc,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 136.33,
        ]);

        return app(AsignadorDocumentos::class)->agregarDte($salida, $dte, null);
    }

    // ══════════════════════════════ catálogo de personal

    public function test_se_da_de_alta_una_persona_con_varias_funciones(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.personal.store'), [
                'nombre' => 'Rene Barillas',
                'telefono' => '7777-0000',
                'funciones' => [FuncionPersonalRuta::Vendedor->value, FuncionPersonalRuta::Cobrador->value],
            ])
            ->assertRedirect();

        $persona = PersonalRuta::sole();

        $this->assertSame('Rene Barillas', $persona->nombre);
        $this->assertTrue($persona->activo);
        // Las funciones son combinables y van normalizadas: se pueden consultar.
        $this->assertCount(2, $persona->funcionesEnum());
        $this->assertTrue($persona->tieneFuncion(FuncionPersonalRuta::Vendedor));
        $this->assertFalse($persona->puedeSerResponsable());
        // Sin login: es lo normal en el personal de campo.
        $this->assertNull($persona->user_id);
    }

    public function test_una_persona_no_queda_atada_a_ninguna_ruta_ni_cliente(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);

        // El catálogo no tiene columna de ruta, cliente ni zona: cualquiera puede ir a
        // cualquier lado, y una columna así se volvería una regla que nadie cumple.
        $this->assertFalse(Schema::hasColumn('rutas_personal', 'ruta_id'));
        $this->assertFalse(Schema::hasColumn('rutas_personal', 'cliente_id'));

        $sanMiguel = Ruta::create(['nombre' => 'San Miguel']);
        $sonsonate = Ruta::create(['nombre' => 'Sonsonate']);

        foreach ([$sanMiguel, $sonsonate] as $ruta) {
            $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::Planificada]);
            app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], null);
        }

        $this->assertSame(2, $persona->salidas()->count());
    }

    public function test_una_persona_no_se_borra_se_desactiva(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);
        $admin = $this->admin();

        // No existe ruta de borrado: alguien con historial de custodia no puede desaparecer.
        $this->assertFalse(Route::has('rutas.personal.destroy'));

        $this->actingAs($admin)
            ->patch(route('rutas.personal.toggle-activo', $persona))
            ->assertRedirect();

        $this->assertFalse($persona->refresh()->activo);
        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_desactivar_avisa_si_esa_persona_todavia_tiene_papeles(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);
        $admin = $this->admin();

        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], null);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        app(Custodia::class)->entregar($documento, $persona, $admin);

        // Se desactiva igual —pudo haberse ido de verdad— pero se avisa: quitarle el papel
        // de la mano en la base borraría el rastro de quién lo tiene.
        $this->actingAs($admin)
            ->patch(route('rutas.personal.toggle-activo', $persona))
            ->assertSessionHas('error');

        $this->assertFalse($persona->refresh()->activo);
        $this->assertSame($persona->id, app(Custodia::class)->tenedorActual($documento)?->id);
    }

    /**
     * La ficha de una persona es la pantalla que se abre el día que falta un papel: tiene
     * que decir qué lleva en la mano AHORA y en qué salidas anduvo. Se renderiza de verdad
     * porque pinta insignias de función y de rol, y una clase mal puesta ahí no la atrapa
     * ninguna prueba de servicio.
     */
    public function test_la_ficha_de_una_persona_muestra_lo_que_lleva_en_la_mano(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::Vendedor->value]);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::ResponsableSalida->value]);

        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);
        app(Custodia::class)->entregar($documento, $persona, $this->admin());

        $this->actingAs($this->admin())
            ->get(route('rutas.personal.show', $persona))
            ->assertOk()
            ->assertSee('Rene Barillas')
            // Las funciones que sabe hacer, con su etiqueta legible.
            ->assertSee(FuncionPersonalRuta::Vendedor->label())
            ->assertSee(FuncionPersonalRuta::ResponsableSalida->label())
            // Y el papel que hoy tiene en la mano.
            ->assertSee('DTE-03-M001P002-000000000000001')
            ->assertSee('San Miguel');
    }

    public function test_la_pantalla_de_editar_trae_marcadas_las_funciones_que_ya_tiene(): void
    {
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas', 'telefono' => '7777-0000']);
        $persona->funciones()->create(['funcion' => FuncionPersonalRuta::Cobrador->value]);

        $this->actingAs($this->admin())
            ->get(route('rutas.personal.edit', $persona))
            ->assertOk()
            ->assertSee('Rene Barillas')
            ->assertSee('7777-0000')
            // Todas las funciones se ofrecen; ninguna es un cargo excluyente.
            ->assertSee(FuncionPersonalRuta::Cobrador->label())
            ->assertSee(FuncionPersonalRuta::Repartidor->label());
    }

    public function test_un_usuario_no_puede_enlazarse_a_dos_personas(): void
    {
        $usuario = User::factory()->create(['activo' => true]);
        PersonalRuta::create(['nombre' => 'Rene Barillas', 'user_id' => $usuario->id]);

        $this->actingAs($this->admin())
            ->post(route('rutas.personal.store'), ['nombre' => 'Otro Rene', 'user_id' => $usuario->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_ver_personal_exige_permiso(): void
    {
        $sinPermiso = User::factory()->create();
        $sinPermiso->givePermissionTo(['rutas.ver']);

        $this->actingAs($sinPermiso)->get(route('rutas.personal.index'))->assertForbidden();

        $conPermiso = User::factory()->create();
        $conPermiso->givePermissionTo(['rutas.ver', 'rutas.personal.ver']);

        $this->actingAs($conPermiso)->get(route('rutas.personal.index'))->assertOk();

        // Ver no es gestionar: el alta necesita el otro permiso.
        $this->actingAs($conPermiso)->get(route('rutas.personal.create'))->assertForbidden();
    }

    // ══════════════════════════════ pantalla de recepción

    public function test_la_recepcion_encuentra_el_documento_por_numero_de_control(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $this->actingAs($this->admin())
            ->get(route('rutas.recepcion.index', ['q' => 'DTE-03-M001P002-000000000000001']))
            ->assertOk()
            ->assertSee('Recibir CCF firmado')
            // La ficha muestra lo que hace falta para reconocer el papel sin dudar.
            ->assertSee('Selectos San Miguel')
            ->assertSee('136.33');
    }

    public function test_la_recepcion_tolera_el_numero_escaneado_sin_separadores(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Un lector de código de barras teclea lo que lee: puede venir sin guiones.
        $this->actingAs($this->admin())
            ->get(route('rutas.recepcion.index', ['q' => 'DTE03M001P002000000000000001']))
            ->assertOk()
            ->assertSee('Recibir CCF firmado');
    }

    public function test_una_busqueda_ambigua_no_elige_por_su_cuenta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);

        // Dos documentos que terminan igual: el sistema no puede saber cuál está en la mano.
        $this->documento($salida, $sala, 'DTE-03-M001P001-000000000000986', '260602320012341');
        $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000986', '260602320012342');

        $this->actingAs($this->admin())
            ->get(route('rutas.recepcion.index', ['q' => '986']))
            ->assertOk()
            ->assertSee('Elegí cuál tenés en la mano')
            ->assertSee('Marcar para recibir');

        $this->assertSame(0, SalidaRutaDocumento::conDocumentacionFisica()->count());
    }

    public function test_un_numero_desconocido_lo_dice_y_no_crea_nada(): void
    {
        $this->actingAs($this->admin())
            ->get(route('rutas.recepcion.index', ['q' => 'DTE-03-M001P002-000000000009999']))
            ->assertOk()
            ->assertSee('No se encontró ningún documento');

        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ══════════════════════════════ bandeja de excepciones

    public function test_esperar_el_albaran_no_es_una_excepcion(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::Planificada]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $grupos = app(BandejaExcepciones::class)->clasificar(collect([$documento]));

        // Recién salido y sin albarán: es la espera normal. Una bandeja que avisa de todo
        // se deja de mirar.
        $this->assertSame(0, array_sum(app(BandejaExcepciones::class)->contar($grupos)));
    }

    public function test_entregado_hace_dias_y_sin_papel_es_una_excepcion(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // El AC01 prueba la entrega hace diez días; el papel sigue sin volver.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => '260602320012345',
            'monto_albaran' => 136.33,
            'fecha_albaran' => now()->subDays(10)->toDateString(),
            'origen' => 'gmail',
        ]);

        $grupos = app(BandejaExcepciones::class)->clasificar(collect([$documento->refresh()]));

        $this->assertCount(1, $grupos[BandejaExcepciones::ENTREGADO_SIN_PAPEL]);
    }

    public function test_un_papel_en_manos_de_alguien_inactivo_es_una_excepcion(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $persona = PersonalRuta::create(['nombre' => 'Rene Barillas']);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], null);

        app(Custodia::class)->entregar($documento, $persona, $this->admin());
        // Se desactiva DESPUÉS, con el papel ya en la mano: eso es lo que la bandeja tiene
        // que señalar. Desactivar a alguien no le quita los documentos que lleva.
        $persona->update(['activo' => false]);

        $grupos = app(BandejaExcepciones::class)->clasificar(collect([$documento->fresh()]));

        $this->assertCount(1, $grupos[BandejaExcepciones::CUSTODIA_PERSONA_INACTIVA]);
    }

    public function test_una_salida_finalizada_con_papeles_sin_volver_es_una_excepcion(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        $salida->finalizar();

        $grupos = app(BandejaExcepciones::class)->clasificar(collect([$documento->fresh()]));

        $this->assertCount(1, $grupos[BandejaExcepciones::SALIDA_CERRADA_PENDIENTE]);
        // Y también salió sin que nadie registrara quién se llevó el papel.
        $this->assertCount(1, $grupos[BandejaExcepciones::SIN_RESPONSABLE_CONOCIDO]);
    }

    public function test_una_recepcion_anulada_y_rehecha_queda_señalada(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');
        $admin = $this->admin();

        $evento = app(Custodia::class)->recibir($documento, $admin);
        app(Custodia::class)->anular($evento, 'Se escaneó el documento equivocado.', $admin);
        app(Custodia::class)->recibir($documento->fresh(), $admin);

        $grupos = app(BandejaExcepciones::class)->clasificar(collect([$documento->fresh()]));

        // Dos recepciones en el historial: una corrección legítima que vale la pena mirar.
        $this->assertCount(1, $grupos[BandejaExcepciones::RECIBIDO_MAS_DE_UNA_VEZ]);
    }

    public function test_la_bandeja_se_ve_con_permiso_de_custodia(): void
    {
        $usuario = User::factory()->create();
        $usuario->givePermissionTo(['rutas.ver', 'rutas.custodia.ver']);

        $this->actingAs($usuario)->get(route('rutas.excepciones.index'))->assertOk();

        $sinPermiso = User::factory()->create();
        $sinPermiso->givePermissionTo(['rutas.ver']);

        $this->actingAs($sinPermiso)->get(route('rutas.excepciones.index'))->assertForbidden();
    }

    // ══════════════════════════════ nada de esto cambia el cobro

    public function test_un_cliente_sin_exigencia_de_papel_cobra_igual_que_antes(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => now()->toDateString(), 'estado' => EstadoSalidaRuta::EnCurso]);
        $documento = $this->documento($salida, $sala, 'DTE-03-M001P002-000000000000001');

        // Sin perfil documental, el papel no interviene en el cobro. Toda la custodia nueva
        // no cambia una coma de eso.
        $this->assertFalse($documento->documentacionFisicaRecibida());
        $this->assertTrue(PpqElegibilidad::sePuedeCobrar($documento->dte));
        $this->assertNull(PpqElegibilidad::motivoParaCobrar($documento->dte));
        $this->assertNull(PpqElegibilidad::advertenciaParaCobrar($documento->dte));
    }
}
