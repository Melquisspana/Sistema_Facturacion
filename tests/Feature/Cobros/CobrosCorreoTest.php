<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\CorreoCobroParser;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\SolicitudCobroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * Lectura de los correos con que Calleja responde a una solicitud.
 *
 * Los mensajes de estas pruebas son los REALES que el encargo describe:
 *
 *   RECIBIDO (000123202609040951) · REFERENCIA #31001 · PROGRAMACION DE PAGO: 07/09/2026
 *   OBSERVACIONES REF 31001
 *
 * Nada de esto toca una cuenta de correo: el lector recibe mensajes ya leídos, que es
 * justamente la costura que permite probarlo. Y en ningún caso se envía, se responde ni se
 * modifica un mensaje.
 */
class CobrosCorreoTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    /** Una solicitud ya presentada, con el nombre de archivo que el acuse nombra. */
    private function solicitudPresentada(Cliente $cliente, string $archivo = '000123202609040951.xlsx'): CobroSolicitud
    {
        $usuario = User::factory()->create();

        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/5131',
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '26090017003463',
            'sala_codigo' => '0017',
        ]);

        $dte = $this->dteVerificableCobros($cliente, 'DTE-03-M001P002-000000000000119', '2026-08-26', '77.74');
        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000119',
            'codigo_generacion' => '00000000-0000-4000-8000-000000000102',
            'fecha_emision' => '2026-08-26',
            'monto' => '77.74',
            'ppq_albaran_id' => $albaran->id,
        ]);

        $servicio = app(SolicitudCobroService::class);
        $solicitud = $servicio->crear($cliente, [$documento->id], $usuario);
        $solicitud->forceFill(['archivo_nombre' => $archivo])->save();
        $servicio->registrarPresentacion($solicitud->refresh(), $usuario);

        return $solicitud->refresh();
    }

    /** @return array<string, mixed> */
    private function mensaje(array $datos = []): array
    {
        return array_merge([
            'id' => 'msg-'.uniqid(),
            'threadId' => 'thr-1',
            'asunto' => 'RECIBIDO (000123202609040951)',
            'cuerpo' => "REFERENCIA #31001\nPROGRAMACION DE PAGO: 07/09/2026",
            'remitente' => 'fiscal@cliente-ejemplo.test',
            'fecha' => 'Fri, 04 Sep 2026 09:51:00 -0600',
        ], $datos);
    }

    // ------------------------------------------------------------------ parser

    /** DORADA · el acuse real se interpreta entero: tipo, archivo, referencia y fecha. */
    public function test_dorada_interpreta_el_acuse_real_de_calleja(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar(
            'RECIBIDO (000123202609040951)',
            "REFERENCIA #31001\nPROGRAMACION DE PAGO: 07/09/2026",
        );

        $this->assertSame('recibido', $leido['tipo']);
        $this->assertSame('000123202609040951', $leido['archivo_referido']);
        $this->assertSame('31001', $leido['referencia_calleja']);
        // 07/09/2026 es SEPTIEMBRE: la fecha se lee como salvadoreña, no como americana.
        $this->assertSame('2026-09-07', $leido['fecha_programada_pago']);
    }

    /** El correo de observaciones se reconoce y conserva su referencia. */
    public function test_interpreta_el_correo_de_observaciones(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar(
            'OBSERVACIONES REF 31001',
            'El CCF DTE-03-M001P002-000000000000119 no trae su nota de crédito.',
        );

        $this->assertSame('observaciones', $leido['tipo']);
        $this->assertSame('31001', $leido['referencia_calleja']);
        $this->assertSame(['DTE03M001P002000000000000119'], $leido['numeros_control']);
    }

    /** Un correo que no se reconoce no se fuerza a ninguna categoría. */
    public function test_un_correo_cualquiera_queda_como_desconocido(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar('Reunión del jueves', 'Nos vemos a las 3.');

        $this->assertSame('desconocido', $leido['tipo']);
        $this->assertNull($leido['referencia_calleja']);
        $this->assertNull($leido['archivo_referido']);
    }

    /** Los códigos de generación se reconocen y se devuelven en mayúsculas, sin repetir. */
    public function test_reconoce_los_codigos_de_generacion_mencionados(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar(
            'OBSERVACIONES REF 31001',
            'Revisar 00000000-0000-4000-8000-000000000102 y 00000000-0000-4000-8000-000000000103 '
                .'(00000000-0000-4000-8000-000000000102 otra vez).',
        );

        $this->assertSame([
            '00000000-0000-4000-8000-000000000102',
            '00000000-0000-4000-8000-000000000103',
        ], $leido['codigos_generacion']);
    }

    // ------------------------------------------------------------------ lector

    /**
     * DORADA · el acuse se ata a la solicitud por el nombre del archivo, registra la
     * referencia y la fecha programada, y deja el documento como recibido.
     */
    public function test_dorada_el_acuse_se_asocia_por_el_archivo_y_registra_la_referencia(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);

        $resumen = app(LectorCorreosCobro::class)->procesar($cliente, [$this->mensaje()]);

        $this->assertSame(1, $resumen['nuevos']);
        $this->assertSame(1, $resumen['asociados']);

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame('2026-09-07', $solicitud->fecha_programada_pago?->toDateString());

        $documento = $solicitud->documentos()->firstOrFail();
        $this->assertSame(EstadoPresentacionCobro::Recibida, $documento->presentacion_estado);
        $this->assertSame(1, CobroEvento::where('tipo', TipoEventoCobro::Recibido->value)->count());

        // El mensaje original se conserva entero.
        $correo = CobroCorreo::firstOrFail();
        $this->assertSame('RECIBIDO (000123202609040951)', $correo->asunto);
        $this->assertStringContainsString('PROGRAMACION DE PAGO', (string) $correo->cuerpo);
        $this->assertSame('asociado', $correo->estado);
    }

    /** DORADA · releer el buzón no duplica nada ni vuelve a aplicar el acuse. */
    public function test_dorada_releer_el_buzon_no_duplica_correos_ni_eventos(): void
    {
        $cliente = $this->cliente();
        $this->solicitudPresentada($cliente);

        $lector = app(LectorCorreosCobro::class);
        $mensaje = $this->mensaje(['id' => 'msg-fijo']);

        $lector->procesar($cliente, [$mensaje]);
        $eventos = CobroEvento::count();

        $segundo = $lector->procesar($cliente, [$mensaje]);

        $this->assertSame(0, $segundo['nuevos']);
        $this->assertSame(1, $segundo['repetidos']);
        $this->assertSame(1, CobroCorreo::count());
        $this->assertSame($eventos, CobroEvento::count());
    }

    /** Un acuse que no corresponde a ningún envío queda a la vista, con su motivo. */
    public function test_un_acuse_de_un_archivo_desconocido_queda_sin_asociar(): void
    {
        $cliente = $this->cliente();
        $this->solicitudPresentada($cliente);

        $resumen = app(LectorCorreosCobro::class)->procesar($cliente, [
            $this->mensaje(['asunto' => 'RECIBIDO (000123209912310000)']),
        ]);

        $this->assertSame(1, $resumen['sin_asociar']);

        $correo = CobroCorreo::firstOrFail();
        $this->assertSame('sin_asociar', $correo->estado);
        $this->assertStringContainsString('000123209912310000', (string) $correo->motivo);
        $this->assertNull($correo->cobro_solicitud_id);
    }

    /**
     * Un acuse SIN referencia identifica el envío pero no se aplica: la referencia es lo
     * que después ata el ajuste de pronto pago a esta presentación.
     */
    public function test_un_acuse_sin_referencia_identifica_el_envio_pero_no_se_aplica(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);

        app(LectorCorreosCobro::class)->procesar($cliente, [
            $this->mensaje(['cuerpo' => 'Se recibió el archivo, gracias.']),
        ]);

        $correo = CobroCorreo::firstOrFail();
        $this->assertSame('sin_asociar', $correo->estado);
        $this->assertSame($solicitud->id, $correo->cobro_solicitud_id, 'El envío sí se identificó.');
        $this->assertStringContainsString('no trae la referencia', (string) $correo->motivo);

        $this->assertSame(EstadoSolicitudCobro::Presentada, $solicitud->refresh()->estado, 'No se dio por recibida.');
    }

    /**
     * DORADA · las observaciones se anotan en los documentos que el correo identifica, y
     * conviven con el estado de presentación y de pago sin pisarlos.
     */
    public function test_dorada_las_observaciones_se_anotan_sin_tocar_los_estados(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);
        $documento = $solicitud->documentos()->firstOrFail();

        $documento->forceFill(['observaciones' => 'Nota interna previa.'])->save();
        $estadoAntes = $documento->presentacion_estado;

        app(LectorCorreosCobro::class)->procesar($cliente, [
            $this->mensaje([
                'asunto' => 'OBSERVACIONES REF 31001',
                'cuerpo' => 'El CCF DTE-03-M001P002-000000000000119 viene sin su nota de crédito.',
            ]),
        ]);

        $documento->refresh();
        $this->assertSame($estadoAntes, $documento->presentacion_estado, 'Una observación no mueve la presentación.');
        $this->assertSame('pendiente', $documento->pago_estado->value, 'Ni el pago.');
        $this->assertSame('Nota interna previa.', $documento->observaciones, 'Ni pisa nuestra propia nota.');

        $observaciones = CobroEvento::where('cobro_documento_id', $documento->id)
            ->where('tipo', TipoEventoCobro::Observacion->value)
            ->get();

        $this->assertCount(1, $observaciones);
        $this->assertStringStartsWith('OBSERVACIONES REF 31001', $observaciones->first()->detalle);
        $this->assertStringContainsString('viene sin su nota de crédito', $observaciones->first()->detalle);
        $this->assertSame('asociado', CobroCorreo::firstOrFail()->estado);
    }

    /** Observaciones que no nombran ningún documento van a revisión manual. */
    public function test_observaciones_sin_documento_identificable_van_a_revision(): void
    {
        $cliente = $this->cliente();
        $this->solicitudPresentada($cliente);

        app(LectorCorreosCobro::class)->procesar($cliente, [
            $this->mensaje([
                'asunto' => 'OBSERVACIONES REF 31001',
                'cuerpo' => 'Hay diferencias en el envío, favor revisar.',
            ]),
        ]);

        $correo = CobroCorreo::firstOrFail();
        $this->assertSame('sin_asociar', $correo->estado);
        $this->assertStringContainsString('no menciona ningún código de generación', (string) $correo->motivo);
        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Observacion->value)->count());
    }

    /** Las observaciones también casan por código de generación. */
    public function test_las_observaciones_casan_por_codigo_de_generacion(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitudPresentada($cliente);
        $documento = $solicitud->documentos()->firstOrFail();

        app(LectorCorreosCobro::class)->procesar($cliente, [
            $this->mensaje([
                'asunto' => 'OBSERVACIONES REF 31001',
                'cuerpo' => 'Documento 00000000-0000-4000-8000-000000000102 observado.',
            ]),
        ]);

        $this->assertSame(1, CobroEvento::where('cobro_documento_id', $documento->id)
            ->where('tipo', TipoEventoCobro::Observacion->value)->count());
    }

    /** El módulo de correo viene APAGADO: leer el buzón es una decisión, no un efecto. */
    public function test_la_lectura_de_correo_esta_apagada_por_defecto(): void
    {
        $this->assertFalse((bool) config('cobros.correo.enabled'));
    }
}
