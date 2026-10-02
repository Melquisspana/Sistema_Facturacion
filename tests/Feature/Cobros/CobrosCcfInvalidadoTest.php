<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoDte;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Cobros\VinculadorAlbaranes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class CobrosCcfInvalidadoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const OC = '26090017003463';

    private Cliente $cliente;

    private ?Establecimiento $estab = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        config(['ppq.gmail.enabled' => false]);
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        ClientePerfilDocumento::create([
            'cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0,
        ]);
    }

    private function documento(int $numero, bool $invalidado = false, string $oc = self::OC): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'cliente_id' => $this->cliente->id,
            'tipo_dte' => '03', 'ambiente' => '01',
            'estado' => $invalidado ? EstadoDte::Invalidado->value : EstadoDte::Aceptado->value,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $numero, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(), 'numero_orden_compra' => $oc,
            'fecha_emision' => '2026-09-18', 'hora_emision' => '08:00:00', 'total_pagar' => '123.74',
        ]);

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id, 'tipo_dte' => '03', 'numero_control' => $dte->numero_control,
            'fecha_emision' => '2026-09-18', 'monto' => '123.74',
        ]);
    }

    private function albaran(int $numero = 5131, string $oc = self::OC): PpqAlbaran
    {
        return PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.$numero, 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17', 'monto_albaran' => '123.74',
            'numero_orden_compra' => $oc, 'sala_codigo' => '0017',
        ]);
    }

    private function asignar(CobroDocumento $doc, PpqAlbaran $albaran): void
    {
        $doc->update(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado]);
    }

    public function test_bandeja_excluye_retirable_de_documentos_meses_etapas_y_contadores(): void
    {
        $invalidado = $this->documento(226, true);
        $invalidado->update(['fecha_emision' => '2026-08-18']);
        $vigente = $this->documento(231);
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value));

        $respuesta = $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();
        $respuesta->assertDontSee($invalidado->numero_control)->assertSee($vigente->numero_control);
        $this->assertSame([$vigente->id], $respuesta->viewData('documentos')->pluck('id')->all());
        $this->assertSame(['' => 1, 'no_entregados' => 1, 'listos' => 0, 'presentados' => 0, 'pagados' => 0, 'diferencias' => 0], $respuesta->viewData('etapas'));
        $this->assertSame(1, $respuesta->viewData('contadores')['total']);
        $this->assertSame(1, $respuesta->viewData('contadores')['sin_albaran']);
        $this->assertSame(['2026-09'], array_column($respuesta->viewData('meses'), 'mes'));
    }

    public function test_invalidado_en_ppq_o_con_pago_sigue_visible_sin_poder_marcarse(): void
    {
        $enPpq = $this->documento(227, true);
        $this->asignar($enPpq, $this->albaran());
        $enPpq->update(['presentacion_estado' => EstadoPresentacionCobro::Preparada]);
        $pagado = $this->documento(226, true);
        $pagado->forceFill(['pago_estado' => EstadoPagoCobro::Parcial, 'monto_pagado' => '10.00'])->save();
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value));

        $respuesta = $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();
        $respuesta->assertSee($enPpq->numero_control)->assertSee($pagado->numero_control)
            ->assertSee('Invalidado')->assertSee('Estaba en PPQ o presentado: revíselo')
            ->assertSee('Tiene pago registrado: revíselo')->assertSee('0 listo(s) en esta página')
            ->assertDontSee('id="doc_'.$enPpq->id.'"', false)->assertDontSee('id="doc_'.$pagado->id.'"', false);
        $this->get(route('cobros.documentos.show', $enPpq))->assertOk()
            ->assertSee('Este CCF fue invalidado en Hacienda. No se presenta ni recibe albarán.');
    }

    public function test_reemitido_vincula_por_oc_sin_competir_con_invalidado(): void
    {
        $invalidado = $this->documento(226, true);
        $vigente = $this->documento(231);
        $albaran = $this->albaran();
        $vinculador = app(VinculadorAlbaranes::class);

        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $vinculador->aplicar($vigente));
        $this->assertSame($albaran->id, $vigente->refresh()->ppq_albaran_id);
        $auditoria = $vinculador->auditar($invalidado);
        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $auditoria['estado']);
        $this->assertSame('El CCF fue invalidado en Hacienda; no se le vincula albarán.', $auditoria['motivo']);
        $this->assertNull($auditoria['albaran_id']);
    }

    public function test_otro_competidor_vigente_y_albaran_tomado_siguen_bloqueando(): void
    {
        $invalidado = $this->documento(226, true);
        $vigente = $this->documento(231);
        $albaran = $this->albaran();
        $vinculador = app(VinculadorAlbaranes::class);
        $this->asignar($invalidado, $albaran);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $vinculador->auditar($vigente)['estado']);

        // El vínculo explícito también respeta que el albarán siga ocupado.
        $albaran->update(['dte_id' => $vigente->dte_id]);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $vinculador->auditar($vigente)['estado']);
        $invalidado->update(['ppq_albaran_id' => null]);
        $this->documento(232);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $vinculador->auditar($vigente)['estado']);
    }

    public function test_sincronizar_libera_y_reasigna_en_la_misma_corrida_sin_tocar_en_curso_ni_pagados(): void
    {
        $invalidado = $this->documento(226, true);
        $vigente = $this->documento(231);
        $albaran = $this->albaran();
        $this->asignar($invalidado, $albaran);
        $protegidos = [];
        foreach ([EstadoPresentacionCobro::Preparada, EstadoPresentacionCobro::Presentada, EstadoPresentacionCobro::Recibida] as $i => $estado) {
            $doc = $this->documento(300 + $i, true, '2609001700400'.$i);
            $this->asignar($doc, $this->albaran(6000 + $i, '2609001700400'.$i));
            $doc->update(['presentacion_estado' => $estado]);
            $protegidos[] = $doc;
        }
        $pagado = $this->documento(400, true, '26090017005000');
        $this->asignar($pagado, $this->albaran(7000, '26090017005000'));
        $pagado->forceFill(['pago_estado' => EstadoPagoCobro::Parcial, 'monto_pagado' => '10.00'])->save();
        $protegidos[] = $pagado;
        $antes = array_map(fn ($doc) => $doc->refresh()->getRawOriginal(), $protegidos);
        config(['cobros.alta.automatica' => true, 'cobros.vinculacion.automatica' => true]);

        $opciones = ['--cliente' => $this->cliente->id, '--vincular' => true, '--aplicar' => true];
        $this->artisan('cobros:sincronizar', $opciones)
            ->expectsOutputToContain('Albaranes liberados de CCF invalidados: 1.')->assertExitCode(0);
        $this->assertNull($invalidado->refresh()->ppq_albaran_id);
        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $invalidado->vinculacion_estado);
        $this->assertSame('Albarán liberado: el CCF fue invalidado en Hacienda.', $invalidado->vinculacion_motivo);
        $this->assertNotNull($invalidado->vinculado_en);
        $this->assertSame($albaran->id, $vigente->refresh()->ppq_albaran_id);
        foreach ($protegidos as $i => $doc) {
            $this->assertSame($antes[$i], $doc->refresh()->getRawOriginal());
        }
        $actividad = Activity::where('log_name', 'cobros_vinculacion')->where('subject_id', $invalidado->id)->sole();
        $this->assertSame($albaran->id, $actividad->properties['albaran_id']);
        $this->artisan('cobros:sincronizar', $opciones)
            ->expectsOutputToContain('Albaranes liberados de CCF invalidados: 0.')->assertExitCode(0);
        $this->assertSame(1, Activity::where('log_name', 'cobros_vinculacion')->where('subject_id', $invalidado->id)->count());
    }

    public function test_modo_seco_y_automatica_apagada_no_liberan(): void
    {
        $invalidado = $this->documento(226, true);
        $this->asignar($invalidado, $this->albaran());
        $antes = $invalidado->refresh()->getRawOriginal();
        config(['cobros.alta.automatica' => true, 'cobros.vinculacion.automatica' => true]);
        $opciones = ['--cliente' => $this->cliente->id, '--vincular' => true];
        $this->artisan('cobros:sincronizar', $opciones)
            ->expectsOutputToContain('1 se liberarían')->assertExitCode(0);
        $this->assertSame($antes, $invalidado->refresh()->getRawOriginal());
        config(['cobros.vinculacion.automatica' => false]);
        $this->artisan('cobros:sincronizar', $opciones + ['--aplicar' => true])->assertExitCode(0);
        $this->assertSame($antes, $invalidado->refresh()->getRawOriginal());
        $this->assertSame(0, Activity::where('log_name', 'cobros_vinculacion')->count());
    }

    public function test_ppq_y_solicitud_rechazan_invalidados_con_sus_correlativos(): void
    {
        $a = $this->documento(226, true);
        $b = $this->documento(227, true);
        foreach ([
            fn () => app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]),
            fn () => app(SolicitudCobroService::class)->previsualizar($this->cliente, [$a->id, $b->id]),
            fn () => app(SolicitudCobroService::class)->crear($this->cliente, [$a->id, $b->id]),
        ] as $accion) {
            try {
                $accion();
                $this->fail('Debió rechazar los CCF invalidados.');
            } catch (ValidationException $e) {
                $mensaje = implode(' ', $e->errors()['documentos']);
                $this->assertStringContainsString('invalidado', $mensaje);
                $this->assertStringContainsString('226', $mensaje);
                $this->assertStringContainsString('227', $mensaje);
            }
        }
        $this->assertDatabaseCount('ppq_lotes', 0);
        $this->assertDatabaseCount('cobro_solicitudes', 0);
    }

    public function test_vincular_a_mano_rechaza_invalidado(): void
    {
        $doc = $this->documento(226, true);
        try {
            app(VinculadorAlbaranes::class)->vincularAMano($doc, $this->albaran(), User::factory()->create(), 'Entrega verificada');
            $this->fail('Debió rechazar el CCF invalidado.');
        } catch (ValidationException $e) {
            $this->assertSame(['El CCF fue invalidado en Hacienda: vincule el albarán al CCF que lo reemplazó.'], $e->errors()['ppq_albaran_id']);
        }
        $this->assertNull($doc->refresh()->ppq_albaran_id);
    }

    public function test_auditoria_de_bandeja_no_audita_ni_aplica_invalidados_aunque_esten_en_ppq(): void
    {
        $retirable = $this->documento(226, true);
        $enPpq = $this->documento(227, true);
        $enPpq->update(['presentacion_estado' => EstadoPresentacionCobro::Preparada]);
        $vigente = $this->documento(231);
        $albaran = $this->albaran();
        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value));

        $respuesta = $this->get(route('cobros.vinculacion', $this->cliente))->assertOk();
        $this->assertSame([$vigente->id], collect($respuesta->viewData('auditoria')['detalle'])
            ->map(fn ($fila) => $fila['documento']->id)->all());
        $this->post(route('cobros.vinculacion.aplicar', $this->cliente), ['aplicar' => true])->assertRedirect();
        $this->assertSame($albaran->id, $vigente->refresh()->ppq_albaran_id);
        foreach ([$retirable, $enPpq] as $doc) {
            $this->assertNull($doc->refresh()->vinculado_en);
            $this->assertNull($doc->vinculacion_motivo);
        }
    }

    public function test_documento_manual_no_se_considera_invalidado(): void
    {
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03', 'numero_control' => 'CCF-MANUAL-1', 'monto' => '123.74',
        ]);
        $this->assertFalse($doc->estaInvalidado());
        $this->assertFalse(CobroDocumento::invalidados()->whereKey($doc->id)->exists());
        $this->assertTrue(CobroDocumento::sinInvalidadosRetirables()->whereKey($doc->id)->exists());
    }
}
