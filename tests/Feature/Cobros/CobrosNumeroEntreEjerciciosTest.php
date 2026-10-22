<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Cobros\ImportadorEnviadosService;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Cobros\ReporteCasoCalleja;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\GmailClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * #121 · Desde el 01/01/2027 el correlativo del número de control vuelve a 1 (#42), así
 * que el MISMO número puede pertenecer a un CCF de 2026 y a otro de 2027. El seguimiento
 * los identifica por número + ejercicio, y los archivos de Calleja —que solo traen el
 * número— se resuelven por la fecha del documento, sin vincular jamás uno de otro año.
 */
class CobrosNumeroEntreEjerciciosTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private const NUMERO = 'DTE-03-M001P002-000000000000015';

    private const NUMERO_TXT = 'DTE03M001P002000000000000015';

    private const NIT = '0614-010101-101-1';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Carbon::setTestNow('2027-02-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ apoyo

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => self::NIT]);

        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    private function externo(Cliente $cliente, ?string $fecha, string $monto = '100.00', array $extra = []): CobroDocumento
    {
        return CobroDocumento::create($extra + [
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => self::NUMERO,
            'fecha_emision' => $fecha,
            'monto' => $monto,
        ]);
    }

    /** @param  array<int, string>  $lineas  «FECHA;VALOR» por línea */
    private function txt(Cliente $cliente, array $lineas, string $nombre = 'pagos.txt'): array
    {
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n";
        foreach ($lineas as $linea) {
            $txt .= '000123;TITULAR DE EJEMPLO;CF;'.self::NUMERO_TXT.";{$linea}\n";
        }

        return app(AplicadorPagosTxt::class)->aplicar(
            $cliente,
            app(ConciliacionTxtParser::class)->parse($txt),
            ArchivoConciliacion::desdeContenido($txt, $nombre),
        );
    }

    private function pagos(CobroDocumento $documento): int
    {
        return CobroEvento::where('cobro_documento_id', $documento->id)->where('tipo', TipoEventoCobro::Pago->value)->count();
    }

    // ------------------------------------------------------------------ identidad

    public function test_el_ejercicio_se_deriva_de_la_fecha_de_emision(): void
    {
        $cliente = $this->cliente();

        $this->assertSame(2026, $this->externo($cliente, '2025-11-03')->ejercicio_control, 'Lo anterior al reinicio comparte 2026.');
        $this->assertSame(2027, $this->externo($cliente, '2027-01-15')->ejercicio_control);
    }

    public function test_el_mismo_numero_cabe_una_vez_por_ejercicio(): void
    {
        $cliente = $this->cliente();
        $this->externo($cliente, '2026-12-20');
        $this->externo($cliente, '2027-01-15');

        $this->expectException(QueryException::class);
        $this->externo($cliente, '2027-01-20');
    }

    // ------------------------------------------------------------------ alta

    public function test_el_alta_no_adopta_el_externo_de_otro_ejercicio(): void
    {
        $cliente = $this->cliente();
        $viejo = $this->externo($cliente, '2026-12-20', '80.00');
        $dte = $this->dteVerificableCobros($cliente, self::NUMERO, '2027-01-15', '100.00');

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertSame(1, $resumen['creados']);
        $this->assertSame(0, $resumen['adoptados']);
        $this->assertNull($viejo->refresh()->dte_id, 'El de 2026 no se queda con el DTE de 2027.');
        $nuevo = CobroDocumento::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(2027, $nuevo->ejercicio_control);
        $this->assertSame('100.00', (string) $nuevo->monto);
    }

    public function test_el_alta_adopta_el_externo_del_mismo_ejercicio(): void
    {
        $cliente = $this->cliente();
        $externo = $this->externo($cliente, '2027-01-15');
        $dte = $this->dteVerificableCobros($cliente, self::NUMERO, '2027-01-15', '100.00');

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertSame(1, $resumen['adoptados']);
        $this->assertSame($dte->id, $externo->refresh()->dte_id);
    }

    public function test_el_alta_no_hereda_el_antecedente_ppq_del_numero_de_2026(): void
    {
        $cliente = $this->cliente();
        $lote = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ dic', 'fecha' => '2026-12-21', 'estado' => 'listo']);
        $lote->items()->create(['origen' => 'gmail', 'tipo_dte' => '03', 'numero_control' => self::NUMERO,
            'fecha_documento' => '2026-12-20', 'monto_dte' => 80, 'sin_albaran' => true]);
        $dte = $this->dteVerificableCobros($cliente, self::NUMERO, '2027-01-15', '100.00');

        app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertFalse(CobroDocumento::where('dte_id', $dte->id)->firstOrFail()->revisar_historico,
            'El renglón PPQ es del CCF de 2026, no de este.');
    }

    public function test_incorporar_el_mismo_numero_de_otro_ejercicio_se_permite(): void
    {
        $cliente = $this->cliente();
        $this->externo($cliente, '2026-12-20');

        $nuevo = app(AltaCobrosService::class)->incorporar($cliente, [
            'numero_control' => self::NUMERO, 'fecha_emision' => '2027-01-15', 'monto' => '50.00',
        ]);

        $this->assertSame(2027, $nuevo->ejercicio_control);
        $this->assertSame(2, CobroDocumento::count());
    }

    public function test_incorporar_el_mismo_numero_del_mismo_ejercicio_se_rechaza(): void
    {
        $cliente = $this->cliente();
        $this->externo($cliente, '2027-01-15');

        $this->expectException(ValidationException::class);
        app(AltaCobrosService::class)->incorporar($cliente, [
            'numero_control' => self::NUMERO, 'fecha_emision' => '2027-01-20', 'monto' => '50.00',
        ]);
    }

    public function test_incorporar_sin_fecha_un_numero_que_ya_existe_pide_la_fecha(): void
    {
        $cliente = $this->cliente();
        $this->externo($cliente, '2026-12-20');

        try {
            app(AltaCobrosService::class)->incorporar($cliente, ['numero_control' => self::NUMERO, 'monto' => '50.00']);
            $this->fail('Sin fecha no se sabe de qué año es.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fecha_emision', $e->errors());
        }
        $this->assertSame(1, CobroDocumento::count());
    }

    // ------------------------------------------------------------------ pago por TXT

    public function test_la_linea_paga_solo_el_documento_del_anio_de_su_fecha(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');
        $de2027 = $this->externo($cliente, '2027-01-15');

        $informe = $this->txt($cliente, ['15-ENE-27;100.00']);

        $this->assertCount(1, $informe['aplicados']);
        $this->assertSame(EstadoPagoCobro::Pagado, $de2027->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pendiente, $de2026->refresh()->pago_estado);
        $this->assertSame(0, $this->pagos($de2026));
    }

    public function test_el_mismo_numero_de_dos_anios_en_un_archivo_no_es_contradiccion(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20', '80.00');
        $de2027 = $this->externo($cliente, '2027-01-15');

        $informe = $this->txt($cliente, ['20-DIC-26;80.00', '15-ENE-27;100.00']);

        $this->assertCount(2, $informe['aplicados']);
        $this->assertSame('80.00', (string) $de2026->refresh()->monto_pagado);
        $this->assertSame('100.00', (string) $de2027->refresh()->monto_pagado);
    }

    public function test_una_fecha_anterior_a_2026_identifica_el_documento_historico(): void
    {
        $cliente = $this->cliente();
        $historico = $this->externo($cliente, '2025-11-03');

        $this->txt($cliente, ['03-NOV-25;100.00']);

        $this->assertSame(EstadoPagoCobro::Pagado, $historico->refresh()->pago_estado);
    }

    public function test_una_linea_de_2027_no_paga_el_documento_de_2026(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');

        $informe = $this->txt($cliente, ['15-ENE-27;100.00']);

        $this->assertCount(1, $informe['no_identificados']);
        $this->assertSame(0, $this->pagos($de2026));
        $this->assertSame(EstadoPagoCobro::Pendiente, $de2026->refresh()->pago_estado);
    }

    public function test_sin_fecha_y_con_dos_candidatos_va_a_revision_con_aviso(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');
        $de2027 = $this->externo($cliente, '2027-01-15');

        $informe = $this->txt($cliente, [';100.00']);

        $this->assertCount(1, $informe['ambiguos']);
        $this->assertEqualsCanonicalizing([$de2026->id, $de2027->id], collect($informe['ambiguos'][0]['candidatos'])->pluck('id')->all());
        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count(), 'No se aplica a ninguno.');
    }

    public function test_el_aviso_de_ambiguedad_se_ve_en_la_pantalla_de_pagos(): void
    {
        $cliente = $this->cliente();
        $this->externo($cliente, '2026-12-20');
        $this->externo($cliente, '2027-01-15');
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.self::NUMERO_TXT.";;100.00\n";

        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->post(route('cobros.pagos', $cliente), ['archivo' => UploadedFile::fake()->createWithContent('pagos.txt', $txt)])
            ->assertOk()
            ->assertSee('existe en más de un año')
            ->assertSee('2026')
            ->assertSee('2027');
    }

    public function test_sin_fecha_con_un_solo_candidato_pendiente_se_aplica_y_queda_anotado(): void
    {
        $cliente = $this->cliente();
        $documento = $this->externo($cliente, '2026-12-20');

        $informe = $this->txt($cliente, [';100.00']);

        $this->assertCount(1, $informe['aplicados']);
        $this->assertSame(EstadoPagoCobro::Pagado, $documento->refresh()->pago_estado);
        $evento = CobroEvento::where('cobro_documento_id', $documento->id)->where('tipo', TipoEventoCobro::Pago->value)->firstOrFail();
        $this->assertSame(EstadoEventoCobro::Aplicado, $evento->estado);
        $this->assertSame('sin_fecha_candidato_unico', $evento->datos['identificacion'] ?? null);
        $this->assertStringContainsString('identificado sin fecha, candidato único', (string) $evento->detalle);
    }

    public function test_sin_fecha_con_un_solo_candidato_ya_pagado_va_a_revision(): void
    {
        $cliente = $this->cliente();
        $documento = $this->externo($cliente, '2026-12-20');
        $this->txt($cliente, ['20-DIC-26;100.00'], 'remesa-1.txt');

        $informe = $this->txt($cliente, [';100.00'], 'remesa-2.txt');

        $this->assertCount(1, $informe['en_revision']);
        $evento = CobroEvento::where('cobro_documento_id', $documento->id)->where('evidencia_nombre', 'remesa-2.txt')->firstOrFail();
        $this->assertSame(EstadoEventoCobro::EnRevision, $evento->estado);
        $this->assertStringContainsString('sin fecha', (string) $evento->estado_motivo);
        $this->assertSame('100.00', (string) $documento->refresh()->monto_pagado, 'No suma dos veces.');
    }

    public function test_sin_fecha_con_un_solo_candidato_anulado_va_a_revision(): void
    {
        $cliente = $this->cliente();
        $dte = $this->dteVerificableCobros($cliente, self::NUMERO, '2026-12-20', '100.00');
        $dte->forceFill(['estado' => 'invalidado'])->save();
        $documento = $this->externo($cliente, '2026-12-20', '100.00', ['dte_id' => $dte->id, 'origen' => OrigenCobroDocumento::Dte->value]);

        $this->txt($cliente, [';100.00']);

        $evento = CobroEvento::where('cobro_documento_id', $documento->id)->where('tipo', TipoEventoCobro::Pago->value)->firstOrFail();
        $this->assertSame(EstadoEventoCobro::EnRevision, $evento->estado);
        $this->assertStringContainsString('sin fecha', (string) $evento->estado_motivo);
        $this->assertSame(EstadoPagoCobro::Pendiente, $documento->refresh()->pago_estado);
    }

    // ------------------------------------------------------------------ importación de enviados

    public function test_importar_un_enviado_de_2027_no_completa_el_documento_de_2026(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20', '80.00');
        $codigo = strtoupper(Str::uuid()->toString());
        $json = [
            'identificacion' => ['numeroControl' => self::NUMERO, 'codigoGeneracion' => $codigo, 'tipoDte' => '03', 'fecEmi' => '2027-01-15'],
            'resumen' => ['totalPagar' => 100.0],
            'receptor' => ['nit' => self::NIT, 'nombre' => 'Calleja'],
            'selloRecibido' => '2027SELLO'.$codigo,
        ];
        $this->app->instance(GmailClient::class, new GmailClientDeImportacion([['m1']], [
            'm1' => [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode($json)]],
        ]));

        $r = app(ImportadorEnviadosService::class)->importar($cliente, Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31'), 100, true);

        $this->assertSame([], $r->excepciones);
        $this->assertNull($de2026->refresh()->codigo_generacion, 'El de 2026 no recibe datos del de 2027.');
        $nuevo = CobroDocumento::where('codigo_generacion', $codigo)->firstOrFail();
        $this->assertSame(2027, $nuevo->ejercicio_control);
        $this->assertNotSame($de2026->id, $nuevo->id);
    }

    // ------------------------------------------------------------------ correos

    private function correoConNumero(Cliente $cliente, string $fecha): void
    {
        app(LectorCorreosCobro::class)->procesar($cliente, [[
            'id' => 'msg-'.uniqid(),
            'threadId' => 'thr-1',
            'asunto' => 'OBSERVACIONES REF 31001',
            'cuerpo' => 'El CCF '.self::NUMERO.' viene sin su nota de crédito.',
            'remitente' => 'fiscal@cliente-ejemplo.test',
            'fecha' => $fecha,
        ]]);
    }

    private function observaciones(CobroDocumento $documento): int
    {
        return CobroEvento::where('cobro_documento_id', $documento->id)->where('tipo', TipoEventoCobro::Observacion->value)->count();
    }

    public function test_un_correo_de_2026_con_solo_el_numero_no_toca_el_documento_de_2027(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');
        $de2027 = $this->externo($cliente, '2027-01-15');

        $this->correoConNumero($cliente, 'Mon, 28 Dec 2026 09:51:00 -0600');

        $this->assertSame(1, $this->observaciones($de2026));
        $this->assertSame(0, $this->observaciones($de2027));
    }

    public function test_un_correo_de_2027_con_solo_el_numero_y_dos_candidatos_queda_pendiente(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');
        $de2027 = $this->externo($cliente, '2027-01-15');

        $this->correoConNumero($cliente, 'Wed, 03 Feb 2027 09:51:00 -0600');

        $this->assertSame(0, $this->observaciones($de2026));
        $this->assertSame(0, $this->observaciones($de2027));
    }

    // ------------------------------------------------------------------ reporte del caso

    public function test_el_reporte_del_caso_recibe_solo_el_documento_del_lote(): void
    {
        $cliente = $this->cliente();
        $de2026 = $this->externo($cliente, '2026-12-20');
        $de2027 = $this->externo($cliente, '2027-01-15');
        $de2027->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Presentada->value])->save();

        $lote = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ feb', 'fecha' => '2027-02-01', 'estado' => 'borrador']);
        $lote->items()->create(['origen' => 'gmail', 'tipo_dte' => '03', 'numero_control' => self::NUMERO,
            'fecha_documento' => '2027-01-15', 'monto_dte' => 100, 'sin_albaran' => true]);

        $resultado = app(ReporteCasoCalleja::class)->aplicar($cliente, ['caso' => '12100', 'documentos' => [self::NUMERO_TXT => 'REGISTRADO']], null, $lote);

        $this->assertSame(1, $resultado['recibidos']);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $de2027->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $de2026->refresh()->presentacion_estado);
        $this->assertSame(1, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }
}
