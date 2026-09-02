<?php

namespace Tests\Feature\Configuracion;

use App\Models\Empresa;
use App\Models\ExportacionCliente;
use App\Models\User;
use App\Support\Exportaciones\DatosExportador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El FDA de la EMPRESA vive en Configuración → Parámetros fiscales, con respaldo al
 * valor histórico.
 *
 * Antes estaba en tres sitios a la vez: `config/exportaciones.php`, el campo FDA de
 * cada perfil de cliente, y una copia congelada dentro de cada lista. Editarlo en
 * uno lo dejaba viejo en los otros dos.
 *
 * La migración es SEGURA porque el respaldo es explícito: mientras nadie configure
 * nada, {@see DatosExportador} devuelve exactamente el valor de siempre y la lista
 * de empaque sale idéntica. Eso es lo que estas pruebas fijan.
 */
class FdaEmpresaExportadoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        Role::findOrCreate('facturacion', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // ------------------------------------------------------ resolución con respaldo

    public function test_sin_ajuste_responde_el_valor_historico_de_configuracion(): void
    {
        config([
            'exportaciones.fda_reg_number' => '00000000001',
            'exportaciones.exportador_nombre' => 'Titular de Ejemplo',
            'exportaciones.exportador_direccion' => 'Calle de Ejemplo 123, Localidad A',
        ]);

        $datos = app(DatosExportador::class);

        $this->assertSame('00000000001', $datos->fdaRegNumber());
        $this->assertSame('Titular de Ejemplo', $datos->nombre());
        $this->assertSame('Calle de Ejemplo 123, Localidad A', $datos->direccion());
    }

    public function test_sin_ajuste_ni_valor_historico_el_nombre_cae_a_la_empresa_emisora(): void
    {
        config([
            'exportaciones.exportador_nombre' => '',
            'exportaciones.exportador_direccion' => '',
        ]);

        Empresa::create([
            'razon_social' => 'DULCES LA NEGRITA, S.A. DE C.V.',
            'direccion' => 'Calle de Ejemplo 123, Localidad A',
            'ambiente' => '00',
            'activo' => true,
        ]);

        $datos = app(DatosExportador::class);

        $this->assertSame('DULCES LA NEGRITA, S.A. DE C.V.', $datos->nombre());
        $this->assertSame('Calle de Ejemplo 123, Localidad A', $datos->direccion());
    }

    /**
     * El FDA NO cae a la Empresa: esa columna no existe y no se va a inventar. Sin
     * valor, la respuesta es null y la pantalla lo dice.
     */
    public function test_sin_ningun_valor_el_fda_es_nulo_y_no_se_inventa(): void
    {
        config(['exportaciones.fda_reg_number' => '']);
        Empresa::create(['razon_social' => 'DULCES LA NEGRITA', 'ambiente' => '00', 'activo' => true]);

        $this->assertNull(app(DatosExportador::class)->fdaRegNumber());
    }

    // ------------------------------------------------------- pantalla de ajustes

    public function test_parametros_fiscales_muestra_el_valor_que_de_verdad_se_imprime(): void
    {
        config(['exportaciones.fda_reg_number' => '00000000001']);

        $this->actingAs($this->admin())
            ->get(route('configuracion.fiscal.parametros'))
            ->assertOk()
            ->assertSee('Empresa exportadora')
            ->assertSee('Número de registro FDA de la empresa')
            ->assertSee('00000000001');
    }

    public function test_parametros_fiscales_avisa_cuando_el_fda_no_esta_configurado(): void
    {
        config(['exportaciones.fda_reg_number' => '']);

        $this->actingAs($this->admin())
            ->get(route('configuracion.fiscal.parametros'))
            ->assertOk()
            ->assertSee('la casilla FDA vacía');
    }

    // ------------------------------------------- la lista lo toma solo, no se teclea

    public function test_el_formulario_de_lista_precarga_el_fda_y_no_lo_pide_a_mano(): void
    {
        config([
            'exportaciones.fda_reg_number' => '00000000001',
            'exportaciones.exportador_nombre' => 'Titular de Ejemplo',
        ]);

        $resp = $this->actingAs($this->admin())->get(route('facturacion.listas.create'))->assertOk();

        $resp->assertSee('FDA de la empresa');
        $resp->assertSee('00000000001');
        $resp->assertSee('Titular de Ejemplo');
        // Viaja en un campo oculto: la lista conserva su propio snapshot, pero nadie
        // lo escribe a mano.
        $resp->assertSee('type="hidden" name="fda_reg_number"', false);
        $resp->assertSee('Configuración → Parámetros fiscales');
    }

    /**
     * El campo FDA del PERFIL DE CLIENTE es otra cosa: el del importador. Que ambos
     * existan es correcto; lo que estaba mal era guardar el de la empresa ahí.
     */
    public function test_el_fda_del_perfil_es_el_del_importador_y_es_independiente(): void
    {
        config(['exportaciones.fda_reg_number' => '00000000001']);

        $perfil = ExportacionCliente::create([
            'nombre' => 'CAROLINAS',
            'fda_reg_number' => '99887766',
            'fda_requiere_revision' => false,
            'activo' => true,
        ]);

        $this->assertSame('99887766', $perfil->fdaImportador());
        $this->assertSame('00000000001', app(DatosExportador::class)->fdaRegNumber());
    }
}
