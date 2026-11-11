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
use Illuminate\Support\Facades\DB;
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

    /**
     * #22 · Dos altas del mismo lugar a la vez: la segunda ya pasó la consulta y choca con el
     * índice único. Se avisa quién lo tiene, sin error del sistema y sin dejar auditoría falsa.
     */
    public function test_el_alta_simultanea_del_mismo_lugar_avisa_quien_lo_tiene(): void
    {
        $sanVicente = Ruta::create(['nombre' => 'San Vicente']);
        $otra = Ruta::create(['nombre' => 'Chalatenango']);

        // La otra pantalla lo guarda justo entre la consulta del controlador y su alta.
        RutaCobertura::creating(fn () => DB::table('ruta_coberturas')->insert([
            'ruta_id' => $sanVicente->id, 'departamento_id' => $this->cabanas->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.cobertura.store', $otra), ['departamento_id' => $this->cabanas->id])
            ->assertSessionHas('error', '«Cabañas» ya lo cubre la ruta «San Vicente». Quitalo de allá primero.');

        $this->assertSame(0, $otra->coberturas()->count());
        $this->assertFalse(Activity::where('description', 'agregó a la cobertura de la ruta')->exists());
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

    // ══════════════════════════════ pantallas

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
