<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Cobros\CorreoCobroParser;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\RevisionHistoricaService;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Support\Correo\MensajeActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class CobrosProteccionesProduccionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private function documento(int $numero = 119, array $datos = []): CobroDocumento
    {
        $cliente = Cliente::first() ?? Cliente::factory()->contribuyente()->create();

        return CobroDocumento::create($datos + [
            'cliente_id' => $cliente->id,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $numero, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => $numero === 119 ? '00000000-0000-4000-8000-000000000102' : strtoupper((string) Str::uuid()),
            'fecha_emision' => '2026-08-26',
            'monto' => '77.74',
        ]);
    }

    private function albaran(array $datos = []): PpqAlbaran
    {
        return PpqAlbaran::create($datos + [
            'numero_albaran' => 'AC01/0207/00/3874', 'tipo_codigo' => 'AC01',
            'sala_codigo' => '0207', 'numero_orden_compra' => '26080207003463',
            'fecha_albaran' => '2026-08-26', 'monto_albaran' => '77.74',
        ]);
    }

    private function conDte(CobroDocumento $doc): CobroDocumento
    {
        if (! Establecimiento::exists()) {
            $this->seedCatalogosDte();
            $this->crearEmisorDte();
        }
        $estab = Establecimiento::firstOrFail();
        $dte = Dte::create([
            'establecimiento_id' => $estab->id, 'cliente_id' => $doc->cliente_id,
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '00',
            'numero_control' => $doc->numero_control, 'codigo_generacion' => $doc->codigo_generacion,
            'fecha_emision' => '2026-08-26', 'hora_emision' => '10:00:00',
            'numero_orden_compra' => '26080207003463', 'total_pagar' => $doc->monto,
        ]);
        $doc->update(['dte_id' => $dte->id]);

        return $doc->refresh();
    }

    private function rechaza(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Debía bloquearse la operación.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_en_produccion_la_bandeja_oculta_los_dte_de_pruebas(): void
    {
        $pruebas = $this->conDte($this->documento(30));   // su DTE es ambiente 00
        $real = $this->documento(31);                      // externo, sin DTE
        ClientePerfilDocumento::create(['cliente_id' => $pruebas->cliente_id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        $usuario = User::factory()->create()->assignRole('administrador');

        config(['dte.ambiente' => '01']);
        $ids = $this->actingAs($usuario)->get(route('cobros.index', ['cliente_id' => $pruebas->cliente_id]))
            ->assertOk()->viewData('documentos')->pluck('id')->all();

        $this->assertNotContains($pruebas->id, $ids);
        $this->assertContains($real->id, $ids);
    }

    public function test_historico_completo_no_se_presenta_por_servicio_ni_por_http(): void
    {
        $doc = $this->documento(119, ['ppq_albaran_id' => $this->albaran()->id, 'revisar_historico' => true]);
        $this->assertFalse($doc->estaCompletoParaPresentar());
        $this->rechaza(fn () => app(SolicitudCobroService::class)->crear($doc->cliente, [$doc->id]));
        $usuario = User::factory()->create()->assignRole('administrador');
        $this->actingAs($usuario)->post(route('cobros.solicitudes.store', $doc->cliente), ['documentos' => [$doc->id]])->assertRedirect();
        $this->assertSame(0, CobroSolicitud::count());
    }

    public function test_pago_parcial_completo_y_en_revision_impiden_presentar_la_factura_entera(): void
    {
        $doc = $this->documento(119, ['ppq_albaran_id' => $this->albaran()->id]);
        foreach (['parcial', 'pagado', 'diferencia'] as $estado) {
            $doc->forceFill(['pago_estado' => $estado, 'monto_pagado' => '10.00'])->save();
            $this->rechaza(fn () => app(SolicitudCobroService::class)->crear($doc->cliente, [$doc->id]));
        }
        $doc->forceFill(['pago_estado' => 'pendiente', 'monto_pagado' => '0.00'])->save();
        $doc->eventos()->create(['tipo' => 'pago', 'origen' => 'txt', 'estado' => 'en_revision', 'monto' => '10.00']);
        $this->rechaza(fn () => app(SolicitudCobroService::class)->crear($doc->cliente, [$doc->id]));
        $this->assertSame(0, CobroSolicitud::count());
    }

    public function test_revision_exige_evidencia_conserva_motivo_y_no_se_reabre_al_sincronizar(): void
    {
        $doc = $this->documento(119, ['revisar_historico' => true, 'revisar_historico_motivo' => 'Lote anterior']);
        $user = User::factory()->create();
        $servicio = app(RevisionHistoricaService::class);
        $this->rechaza(fn () => $servicio->resolver($doc, $user, 'habilitar_presentacion', 'Revisado', ''));
        $servicio->resolver($doc, $user, 'habilitar_presentacion', 'Nunca presentado ni cobrado', 'Correo de confirmación del 20/09, referencia 31001');
        $this->assertFalse($doc->refresh()->revisar_historico);
        $this->assertSame('Lote anterior', $doc->eventos()->first()->datos['motivo_anterior']);
        $this->assertSame($user->id, $doc->eventos()->first()->user_id);
        app(AltaCobrosService::class)->marcarRevisionHistorica($doc->cliente, now()->addYear());
        $this->assertFalse($doc->refresh()->revisar_historico);
        $this->rechaza(fn () => $servicio->resolver($doc, $user, 'habilitar_presentacion', 'Otra vez', 'Correo'));
    }

    public function test_revision_que_confirma_cobro_no_inventa_pago_ni_habilita_presentacion(): void
    {
        $doc = $this->documento(119, ['revisar_historico' => true]);
        app(RevisionHistoricaService::class)->resolver($doc, User::factory()->create(), 'mantener_bloqueo', 'Ya cobrado por PPQ', 'Lote de junio');
        $this->assertTrue($doc->refresh()->revisar_historico);
        $this->assertSame('0.00', $doc->monto_pagado);
        $this->assertSame(TipoEventoCobro::Nota, $doc->eventos()->first()->tipo);
    }

    public function test_dos_ccf_compiten_aunque_solo_uno_este_en_el_lote_y_coincida_el_monto(): void
    {
        $albaran = $this->albaran();
        $uno = $this->conDte($this->documento());
        $dos = $this->conDte($this->documento(120, ['monto' => '100.00']));
        $servicio = app(VinculadorAlbaranes::class);
        $this->assertSame(1, $servicio->auditarLote(collect([$uno]))['resumen']['revisar']);
        foreach ([$uno, $dos] as $doc) {
            $this->assertSame(EstadoVinculacionAlbaran::Revisar, $servicio->aplicar($doc));
            $this->assertNull($doc->refresh()->ppq_albaran_id);
        }
        $this->assertNull($albaran->refresh()->dte_id);
    }

    public function test_ccf_sin_colision_se_vincula_y_el_importe_distinto_se_revisa_sin_crear_nc(): void
    {
        $albaran = $this->albaran();
        $doc = $this->conDte($this->documento());
        $servicio = app(VinculadorAlbaranes::class);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $servicio->aplicar($doc));
        $albaran->update(['monto_albaran' => '76.69']);
        $veredicto = $servicio->auditar($doc->refresh());
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('no deducir una NC', $veredicto['motivo']);
        $this->assertSame(0, Dte::where('tipo_dte', '05')->count());
    }

    public function test_vinculo_explicito_y_manual_no_pueden_reutilizar_albaran_ocupado(): void
    {
        $uno = $this->conDte($this->documento());
        $albaran = $this->albaran(['dte_id' => $uno->dte_id]);
        $dos = $this->documento(120, ['ppq_albaran_id' => $albaran->id]);
        $servicio = app(VinculadorAlbaranes::class);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $servicio->auditar($uno)['estado']);
        $this->rechaza(fn () => $servicio->vincularAMano($uno, $albaran, User::factory()->create(), 'Lo revisé'));
        $this->assertSame($albaran->id, $dos->refresh()->ppq_albaran_id);
        $this->assertNull($uno->refresh()->ppq_albaran_id);
    }

    public function test_vinculo_manual_exige_motivo_y_la_segunda_peticion_no_duplica(): void
    {
        $uno = $this->documento();
        $dos = $this->documento(120);
        $albaran = $this->albaran();
        $usuario = User::factory()->create();
        $servicio = app(VinculadorAlbaranes::class);
        $this->rechaza(fn () => $servicio->vincularAMano($uno, $albaran, $usuario));
        $servicio->vincularAMano($uno, $albaran, $usuario, 'Confirmado con el albarán original');
        $this->rechaza(fn () => $servicio->vincularAMano($dos, $albaran, $usuario, 'Segundo intento'));
        $this->assertSame(1, CobroDocumento::where('ppq_albaran_id', $albaran->id)->count());
    }

    private function observaciones(): string
    {
        return "00000000-0000-4000-8000-000000000102\tDTE-03-M001P002-000000000000119\t77.74\tFALTA NOTA DE CREDITO\n"
            ."00000000-0000-4000-8000-000000000103\tDTE-03-M001P001-000000000001186\t141.25\tNO APARECE EN REPORTERIA\n"
            ."[cid:0803C54C-1234-1234-1234-123456789ABC]\n________________________\n"
            ."De: Cuentas por pagar\nRECIBIDO (000123202609040951)\nREFERENCIA #31001\nPROGRAMACION DE PAGO: 07/09/2026";
    }

    public function test_observaciones_31001_son_dos_documentos_sin_acuse_archivo_ni_imagenes(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar('OBSERVACIONES REF 31001', $this->observaciones());
        $this->assertSame('observaciones', $leido['tipo']);
        $this->assertSame('31001', $leido['referencia_calleja']);
        $this->assertNull($leido['archivo_referido']);
        $this->assertNull($leido['fecha_programada_pago']);
        $this->assertCount(2, $leido['documentos']);
        $this->assertCount(2, $leido['codigos_generacion']);
        $this->assertCount(2, $leido['numeros_control']);
    }

    public function test_documento_ausente_sigue_pendiente_y_el_presente_recibe_su_observacion_sin_marcar_recibido(): void
    {
        $doc = $this->documento();
        $mensaje = ['id' => 'obs31001', 'asunto' => 'OBSERVACIONES REF 31001', 'cuerpo' => $this->observaciones()];
        $lector = app(LectorCorreosCobro::class);
        $lector->procesar($doc->cliente, [$mensaje]);
        $lector->procesar($doc->cliente, [$mensaje]);
        $correo = CobroCorreo::firstOrFail();
        $this->assertSame('sin_asociar', $correo->estado);
        $this->assertStringContainsString('1186', $correo->motivo);
        $this->assertStringContainsString('RECIBIDO', $correo->cuerpo, 'Se conserva la evidencia íntegra.');
        $this->assertSame(1, $doc->eventos()->count());
        $this->assertStringContainsString('FALTA NOTA DE CREDITO', $doc->eventos()->first()->detalle);
        $this->assertSame('sin_presentar', $doc->refresh()->presentacion_estado->value);
        $this->assertSame('pendiente', $doc->pago_estado->value);
    }

    public function test_uuid_y_control_contradictorios_no_aplican_observacion_a_ninguno(): void
    {
        $uno = $this->documento();
        $dos = $this->documento(120);
        app(LectorCorreosCobro::class)->procesar($uno->cliente, [[
            'id' => 'contradiccion', 'asunto' => 'OBSERVACIONES REF 31001',
            'cuerpo' => $uno->codigo_generacion."\t".$dos->numero_control."\tREVISAR",
        ]]);
        $this->assertSame(0, $uno->eventos()->count() + $dos->eventos()->count());
        $this->assertSame('sin_asociar', CobroCorreo::firstOrFail()->estado);
    }

    public function test_html_actual_no_se_pierde_por_historial_del_texto_plano(): void
    {
        $actual = MensajeActual::desdePartes("Hola\nOn Friday wrote:\nRECIBIDO (000123202609040951)",
            '<p>OBSERVACIONES REF 31001</p><table><tr><td>DTE-03-M001P002-000000000000119</td><td>FALTA NC</td></tr></table><div class="gmail_quote">RECIBIDO (000123202609040951)</div>');
        $this->assertStringContainsString('FALTA NC', $actual);
        $this->assertStringNotContainsString('RECIBIDO', $actual);
        $this->assertCount(1, app(CorreoCobroParser::class)->interpretar('Observaciones', $actual)['documentos']);
    }

    public function test_acuse_actual_se_conserva_y_rechazado_nunca_se_convierte_en_recibido(): void
    {
        $parser = app(CorreoCobroParser::class);
        $actual = "RECIBIDO (000123202609040951)\nREFERENCIA #31001\nPROGRAMACION DE PAGO: 07/09/2026\nFrom: anterior\nREFERENCIA #99999";
        $leido = $parser->interpretar('RE: SOLICITUD DE QUEDAN', $actual);
        $this->assertSame('recibido', $leido['tipo']);
        $this->assertSame('000123202609040951', $leido['archivo_referido']);
        $this->assertSame('2026-09-07', $leido['fecha_programada_pago']);
        $this->assertSame('desconocido', $parser->interpretar('RECHAZADO', $actual)['tipo']);
    }

    public function test_saltos_visuales_dentro_de_la_celda_no_dividen_la_identidad(): void
    {
        $html = '<table><tr><td><p>C04E2D83-2DCF-4B46-<br>8EB4-502893362BC2</p></td>'
            .'<td><p>DTE-03-M001P002-<br>000000000000119</p></td><td>77.74</td><td>FALTA NC</td></tr></table>';
        $actual = MensajeActual::desdePartes('', $html);
        $leido = app(CorreoCobroParser::class)->interpretar('OBSERVACIONES REF 31001', $actual);
        $this->assertCount(1, $leido['documentos']);
        $this->assertSame('00000000-0000-4000-8000-000000000102', $leido['documentos'][0]['codigo_generacion']);
        $this->assertSame('DTE03M001P002000000000000119', $leido['documentos'][0]['numero_control']);
    }
}
