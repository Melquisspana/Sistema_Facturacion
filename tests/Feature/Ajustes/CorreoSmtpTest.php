<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;
use App\Models\Configuracion;
use App\Models\User;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección Correo: servidor SMTP con confirmación N2, y convivencia con lo que ya
 * había (documentos fiscales y contabilidad, que siguen en la tabla anterior).
 *
 * Ningún test de este archivo envía correo: los que rozan el envío usan Mail::fake().
 */
class CorreoSmtpTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> Formulario válido completo. */
    private const FORMULARIO = [
        'servidor' => 'smtp.ejemplo.com',
        'puerto' => '587',
        'seguridad' => 'smtp',
        'usuario' => 'facturacion@ejemplo.com',
        'remitente' => 'facturacion@ejemplo.com',
        'remitente_nombre' => 'Dulces La Negrita',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** @param  array<string, mixed>  $extra */
    private function guardar(array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin())
            ->put(route('configuracion.correo.smtp.update'), array_merge(self::FORMULARIO, $extra));
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_de_correo_muestra_las_tres_secciones(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertSee('Servidor de correo (SMTP)')
            ->assertSee('Documentos fiscales')
            ->assertSee('Contabilidad');
    }

    /** Nombres humanos en pantalla; la clave técnica queda como apoyo, no como título. */
    public function test_los_campos_tienen_nombres_humanos(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertSee('Servidor')
            ->assertSee('Puerto')
            ->assertSee('Seguridad de la conexión')
            ->assertSee('Correo remitente')
            ->assertSee('Nombre remitente');
    }

    /** Sin override, el estado que se muestra es el del .env/config. */
    public function test_sin_override_la_pantalla_muestra_el_valor_de_config(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.delenv.com']);

        $this->actingAs($this->admin())
            ->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertSee('smtp.delenv.com');

        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('mail.smtp.host'));
    }

    // ------------------------------------------------------------------- N2

    /** El primer envío NO escribe: devuelve la pantalla de confirmación. */
    public function test_guardar_sin_confirmar_muestra_la_confirmacion_y_no_escribe(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com']);

        $this->guardar()
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('smtp.ejemplo.com');

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'mail.smtp.host']);
        $this->assertSame('smtp.viejo.com', Ajustes::texto('mail.smtp.host'));
    }

    /** La confirmación enseña el antes y el después de cada campo que cambia. */
    public function test_la_confirmacion_muestra_el_antes_y_el_despues(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com', 'mail.mailers.smtp.port' => 2525]);

        $this->guardar()
            ->assertOk()
            ->assertSee('smtp.viejo.com')
            ->assertSee('smtp.ejemplo.com')
            ->assertSee('2525')
            ->assertSee('587');
    }

    /** Cancelar es no volver: sin el segundo envío no se escribe nada. */
    public function test_cancelar_la_confirmacion_no_escribe_nada(): void
    {
        $this->guardar()->assertOk();

        // El usuario se va a otra pantalla en vez de confirmar.
        $this->actingAs($this->admin())->get(route('configuracion.correo.edit'))->assertOk();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_confirmar_guarda_el_override(): void
    {
        $this->guardar(['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.correo.edit'))
            ->assertSessionHas('status');

        $this->assertSame('smtp.ejemplo.com', Ajustes::texto('mail.smtp.host'));
        $this->assertSame(587, Ajustes::entero('mail.smtp.port'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('mail.smtp.host'));
    }

    /** El override de base de datos gana sobre el .env. */
    public function test_el_override_gana_sobre_config(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.delenv.com']);

        $this->guardar(['confirmacion' => '1'])->assertRedirect();

        $this->assertSame('smtp.ejemplo.com', Ajustes::texto('mail.smtp.host'));
    }

    /** Y quitarlo devuelve el valor al .env. */
    public function test_quitar_el_override_vuelve_al_fallback(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.delenv.com']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.correo.smtp.update'), self::FORMULARIO + ['confirmacion' => '1']);
        $this->assertSame('smtp.ejemplo.com', Ajustes::texto('mail.smtp.host'));

        $this->actingAs($admin);
        Ajustes::quitarOverride('mail.smtp.host');

        $this->assertSame('smtp.delenv.com', Ajustes::texto('mail.smtp.host'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('mail.smtp.host'));
    }

    /** Un envío que no cambia nada no monta ninguna ceremonia. */
    public function test_sin_cambios_no_pide_confirmacion(): void
    {
        config([
            'mail.mailers.smtp.host' => self::FORMULARIO['servidor'],
            'mail.mailers.smtp.port' => (int) self::FORMULARIO['puerto'],
            'mail.mailers.smtp.scheme' => self::FORMULARIO['seguridad'],
            'mail.mailers.smtp.username' => self::FORMULARIO['usuario'],
            'mail.from.address' => self::FORMULARIO['remitente'],
            'mail.from.name' => self::FORMULARIO['remitente_nombre'],
        ]);

        $this->guardar()
            ->assertRedirect(route('configuracion.correo.edit'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // ------------------------------------------------------------ validación

    public function test_un_puerto_fuera_de_rango_se_rechaza(): void
    {
        $this->guardar(['puerto' => '70000', 'confirmacion' => '1'])
            ->assertSessionHasErrors('puerto');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_un_remitente_invalido_se_rechaza(): void
    {
        $this->guardar(['remitente' => 'no-es-correo', 'confirmacion' => '1'])
            ->assertSessionHasErrors('remitente');
    }

    /** La seguridad es una lista cerrada: no se aceptan valores inventados. */
    public function test_la_seguridad_solo_admite_los_valores_soportados(): void
    {
        $this->guardar(['seguridad' => 'tls', 'confirmacion' => '1'])
            ->assertSessionHasErrors('seguridad');

        foreach (['auto', 'smtp', 'smtps'] as $valor) {
            $this->guardar(['seguridad' => $valor, 'confirmacion' => '1'])->assertRedirect();
        }
    }

    // --------------------------------------------------------------- accesos

    public function test_un_rol_sin_permiso_no_puede_guardar_el_servidor(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)
            ->put(route('configuracion.correo.smtp.update'), self::FORMULARIO)
            ->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_el_administrador_conserva_el_acceso(): void
    {
        $this->actingAs($this->admin())->get(route('configuracion.correo.edit'))->assertOk();
    }

    /**
     * Los campos SMTP son N2 y `configuracion.gestionar` alcanza: la ceremonia
     * fuerte (permiso aparte) es solo para lo fiscal.
     */
    public function test_un_gestor_de_configuracion_sin_permiso_critico_puede_guardar(): void
    {
        $rol = Role::findOrCreate('gestor_configuracion', 'web');
        $rol->syncPermissions([Permission::findOrCreate('configuracion.gestionar', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $usuario = User::factory()->create(['activo' => true])->assignRole('gestor_configuracion');

        $this->actingAs($usuario)
            ->put(route('configuracion.correo.smtp.update'), self::FORMULARIO + ['confirmacion' => '1'])
            ->assertRedirect();

        $this->assertSame('smtp.ejemplo.com', Ajustes::texto('mail.smtp.host'));
    }

    // ------------------------------------------------------------- legacy

    /** Documentos fiscales: mismo comportamiento y misma tabla que antes. */
    public function test_documentos_fiscales_conserva_su_comportamiento(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->put(route('configuracion.correo.update'), [
                'auto_envio' => '1',
                'adjuntar_jws' => '1',
                'plantilla' => 'Hola {{cliente}}',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Configuracion::olvidarCache();

        $this->assertTrue(Ajustes::bool('correo.auto_envio', false));
        $this->assertTrue(Ajustes::bool('correo.adjuntar_jws', false));
        $this->assertSame('Hola {{cliente}}', Ajustes::texto('correo.plantilla'));

        // Ya viven en la tabla nueva (fase 4) y NO quedan duplicadas en la anterior.
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'correo.auto_envio']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'correo.auto_envio']);

        Mail::assertNothingSent();
    }

    /** Contabilidad sigue funcionando igual, desde su propia pantalla. */
    public function test_contabilidad_conserva_su_comportamiento(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'conta@ejemplo.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Configuracion::olvidarCache();

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'contabilidad.correo', 'valor' => 'conta@ejemplo.com']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);
        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->direccion());

        Mail::assertNothingSent();
    }

    /** La pantalla de Correo refleja el estado de contabilidad y enlaza al suyo. */
    public function test_correo_muestra_el_estado_de_contabilidad(): void
    {
        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::set('contabilidad.enviar_copia', true);

        $this->actingAs($this->admin())
            ->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertSee('conta@ejemplo.com')
            ->assertSee(route('configuracion.contabilidad.edit'), false);
    }

    // ------------------------------------------------- ajustes fiscales N3

    /** Ninguna ruta de esta sección puede tocar los ajustes fiscales. */
    public function test_los_ajustes_fiscales_siguen_sin_ser_editables(): void
    {
        config(['dte.ambiente' => '00']);
        $this->actingAs($this->admin());

        foreach (['dte.ambiente', 'dte.transmision.ambiente', 'dte.firma.enabled', 'dte.transmision.enabled'] as $clave) {
            $this->assertFalse(Ajustes::puedeEditar($this->admin(), $clave), "{$clave} no debería ser editable.");
        }

        $this->assertSame('00', config('dte.ambiente'));
    }
}
