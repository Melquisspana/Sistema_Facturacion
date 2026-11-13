<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Instalar el sistema como app en el celular (13/11/2026): manifiesto, íconos y etiquetas
 * del <head>. El navegador pide el manifiesto antes de entrar, así que no exige sesión.
 * Datos inventados.
 */
class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    private const NOMBRE = 'Confitería de prueba';

    public function test_el_manifiesto_responde_sin_sesion_con_el_nombre_de_la_empresa(): void
    {
        Empresa::create(['razon_social' => 'Empresa de prueba, S.A. de C.V.', 'nombre_comercial' => self::NOMBRE, 'ambiente' => '00', 'activo' => true]);

        $respuesta = $this->get('/manifest.webmanifest')->assertOk();

        $this->assertGuest();
        $this->assertStringStartsWith('application/manifest+json', $respuesta->headers->get('Content-Type'));

        $manifiesto = json_decode($respuesta->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::NOMBRE, $manifiesto['name']);
        $this->assertNotEmpty($manifiesto['short_name']);
        $this->assertLessThanOrEqual(12, mb_strlen($manifiesto['short_name']));
        $this->assertSame('/', $manifiesto['start_url']);
        $this->assertSame('/', $manifiesto['scope']);
        $this->assertSame('standalone', $manifiesto['display']);
        $this->assertSame('es', $manifiesto['lang']);
        $this->assertSame('#131417', $manifiesto['theme_color']);
        $this->assertSame('#131417', $manifiesto['background_color']);

        $iconos = collect($manifiesto['icons']);
        $this->assertTrue($iconos->contains(fn ($i) => $i['sizes'] === '192x192' && $i['purpose'] === 'any'));
        $this->assertTrue($iconos->contains(fn ($i) => $i['sizes'] === '512x512' && $i['purpose'] === 'any'));
        $this->assertTrue($iconos->contains(fn ($i) => $i['sizes'] === '512x512' && $i['purpose'] === 'maskable'));
        foreach ($iconos as $icono) {
            $this->assertSame('image/png', $icono['type']);
            // Apache se queda con `/icons/` (su `Alias` de fábrica): ahí los íconos dan 404 en producción.
            $this->assertStringStartsNotWith('/icons/', $icono['src']);
            $this->assertFileExists(public_path(ltrim($icono['src'], '/')));
        }
    }

    public function test_sin_empresa_el_manifiesto_usa_el_nombre_del_sistema(): void
    {
        config(['app.name' => 'Sistema de prueba con nombre largo']);

        $manifiesto = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('Sistema de prueba con nombre largo', $manifiesto['name']);
        // Bajo el ícono cabe poco: si el nombre es largo, va la primera palabra.
        $this->assertSame('Sistema', $manifiesto['short_name']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function iconos(): array
    {
        return [
            'normal 192' => ['app-icons/icon-192.png', 192],
            'normal 512' => ['app-icons/icon-512.png', 512],
            'maskable 512' => ['app-icons/icon-maskable-512.png', 512],
            'iPhone 180' => ['app-icons/apple-touch-icon.png', 180],
        ];
    }

    #[DataProvider('iconos')]
    public function test_los_iconos_existen_con_su_tamano(string $ruta, int $lado): void
    {
        $info = getimagesize(public_path($ruta));

        $this->assertNotFalse($info);
        $this->assertSame([$lado, $lado, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    private function assertEtiquetasDeApp(string $html): void
    {
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#131417">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/app-icons/apple-touch-icon.png">', $html);
        $this->assertStringContainsString('<meta name="mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-status-bar-style" content="black">', $html);
        $this->assertMatchesRegularExpression('/<meta name="apple-mobile-web-app-title" content="[^"]+">/', $html);
    }

    public function test_el_login_trae_las_etiquetas_para_instalar(): void
    {
        $this->assertEtiquetasDeApp($this->get(route('login'))->assertOk()->getContent());
    }

    public function test_el_layout_de_la_app_trae_las_etiquetas_para_instalar(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->assertEtiquetasDeApp($this->actingAs($usuario)->get(route('profile.edit'))->assertOk()->getContent());
    }

    public function test_el_layout_del_vendedor_trae_las_etiquetas_para_instalar(): void
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Vendedor Ficticio', 'user_id' => $vendedor->id]);

        $this->assertEtiquetasDeApp($this->actingAs($vendedor)->get(route('rutas.mi-salida.index'))->assertOk()->getContent());
    }
}
