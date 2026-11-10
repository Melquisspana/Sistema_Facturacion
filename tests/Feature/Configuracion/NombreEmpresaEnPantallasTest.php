<?php

namespace Tests\Feature\Configuracion;

use App\Enums\TipoDte;
use App\Mail\DteCorreo;
use App\Mail\Gastos\ResumenGastosCorreo;
use App\Mail\PaqueteContabilidadCorreo;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Support\Dte\PlantillaCorreo;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que ve la gente (pantallas y correos) lleva el nombre de Configuración › Empresa
 * emisora. Antes estaba escrito a mano en cada vista (#27). Datos inventados.
 */
class NombreEmpresaEnPantallasTest extends TestCase
{
    use RefreshDatabase;

    private const NOMBRE = 'Confitería de prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Sistema de prueba']);
    }

    private function emisor(): PuntoVenta
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => self::NOMBRE, 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);

        return PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function dte(): Dte
    {
        $pv = $this->emisor();

        return Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => 'aceptado',
            'ambiente' => '00',
            'establecimiento_id' => $pv->establecimiento_id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba'])->id,
            'numero_control' => 'DTE-03-M001P001-000000000000001',
            'fecha_emision' => '2026-11-09',
            'hora_emision' => '09:00:00',
            'total_pagar' => 150.00,
        ]);
    }

    public function test_el_inicio_el_encabezado_y_el_logo_llevan_el_nombre_de_la_empresa(): void
    {
        $this->emisor();

        $this->actingAs($this->usuario())->get(route('dashboard'))->assertOk()
            ->assertSee('Resumen operativo de '.self::NOMBRE)
            ->assertSee('<title>'.self::NOMBRE.'</title>', false)
            ->assertSee('alt="'.self::NOMBRE.'"', false)
            ->assertDontSee('Sistema de prueba');
    }

    public function test_sin_empresa_configurada_el_inicio_abre_con_el_nombre_del_sistema(): void
    {
        $this->actingAs($this->usuario())->get(route('dashboard'))->assertOk()
            ->assertSee('Resumen operativo de Sistema de prueba')
            ->assertSee('alt="Sistema de prueba"', false);
    }

    public function test_la_pantalla_de_entrada_lleva_el_nombre_de_la_empresa(): void
    {
        $this->emisor();

        $this->get(route('login'))->assertOk()
            ->assertSee('<title>'.self::NOMBRE.'</title>', false)
            ->assertSee('alt="'.self::NOMBRE.'"', false);
    }

    public function test_los_formularios_de_ccf_factura_y_exportacion_nombran_a_la_empresa_configurada(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $this->emisor();
        $usuario = $this->usuario('facturacion');

        foreach (['facturacion.create-ccf', 'facturacion.create-factura', 'facturacion.create-exportacion'] as $ruta) {
            $this->actingAs($usuario)->get(route($ruta))->assertOk()
                ->assertSee('Estos datos pertenecen a '.self::NOMBRE);
        }
    }

    public function test_el_formulario_de_ccf_sin_establecimiento_nombra_a_la_empresa_configurada(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        Empresa::create(['razon_social' => self::NOMBRE, 'activo' => true]);

        $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.create-ccf'))->assertOk()
            ->assertSee('('.self::NOMBRE.'), no del cliente');
    }

    public function test_el_correo_del_dte_al_cliente_lleva_el_nombre_de_la_empresa(): void
    {
        $dte = $this->dte();

        $correo = new DteCorreo($dte, 'pdf');
        $correo->assertSeeInHtml(self::NOMBRE);
        $correo->assertDontSeeInHtml('Sistema de prueba');
        $correo->assertDontSeeInText('Sistema de prueba');
        $correo->assertHasSubject('Comprobante de Crédito Fiscal DTE-03-M001P001-000000000000001 — '.self::NOMBRE);
        $this->assertSame(self::NOMBRE, PlantillaCorreo::variables($dte)['{{empresa}}']);
        $this->assertStringContainsString('emitido por '.self::NOMBRE.'.', PlantillaCorreo::render(null, $dte));
    }

    public function test_el_correo_del_paquete_de_contabilidad_lleva_el_nombre_de_la_empresa(): void
    {
        $this->emisor();

        $correo = new PaqueteContabilidadCorreo('octubre 2026', 'https://drive.example.test/paquete', 'paquete.zip', [
            'compras_cantidad' => 1, 'compras_total' => 10.0, 'ventas_cantidad' => 1, 'ventas_invalidadas' => 0,
            'ventas_pendientes_sello' => 0, 'ventas_total' => 20.0, 'desde' => '2026-10-01', 'hasta' => '2026-10-31',
            'incluir_compras' => true, 'incluir_ventas' => true,
        ]);

        $correo->assertHasSubject('Paquete contabilidad '.self::NOMBRE.' - octubre 2026');
        $correo->assertSeeInText(self::NOMBRE);
        $correo->assertDontSeeInHtml('Sistema de prueba');
    }

    public function test_el_correo_de_gastos_pendientes_firma_con_el_nombre_de_la_empresa(): void
    {
        $this->emisor();

        $correo = new ResumenGastosCorreo('Usuario de prueba', [
            'hasta' => '09/11/2026', 'vencidos' => [], 'proximos' => [], 'sin_monto' => [], 'totales' => [],
        ]);

        $correo->assertSeeInText(self::NOMBRE);
        $correo->assertDontSeeInText('Sistema de prueba');
    }
}
