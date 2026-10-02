<?php

namespace Tests\Feature\Seguridad;

use App\Enums\PermisoSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ExportacionCliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Datos que terminan DENTRO de una expresión JavaScript (estado de Alpine, `confirm()`).
 *
 * Escapar con `{{ }}` no alcanza ahí: el navegador decodifica las entidades del atributo
 * antes de que el JavaScript lo lea, así que una comilla simple escapada vuelve a ser una
 * comilla y cierra la cadena. Estos valores tienen que salir con `@js`, que produce un
 * literal de JavaScript válido. Cada prueba falla con la interpolación anterior.
 */
class EscapadoJavascriptEnVistasTest extends TestCase
{
    use RefreshDatabase;

    /** Cierra la cadena JS si se interpola con `'{{ }}'`. */
    private const CARGA = "x');alert(1);//";

    /** Cómo aparecía la carga con la interpolación vulnerable. */
    private const RASTRO_VULNERABLE = 'x&#039;);alert(1);//';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    public function test_el_listado_de_documentos_descarta_una_fecha_de_filtro_que_no_es_fecha(): void
    {
        $this->actingAs($this->usuario(['dte.ver']))
            ->get(route('facturacion.index', ['fecha_desde' => self::CARGA, 'fecha_hasta' => self::CARGA]))
            ->assertOk()
            ->assertDontSee('alert(1)', false);
    }

    public function test_el_listado_de_documentos_conserva_una_fecha_de_filtro_valida(): void
    {
        $this->actingAs($this->usuario(['dte.ver']))
            ->get(route('facturacion.index', ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee("desde: '2026-09-01'", false)
            ->assertSee("hasta: '2026-09-30'", false);
    }

    public function test_la_frecuencia_devuelta_por_old_en_el_formulario_de_gasto_sale_como_literal_js(): void
    {
        config()->set('gastos.enabled', true);

        $this->actingAs($this->usuario(['gastos.ver', 'gastos.registrar', 'gastos.recurrencias']))
            ->withSession(['_old_input' => ['repeticion' => ['frecuencia' => self::CARGA]]])
            ->get(route('gastos.create'))
            ->assertOk()
            ->assertDontSee(self::RASTRO_VULNERABLE, false);
    }

    public function test_los_valores_devueltos_por_old_en_el_formulario_de_regla_salen_como_literal_js(): void
    {
        config()->set('gastos.enabled', true);

        $this->actingAs($this->usuario(['gastos.ver', 'gastos.recurrencias']))
            ->withSession(['_old_input' => [
                'frecuencia' => self::CARGA,
                'monto_modo' => self::CARGA,
                'ambito' => self::CARGA,
            ]])
            ->get(route('gastos.reglas.create'))
            ->assertOk()
            ->assertDontSee(self::RASTRO_VULNERABLE, false);
    }

    public function test_el_origen_del_descuento_devuelto_por_old_en_el_perfil_documental_sale_como_literal_js(): void
    {
        $cliente = Cliente::factory()->create();
        $clave = TipoNotaCredito::cases()[0]->value;

        $this->actingAs($this->usuario(['clientes.ver', 'clientes.gestionar']))
            ->withSession(['_old_input' => ['modalidades' => [$clave => ['descuento_origen' => self::CARGA]]]])
            ->get(route('clientes.perfil-documento.edit', $cliente))
            ->assertOk()
            ->assertDontSee(self::RASTRO_VULNERABLE, false);
    }

    /**
     * La ficha antigua de clientes de exportación ya no tiene ruta (redirige a la ficha
     * del cliente), pero la vista sigue en el repositorio: se renderiza directo.
     */
    public function test_el_nombre_del_cliente_en_el_confirm_de_copiar_precios_sale_como_literal_js(): void
    {
        $this->actingAs($this->usuario(['exportaciones.ver', 'exportaciones.gestionar']));
        $cliente = ExportacionCliente::create(['nombre' => self::CARGA, 'activo' => true]);
        $otro = ExportacionCliente::create(['nombre' => 'Otro cliente', 'activo' => true]);
        $otro->productos_count = 1;

        $this->withViewErrors([]);

        $this->view('exportaciones.clientes.show', [
            'cliente' => $cliente,
            'disponibles' => collect(),
            'otrosClientes' => collect([$otro]),
            'soloHabilitados' => false,
            'clientesDte' => collect(),
        ])
            // El nombre también se muestra como texto (escapado como HTML, correcto); lo
            // que no puede pasar es que llegue así al confirm(), entre comillas angulares.
            ->assertDontSee('«'.self::RASTRO_VULNERABLE, false)
            ->assertSee('x\u0027);alert(1)', false);
    }
}
