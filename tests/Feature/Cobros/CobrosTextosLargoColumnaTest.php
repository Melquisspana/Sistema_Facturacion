<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroCorreoProgreso;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroDocumentoProcedencia;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Cobros\BarridoCorreosCobro;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\ValidadorCodigoProveedorTxt;
use Error;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class CobrosTextosLargoColumnaTest extends TestCase
{
    use RefreshDatabase;

    private function documento(Cliente $cliente): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => 'externo',
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000719',
            'fecha_emision' => '2026-08-26',
            'monto' => '150.00',
        ]);
    }

    // Mismo formato de CobrosPagoDuplicadoTest: el proveedor cambia la huella del TXT.
    private function aplicar(Cliente $cliente, string $importe, string $nombre, string $relleno = ''): array
    {
        $codigo = ValidadorCodigoProveedorTxt::CODIGO_CALLEJA;
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."{$codigo};PROVEEDOR FICTICIO{$relleno};CF;DTE03M001P002000000000000719;26-AGO-26;{$importe}\n";

        return app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, $nombre),
        );
    }

    public function test_pago_repetido_con_archivos_largos_conserva_los_importes(): void
    {
        $cliente = Cliente::factory()->create();
        $documento = $this->documento($cliente);
        $this->aplicar($cliente, '75.00', str_repeat('á', 146).'.txt');
        $this->aplicar($cliente, '50.00', str_repeat('é', 146).'.txt', ' SEGUNDO');

        $evento = $documento->pagosEnRevision()->firstOrFail();
        $this->assertSame(EstadoEventoCobro::EnRevision, $evento->estado);
        $this->assertLessThanOrEqual(255, mb_strlen($evento->estado_motivo));
        foreach (['Anterior: 75.00', 'este pago: 50.00', 'documento: 150.00', 'suma: 125.00', 'DOS ABONOS'] as $texto) {
            $this->assertStringContainsString($texto, $evento->estado_motivo);
        }
        $this->assertSame('75.00', $documento->refresh()->monto_pagado);
    }

    public function test_vinculo_manual_con_nota_larga_y_contradicciones_cabe_en_la_columna(): void
    {
        $documento = $this->documento(Cliente::factory()->create());
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0099/00/7131',
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-08-26',
            'monto_albaran' => '130.00',
            'sala_codigo' => '0099',
        ]);

        app(VinculadorAlbaranes::class)->vincularAMano(
            $documento, $albaran, User::factory()->create(['name' => 'Operador de prueba']), str_repeat('ñ', 255),
        );

        $documento->refresh();
        $this->assertSame($albaran->id, $documento->ppq_albaran_id);
        $this->assertStringContainsString('pese a:', $documento->vinculacion_motivo);
        $this->assertLessThanOrEqual(255, mb_strlen($documento->vinculacion_motivo));
    }

    public function test_resolver_con_motivo_de_255_caracteres_cabe_en_la_columna(): void
    {
        $documento = $this->documento(Cliente::factory()->create());
        $evento = $documento->eventos()->create([
            'tipo' => 'pago', 'origen' => 'manual', 'estado' => 'en_revision', 'monto' => '50.00',
        ]);
        $usuario = User::factory()->create(['name' => 'Operador de prueba']);

        $evento->resolver(EstadoEventoCobro::Descartado, $usuario, str_repeat('ó', 255));

        $evento->refresh();
        $this->assertLessThanOrEqual(255, mb_strlen($evento->estado_motivo));
        $this->assertSame($usuario->id, $evento->resuelto_por);
        $this->assertSame(EstadoEventoCobro::Descartado, $evento->estado);
    }

    public function test_correo_con_asunto_largo_cabe_en_correo_y_evidencia(): void
    {
        $cliente = Cliente::factory()->create();
        $documento = $this->documento($cliente);
        $asunto = 'OBSERVACIONES '.str_repeat('ú', 286);

        $resumen = app(LectorCorreosCobro::class)->procesar($cliente, [[
            'id' => 'mensaje-largo',
            'asunto' => $asunto,
            'cuerpo' => 'Revisar el CCF '.$documento->numero_control,
        ]]);

        $this->assertSame(1, $resumen['asociados']);
        $correo = CobroCorreo::sole();
        $evento = CobroEvento::sole();
        $this->assertLessThanOrEqual(160, mb_strlen($evento->evidencia_nombre));
        $this->assertLessThanOrEqual(255, mb_strlen($correo->asunto));
    }

    public function test_fallo_intermedio_revierte_el_correo_continua_y_permite_reintentar(): void
    {
        Log::spy();
        $cliente = Cliente::factory()->create();
        $documento = $this->documento($cliente);
        $mensajes = [];
        foreach ([1, 2, 3] as $numero) {
            $mensajes[] = [
                'id' => 'mensaje-'.$numero,
                'asunto' => 'OBSERVACIONES '.str_repeat('á', 286),
                'cuerpo' => 'Revisar el CCF '.$documento->numero_control,
                'remitente' => 'operador@ejemplo.test',
                'fecha' => '2026-09-0'.$numero.' 10:00:00',
            ];
        }
        $fallar = true;
        // La excepción ocurre DESPUÉS de insertar el correo y su evento: prueba rollback,
        // no solo un parser que falla antes de escribir nada.
        CobroEvento::created(function (CobroEvento $evento) use (&$fallar, $mensajes) {
            if ($fallar && ($evento->datos['gmail_message_id'] ?? null) === 'mensaje-2') {
                throw new RuntimeException('Fallo simulado: '.implode(' / ', array_intersect_key(
                    $mensajes[1], array_flip(['asunto', 'cuerpo', 'remitente']),
                )));
            }
        });

        $lector = app(LectorCorreosCobro::class);
        $resumen = $lector->procesar($cliente, $mensajes);

        $this->assertSame(1, $resumen['fallidos']);
        $this->assertSame(2, $resumen['nuevos']);
        $this->assertSame(2, $resumen['asociados']);
        $this->assertSame(0, $resumen['sin_asociar']);
        $this->assertSame(0, $resumen['repetidos']);
        $this->assertSame(['mensaje-1', 'mensaje-3'], $resumen['correos']->pluck('gmail_message_id')->all());
        $this->assertDatabaseMissing('cobro_correos', ['gmail_message_id' => 'mensaje-2']);
        $this->assertDatabaseMissing('cobro_eventos', ['referencia_linea' => 'gmail-mensaje-2']);
        Log::shouldHaveReceived('warning')->once()->with('No se pudo procesar un correo de cobros.', [
            'gmail_message_id' => 'mensaje-2',
            'clase' => RuntimeException::class,
            'error' => 'Fallo simulado: [omitido] / [omitido] / [omitido]',
        ]);

        $barrido = app(BarridoCorreosCobro::class);
        $tanda = [
            'vistos' => collect($mensajes)->mapWithKeys(fn ($m) => [$m['id'] => Carbon::parse($m['fecha'])])->all(),
            'cola_agotada' => true,
        ];
        // Con un mensaje sin registrar, el barrido no se da por terminado.
        $this->assertFalse($barrido->confirmarAvance($cliente, 'consulta ficticia', $tanda)->barrido_completo);

        $fallar = false;
        $reintento = $lector->procesar($cliente, $mensajes);
        $this->assertSame(0, $reintento['fallidos']);
        $this->assertSame(1, $reintento['nuevos']);
        $this->assertSame(2, $reintento['repetidos']);
        $this->assertSame(3, CobroCorreo::count());
        $this->assertSame(3, CobroEvento::count());
        $this->assertTrue($barrido->confirmarAvance($cliente, 'consulta ficticia', $tanda)->barrido_completo);
    }

    public function test_los_demas_textos_libres_se_recortan_al_guardar(): void
    {
        $cliente = Cliente::factory()->create();
        $documento = $this->documento($cliente);
        $largo = str_repeat('界', 320);
        $documento->forceFill(['vinculacion_motivo' => $largo, 'revisar_historico_motivo' => $largo])->save();
        $ajuste = CobroAjuste::create([
            'cliente_id' => $cliente->id, 'referencia' => 'PPQ/99999', 'monto' => '-12.00',
            'motivo' => $largo, 'evidencia_nombre' => $largo,
        ]);
        $procedencia = CobroDocumentoProcedencia::create([
            'cobro_documento_id' => $documento->id, 'fuente' => 'gmail_enviados',
            'gmail_message_id' => 'adjunto-ficticio', 'adjunto_hash' => hash('sha256', 'ficticio'),
            'adjunto_nombre' => $largo,
        ]);
        $solicitud = CobroSolicitud::create([
            'cliente_id' => $cliente->id, 'referencia' => 'SOL-FICTICIA', 'formato' => 'prueba',
            'archivo_nombre' => 'ficticio.xlsx', 'presentada_nota' => $largo,
        ]);
        $correo = CobroCorreo::create([
            'gmail_message_id' => 'correo-ficticio', 'asunto' => $largo, 'remitente' => $largo,
            'motivo' => $largo,
        ]);

        foreach ([$documento, $ajuste, $procedencia, $solicitud, $correo] as $modelo) {
            $modelo->refresh();
            foreach ($modelo::largosDeTexto() as $campo => $limite) {
                $this->assertLessThanOrEqual($limite, mb_strlen($modelo->$campo));
                $this->assertStringEndsWith('...', $modelo->$campo);
            }
        }
        $this->assertSame($largo, $correo->motivo, 'motivo es TEXT desde septiembre: no se limita.');
    }

    public function test_el_log_de_un_error_sql_no_incluye_la_consulta_ni_sus_valores(): void
    {
        Log::spy();
        $mensaje = ['id' => 'mensaje-sql', 'asunto' => 'Asunto privado', 'cuerpo' => 'Cuerpo privado'];
        CobroCorreo::created(function () use ($mensaje) {
            throw new QueryException('sqlite', 'insert into cobro_correos (asunto, cuerpo) values (?, ?)', [
                $mensaje['asunto'], $mensaje['cuerpo'],
            ], new PDOException('Error simulado del motor.'));
        });

        $resumen = app(LectorCorreosCobro::class)->procesar(Cliente::factory()->create(), [$mensaje]);

        $this->assertSame(1, $resumen['fallidos']);
        $this->assertSame(0, CobroCorreo::count());
        Log::shouldHaveReceived('warning')->once()->with('No se pudo procesar un correo de cobros.', [
            'gmail_message_id' => 'mensaje-sql',
            'clase' => QueryException::class,
            'error' => 'Error simulado del motor.',
        ]);
    }

    public function test_el_comando_muestra_fallidos_y_captura_errores_de_tipo_throwable(): void
    {
        Log::spy();
        config()->set('cobros.correo.enabled', true);
        $cliente = Cliente::factory()->create();
        $this->mock(GmailClient::class)->shouldReceive('disponible')->once()->andReturnTrue();
        $this->partialMock(BarridoCorreosCobro::class)->shouldReceive('prepararTanda')->once()->andReturn([
            'mensajes' => [['id' => 'mensaje-error', 'asunto' => 'Aviso ficticio']],
            'vistos' => ['mensaje-error' => Carbon::parse('2026-09-01')],
            'cabeza' => 1, 'cola' => 0, 'ya_conocidos' => 0, 'cola_agotada' => true,
            'queda_backlog' => true, 'tope_alcanzado' => false,
        ]);
        CobroCorreo::created(fn () => throw new Error('Fallo simulado.'));

        $this->artisan('cobros:leer-correos', [
            '--cliente' => $cliente->id, '--query' => 'consulta ficticia', '--aplicar' => true,
        ])->expectsOutputToContain('1 fallido(s) (se reintentarán)')->assertExitCode(0);

        $this->assertSame(0, CobroCorreo::count());
        $this->assertFalse(CobroCorreoProgreso::sole()->barrido_completo);
    }

    public function test_recorte_respeta_el_limite_multibyte_y_no_toca_identidades(): void
    {
        foreach ([CobroEvento::class, CobroDocumento::class, CobroCorreo::class, CobroAjuste::class,
            CobroDocumentoProcedencia::class, CobroSolicitud::class] as $clase) {
            foreach ($clase::largosDeTexto() as $campo => $limite) {
                foreach ([null, '', str_repeat('界', $limite), str_repeat('ñ', $limite)] as $valor) {
                    $this->assertSame([$campo => $valor], $clase::recortarTextos([$campo => $valor]));
                }
                // Los caracteres combinantes no siempre ocupan ancho visual propio.
                $valor = str_repeat("a\u{0301}", $limite);
                $recortado = $clase::recortarTextos([$campo => $valor])[$campo];
                $this->assertLessThanOrEqual($limite, mb_strlen($recortado));
                $this->assertStringEndsWith('...', $recortado);
            }
            $identidades = array_fill_keys([
                'gmail_message_id', 'gmail_thread_id', 'referencia_linea', 'referencia',
                'referencia_calleja', 'archivo_hash', 'numero_control', 'numero_control_norm',
                'codigo_generacion', 'archivo_referido', 'archivo_nombre', 'archivo_path',
                'consulta', 'consulta_hash', 'adjunto_hash', 'codigo_generacion_relacionado',
            ], str_repeat('x', 600));
            $this->assertSame($identidades, $clase::recortarTextos($identidades));
        }
    }
}
