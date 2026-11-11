<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Dte\DtePdfService;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tocar un CCF en la hoja de la salida abre su PDF (el de Facturación), en la oficina y
 * en «Mi salida». Solo CCF de esa salida, y en «Mi salida» solo si va en ella.
 */
class SalidaCcfTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dte.ambiente' => '01', 'rutas.entregas_desde' => '2026-09-28']);
        $this->travelTo('2026-11-03 09:00:00');
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Javier Inventado', 'user_id' => $this->vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Oriente']);
        $this->sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Josefina 14', 'ruta_id' => $this->ruta->id]);
    }

    private function salida(PersonalRuta $persona): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-03', 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$persona->id], $persona->id);

        return $salida;
    }

    private function entrega(SalidaRuta $salida, array $extra = []): SalidaRutaEntrega
    {
        $this->correlativo++;
        $empresa = Empresa::firstOrCreate(['razon_social' => 'Dulces Inventados'], ['ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::firstOrCreate(['empresa_id' => $empresa->id, 'codigo' => 'M001'], ['nombre' => 'Casa', 'activo' => true]);
        $dte = Dte::create($extra + [
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id, 'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (900 + $this->correlativo), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-11-01', 'hora_emision' => '10:00:00', 'total_pagar' => 150.00,
        ]);

        return $salida->entregas()->create(['dte_id' => $dte->id, 'cliente_sucursal_id' => $this->sala->id]);
    }

    /** El vendedor baja sus CCF desde «Por entregar» (PorEntregarTest); la hoja de antes ya no existe. */
    public function test_el_vendedor_no_abre_el_ccf_de_una_salida(): void
    {
        $suya = $this->salida($this->persona);
        $entrega = $this->entrega($suya);

        // La ruta de la oficina pide rutas.ver: el vendedor no la tiene.
        $this->actingAs($this->vendedor)->get(route('rutas.salidas.ccf', [$suya, $entrega]))->assertForbidden();
        $this->actingAs($this->vendedor)->get('/rutas/mi-salida/'.$suya->id.'/ccf/'.$entrega->id)->assertNotFound();
    }

    public function test_la_oficina_abre_el_ccf_de_cualquier_salida(): void
    {
        $salida = $this->salida(PersonalRuta::create(['nombre' => 'Rosa Ficticia']));
        $entrega = $this->entrega($salida);
        $oficina = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $url = route('rutas.salidas.ccf', [$salida, $entrega]);

        $this->actingAs($oficina)->get(route('rutas.salidas.show', $salida))->assertOk()->assertSee('href="'.$url.'"', false);
        $respuesta = $this->actingAs($oficina)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Se descarga, no se abre en el navegador (pedido de los vendedores).
        $this->assertStringStartsWith('attachment;', (string) $respuesta->headers->get('Content-Disposition'));

        // Otro CCF que no va en esa salida: 404.
        $otra = $this->salida($this->persona);
        $this->actingAs($oficina)->get(route('rutas.salidas.ccf', [$salida, $this->entrega($otra)]))->assertNotFound();
        // Sin rutas.ver no se entra.
        $contabilidad = User::factory()->create(['activo' => true])->assignRole('contabilidad');
        $this->actingAs($contabilidad)->get($url)->assertForbidden();
    }

    /** Un CCF invalidado se puede abrir igual y el PDF lo dice. */
    public function test_un_ccf_invalidado_se_abre_con_su_cinta(): void
    {
        $salida = $this->salida($this->persona);
        $entrega = $this->entrega($salida, ['estado' => 'invalidado']);

        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('facturacion'))->get(route('rutas.salidas.ccf', [$salida, $entrega]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('INVALIDADO', app(DtePdfService::class)->html($entrega->dte));
    }
}
