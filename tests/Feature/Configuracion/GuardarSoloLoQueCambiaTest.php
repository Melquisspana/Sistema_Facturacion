<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Las pantallas de Configuración que guardan con confirmación (tienda web, correo,
 * Gmail, los dos buzones de compras y Sistema) guardan SOLO lo que cambió, igual que
 * Configuración › Opciones.
 *
 * Antes guardaban todos los campos del formulario: la auditoría quedaba con líneas sin
 * cambio («No → No») y los campos que no se tocaron dejaban de seguir al .env. Ahora un
 * cambio deja una línea, lo demás sigue saliendo del servidor y guardar otra vez lo
 * mismo no deja ninguna. Datos inventados.
 */
class GuardarSoloLoQueCambiaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cada pantalla: .env de partida, formulario tal como se ve, el campo que se cambia,
     * la clave que tiene que quedar en la auditoría y los avisos.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>, 4: string, 5: string, 6: string}>
     */
    public static function pantallas(): array
    {
        return [
            'tienda web' => [
                'configuracion.integraciones.tienda.update',
                [],
                ['url' => 'https://tienda.ejemplo.com/api', 'automatica' => '0'],
                ['url' => 'https://tienda.ejemplo.com/api/v2'],
                'tienda.url',
                'Integración con la tienda web guardada.',
                'No había nada que cambiar en la tienda web.',
            ],
            'servidor de correo' => [
                'configuracion.correo.smtp.update',
                [
                    'mail.mailers.smtp.host' => 'smtp.ejemplo.com',
                    'mail.mailers.smtp.port' => 587,
                    'mail.mailers.smtp.username' => 'envios@ejemplo.com',
                    'mail.from.address' => 'facturas@ejemplo.com',
                    'mail.from.name' => 'Empresa de prueba',
                ],
                [
                    'servidor' => 'smtp.ejemplo.com', 'puerto' => '587', 'seguridad' => 'auto',
                    'usuario' => 'envios@ejemplo.com', 'remitente' => 'facturas@ejemplo.com',
                    'remitente_nombre' => 'Empresa de prueba',
                ],
                ['remitente_nombre' => 'Otra empresa de prueba'],
                'mail.from.name',
                'Servidor de correo guardado. Probá la conexión para comprobar que funciona.',
                'No había nada que cambiar en el servidor de correo.',
            ],
            'Gmail' => [
                'configuracion.integraciones.gmail.update',
                [
                    'ppq.gmail.enabled' => true,
                    'ppq.gmail.client_id' => 'cliente-de-prueba.apps.ejemplo.com',
                    'ppq.gmail.redirect_uri' => 'https://sistema.ejemplo.com/ppq/gmail/callback',
                    'ppq.gmail.label_albaranes' => 'Etiqueta_Prueba',
                    'ppq.gmail.enviados_query' => 'in:sent',
                    'ppq.gmail.dte_adjunto_query' => 'filename:json',
                ],
                [
                    'activada' => '1', 'client_id' => 'cliente-de-prueba.apps.ejemplo.com',
                    'redirect_uri' => 'https://sistema.ejemplo.com/ppq/gmail/callback',
                    'label_albaranes' => 'Etiqueta_Prueba', 'enviados_query' => 'in:sent',
                    'dte_adjunto_query' => 'filename:json',
                ],
                ['label_albaranes' => 'Otra_Etiqueta_Prueba'],
                'ppq.gmail.label_albaranes',
                'Integración con Gmail guardada. Probá la conexión para comprobar que funciona.',
                'No había nada que cambiar en la integración con Gmail.',
            ],
            'buzón de compras' => [
                'configuracion.integraciones.documentos-recibidos.update',
                [
                    'documentos_recibidos.mail.driver' => 'imap',
                    'documentos_recibidos.mail.host' => 'imap.ejemplo.com',
                    'documentos_recibidos.mail.port' => 993,
                    'documentos_recibidos.mail.encryption' => 'ssl',
                    'documentos_recibidos.mail.username' => 'compras@ejemplo.com',
                    'documentos_recibidos.mail.folder' => 'INBOX',
                    'documentos_recibidos.mail.search' => 'ALL',
                    'documentos_recibidos.mail.timeout' => 15,
                    'documentos_recibidos.limite' => 30,
                ],
                [
                    'lectura' => 'imap', 'servidor' => 'imap.ejemplo.com', 'puerto' => '993',
                    'seguridad' => 'ssl', 'usuario' => 'compras@ejemplo.com', 'carpeta' => 'INBOX',
                    'busqueda' => 'ALL', 'espera' => '15', 'limite' => '30',
                ],
                ['carpeta' => 'Compras'],
                'documentos_recibidos.folder',
                'Buzón de compras guardado. Probá la conexión para comprobar que funciona.',
                'No había nada que cambiar en el buzón de compras.',
            ],
            'segundo buzón' => [
                'configuracion.integraciones.buzon-adicional.update',
                [
                    'documentos_recibidos.mail.folder' => 'INBOX',
                    'documentos_recibidos.buzon_adicional.host' => 'imap.segundo.ejemplo.com',
                    'documentos_recibidos.buzon_adicional.port' => 993,
                    'documentos_recibidos.buzon_adicional.encryption' => 'ssl',
                    'documentos_recibidos.buzon_adicional.username' => 'compras2@ejemplo.com',
                    'documentos_recibidos.buzon_adicional.folder' => 'CCF',
                ],
                [
                    'servidor' => 'imap.segundo.ejemplo.com', 'puerto' => '993', 'seguridad' => 'ssl',
                    'usuario' => 'compras2@ejemplo.com', 'carpeta' => 'CCF',
                ],
                ['usuario' => 'compras3@ejemplo.com'],
                'documentos_recibidos.buzon_adicional.username',
                'Segundo buzón guardado. Probá la conexión para comprobar que funciona.',
                'No había nada que cambiar en el segundo buzón.',
            ],
            'Sistema' => [
                'configuracion.sistema.update',
                ['backup_diario.dias_retencion' => 30, 'backup.notifications.mail.to' => 'avisos@ejemplo.com'],
                ['retencion' => '30', 'avisos' => 'avisos@ejemplo.com'],
                ['retencion' => '45'],
                'respaldos.dias_retencion',
                'Política de respaldos guardada.',
                'No había nada que cambiar en la política de respaldos.',
            ],
        ];
    }

    /** Líneas de «cambió la configuración» de la auditoría. */
    private function lineasDeCambio()
    {
        return Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => ($a->properties['accion'] ?? null) === 'cambio')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $formulario
     * @param  array<string, mixed>  $cambio
     */
    #[DataProvider('pantallas')]
    public function test_guarda_y_audita_solo_el_campo_que_cambio(
        string $ruta, array $env, array $formulario, array $cambio, string $clave, string $exito, string $sinCambios,
    ): void {
        config($env);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $envio = array_replace($formulario, $cambio, ['confirmacion' => '1']);

        $this->actingAs($admin)->put(route($ruta), $envio)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $exito);

        // Una sola línea, la del campo que cambió, con su antes y su después.
        $linea = $this->lineasDeCambio()->sole();
        $this->assertSame($clave, $linea->properties['clave']);
        $this->assertNotEquals($linea->properties['valor_antes'] ?? null, $linea->properties['valor_despues'] ?? null);

        // Lo que no se tocó no quedó guardado: sigue saliendo del servidor.
        $this->assertSame([$clave], AjusteSistema::query()->pluck('clave')->all());
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente($clave));

        // Guardar lo mismo otra vez no deja ninguna línea y el aviso lo dice.
        $this->actingAs($admin)->put(route($ruta), $envio)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $sinCambios);

        $this->assertCount(1, $this->lineasDeCambio());
    }

    /** Sin ningún cambio, ni el primer envío deja líneas en la auditoría. */
    #[DataProvider('pantallas')]
    public function test_sin_cambios_no_queda_ninguna_linea(
        string $ruta, array $env, array $formulario, array $cambio, string $clave, string $exito, string $sinCambios,
    ): void {
        config($env);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($admin);
        // La tienda no tiene .env: su URL se guarda antes, como la dejó alguien.
        if ($ruta === 'configuracion.integraciones.tienda.update') {
            Ajustes::guardar('tienda.url', $formulario['url']);
        }
        $lineasAntes = $this->lineasDeCambio()->count();

        $this->put(route($ruta), $formulario + ['confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $sinCambios);

        $this->assertCount($lineasAntes, $this->lineasDeCambio());
    }

    /**
     * Un valor que el catálogo no admite sigue frenando el guardado aunque otro campo sí
     * cambie: no se descarta en silencio por no estar entre los cambios.
     */
    public function test_un_valor_invalido_para_el_catalogo_frena_el_guardado(): void
    {
        config(['backup_diario.dias_retencion' => 30, 'backup.notifications.mail.to' => 'avisos@ejemplo.com']);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');

        // «avisos@localhost» pasa la regla `email` del formulario pero no la del catálogo.
        $this->actingAs($admin)
            ->from(route('configuracion.sistema'))
            ->put(route('configuracion.sistema.update'), [
                'retencion' => '45', 'avisos' => 'avisos@localhost', 'confirmacion' => '1',
            ])
            ->assertRedirect(route('configuracion.sistema'))
            ->assertSessionHasErrors('avisos');

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertCount(0, $this->lineasDeCambio());
    }
}
