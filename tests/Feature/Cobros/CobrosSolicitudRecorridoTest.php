<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * Recorrido de una solicitud de quedan por las pantallas de Cobros, como la usa la oficina:
 * vista previa → generar → presentar → acuse, con los dobles clics y los estados que no
 * corresponden. Datos inventados.
 */
class CobrosSolicitudRecorridoTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private Cliente $cliente;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function usuario(RolSistema $rol = RolSistema::Facturacion): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function documento(): CobroDocumento
    {
        $this->n++;
        $numero = 'DTE-03-M001P002-'.str_pad((string) (81000 + $this->n), 15, '0', STR_PAD_LEFT);
        $dte = $this->dteVerificableCobros($this->cliente, $numero, '2026-09-18', '123.74');
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.(7100 + $this->n), 'tipo_codigo' => 'AC01', 'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74', 'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => '0017',
        ]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $numero, 'fecha_emision' => '2026-09-18', 'monto' => '123.74',
            'ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ]);
    }

    private function previa(User $usuario, CobroDocumento ...$docs): string
    {
        return $this->actingAs($usuario)->post(route('cobros.solicitudes.previa', $this->cliente),
            ['documentos' => array_map(fn ($d) => $d->id, $docs)])->assertOk()->viewData('token');
    }

    private function generar(User $usuario, string $token)
    {
        return $this->actingAs($usuario)->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $token]);
    }

    private function presentar(User $usuario, CobroSolicitud $solicitud, array $datos = [])
    {
        return $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.presentar', $solicitud), $datos);
    }

    private function acuse(User $usuario, CobroSolicitud $solicitud, array $datos)
    {
        return $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.recibido', $solicitud), $datos);
    }

    public function test_generar_presentar_y_acusar_con_dobles_clics_y_estados_que_no_corresponden(): void
    {
        $oficina = $this->usuario();
        $a = $this->documento();
        $b = $this->documento();

        // Generar desde la vista previa. El doble clic en «Confirmar» no arma otra.
        $token = $this->previa($oficina, $a, $b);
        $this->generar($oficina, $token)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->generar($oficina, $token)->assertSessionHasErrors('documentos');
        $solicitud = CobroSolicitud::sole();
        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);

        // Los mismos CCF no entran en otra solicitud mientras esta siga vigente. El aviso nombra
        // el quedan por su referencia (la que se ve en pantalla) y no manda a corregirlo: no hay
        // pantalla para eso.
        $this->actingAs($oficina)->post(route('cobros.solicitudes.previa', $this->cliente), ['documentos' => [$a->id]])
            ->assertSessionHasErrors(['documentos' => 'Estos CCF ya van en otro quedan: '.$a->correlativoCorto()
                ." (quedan {$solicitud->referencia}). No se arma otro con lo mismo."]);
        $this->assertSame(1, CobroSolicitud::count());

        // Presentar y presentar otra vez.
        $this->presentar($oficina, $solicitud, ['presentada_en' => '2026-09-19'])->assertSessionHas('status');
        $this->presentar($oficina, $solicitud, ['presentada_en' => '2026-09-20'])
            ->assertSessionHasErrors(['solicitud' => 'Esta solicitud ya figura presentada el 19/09/2026: no se declara dos veces.']);
        $this->assertSame('2026-09-19', $solicitud->refresh()->presentada_en->toDateString());
        $this->assertSame(2, CobroEvento::where('tipo', TipoEventoCobro::Presentacion->value)->count());

        // Acuse, el mismo otra vez (doble clic) y otro con otra referencia.
        $this->acuse($oficina, $solicitud, ['referencia_calleja' => '31001', 'fecha_programada_pago' => '2026-09-24'])
            ->assertSessionHas('status');
        $this->acuse($oficina, $solicitud, ['referencia_calleja' => '31001', 'fecha_programada_pago' => '2026-09-24'])
            ->assertSessionHas('status');
        $this->acuse($oficina, $solicitud, ['referencia_calleja' => '39999'])->assertSessionHasErrors('referencia_calleja');
        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame(2, CobroEvento::where('tipo', TipoEventoCobro::Recibido->value)->count());
        $this->assertSame(EstadoPresentacionCobro::Recibida, $b->refresh()->presentacion_estado);

        // Presentar después del acuse la haría retroceder: se niega.
        $this->presentar($oficina, $solicitud)->assertSessionHasErrors('solicitud');
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->refresh()->estado);

        // La ficha abre y muestra la referencia del cliente.
        $this->actingAs($oficina)->get(route('cobros.solicitudes.show', $solicitud))->assertOk()->assertSee('31001');
    }

    public function test_una_solicitud_corregida_no_revive_ni_por_presentar_ni_por_acuse(): void
    {
        $oficina = $this->usuario();
        $a = $this->documento();
        $b = $this->documento();
        $this->generar($oficina, $this->previa($oficina, $a, $b))->assertSessionHas('status');
        $original = CobroSolicitud::sole();
        $this->presentar($oficina, $original, ['presentada_en' => '2026-09-19'])->assertSessionHas('status');

        // El reenvío sin B (no había corrección por pantalla: es el servicio).
        $nueva = app(SolicitudCobroService::class)->corregir($original, [$a->id], 'B iba de más.', $oficina);

        $this->presentar($oficina, $original->refresh())->assertSessionHasErrors('solicitud');
        $this->acuse($oficina, $original, ['referencia_calleja' => '31001'])->assertSessionHasErrors('solicitud');

        $this->assertSame(EstadoSolicitudCobro::Corregida, $original->refresh()->estado);
        $this->assertNull($original->referencia_calleja);
        $this->assertSame(EstadoSolicitudCobro::Generada, $nueva->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame($nueva->id, $a->cobro_solicitud_id);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $b->refresh()->presentacion_estado);
        $this->assertNull($b->cobro_solicitud_id);

        // La nueva sigue su camino normal.
        $this->presentar($oficina, $nueva, ['presentada_en' => '2026-09-20'])->assertSessionHas('status');
        $this->assertSame(EstadoPresentacionCobro::Presentada, $a->refresh()->presentacion_estado);
    }

    /** Una presentación o un acuse con fecha futura no se registra: todavía no pasó. */
    public function test_presentar_o_acusar_con_fecha_futura_da_error_y_no_cambia_nada(): void
    {
        $oficina = $this->usuario();
        $a = $this->documento();
        $this->generar($oficina, $this->previa($oficina, $a))->assertSessionHas('status');
        $solicitud = CobroSolicitud::sole();
        $manana = today()->addDay()->toDateString();

        $this->presentar($oficina, $solicitud, ['presentada_en' => $manana])->assertSessionHasErrors('presentada_en');
        $this->acuse($oficina, $solicitud, ['referencia_calleja' => '31001', 'recibida_en' => $manana])
            ->assertSessionHasErrors('recibida_en');

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->estado);
        $this->assertNull($solicitud->presentada_en);
        $this->assertNull($solicitud->referencia_calleja);
        $this->assertSame(0, CobroEvento::count());

        // La fecha programada de pago sí es futura: esa se acepta.
        $this->acuse($oficina, $solicitud, ['referencia_calleja' => '31001', 'fecha_programada_pago' => today()->addWeek()->toDateString()])
            ->assertSessionHas('status');
    }

    public function test_sin_permiso_no_genera_ni_presenta_ni_acusa_y_nada_cambia(): void
    {
        $oficina = $this->usuario();
        $a = $this->documento();
        $this->generar($oficina, $this->previa($oficina, $a))->assertSessionHas('status');
        $solicitud = CobroSolicitud::sole();
        $b = $this->documento();

        foreach ([RolSistema::Jefatura, RolSistema::Contabilidad] as $rol) {
            $quien = $this->usuario($rol);
            $this->actingAs($quien)->post(route('cobros.solicitudes.previa', $this->cliente), ['documentos' => [$b->id]])->assertForbidden();
            $this->actingAs($quien)->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => 'x'])->assertForbidden();
            $this->presentar($quien, $solicitud)->assertForbidden();
            $this->acuse($quien, $solicitud, ['referencia_calleja' => '31001'])->assertForbidden();
        }

        $this->assertSame(1, CobroSolicitud::count());
        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->refresh()->estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $b->refresh()->presentacion_estado);
    }
}
