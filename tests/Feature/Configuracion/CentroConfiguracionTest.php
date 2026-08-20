<?php

namespace Tests\Feature\Configuracion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Centro de Configuración: las seis pantallas comparten UN shell
 * (<x-configuracion-layout>) y UNA sola fuente de pestañas
 * (configuracion/_nav.blade.php).
 *
 * Todo lo que se prueba acá es PRESENTACIÓN. Las rutas, el permiso del grupo
 * (`configuracion.gestionar`) y los valores guardados no se tocan: eso lo cubren
 * EmpresaConfiguracionTest, ContabilidadCorreoTest y RolesPermisosTest.
 */
class CentroConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    /** Marcador del componente configuracion/_nav.blade.php. */
    private const MARCADOR_NAV = '<nav aria-label="Secciones de configuración"';

    /** Las seis secciones del centro, en el orden en que se dibujan. */
    private const SECCIONES = [
        'configuracion.empresa.edit',
        'configuracion.establecimientos.index',
        'configuracion.puntos-venta.index',
        'configuracion.correlativos.index',
        'configuracion.contabilidad.edit',
        'configuracion.correo.edit',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** @return array<string, array{0: string}> */
    public static function seccionesProvider(): array
    {
        return array_combine(
            self::SECCIONES,
            array_map(static fn (string $r) => [$r], self::SECCIONES),
        );
    }

    // --------------------------------------------------------- navegación cruzada

    /** Desde cualquiera de las seis se llega a las otras cinco. */
    #[DataProvider('seccionesProvider')]
    public function test_cada_pantalla_enlaza_a_las_seis_secciones(string $ruta): void
    {
        $resp = $this->actingAs($this->admin())->get(route($ruta))->assertOk();

        foreach (self::SECCIONES as $destino) {
            $resp->assertSee(route($destino), false);
        }
    }

    /** Correo dejó de ser una pantalla huérfana: está en la barra de las seis. */
    #[DataProvider('seccionesProvider')]
    public function test_correo_aparece_en_la_barra_de_cada_pantalla(string $ruta): void
    {
        $this->actingAs($this->admin())->get(route($ruta))->assertOk()
            ->assertSee(route('configuracion.correo.edit'), false)
            ->assertSee('Correo');
    }

    /** Cada pantalla marca SU pestaña, y solo una. */
    #[DataProvider('seccionesProvider')]
    public function test_cada_pantalla_marca_una_sola_pestana_activa(string $ruta): void
    {
        $html = $this->actingAs($this->admin())->get(route($ruta))->assertOk()->getContent();
        $barra = $this->barraDe($html);

        $this->assertSame(1, substr_count($barra, 'aria-current="page"'), "La barra de {$ruta} debe marcar exactamente una pestaña.");
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route($ruta), '/').'"\s+aria-current="page"/',
            $barra,
            "La pestaña activa de {$ruta} no es la suya.",
        );
    }

    // --------------------------------------------------------- shell compartido

    /** Las seis usan el MISMO marco: ancho del módulo, tarjeta y barra de pestañas. */
    #[DataProvider('seccionesProvider')]
    public function test_las_seis_comparten_el_shell(string $ruta): void
    {
        $resp = $this->actingAs($this->admin())->get(route($ruta))->assertOk();

        $resp->assertSee('max-w-6xl', false);                              // ancho del módulo
        $resp->assertSee('overflow-hidden bg-white shadow sm:rounded-lg', false); // tarjeta
        $resp->assertSee(self::MARCADOR_NAV, false);                       // navegación interna
        $resp->assertSee('Configuración &mdash;', false);                  // título de página
    }

    /** El HTML de las pestañas NO está duplicado: una sola barra por página. */
    #[DataProvider('seccionesProvider')]
    public function test_la_barra_de_pestanas_no_esta_duplicada(string $ruta): void
    {
        $html = $this->actingAs($this->admin())->get(route($ruta))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::MARCADOR_NAV), "La página {$ruta} dibuja la barra más de una vez.");
    }

    /**
     * Una sola fila: la barra se DESPLAZA en horizontal cuando no cabe, en vez de
     * partirse y dejar una pestaña suelta en un segundo renglón.
     */
    #[DataProvider('seccionesProvider')]
    public function test_las_pestanas_no_se_parten_en_dos_lineas(string $ruta): void
    {
        $barra = $this->barraDe(
            $this->actingAs($this->admin())->get(route($ruta))->assertOk()->getContent()
        );

        $this->assertStringContainsString('overflow-x-auto', $barra);
        $this->assertStringNotContainsString('flex-wrap', $barra);
        $this->assertSame(6, substr_count($barra, 'shrink-0'), 'Las seis pestañas deben resistirse a comprimirse.');
        $this->assertSame(6, substr_count($barra, 'whitespace-nowrap'), 'Ninguna pestaña debe partir su texto.');
    }

    // --------------------------------------------------------- subpantallas

    /**
     * Crear/editar dentro de una sección conserva activa la pestaña de esa sección
     * (patrón `.*`), así que no se pierde el contexto al entrar a un formulario.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function subpantallasProvider(): array
    {
        return [
            'nuevo establecimiento' => ['configuracion.establecimientos.create', 'configuracion.establecimientos.index'],
            'nuevo punto de venta' => ['configuracion.puntos-venta.create', 'configuracion.puntos-venta.index'],
            'nuevo correlativo' => ['configuracion.correlativos.create', 'configuracion.correlativos.index'],
        ];
    }

    #[DataProvider('subpantallasProvider')]
    public function test_las_subpantallas_conservan_su_seccion_activa(string $ruta, string $seccion): void
    {
        $html = $this->actingAs($this->admin())->get(route($ruta))->assertOk()->getContent();
        $barra = $this->barraDe($html);

        $this->assertStringContainsString(self::MARCADOR_NAV, $html, 'La subpantalla también lleva la navegación interna.');
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route($seccion), '/').'"\s+aria-current="page"/',
            $barra,
        );
    }

    // --------------------------------------------------------- utilidades

    /** Devuelve solo la barra de pestañas del Centro de Configuración. */
    private function barraDe(string $html): string
    {
        $inicio = strpos($html, self::MARCADOR_NAV);
        $this->assertNotFalse($inicio, 'La página no tiene la navegación interna de Configuración.');

        $fin = strpos($html, '</nav>', $inicio);
        $this->assertNotFalse($fin, 'La navegación interna no está cerrada.');

        return substr($html, $inicio, $fin - $inicio);
    }
}
