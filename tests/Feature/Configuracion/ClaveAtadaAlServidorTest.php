<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * La clave de una integración queda atada a su servidor: si alguien cambia la dirección
 * de la tienda web, el servidor de correo o el de un buzón, la clave guardada no sale
 * hacia el servidor nuevo hasta que alguien la cargue otra vez.
 *
 * - La guardada en la aplicación se borra al cambiar el servidor (queda en la auditoría,
 *   sin el valor) y el aviso dice dónde se carga de nuevo.
 * - La del archivo del servidor (.env) solo viaja al servidor del .env: con otro
 *   servidor guardado en la aplicación, no se usa.
 *
 * Mismo servidor, nada cambia; clave nueva, se guarda y se usa. Nada sale a internet:
 * se mira qué clave entregaría cada integración. Datos y claves inventados.
 */
class ClaveAtadaAlServidorTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_VIEJA = 'clave-de-prueba-vieja';

    private const CLAVE_NUEVA = 'clave-de-prueba-nueva';

    private const CLAVE_ENV = 'clave-de-prueba-del-env';

    /**
     * Cada integración: pantalla, formulario tal como se ve, campo del servidor, servidor
     * nuevo, clave del servidor, clave del secreto, ruta para cargar el secreto y .env.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: string, 4: string, 5: string, 6: string, 7: array<string, mixed>}>
     */
    public static function integraciones(): array
    {
        return [
            'tienda web' => [
                'configuracion.integraciones.tienda',
                ['url' => 'https://tienda.ejemplo.com/api', 'automatica' => '0'],
                'url', 'https://otra-tienda.ejemplo.com/api',
                'tienda.url', 'tienda.secreto',
                'configuracion.integraciones.tienda.secreto.update',
                [],
            ],
            'servidor de correo' => [
                'configuracion.correo.edit',
                ['servidor' => 'smtp.ejemplo.com', 'puerto' => '587', 'seguridad' => 'auto', 'usuario' => 'envios@ejemplo.com'],
                'servidor', 'smtp.otro.ejemplo.com',
                'mail.smtp.host', 'mail.smtp.password',
                'configuracion.correo.smtp.password.update',
                ['mail.mailers.smtp.host' => 'smtp.ejemplo.com', 'mail.mailers.smtp.port' => 587, 'mail.mailers.smtp.username' => 'envios@ejemplo.com'],
            ],
            'buzón de compras' => [
                'configuracion.integraciones.documentos-recibidos',
                [
                    'lectura' => 'imap', 'servidor' => 'imap.ejemplo.com', 'puerto' => '993', 'seguridad' => 'ssl',
                    'usuario' => 'compras@ejemplo.com', 'carpeta' => 'INBOX', 'busqueda' => 'ALL', 'espera' => '15', 'limite' => '30',
                ],
                'servidor', 'imap.otro.ejemplo.com',
                'documentos_recibidos.host', 'documentos_recibidos.password',
                'configuracion.integraciones.documentos-recibidos.secreto.update',
                [
                    'documentos_recibidos.mail.driver' => 'imap', 'documentos_recibidos.mail.host' => 'imap.ejemplo.com',
                    'documentos_recibidos.mail.username' => 'compras@ejemplo.com',
                ],
            ],
            'segundo buzón' => [
                'configuracion.integraciones.buzon-adicional',
                ['servidor' => 'imap.segundo.ejemplo.com', 'puerto' => '993', 'seguridad' => 'ssl', 'usuario' => 'compras2@ejemplo.com', 'carpeta' => 'CCF'],
                'servidor', 'imap.otro.ejemplo.com',
                'documentos_recibidos.buzon_adicional.host', 'documentos_recibidos.buzon_adicional.password',
                'configuracion.integraciones.buzon-adicional.secreto.update',
                [
                    'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
                    'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
                    'documentos_recibidos.buzon_adicional.folder' => 'CCF',
                ],
            ],
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Ruta de «Guardar» de la pantalla (la del servidor de correo tiene su propio nombre). */
    private function rutaGuardar(string $pantalla): string
    {
        return $pantalla === 'configuracion.correo.edit' ? 'configuracion.correo.smtp.update' : $pantalla.'.update';
    }

    /** Integración funcionando: .env, dirección (la tienda la guarda la aplicación) y clave guardada. */
    private function integracionFuncionando(User $admin, array $env, string $claveServidor, string $servidor, string $claveSecreto): void
    {
        config($env);
        $this->actingAs($admin);
        if ($claveServidor === 'tienda.url') {
            Ajustes::guardar('tienda.url', $servidor);
        }
        Ajustes::guardar($claveSecreto, self::CLAVE_VIEJA);
        $this->assertSame(self::CLAVE_VIEJA, Ajustes::secretoParaRuntime($claveSecreto));
    }

    /** Todo lo que quedó en la auditoría, para buscar claves que no deberían estar. */
    private function auditoriaCompleta(): string
    {
        return (string) json_encode(Activity::query()->get(['description', 'properties'])->toArray());
    }

    #[DataProvider('integraciones')]
    public function test_cambiar_el_servidor_quita_la_clave_guardada_y_no_la_manda_al_nuevo(
        string $pantalla, array $formulario, string $campo, string $nuevo, string $claveServidor, string $claveSecreto, string $rutaSecreto, array $env,
    ): void {
        $admin = $this->admin();
        $this->integracionFuncionando($admin, $env, $claveServidor, $formulario[$campo], $claveSecreto);
        $etiqueta = app(CatalogoAjustes::class)->definicion($claveSecreto)->etiqueta;

        $this->actingAs($admin)
            ->put(route($this->rutaGuardar($pantalla)), array_replace($formulario, [$campo => $nuevo, 'confirmacion' => '1']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($pantalla))
            ->assertSessionHas('status', fn (string $aviso) => str_contains($aviso, "«{$etiqueta}» dejó de usarse porque cambió")
                && str_contains($aviso, 'con el botón «Configurar», más abajo en esta pantalla.'));

        // El servidor nuevo quedó y la clave vieja ya no sale hacia él.
        $this->assertSame($nuevo, Ajustes::texto($claveServidor));
        $this->assertNull(Ajustes::secretoParaRuntime($claveSecreto));
        $this->assertFalse(AjusteSistema::query()->where('clave', $claveSecreto)->exists());

        // Queda en la auditoría quién la quitó, sin el valor.
        $quitada = Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => ($a->properties['clave'] ?? null) === $claveSecreto && $a->properties['accion'] === 'override_quitado')
            ->sole();
        $this->assertSame($admin->id, (int) $quitada->causer_id);
        $this->assertArrayNotHasKey('valor_antes', $quitada->properties->all());
        $this->assertStringNotContainsString(self::CLAVE_VIEJA, $this->auditoriaCompleta());
    }

    #[DataProvider('integraciones')]
    public function test_con_el_mismo_servidor_la_clave_sigue(
        string $pantalla, array $formulario, string $campo, string $nuevo, string $claveServidor, string $claveSecreto, string $rutaSecreto, array $env,
    ): void {
        $admin = $this->admin();
        $this->integracionFuncionando($admin, $env, $claveServidor, $formulario[$campo], $claveSecreto);

        // Mismo servidor (el nombre de un servidor, en mayúsculas) y otro campo cambiado.
        $otroCampo = $campo === 'url' ? ['automatica' => '1'] : ['puerto' => '2525', $campo => mb_strtoupper($formulario[$campo])];
        $this->actingAs($admin)
            ->put(route($this->rutaGuardar($pantalla)), array_replace($formulario, $otroCampo, ['confirmacion' => '1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $aviso) => ! str_contains($aviso, 'dejó de usarse'));

        $this->assertSame(self::CLAVE_VIEJA, Ajustes::secretoParaRuntime($claveSecreto));
    }

    #[DataProvider('integraciones')]
    public function test_con_la_clave_nueva_se_guarda_y_se_usa_con_el_servidor_nuevo(
        string $pantalla, array $formulario, string $campo, string $nuevo, string $claveServidor, string $claveSecreto, string $rutaSecreto, array $env,
    ): void {
        $admin = $this->admin();
        $this->integracionFuncionando($admin, $env, $claveServidor, $formulario[$campo], $claveSecreto);
        $this->actingAs($admin)
            ->put(route($this->rutaGuardar($pantalla)), array_replace($formulario, [$campo => $nuevo, 'confirmacion' => '1']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route($rutaSecreto), ['password' => self::CLAVE_NUEVA, 'confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($pantalla));

        $this->assertSame($nuevo, Ajustes::texto($claveServidor));
        $this->assertSame(self::CLAVE_NUEVA, Ajustes::secretoParaRuntime($claveSecreto));
    }

    /** La clave del .env solo viaja al servidor del .env. La tienda no tiene .env. */
    #[DataProvider('integraciones')]
    public function test_la_clave_del_env_no_sale_hacia_un_servidor_cambiado(
        string $pantalla, array $formulario, string $campo, string $nuevo, string $claveServidor, string $claveSecreto, string $rutaSecreto, array $env,
    ): void {
        $claveConfig = app(CatalogoAjustes::class)->definicion($claveSecreto)->claveConfig;
        if ($claveConfig === null) {
            $this->assertSame('tienda.secreto', $claveSecreto);

            return;
        }
        config($env + [$claveConfig => self::CLAVE_ENV]);
        $admin = $this->admin();
        $this->assertSame(self::CLAVE_ENV, Ajustes::secretoParaRuntime($claveSecreto));
        $etiqueta = app(CatalogoAjustes::class)->definicion($claveSecreto)->etiqueta;

        $this->actingAs($admin)
            ->put(route($this->rutaGuardar($pantalla)), array_replace($formulario, [$campo => $nuevo, 'confirmacion' => '1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $aviso) => str_contains($aviso, "«{$etiqueta}» dejó de usarse"));

        $this->assertNull(Ajustes::secretoParaRuntime($claveSecreto));
        $this->assertFalse(Ajustes::estadoParaPantalla($claveSecreto)->configurado);

        // De vuelta al servidor del .env, la clave del .env vuelve a servir.
        $this->actingAs($admin)
            ->put(route($this->rutaGuardar($pantalla)), array_replace($formulario, ['confirmacion' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::CLAVE_ENV, Ajustes::secretoParaRuntime($claveSecreto));
    }

    /**
     * «Usar el valor del servidor» en la dirección también es cambiar de servidor: la
     * clave guardada para la dirección de la aplicación no viaja a la del .env.
     */
    #[DataProvider('integraciones')]
    public function test_volver_al_servidor_del_env_quita_la_clave_guardada_para_el_otro(
        string $pantalla, array $formulario, string $campo, string $nuevo, string $claveServidor, string $claveSecreto, string $rutaSecreto, array $env,
    ): void {
        config($env);
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar($claveServidor, $nuevo);
        Ajustes::guardar($claveSecreto, self::CLAVE_VIEJA);
        $etiqueta = app(CatalogoAjustes::class)->definicion($claveSecreto)->etiqueta;

        $this->actingAs($admin)
            ->from(route($pantalla))
            ->delete(route('configuracion.ajustes.servidor', $claveServidor), ['confirmacion' => '1', 'volver' => route($pantalla)])
            ->assertRedirect(route($pantalla))
            ->assertSessionHas('status', fn (string $aviso) => str_contains($aviso, "«{$etiqueta}» dejó de usarse"));

        $this->assertNull(Ajustes::secretoParaRuntime($claveSecreto));
        $this->assertFalse(AjusteSistema::query()->where('clave', $claveSecreto)->exists());
    }

    /**
     * Quitar la clave guardada con otro servidor en la aplicación: la del .env tampoco se
     * usa, y el aviso lo dice en vez de prometer que «vuelve a usarse la del archivo».
     */
    public function test_quitar_la_clave_guardada_con_otro_servidor_no_vuelve_a_la_del_env(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.ejemplo.com', 'mail.mailers.smtp.password' => self::CLAVE_ENV]);
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.host', 'smtp.otro.ejemplo.com');
        Ajustes::guardar('mail.smtp.password', self::CLAVE_NUEVA);

        $this->actingAs($admin)
            ->delete(route('configuracion.correo.smtp.password.destroy'), ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.correo.edit'))
            ->assertSessionHas('status', 'Contraseña del servidor SMTP: se quitó el valor guardado. El del archivo del servidor no se usa porque el servidor guardado en la aplicación es otro. Queda sin configurar hasta que cargues uno nuevo.');

        $this->assertNull(Ajustes::secretoParaRuntime('mail.smtp.password'));
    }

    /** Lo que entrega cada integración al conectarse, no solo lo que dice el ajuste. */
    public function test_las_integraciones_no_entregan_la_clave_vieja_al_servidor_nuevo(): void
    {
        config([
            'documentos_recibidos.mail.driver' => 'imap',
            'documentos_recibidos.mail.host' => 'imap.ejemplo.com',
            'documentos_recibidos.mail.password' => self::CLAVE_ENV,
            'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
            'documentos_recibidos.buzon_adicional.password' => self::CLAVE_ENV,
        ]);
        $this->actingAs($this->admin());
        Ajustes::guardar('tienda.url', 'https://tienda.ejemplo.com/api');
        Ajustes::guardar('tienda.secreto', self::CLAVE_VIEJA);

        Ajustes::guardar('tienda.url', 'https://otra-tienda.ejemplo.com/api');
        Ajustes::guardar('documentos_recibidos.host', 'imap.otro.ejemplo.com');
        Ajustes::guardar('documentos_recibidos.buzon_adicional.host', 'imap.otro.ejemplo.com');

        $this->assertSame('', app(ConfiguracionTiendaWeb::class)->secreto());
        $this->assertFalse(app(ConfiguracionTiendaWeb::class)->completo());
        $this->assertSame('', app(ConfiguracionDocumentosRecibidos::class)->paraLector()['password']);
        $this->assertFalse(app(ConfiguracionDocumentosRecibidos::class)->completa());
        $this->assertSame('', app(ConfiguracionBuzonAdicional::class)->paraLector()['password']);
    }

    /** Cada secreto atado apunta a un ajuste de texto que existe y no es secreto. */
    public function test_cada_secreto_atado_apunta_a_una_direccion_que_existe(): void
    {
        $catalogo = app(CatalogoAjustes::class);
        $atados = array_filter($catalogo->todos(), fn ($d) => $d->servidor !== null);

        $this->assertEqualsCanonicalizing(
            ['tienda.secreto', 'mail.smtp.password', 'documentos_recibidos.password', 'documentos_recibidos.buzon_adicional.password'],
            array_keys($atados),
        );
        foreach ($atados as $secreto) {
            $this->assertSame(TipoAjuste::Texto, $catalogo->definicion($secreto->servidor)->tipo);
            $this->assertSame($secreto, $catalogo->secretoDeServidor($secreto->servidor));
        }
    }
}
