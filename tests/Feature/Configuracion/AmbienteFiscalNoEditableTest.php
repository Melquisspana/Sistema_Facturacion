<?php

namespace Tests\Feature\Configuracion;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración → Empresa emisora NO puede alterar el ambiente fiscal.
 *
 * La columna `empresas.ambiente` sigue existiendo (no se borra en esta fase), pero era
 * una fuente de verdad FALSA: aparecía como un <select> obligatorio en el formulario y
 * ningún consumidor fiscal la leía. Un administrador podía elegir "Producción", guardar,
 * ver el cambio reflejado en la pantalla y creer que el sistema pasaba a emitir de
 * verdad — mientras el ambiente real seguía saliendo de config('dte.ambiente').
 *
 * Estos tests fijan las dos mitades de la garantía: que el POST no escribe la columna,
 * y que no toca el ambiente que de verdad se usa.
 */
class AmbienteFiscalNoEditableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** @return array<string, string> Payload válido del formulario. */
    private function datos(array $extra = []): array
    {
        return $extra + [
            'razon_social' => 'Dulces La Negrita, S.A. de C.V.',
            'activo' => '1',
        ];
    }

    public function test_el_formulario_ya_no_ofrece_un_selector_de_ambiente(): void
    {
        config(['dte.ambiente' => '00']);

        $respuesta = $this->actingAs($this->admin())
            ->get('/configuracion/empresa')
            ->assertOk();

        // Ni el campo editable ni el name que lo enviaba.
        $respuesta->assertDontSee('name="ambiente"', escape: false);
        $respuesta->assertDontSee('id="ambiente"', escape: false);
    }

    public function test_muestra_el_ambiente_fiscal_real_en_solo_lectura(): void
    {
        config(['dte.ambiente' => '00']);

        $this->actingAs($this->admin())
            ->get('/configuracion/empresa')
            ->assertOk()
            ->assertSee('Ambiente fiscal actual')
            ->assertSee('dte.ambiente=00')
            ->assertSee('Pruebas');
    }

    public function test_el_ambiente_mostrado_sigue_a_config_dte_ambiente_no_a_la_columna(): void
    {
        // La columna dice pruebas; la configuración real dice producción. Manda la
        // configuración: es la que viaja en el JSON del MH.
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        config(['dte.ambiente' => '01']);

        $this->actingAs($this->admin())
            ->get('/configuracion/empresa')
            ->assertOk()
            ->assertSee('dte.ambiente=01')
            ->assertSee('Producción');
    }

    public function test_un_post_con_ambiente_produccion_no_escribe_la_columna(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', $this->datos(['ambiente' => '01']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.empresa.edit'));

        $this->assertSame('00', $empresa->fresh()->ambiente->value);
    }

    public function test_un_post_con_ambiente_produccion_no_cambia_el_ambiente_fiscal_real(): void
    {
        config(['dte.ambiente' => '00']);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', $this->datos(['ambiente' => '01']));

        // Lo único que decide qué ambiente se emite no se movió.
        $this->assertSame('00', (string) config('dte.ambiente'));
    }

    public function test_al_crear_la_empresa_el_ambiente_queda_en_el_default_de_la_tabla(): void
    {
        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', $this->datos(['ambiente' => '01']))
            ->assertSessionHasNoErrors();

        // Se creó el registro, pero con el default de la migración ('00'), no con lo
        // que mandó el formulario.
        $this->assertSame(1, Empresa::count());
        $this->assertSame('00', Empresa::first()->ambiente->value);
    }

    public function test_el_resto_de_los_datos_del_emisor_si_se_guardan(): void
    {
        // Que el ambiente se ignore no puede haber roto el formulario entero.
        $this->actingAs($this->admin())
            ->put('/configuracion/empresa', $this->datos([
                'ambiente' => '01',
                'nit' => '0614-000000-000-0',
                'nrc' => '123456-7',
                'nombre_comercial' => 'La Negrita',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('empresas', [
            'razon_social' => 'Dulces La Negrita, S.A. de C.V.',
            'nit' => '0614-000000-000-0',
            'nombre_comercial' => 'La Negrita',
        ]);
    }
}
