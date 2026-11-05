<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integraciones › Segundo buzón de compras. Mismo patrón que el buzón principal (§20):
 * conexión con confirmación N2, contraseña cifrada en la pantalla de secretos, prueba en
 * solo lectura con su propio historial, y la regla de que su carpeta no sea la del
 * principal. Datos ficticios; ninguna contraseña real.
 */
class SegundoBuzonComprasTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'clave-ficticia-del-buzon-2';

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function formulario(array $cambios = []): array
    {
        return array_replace([
            'servidor' => 'imap.ejemplo.com',
            'puerto' => '993',
            'seguridad' => 'ssl',
            'usuario' => 'compras2@ejemplo.com',
            'carpeta' => 'CCF',
        ], $cambios);
    }

    public function test_sin_permiso_no_se_ve(): void
    {
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->get(route('configuracion.integraciones.buzon-adicional'))->assertForbidden();
        $this->actingAs($facturacion)->put(route('configuracion.integraciones.buzon-adicional.update'), $this->formulario(['confirmacion' => '1']))
            ->assertForbidden();
    }

    public function test_la_pantalla_tapa_el_usuario_y_nunca_muestra_la_contrasena(): void
    {
        config([
            'documentos_recibidos.buzon_adicional.host' => 'imap.ejemplo.com',
            'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
            'documentos_recibidos.buzon_adicional.password' => self::SECRETO,
        ]);

        $html = $this->actingAs($this->admin())->get(route('configuracion.integraciones.buzon-adicional'))
            ->assertOk()
            ->assertSee('co••••@ejemplo.com')
            ->assertSee('configurada')
            ->assertSee('Probar conexión')
            ->getContent();

        $this->assertStringNotContainsString(self::SECRETO, $html);
    }

    public function test_guardar_pide_confirmacion_n2_y_el_lector_usa_lo_guardado(): void
    {
        config(['documentos_recibidos.buzon_adicional.host' => '', 'documentos_recibidos.mail.folder' => 'INBOX']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.integraciones.buzon-adicional.update'), $this->formulario())
            ->assertOk()->assertSee('Vas a cambiar el segundo buzón de compras');
        $this->assertSame('none', app(ConfiguracionBuzonAdicional::class)->driver());

        $this->actingAs($admin)->put(route('configuracion.integraciones.buzon-adicional.update'), $this->formulario(['confirmacion' => '1']))
            ->assertRedirect(route('configuracion.integraciones.buzon-adicional'));

        $buzon = app(ConfiguracionBuzonAdicional::class);
        $this->assertSame('imap', $buzon->driver());
        $this->assertSame('imap.ejemplo.com', $buzon->paraLector()['host']);
        $this->assertSame('CCF', $buzon->carpeta());
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('documentos_recibidos.buzon_adicional.host'));
    }

    public function test_la_carpeta_no_puede_ser_la_del_principal(): void
    {
        config(['documentos_recibidos.mail.folder' => 'INBOX']);

        $this->actingAs($this->admin())->from(route('configuracion.integraciones.buzon-adicional'))
            ->put(route('configuracion.integraciones.buzon-adicional.update'), $this->formulario(['carpeta' => 'INBOX', 'confirmacion' => '1']))
            ->assertSessionHasErrors('carpeta');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_la_contrasena_va_cifrada_por_la_pantalla_de_secretos(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracion.integraciones.buzon-adicional.secreto.edit'))->assertOk()
            ->assertSee('Contraseña del segundo buzón');

        $this->actingAs($admin)->put(route('configuracion.integraciones.buzon-adicional.secreto.update'), [
            'password' => self::SECRETO,
            'confirmacion' => '1',
        ])->assertRedirect(route('configuracion.integraciones.buzon-adicional'));

        $fila = AjusteSistema::query()->where('clave', 'documentos_recibidos.buzon_adicional.password')->sole();
        $this->assertTrue((bool) $fila->cifrado);
        $this->assertStringNotContainsString(self::SECRETO, (string) $fila->getRawOriginal('valor'));
        $this->assertSame(self::SECRETO, app(ConfiguracionBuzonAdicional::class)->password());

        $html = $this->actingAs($admin)->get(route('configuracion.integraciones.buzon-adicional'))->getContent();
        $this->assertStringNotContainsString(self::SECRETO, $html);
    }

    public function test_la_prueba_usa_el_segundo_buzon_y_su_propio_historial(): void
    {
        config([
            'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
            'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
            'documentos_recibidos.buzon_adicional.password' => self::SECRETO,
        ]);

        $recibido = null;
        $prueba = new PruebaConexionImap(
            app(ConfiguracionBuzonAdicional::class),
            app(RegistroVerificaciones::class),
            app(AuditoriaAjustes::class),
            function (array $cfg) use (&$recibido) {
                $recibido = $cfg;

                return 'Rechazado: '.self::SECRETO;
            },
        );

        $resultado = $prueba->ejecutar();

        $this->assertFalse($resultado->exito);
        $this->assertSame('imap.segundo.ejemplo.com', $recibido['host']);
        $this->assertStringNotContainsString(self::SECRETO, $resultado->mensaje);
        $this->assertNotNull(app(RegistroVerificaciones::class)->ultima(ConfiguracionBuzonAdicional::CLAVE_VERIFICACION));
        $this->assertNull(app(RegistroVerificaciones::class)->ultima('imap'));
    }
}
