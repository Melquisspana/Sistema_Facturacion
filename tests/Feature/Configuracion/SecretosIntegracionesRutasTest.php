<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Integraciones › «Quitar valor guardado» de cada secreto, por su RUTA:
 * tienda web, Gmail, buzón de compras y segundo buzón.
 *
 * Lo que tiene que pasar: con la confirmación se borra SOLO la clave de esa pantalla
 * —las demás siguen guardadas y funcionando— y el secreto vuelve al del servidor; sin
 * la confirmación no se borra nada. Queda en la auditoría como los demás ajustes, sin
 * el valor. Secretos inventados.
 */
class SecretosIntegracionesRutasTest extends TestCase
{
    use RefreshDatabase;

    /** Secreto guardado de cada clave, todos distintos para notar cualquier cruce. */
    private const GUARDADOS = [
        'tienda.secreto' => 'secreto-de-prueba-tienda',
        'ppq.gmail.client_secret' => 'secreto-de-prueba-gmail',
        'documentos_recibidos.password' => 'secreto-de-prueba-buzon-1',
        'documentos_recibidos.buzon_adicional.password' => 'secreto-de-prueba-buzon-2',
        // Un vecino que no es de Integraciones: tampoco se toca.
        'mail.smtp.password' => 'secreto-de-prueba-smtp',
    ];

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function secretos(): array
    {
        return [
            'tienda web' => ['configuracion.integraciones.tienda.secreto.destroy', 'tienda.secreto', 'configuracion.integraciones.tienda'],
            'gmail' => ['configuracion.integraciones.gmail.secreto.destroy', 'ppq.gmail.client_secret', 'configuracion.integraciones.gmail'],
            'buzón de compras' => ['configuracion.integraciones.documentos-recibidos.secreto.destroy', 'documentos_recibidos.password', 'configuracion.integraciones.documentos-recibidos'],
            'segundo buzón' => ['configuracion.integraciones.buzon-adicional.secreto.destroy', 'documentos_recibidos.buzon_adicional.password', 'configuracion.integraciones.buzon-adicional'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Lo del archivo del servidor, para ver a qué vuelve cada uno.
        config([
            'ppq.gmail.client_secret' => 'secreto-de-prueba-env-gmail',
            'documentos_recibidos.mail.password' => 'secreto-de-prueba-env-buzon-1',
            'documentos_recibidos.buzon_adicional.password' => 'secreto-de-prueba-env-buzon-2',
            'mail.mailers.smtp.password' => 'secreto-de-prueba-env-smtp',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function guardarTodos(User $admin): void
    {
        $this->actingAs($admin);

        foreach (self::GUARDADOS as $clave => $valor) {
            Ajustes::guardar($clave, $valor);
        }
    }

    /** Actividades «quitó el valor guardado» de una clave. */
    private function quitados(string $clave)
    {
        return Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => $a->properties['clave'] === $clave && $a->properties['accion'] === 'override_quitado')
            ->values();
    }

    /**
     * Aviso que ve quien quita el secreto. La tienda no tiene secreto en el archivo del
     * servidor: queda sin configurar, y el aviso lo dice en los dos envíos. Los demás
     * vuelven al del archivo.
     */
    private function avisoEsperado(string $clave, bool $primerEnvio): string
    {
        $etiqueta = app(CatalogoAjustes::class)->definicion($clave)->etiqueta;

        if ($clave === 'tienda.secreto') {
            return $etiqueta.': no tiene ningún valor, ni guardado ni en el archivo del servidor. Queda sin configurar hasta que cargues uno nuevo.';
        }

        return $primerEnvio
            ? $etiqueta.': se quitó el valor guardado en la aplicación y vuelve a usarse el del archivo de configuración.'
            : $etiqueta.': ya usa el valor del archivo de configuración.';
    }

    private function assertSinSecretosEnLaAuditoria(): void
    {
        $volcado = json_encode(Activity::query()->get(['description', 'properties'])->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (self::GUARDADOS as $valor) {
            $this->assertStringNotContainsString($valor, (string) $volcado);
        }
    }

    #[DataProvider('secretos')]
    public function test_con_confirmacion_borra_solo_su_clave_y_queda_en_la_auditoria(string $ruta, string $clave, string $volver): void
    {
        $admin = $this->admin();
        $this->guardarTodos($admin);

        $this->actingAs($admin)
            // La clave sale del archivo de rutas: mandar otra en la petición no cambia nada.
            ->delete(route($ruta), ['confirmacion' => '1', 'clave' => 'mail.smtp.password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($volver))
            ->assertSessionHas('status', $this->avisoEsperado($clave, primerEnvio: true));

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => $clave]);
        $this->assertNotSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente($clave));

        foreach (self::GUARDADOS as $otra => $valor) {
            if ($otra === $clave) {
                continue;
            }
            $this->assertDatabaseHas('ajustes_sistema', ['clave' => $otra]);
            $this->assertSame($valor, Ajustes::secretoParaRuntime($otra), "«{$otra}» no debió tocarse al quitar «{$clave}».");
        }

        // Vuelve al del servidor (la tienda no tiene uno: queda sin secreto).
        $esperado = [
            'tienda.secreto' => null,
            'ppq.gmail.client_secret' => 'secreto-de-prueba-env-gmail',
            'documentos_recibidos.password' => 'secreto-de-prueba-env-buzon-1',
            'documentos_recibidos.buzon_adicional.password' => 'secreto-de-prueba-env-buzon-2',
        ][$clave];
        $this->assertSame($esperado, Ajustes::secretoParaRuntime($clave) ?: null);

        $quitado = $this->quitados($clave)->sole();
        $this->assertSame($admin->id, (int) $quitado->causer_id);
        $this->assertSame(FuenteAjuste::BaseDeDatos->value, $quitado->properties['fuente_antes']);
        $this->assertArrayNotHasKey('valor_antes', $quitado->properties->all());
        $this->assertArrayNotHasKey('valor_despues', $quitado->properties->all());
        $this->assertSinSecretosEnLaAuditoria();
    }

    #[DataProvider('secretos')]
    public function test_sin_confirmacion_no_borra_nada(string $ruta, string $clave, string $volver): void
    {
        $admin = $this->admin();
        $this->guardarTodos($admin);

        $this->actingAs($admin)
            ->from(route($volver))
            ->delete(route($ruta))
            ->assertSessionHasErrors('confirmacion');

        $this->assertDatabaseCount('ajustes_sistema', count(self::GUARDADOS));
        $this->assertSame(self::GUARDADOS[$clave], Ajustes::secretoParaRuntime($clave));
        $this->assertCount(0, $this->quitados($clave));
    }

    /**
     * El segundo toque (doble clic, o la pantalla vieja) no tiene nada que quitar: no
     * deja en la auditoría un segundo «quitó el valor guardado» que nunca pasó.
     */
    #[DataProvider('secretos')]
    public function test_el_doble_envio_no_duplica_la_auditoria(string $ruta, string $clave, string $volver): void
    {
        $admin = $this->admin();
        $this->guardarTodos($admin);

        $this->actingAs($admin)->delete(route($ruta), ['confirmacion' => '1'])
            ->assertRedirect(route($volver))
            ->assertSessionHas('status', $this->avisoEsperado($clave, primerEnvio: true));
        $this->actingAs($admin)->delete(route($ruta), ['confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route($volver))
            ->assertSessionHas('status', $this->avisoEsperado($clave, primerEnvio: false));

        $this->assertCount(1, $this->quitados($clave));
        $this->assertDatabaseCount('ajustes_sistema', count(self::GUARDADOS) - 1);
    }

    #[DataProvider('secretos')]
    public function test_sin_permiso_da_403_y_no_borra_nada(string $ruta, string $clave, string $volver): void
    {
        $this->guardarTodos($this->admin());
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->delete(route($ruta), ['confirmacion' => '1'])->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', count(self::GUARDADOS));
        $this->assertSame(self::GUARDADOS[$clave], Ajustes::secretoParaRuntime($clave));
    }
}
