<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Probador de la tanda 5 en Configuración: los botones usados como la oficina y
 * empujados a fallar (valores fuera de rango, segundo buzón a medio configurar,
 * secretos borrados en fila).
 *
 * Nada sale a internet ni toca la base real: el buzón es un doble.
 * Datos y secretos inventados.
 */
class ConfiguracionFlujosProbadorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------- avisos y gastos

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    /**
     * Valores fuera de rango o mal escritos: error en ESE campo y nada guardado, aunque
     * otro campo del mismo envío fuera válido.
     */
    public function test_avisos_con_valores_fuera_de_rango_no_guardan_nada(): void
    {
        config(['asistencia.enabled' => true, 'alertas.reaviso_minutos' => 60, 'alertas.tope_diario' => 6]);
        $admin = $this->admin();
        $valido = [$this->campo('alertas.tope_diario') => '10'];

        $casos = [
            'reaviso en cero' => ['alertas.reaviso_minutos', '0'],
            'reaviso de más de un día' => ['alertas.reaviso_minutos', '1441'],
            'reaviso negativo' => ['alertas.reaviso_minutos', '-5'],
            'reaviso con letras' => ['alertas.reaviso_minutos', 'treinta'],
            'tope de 101' => ['alertas.tope_diario', '101'],
            'hora 24:00' => ['alertas.respaldo_hora_limite', '24:00'],
            'hora sin cero' => ['alertas.respaldo_hora_limite', '7:30'],
            'correo mal escrito' => ['alertas.correo', 'avisos@ejemplo.com, no-es-correo'],
            'sin señal en cero' => ['alertas.lector.sin_ping_minutos', '0'],
            'sin señal de más' => ['alertas.lector.sin_ping_minutos', '241'],
            'horario al revés' => ['alertas.lector.horario.1', '17:00-08:00'],
            'horario sin cierre' => ['alertas.lector.horario.2', '08:00'],
            'horario de 25 horas' => ['alertas.lector.horario.3', '08:00-25:00'],
            'horario que abre y cierra a la vez' => ['alertas.lector.horario.4', '08:00-08:00'],
        ];

        foreach ($casos as $caso => [$clave, $valor]) {
            $this->actingAs($admin)
                ->from(route('configuracion.opciones.avisos'))
                ->put(route('configuracion.opciones.avisos.update'), array_replace($valido, [$this->campo($clave) => $valor, 'confirmacion' => '1']))
                ->assertRedirect(route('configuracion.opciones.avisos'))
                ->assertSessionHasErrors($this->campo($clave));

            $this->assertSame(0, DB::table('ajustes_sistema')->count(), "«{$caso}» no debió guardar nada.");
        }

        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
        $this->assertSame(6, Ajustes::entero('alertas.tope_diario'));
    }

    public function test_gastos_con_una_casilla_que_no_es_si_o_no_no_guarda_nada(): void
    {
        config(['gastos.enabled' => true, 'gastos.avisos.automaticos' => false]);
        $admin = $this->admin();

        foreach (['2', 'talvez', '-1'] as $malo) {
            $this->actingAs($admin)
                ->from(route('configuracion.opciones.gastos'))
                ->put(route('configuracion.opciones.gastos.update'), [
                    $this->campo('gastos.avisos.automaticos') => $malo,
                    $this->campo('gastos.recurrencias.generacion_automatica') => '1',
                    'confirmacion' => '1',
                ])
                ->assertSessionHasErrors($this->campo('gastos.avisos.automaticos'));
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertFalse(Ajustes::bool('gastos.recurrencias.generacion_automatica'));
    }

    // ----------------------------------------------------------- segundo buzón

    /**
     * Segundo buzón con servidor y usuario pero SIN contraseña propia: no se prueba con
     * la del buzón principal, no se conecta y no queda un fallo en el historial.
     */
    public function test_segundo_buzon_sin_su_contrasena_no_usa_la_del_principal_ni_se_conecta(): void
    {
        config([
            'documentos_recibidos.mail.driver' => 'imap',
            'documentos_recibidos.mail.host' => 'imap.principal.ejemplo.com',
            'documentos_recibidos.mail.username' => 'compras@ejemplo.com',
            'documentos_recibidos.mail.password' => 'secreto-de-prueba-buzon-1',
            'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
            'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
            'documentos_recibidos.buzon_adicional.password' => '',
            'documentos_recibidos.buzon_adicional.folder' => 'CCF',
        ]);
        $recibidos = [];
        $this->app->bind(PruebaConexionImap::class, function ($app, array $parametros) use (&$recibidos) {
            return new PruebaConexionImap(
                $parametros['configuracion'],
                $app->make(RegistroVerificaciones::class),
                $app->make(AuditoriaAjustes::class),
                function (array $cfg) use (&$recibidos) {
                    $recibidos[] = $cfg;

                    return null;
                },
            );
        });

        $this->actingAs($this->admin())
            ->post(route('configuracion.integraciones.buzon-adicional.probar'))
            ->assertRedirect(route('configuracion.integraciones.buzon-adicional'))
            ->assertSessionHas('error', 'Faltan datos de conexión (servidor, usuario o contraseña).');

        $this->assertSame([], $recibidos);
        $this->assertDatabaseCount('verificaciones_configuracion', 0);
        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }

    // ----------------------------------------------------------------- secretos

    /**
     * Quitar en fila Gmail y después el buzón de compras: cada uno se lleva solo lo suyo
     * y los que quedan (tienda y segundo buzón) siguen guardados y funcionando.
     */
    public function test_quitar_dos_secretos_seguidos_no_arrastra_a_los_demas(): void
    {
        config([
            'ppq.gmail.client_secret' => 'secreto-de-prueba-env-gmail',
            'documentos_recibidos.mail.password' => 'secreto-de-prueba-env-buzon-1',
        ]);
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('tienda.secreto', 'secreto-de-prueba-tienda');
        Ajustes::guardar('ppq.gmail.client_secret', 'secreto-de-prueba-gmail');
        Ajustes::guardar('documentos_recibidos.password', 'secreto-de-prueba-buzon-1');
        Ajustes::guardar('documentos_recibidos.buzon_adicional.password', 'secreto-de-prueba-buzon-2');

        foreach (['gmail', 'documentos-recibidos', 'gmail'] as $pantalla) {
            $this->actingAs($admin)
                ->delete(route('configuracion.integraciones.'.$pantalla.'.secreto.destroy'), ['confirmacion' => '1'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('secreto-de-prueba-env-gmail', Ajustes::secretoParaRuntime('ppq.gmail.client_secret'));
        $this->assertSame('secreto-de-prueba-env-buzon-1', Ajustes::secretoParaRuntime('documentos_recibidos.password'));
        $this->assertSame('secreto-de-prueba-tienda', Ajustes::secretoParaRuntime('tienda.secreto'));
        $this->assertSame('secreto-de-prueba-buzon-2', Ajustes::secretoParaRuntime('documentos_recibidos.buzon_adicional.password'));
        $this->assertDatabaseCount('ajustes_sistema', 2);

        // Dos quitados de verdad, dos líneas; el tercer toque a Gmail no dejó otra.
        $quitados = Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => ($a->properties['accion'] ?? null) === 'override_quitado');
        $this->assertCount(2, $quitados);
    }
}
