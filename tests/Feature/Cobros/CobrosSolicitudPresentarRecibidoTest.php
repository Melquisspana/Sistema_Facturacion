<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\SolicitudCobroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * Declarar presentada una solicitud y capturar a mano el acuse del cliente
 * (`cobros.solicitudes.presentar` y `cobros.solicitudes.recibido`).
 *
 * Generada → Presentada → Recibida va en un solo sentido: un segundo pedido, uno sobre una
 * solicitud ya recibida o sobre una corregida no puede mover los estados hacia atrás ni
 * revivir lo que un reenvío reemplazó. Datos de prueba, sin correos reales.
 */
class CobrosSolicitudPresentarRecibidoTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private Cliente $cliente;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function usuario(RolSistema $rol = RolSistema::Facturacion): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    /** CCF completo para el formato: con su albarán vinculado. */
    private function documento(): CobroDocumento
    {
        $this->n++;
        $numero = 'DTE-03-M001P002-'.str_pad((string) (80000 + $this->n), 15, '0', STR_PAD_LEFT);
        $dte = $this->dteVerificableCobros($this->cliente, $numero, '2026-09-18', '123.74');
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.(7000 + $this->n), 'tipo_codigo' => 'AC01', 'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74', 'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => '0017',
        ]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $numero, 'fecha_emision' => '2026-09-18', 'monto' => '123.74',
            'ppq_albaran_id' => $albaran->id,
        ]);
    }

    /** @return array{0: CobroSolicitud, 1: CobroDocumento, 2: CobroDocumento} */
    private function solicitud(): array
    {
        $a = $this->documento();
        $b = $this->documento();

        return [app(SolicitudCobroService::class)->crear($this->cliente, [$a->id, $b->id], $this->usuario()), $a, $b];
    }

    private function presentar(User $usuario, CobroSolicitud $solicitud, array $datos = [])
    {
        return $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.presentar', $solicitud), $datos);
    }

    private function recibido(User $usuario, CobroSolicitud $solicitud, array $datos = [])
    {
        return $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.recibido', $solicitud), $datos);
    }

    private function eventos(TipoEventoCobro $tipo): int
    {
        return CobroEvento::where('tipo', $tipo->value)->count();
    }

    // ------------------------------------------------------------------ presentar

    public function test_presentar_una_solicitud_generada_la_pasa_con_sus_documentos_y_deja_un_evento_por_documento(): void
    {
        [$solicitud, $a, $b] = $this->solicitud();
        $usuario = $this->usuario();

        $this->presentar($usuario, $solicitud, ['presentada_en' => '2026-09-19', 'nota' => 'Subida a las 9:30.'])
            ->assertRedirect(route('cobros.solicitudes.show', $solicitud))
            ->assertSessionHas('status', "Presentación de {$solicitud->referencia} registrada.");

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Presentada, $solicitud->estado);
        $this->assertSame('2026-09-19', $solicitud->presentada_en->toDateString());
        $this->assertSame($usuario->id, $solicitud->presentada_por);
        $this->assertSame('Subida a las 9:30.', $solicitud->presentada_nota);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $b->refresh()->presentacion_estado);
        $this->assertSame(1, $a->eventos()->where('tipo', TipoEventoCobro::Presentacion->value)->count());
        $this->assertSame(1, $b->eventos()->where('tipo', TipoEventoCobro::Presentacion->value)->count());
        $this->assertSame($usuario->id, $a->eventos()->where('tipo', TipoEventoCobro::Presentacion->value)->value('user_id'));
    }

    public function test_presentar_dos_veces_no_pisa_quien_ni_cuando_la_presento(): void
    {
        [$solicitud, $a] = $this->solicitud();
        $primera = $this->usuario();
        $this->presentar($primera, $solicitud, ['presentada_en' => '2026-09-19'])->assertSessionHas('status');

        $this->presentar($this->usuario(), $solicitud, ['presentada_en' => '2026-09-25', 'nota' => 'Otra vez'])
            ->assertSessionHasErrors('solicitud');

        $solicitud->refresh();
        $this->assertSame('2026-09-19', $solicitud->presentada_en->toDateString());
        $this->assertSame($primera->id, $solicitud->presentada_por);
        $this->assertNull($solicitud->presentada_nota);
        $this->assertSame(2, $this->eventos(TipoEventoCobro::Presentacion));
    }

    public function test_presentar_una_solicitud_ya_recibida_no_la_retrocede(): void
    {
        [$solicitud, $a, $b] = $this->solicitud();
        $usuario = $this->usuario();
        $this->recibido($usuario, $solicitud, ['referencia_calleja' => '31001'])->assertSessionHas('status');

        $this->presentar($usuario, $solicitud, ['presentada_en' => '2026-09-25'])->assertSessionHasErrors('solicitud');

        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $b->refresh()->presentacion_estado);
        $this->assertSame(0, $this->eventos(TipoEventoCobro::Presentacion));
    }

    public function test_presentar_una_solicitud_corregida_da_error_y_no_cambia_nada(): void
    {
        [$original, $a, $b] = $this->solicitud();
        $usuario = $this->usuario();
        $nueva = app(SolicitudCobroService::class)->corregir($original, [$a->id], 'Iba de más.', $usuario);

        $this->presentar($usuario, $original->refresh())->assertSessionHasErrors('solicitud');

        $this->assertSame(EstadoSolicitudCobro::Corregida, $original->refresh()->estado);
        $this->assertNull($original->presentada_en);
        $this->assertSame(EstadoSolicitudCobro::Generada, $nueva->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $b->refresh()->presentacion_estado);
        $this->assertSame(0, $this->eventos(TipoEventoCobro::Presentacion));
    }

    public function test_quien_solo_ve_cobros_no_presenta(): void
    {
        [$solicitud, $a] = $this->solicitud();

        $this->presentar($this->usuario(RolSistema::Jefatura), $solicitud)->assertForbidden();
        $this->presentar($this->usuario(RolSistema::Contabilidad), $solicitud)->assertForbidden();

        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
    }

    // ------------------------------------------------------------------ acuse a mano

    public function test_el_acuse_a_mano_pasa_la_solicitud_y_sus_documentos_a_recibida(): void
    {
        [$solicitud, $a, $b] = $this->solicitud();
        $usuario = $this->usuario();
        $this->presentar($usuario, $solicitud, ['presentada_en' => '2026-09-19']);

        $this->recibido($usuario, $solicitud, [
            'referencia_calleja' => ' 31001 ', 'fecha_programada_pago' => '2026-09-24', 'recibida_en' => '2026-09-20',
        ])->assertRedirect(route('cobros.solicitudes.show', $solicitud))
            ->assertSessionHas('status', "Acuse registrado para {$solicitud->referencia}.");

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame('2026-09-24', $solicitud->fecha_programada_pago->toDateString());
        $this->assertSame('2026-09-20', $solicitud->recibida_en->toDateString());
        $this->assertSame('2026-09-19', $solicitud->presentada_en->toDateString(), 'La presentación declarada no se pisa.');
        $this->assertSame(EstadoPresentacionCobro::Recibida, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $b->refresh()->presentacion_estado);
        $this->assertSame('2026-09-24', $a->fechaProgramadaPago()?->toDateString());
        $this->assertSame(2, $this->eventos(TipoEventoCobro::Recibido));
        $this->assertSame('manual', $a->eventos()->where('tipo', TipoEventoCobro::Recibido->value)->value('origen'));
    }

    public function test_el_acuse_sin_referencia_da_error_y_no_cambia_nada(): void
    {
        [$solicitud, $a] = $this->solicitud();

        $this->recibido($this->usuario(), $solicitud, ['referencia_calleja' => '  ', 'fecha_programada_pago' => '2026-09-24'])
            ->assertSessionHasErrors('referencia_calleja');

        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->refresh()->estado);
        $this->assertNull($solicitud->referencia_calleja);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame(0, $this->eventos(TipoEventoCobro::Recibido));
    }

    public function test_el_acuse_no_revive_una_solicitud_corregida(): void
    {
        [$original, $a, $b] = $this->solicitud();
        $usuario = $this->usuario();
        $nueva = app(SolicitudCobroService::class)->corregir($original, [$a->id], 'Iba de más.', $usuario);

        $this->recibido($usuario, $original->refresh(), ['referencia_calleja' => '31001'])->assertSessionHasErrors('solicitud');

        $original->refresh();
        $this->assertSame(EstadoSolicitudCobro::Corregida, $original->estado, 'La reemplazada no vuelve a figurar como recibida.');
        $this->assertNull($original->referencia_calleja);
        $this->assertSame(EstadoSolicitudCobro::Generada, $nueva->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $b->refresh()->presentacion_estado);
        $this->assertSame(0, $this->eventos(TipoEventoCobro::Recibido));
    }

    public function test_un_segundo_acuse_con_otra_referencia_no_borra_la_primera(): void
    {
        [$solicitud] = $this->solicitud();
        $usuario = $this->usuario();
        $this->recibido($usuario, $solicitud, ['referencia_calleja' => '31001', 'fecha_programada_pago' => '2026-09-24'])
            ->assertSessionHas('status');

        $this->recibido($usuario, $solicitud, ['referencia_calleja' => '39999', 'fecha_programada_pago' => '2026-10-30'])
            ->assertSessionHasErrors('referencia_calleja');

        $solicitud->refresh();
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame('2026-09-24', $solicitud->fecha_programada_pago->toDateString());
        $this->assertSame(2, $this->eventos(TipoEventoCobro::Recibido));
    }

    public function test_repetir_el_mismo_acuse_no_duplica_eventos(): void
    {
        [$solicitud] = $this->solicitud();
        $usuario = $this->usuario();

        $this->recibido($usuario, $solicitud, ['referencia_calleja' => '31001'])->assertSessionHas('status');
        $this->recibido($usuario, $solicitud, ['referencia_calleja' => '31001', 'fecha_programada_pago' => '2026-09-24'])
            ->assertSessionHas('status');

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame('2026-09-24', $solicitud->fecha_programada_pago->toDateString());
        $this->assertSame(2, $this->eventos(TipoEventoCobro::Recibido));
    }

    public function test_quien_solo_ve_cobros_no_registra_acuses(): void
    {
        [$solicitud] = $this->solicitud();

        $this->recibido($this->usuario(RolSistema::Jefatura), $solicitud, ['referencia_calleja' => '31001'])->assertForbidden();

        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->refresh()->estado);
        $this->assertNull($solicitud->referencia_calleja);
    }

    /**
     * El lector del buzón usa el mismo registro. Un acuse que nombra el archivo de una
     * solicitud ya corregida no la revive: el correo queda para revisar, con el motivo, y no
     * como falla que se reintenta en cada lectura.
     */
    public function test_el_acuse_por_correo_de_una_solicitud_corregida_queda_para_revisar(): void
    {
        [$original, $a] = $this->solicitud();
        $original->forceFill(['archivo_nombre' => '000123202609040951.xlsx'])->save();
        app(SolicitudCobroService::class)->corregir($original->refresh(), [$a->id], 'Iba de más.', $this->usuario());

        $resumen = app(LectorCorreosCobro::class)->procesar($this->cliente, [[
            'id' => 'msg-prueba-1', 'threadId' => 'thr-1', 'asunto' => 'RECIBIDO (000123202609040951)',
            'cuerpo' => "REFERENCIA #31001\nPROGRAMACION DE PAGO: 07/09/2026",
            'remitente' => 'fiscal@cliente-ejemplo.test', 'fecha' => 'Fri, 04 Sep 2026 09:51:00 -0600',
        ]]);

        $this->assertSame(0, $resumen['fallidos']);
        $this->assertSame(1, $resumen['sin_asociar']);
        $correo = CobroCorreo::where('gmail_message_id', 'msg-prueba-1')->sole();
        $this->assertSame('sin_asociar', $correo->estado);
        $this->assertStringContainsString('corregida', (string) $correo->motivo);
        $this->assertSame(EstadoSolicitudCobro::Corregida, $original->refresh()->estado);
        $this->assertNull($original->referencia_calleja);
    }
}
