<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Models\User;
use App\Services\Rutas\PropuestaRutas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cobertura de las rutas y la asignación de salas que se propone con ella.
 *
 * Lo que más importa acá es lo que NO pasa solo: definir cobertura no mueve salas, una
 * sala que ya tiene otra ruta no se mueve sin marcarla, y la propuesta se recalcula al
 * aplicar en vez de creerle al formulario.
 */
class CoberturaRutasTest extends TestCase
{
    use RefreshDatabase;

    private Departamento $cabanas;

    private Departamento $cuscatlan;

    private Departamento $sanSalvador;

    private Distrito $ilobasco;

    private Distrito $sensuntepeque;

    private Distrito $cojutepeque;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cabanas = Departamento::create(['codigo' => '09', 'nombre' => 'Cabañas']);
        $this->cuscatlan = Departamento::create(['codigo' => '07', 'nombre' => 'Cuscatlán']);
        $this->sanSalvador = Departamento::create(['codigo' => '06', 'nombre' => 'San Salvador']);

        $this->ilobasco = Distrito::create(['departamento_id' => $this->cabanas->id, 'municipio' => 'Cabañas Oeste', 'nombre' => 'Ilobasco']);
        $this->sensuntepeque = Distrito::create(['departamento_id' => $this->cabanas->id, 'municipio' => 'Cabañas Este', 'nombre' => 'Sensuntepeque']);
        $this->cojutepeque = Distrito::create(['departamento_id' => $this->cuscatlan->id, 'municipio' => 'Cuscatlán Sur', 'nombre' => 'Cojutepeque']);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function sala(string $nombre, Distrito $distrito, ?Ruta $ruta = null, bool $activa = true): ClienteSucursal
    {
        $cliente = Cliente::first() ?? Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
            'departamento_id' => $distrito->departamento_id,
            'distrito_id' => $distrito->id,
            'ruta_id' => $ruta?->id,
            'activo' => $activa,
        ]);
    }

    private function salaSinDistrito(string $nombre, Departamento $departamento): ClienteSucursal
    {
        $cliente = Cliente::first() ?? Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create(['nombre' => $nombre, 'departamento_id' => $departamento->id]);
    }

    // ══════════════════════════════ definir la cobertura

    public function test_se_agrega_un_departamento_completo_y_un_distrito(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('rutas.rutas.cobertura.store', $sanVicente), ['departamento_id' => $this->cabanas->id])
            ->assertSessionHas('status');
        $this->actingAs($admin)
            ->post(route('rutas.rutas.cobertura.store', $sanVicente), ['distrito_id' => $this->cojutepeque->id])
            ->assertSessionHas('status');

        $this->assertSame(2, $sanVicente->coberturas()->count());
        $this->assertTrue(Activity::where('description', 'agregó a la cobertura de la ruta')->exists());
    }

    public function test_un_lugar_es_de_una_sola_ruta_y_se_dice_de_cual(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Chalatenango']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.cobertura.store', $otra), ['departamento_id' => $this->cabanas->id])
            ->assertSessionHas('error', '«Cabañas» ya lo cubre la ruta «San Vicente». Quitalo de allá primero.');

        $this->assertSame(0, $otra->coberturas()->count());
    }

    public function test_se_pide_un_departamento_o_un_distrito_no_los_dos_ni_ninguno(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Vicente']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('rutas.rutas.cobertura.store', $ruta), [])
            ->assertSessionHasErrors('departamento_id');

        $this->actingAs($admin)
            ->post(route('rutas.rutas.cobertura.store', $ruta), [
                'departamento_id' => $this->cabanas->id,
                'distrito_id' => $this->ilobasco->id,
            ])
            ->assertSessionHasErrors('departamento_id');

        $this->assertSame(0, RutaCobertura::count());
    }

    public function test_definir_o_quitar_cobertura_no_mueve_ninguna_sala(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Vicente']);
        $sala = $this->sala('Selectos Ilobasco', $this->ilobasco);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('rutas.rutas.cobertura.store', $ruta), ['departamento_id' => $this->cabanas->id]);
        $this->assertNull($sala->refresh()->ruta_id);

        $sala->update(['ruta_id' => $ruta->id]);
        $cobertura = $ruta->coberturas()->sole();

        $this->actingAs($admin)
            ->delete(route('rutas.rutas.cobertura.destroy', [$ruta, $cobertura]))
            ->assertSessionHas('status');

        $this->assertSame(0, RutaCobertura::count());
        $this->assertSame($ruta->id, $sala->refresh()->ruta_id);
    }

    public function test_no_se_quita_la_cobertura_de_otra_ruta_por_la_url(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Chalatenango']);
        $cobertura = $otra->coberturas()->create(['departamento_id' => $this->cabanas->id]);

        $this->actingAs($this->admin())
            ->delete(route('rutas.rutas.cobertura.destroy', [$ruta, $cobertura]))
            ->assertNotFound();

        $this->assertSame(1, RutaCobertura::count());
    }

    // ══════════════════════════════ la propuesta

    public function test_el_distrito_gana_sobre_el_departamento_completo(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Ilobasco especial']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        $otra->coberturas()->create(['distrito_id' => $this->ilobasco->id]);

        $propuestas = new PropuestaRutas;

        $this->assertSame($otra->id, $propuestas->para($this->sala('Selectos Ilobasco', $this->ilobasco))?->id);
        $this->assertSame($sanVicente->id, $propuestas->para($this->sala('Selectos Sensuntepeque', $this->sensuntepeque))?->id);
        $this->assertNull($propuestas->para($this->sala('Selectos Cojutepeque', $this->cojutepeque)));
    }

    public function test_una_sala_sin_distrito_se_propone_por_su_departamento(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);

        $this->assertSame($sanVicente->id, (new PropuestaRutas)->para($this->salaSinDistrito('Tienda Cabañas', $this->cabanas))?->id);
    }

    public function test_una_ruta_desactivada_no_reclama_salas(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Vicente', 'activa' => false]);
        $ruta->coberturas()->create(['departamento_id' => $this->cabanas->id]);

        $this->assertNull((new PropuestaRutas)->para($this->sala('Selectos Ilobasco', $this->ilobasco)));
    }

    public function test_clasifica_las_salas_segun_lo_que_dice_la_cobertura(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Chalatenango']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);

        $this->sala('Selectos Ilobasco', $this->ilobasco);                       // sin ruta → propuesta
        $this->sala('Selectos Sensuntepeque', $this->sensuntepeque, $otra);      // en otra ruta
        $this->sala('Selectos Sensuntepeque II', $this->sensuntepeque, $sanVicente); // ya coincide
        $this->sala('Selectos Cojutepeque', $this->cojutepeque);                 // sin cobertura
        $this->sala('Selectos Ilobasco cerrada', $this->ilobasco, null, false);  // inactiva: no cuenta

        $clasificacion = (new PropuestaRutas)->clasificar();

        $this->assertSame(['Selectos Ilobasco'], $clasificacion['proponer']->pluck('sala.nombre')->all());
        $this->assertSame(['Selectos Sensuntepeque'], $clasificacion['distintas']->pluck('sala.nombre')->all());
        $this->assertSame(1, $clasificacion['coinciden']);
        $this->assertSame([['departamento' => 'Cuscatlán', 'salas' => 1]], $clasificacion['sinCobertura']->all());
    }

    // ══════════════════════════════ aplicar

    public function test_aplicar_asigna_la_ruta_propuesta_y_queda_auditado(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        $ilobasco = $this->sala('Selectos Ilobasco', $this->ilobasco);
        $cojutepeque = $this->sala('Selectos Cojutepeque', $this->cojutepeque);

        $this->actingAs($this->admin())
            ->post(route('rutas.asignacion.aplicar'), ['sucursales' => [$ilobasco->id, $cojutepeque->id]])
            ->assertSessionHas('status', '1 sala asignada según la cobertura. 1 ya no tenían ruta propuesta y quedaron como estaban.');

        $this->assertSame($sanVicente->id, $ilobasco->refresh()->ruta_id);
        // Sin propuesta no se inventa ruta.
        $this->assertNull($cojutepeque->refresh()->ruta_id);

        $registro = Activity::where('log_name', 'ruta_sala')->sole();
        $this->assertSame($ilobasco->id, $registro->subject_id);
        $this->assertTrue($registro->properties['por_cobertura']);
    }

    public function test_una_sala_en_otra_ruta_solo_se_mueve_si_se_marca(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Chalatenango']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        $marcada = $this->sala('Selectos Ilobasco', $this->ilobasco, $otra);
        $sinMarcar = $this->sala('Selectos Sensuntepeque', $this->sensuntepeque, $otra);

        $this->actingAs($this->admin())
            ->post(route('rutas.asignacion.aplicar'), ['sucursales' => [$marcada->id]]);

        $this->assertSame($sanVicente->id, $marcada->refresh()->ruta_id);
        $this->assertSame($otra->id, $sinMarcar->refresh()->ruta_id);
    }

    /** La ruta destino es la que dice la cobertura AL APLICAR, no la que se vio en pantalla. */
    public function test_aplicar_recalcula_la_propuesta(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $chalate = Ruta::create(['nombre' => 'Chalatenango']);
        $cobertura = $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        $sala = $this->sala('Selectos Ilobasco', $this->ilobasco);

        // Entre abrir la pantalla y enviarla, alguien pasó Cabañas a otra ruta.
        $cobertura->update(['ruta_id' => $chalate->id]);

        $this->actingAs($this->admin())->post(route('rutas.asignacion.aplicar'), ['sucursales' => [$sala->id]]);

        $this->assertSame($chalate->id, $sala->refresh()->ruta_id);
    }

    // ══════════════════════════════ pantallas

    public function test_la_pantalla_de_asignacion_muestra_propuestas_y_lo_que_falta_cubrir(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        Ruta::create(['nombre' => 'Santa Ana']);
        $this->sala('Selectos Ilobasco', $this->ilobasco);
        $this->sala('Selectos Cojutepeque', $this->cojutepeque);

        $this->actingAs($this->admin())
            ->get(route('rutas.asignacion.index'))
            ->assertOk()
            ->assertSee('Selectos Ilobasco')
            ->assertSee('Sin cobertura (1)')
            ->assertSee('Cuscatlán')
            // Una ruta sin cobertura se señala: no propone nada.
            ->assertSee('Santa Ana · sin cobertura');
    }

    public function test_la_ficha_de_la_ruta_muestra_su_cobertura(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente', 'frecuencia_objetivo_dias' => 15]);
        $sanVicente->coberturas()->create(['departamento_id' => $this->cabanas->id]);
        $sanVicente->coberturas()->create(['distrito_id' => $this->cojutepeque->id]);

        $this->actingAs($this->admin())
            ->get(route('rutas.rutas.show', $sanVicente))
            ->assertOk()
            ->assertSeeInOrder(['Cabañas (todo el departamento)', 'Cojutepeque · Cuscatlán'])
            ->assertSee('Sale más o menos cada 15 días.');
    }

    // ══════════════════════════════ lo que se retiró

    public function test_la_custodia_del_papel_ya_no_existe(): void
    {
        $this->assertFalse(Schema::hasTable('custodia_documento_eventos'));
        $this->assertFalse(Schema::hasTable('salida_ruta_documentos'));
        $this->assertFalse(Schema::hasColumn('cliente_perfiles_documento', 'modo_papel_fisico'));

        foreach (['rutas.custodia.ver', 'rutas.custodia.registrar', 'rutas.recepcion', 'rutas.custodia.corregir'] as $permiso) {
            $this->assertFalse(Permission::where('name', $permiso)->exists(), "Sigue existiendo {$permiso}.");
        }
    }
}
