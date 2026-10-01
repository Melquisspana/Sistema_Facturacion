<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteInvalidacionMockService;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\PoliticaInvalidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DEPENDENCIA FISCAL: un comprobante con una nota de crédito (o de débito) VALIDADA
 * VIGENTE en su contra NO se puede invalidar. Primero se invalidan esas notas.
 *
 * ── Por qué cambiaron las expectativas de este archivo ────────────────────────
 * La versión anterior trataba la nota relacionada como una CONFIRMACIÓN REFORZADA: un
 * candado más que `--confirmo-nc-relacionada` (o su casilla en la web) abría, «asumiendo
 * el riesgo de una posible doble corrección fiscal». El Manual Funcional del Sistema de
 * Transmisión v2.0 (págs. impresas 13-16) no plantea un riesgo asumible: PROHÍBE la
 * invalidación mientras la nota siga vigente. Una confirmación humana no sustituye una
 * regla fiscal, así que:
 *
 *   · `test_confirmo_nc_relacionada_permite_pasar_el_candado` y
 *     `test_real_transmite_con_nc_relacionada_confirmada_explicitamente` desaparecen:
 *     afirmaban justamente lo que ahora está prohibido. En su lugar hay pruebas de que
 *     el viejo flag —por HTTP, por consola y por llamada directa— NO abre nada.
 *   · `test_mock_persiste_con_nc_relacionada_confirmada` pasa a comprobar lo contrario:
 *     el mock aplica la MISMA regla, sin red.
 *
 * ── Qué cuenta como «nota vigente» ────────────────────────────────────────────
 * Aceptada REALMENTE por Hacienda (sello real, no MOCK, con fecha de procesamiento) y no
 * invalidada. Por eso se cubren los seis estados del encargo: borrador, rechazada,
 * aceptación MOCK, aceptada real, invalidada y aceptada real ARCHIVADA. Archivar es
 * organización interna, no una invalidación: una nota archivada sigue bloqueando.
 *
 * ── A QUÉ TIPO documental aplica la prohibición (revisión R1 de Codex) ────────
 * Solo al CCF. La primera versión de este cambio bloqueaba cualquier documento que
 * tuviera una nota vigente relacionada, razonando que era «lo conservador». Codex lo
 * rechazó con razón: que el modelo PUEDA representar una nota contra una FE, una FEX o
 * una NC no convierte esa relación en una prohibición del MH, y la fuente revisada
 * enuncia la regla para el comprobante de crédito fiscal. Inventar restricciones fiscales
 * es el mismo error —en espejo— que inventar permisos.
 *
 * La decisión vive en {@see PoliticaInvalidacion::dependeDeNotasVigentes()}; el modelo
 * sigue pudiendo LISTAR notas vigentes de cualquier documento, porque listar y prohibir
 * son cosas distintas.
 */
class DteInvalidacionNcRelacionadaTest extends TestCase
{
    use RefreshDatabase;

    private const CCF_CODIGO_GENERACION = '00000000-0000-4000-8000-000000000010';

    private const CCF_SELLO = '2026000000000000000000000000000000000003'; // 40 chars

    private const CCF_NUMERO_CONTROL = 'DTE-03-M001P002-000000000000001';

    /**
     * Correlativo de los documentos que crea este archivo. Antes el número de control de
     * las notas salía de `random_int(1, 9)` y chocaba con el UNIQUE
     * (`ambiente`, `numero_control`) en cuanto dos documentos del mismo tipo caían en el
     * mismo dígito — un rojo que aparecía o no según la tirada. Un contador no tira dados.
     */
    private int $secuencia = 100;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        // Nada protegido por defecto en este archivo (se prueba aparte en
        // DteInvalidacionProteccionEvidenciaTest); evita que el .env real de la
        // máquina (que en APITEST sí protege #139/#140/#142/#143) contamine el test.
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '06140000000901');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06145555551015']);
        // Credenciales FICTICIAS de apitest: este test mockea toda la red, pero el
        // servicio de autenticación aborta antes de llegar a ella si no hay
        // ninguna. Ver Tests\TestCase::credencialesApitestFicticias().
        $this->credencialesApitestFicticias();
    }

    private function fakeHttp(): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-X', 'descripcionMsg' => 'ok', 'fhProcesamiento' => '01/07/2026 10:00:00'], 200),
        ]);
    }

    /**
     * Documento ACEPTADO REALMENTE del tipo indicado, sobre el mismo emisor. Sirve para
     * comprobar que la dependencia por notas NO se hereda entre tipos documentales.
     */
    private function documentoAceptado(TipoDte $tipo): Dte
    {
        $ccf = $this->ccfAceptado();

        if ($tipo === TipoDte::CreditoFiscal) {
            return $ccf;
        }

        return Dte::create([
            'tipo_dte' => $tipo->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $ccf->establecimiento_id,
            'punto_venta_id' => $ccf->punto_venta_id,
            'cliente_id' => $ccf->cliente_id,
            'numero_control' => 'DTE-'.$tipo->value.'-M001P002-'.str_pad((string) ++$this->secuencia, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => '2026000000000000000000000000000000000005',
            'respuesta_mh' => ['estado' => 'PROCESADO'],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    private function ccfAceptado(): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Titular de Ejemplo', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '06140000000901', 'nrc' => '1000017', 'telefono' => '22220000',
            'correo' => 'facturacion@example.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-555555-101-5',
            'telefono' => '22220001', 'correo' => 'responsable@example.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => self::CCF_NUMERO_CONTROL,
            'codigo_generacion' => self::CCF_CODIGO_GENERACION,
            'sello_recepcion' => self::CCF_SELLO,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => self::CCF_SELLO],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    /**
     * Nota emitida contra el CCF, referenciándolo por `dte_relacionado_id`. Los fixtures
     * se definen EXPLÍCITAMENTE (estado, sello, fecha de procesamiento, archivado) para
     * poder ejercitar la regla completa sin red y sin ambigüedad.
     */
    private function crearNota(
        Dte $original,
        EstadoDte $estado = EstadoDte::Aceptado,
        ?string $sello = self::CCF_SELLO,
        bool $conFechaMh = true,
        bool $archivada = false,
        TipoDte $tipo = TipoDte::NotaCredito,
    ): Dte {
        return Dte::create([
            'tipo_dte' => $tipo->value,
            'estado' => $estado->value,
            'ambiente' => '00',
            'establecimiento_id' => $original->establecimiento_id, 'punto_venta_id' => $original->punto_venta_id, 'cliente_id' => $original->cliente_id,
            'dte_relacionado_id' => $original->id,
            'numero_control' => 'DTE-'.$tipo->value.'-M001P002-'.str_pad((string) ++$this->secuencia, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => $sello,
            'fecha_procesamiento_mh' => $conFechaMh && $sello !== null ? '2026-07-20 23:10:00' : null,
            'archivado' => $archivada,
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:40:00',
        ]);
    }

    private function evento(): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: TipoAnulacionMh::RescindirOperacion,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06145555551015',
        );
    }

    // ---------- Qué cuenta como nota fiscalmente vigente ----------

    public function test_dte_sin_notas_no_esta_bloqueado(): void
    {
        $ccf = $this->ccfAceptado();

        $this->assertFalse($ccf->tieneNotaFiscalVigente());
        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true);
        $this->assertFalse($c['bloqueado'], implode(' | ', $c['razones']));
    }

    /**
     * Los seis fixtures del encargo, en una sola tabla: qué estado de la nota bloquea y
     * cuál no. La expectativa NO es «lo que hace el código», es la regla: solo bloquea
     * una nota ACEPTADA REALMENTE por Hacienda y todavía activa.
     *
     * @return array<string, array{0: EstadoDte, 1: ?string, 2: bool, 3: bool, 4: bool}>
     */
    public static function fixturesDeNota(): array
    {
        return [
            // estado, sello, fecha MH, archivada, ¿bloquea?
            'borrador' => [EstadoDte::Borrador, null, false, false, false],
            'rechazada' => [EstadoDte::Rechazado, null, false, false, false],
            'aceptada MOCK' => [EstadoDte::Aceptado, 'MOCK-SIMULADO-ABCDEF0123456789', false, false, false],
            'aceptada real' => [EstadoDte::Aceptado, self::CCF_SELLO, true, false, true],
            'invalidada' => [EstadoDte::Invalidado, self::CCF_SELLO, true, false, false],
            'aceptada real ARCHIVADA' => [EstadoDte::Aceptado, self::CCF_SELLO, true, true, true],
        ];
    }

    #[DataProvider('fixturesDeNota')]
    public function test_solo_una_nota_aceptada_real_y_vigente_bloquea(
        EstadoDte $estado,
        ?string $sello,
        bool $conFechaMh,
        bool $archivada,
        bool $bloquea,
    ): void {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf, $estado, $sello, $conFechaMh, $archivada);

        $this->assertSame($bloquea, $ccf->tieneNotaFiscalVigente());

        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true);
        $this->assertSame($bloquea, $c['bloqueado'], implode(' | ', $c['razones']));

        if ($bloquea) {
            $this->assertStringContainsString('nota de crédito vigente', implode(' ', $c['razones']));
        }
    }

    /**
     * El modelo puede representar una ND (06) contra un CCF aunque el sistema todavía no
     * tenga flujo de emisión de notas de débito. Si esa fila existe y está aceptada, el
     * candado la respeta igual: se protege lo que la relación PUEDE expresar.
     */
    public function test_una_nota_de_debito_aceptada_tambien_bloquea(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf, tipo: TipoDte::NotaDebito);

        $this->assertTrue($ccf->tieneNotaFiscalVigente());
        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf, $this->evento(), true, true);
        $this->assertTrue($c['bloqueado']);
    }

    // ---------- El viejo flag no abre nada, por ninguna vía ----------

    public function test_real_bloquea_transmision_con_nota_vigente(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf);
        $this->fakeHttp();

        $this->expectException(DteInvalidacionException::class);
        try {
            app(DteInvalidacionService::class)->transmitir($ccf, $this->evento(), true, true);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * REGRESIÓN del cambio: el flag obsoleto ya no existe en la firma del servicio, así
     * que ni siquiera se puede pasar por llamada directa. Antes, este mismo escenario
     * TRANSMITÍA. Se comprueba además que no hubo firma, ni token, ni POST a anulardte.
     */
    public function test_el_flag_obsoleto_ya_no_existe_en_la_llamada_directa(): void
    {
        $this->assertFalse(
            collect((new \ReflectionMethod(DteInvalidacionService::class, 'transmitir'))->getParameters())
                ->contains(fn (\ReflectionParameter $p) => $p->getName() === 'confirmoNcRelacionada'),
            'transmitir() no debe admitir ninguna confirmación de NC relacionada.'
        );
        $this->assertFalse(
            collect((new \ReflectionMethod(DteInvalidacionService::class, 'evaluarCandados'))->getParameters())
                ->contains(fn (\ReflectionParameter $p) => $p->getName() === 'confirmoNcRelacionada'),
            'evaluarCandados() no debe admitir ninguna confirmación de NC relacionada.'
        );
    }

    /** El POST web con el flag viejo sigue bloqueado, y no sale ni una petición. */
    public function test_post_web_con_el_flag_viejo_sigue_bloqueado(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf);
        $this->fakeHttp();

        $usuario = User::factory()->create()->assignRole('administrador');

        $this->actingAs($usuario)
            ->post(route('facturacion.invalidacion.transmitir', $ccf), [
                'tipo' => TipoAnulacionMh::RescindirOperacion->value,
                'confirmacion_invalidacion' => 'INVALIDAR DTE',
                'confirmar_nc_relacionada' => '1',
            ])
            ->assertRedirect(route('facturacion.show', $ccf))
            ->assertSessionHas('error');

        $ccf->refresh();
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $this->assertNull($ccf->sello_invalidacion);
        Http::assertNothingSent();
    }

    /** La consola: el flag se acepta pero se anuncia obsoleto y no abre el candado. */
    public function test_consola_real_con_el_flag_viejo_sigue_bloqueada(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf);
        $this->fakeHttp();

        $this->artisan('dte:invalidacion-real', [
            'dte' => $ccf->id,
            '--tipo' => TipoAnulacionMh::RescindirOperacion->value,
            '--transmitir-real' => true,
            '--confirmo-invalidar' => true,
            '--confirmo-nc-relacionada' => true,
        ])
            ->expectsOutputToContain('OBSOLETA')
            ->assertFailed();

        $ccf->refresh();
        $this->assertNull($ccf->sello_invalidacion);
        Http::assertNothingSent();
    }

    // ---------- Mock: misma regla, sin red ----------

    public function test_mock_bloquea_con_nota_vigente(): void
    {
        $ccf = $this->ccfAceptado();
        $this->crearNota($ccf);

        $this->expectException(DteInvalidacionException::class);
        app(DteInvalidacionMockService::class)->firmarMock($ccf, $this->evento(), persistir: true, permitirSinMock: true);
    }

    /**
     * CAMBIO DE EXPECTATIVA: antes se llamaba `test_mock_persiste_con_nc_relacionada_confirmada`
     * y afirmaba que la confirmación dejaba persistir. Ahora la firma del mock ya no
     * admite esa confirmación y la regla es la misma que en la transmisión real.
     */
    public function test_el_mock_no_admite_ninguna_confirmacion_de_nota(): void
    {
        $this->assertFalse(
            collect((new \ReflectionMethod(DteInvalidacionMockService::class, 'firmarMock'))->getParameters())
                ->contains(fn (\ReflectionParameter $p) => $p->getName() === 'permitirNcRelacionada'),
            'firmarMock() no debe admitir ninguna confirmación de NC relacionada.'
        );
    }

    // ---------- R1: la prohibición es del CCF, no de cualquier relación ----------

    /**
     * FE, FEX y NC NO heredan la prohibición solo por tener una nota vigente relacionada.
     * El fixture es deliberadamente el MISMO que bloquea a un CCF —nota tipo 05 aceptada
     * realmente por el MH, colgada por `dte_relacionado_id`—: lo único que cambia es el
     * tipo del documento que se invalida.
     *
     * Esto NO habilita clases nuevas de notas ni afirma que esos documentos no tengan
     * otras dependencias: para FE/FEX el manual contempla eventos de RETORNO, que no están
     * implementados y tendrán su propia política.
     *
     * @return array<string, array{0: string}>
     */
    public static function tiposQueNoDependenDeNotas(): array
    {
        return [
            'FE 01' => [TipoDte::Factura->value],
            'NC 05' => [TipoDte::NotaCredito->value],
            'FEX 11' => [TipoDte::FacturaExportacion->value],
        ];
    }

    #[DataProvider('tiposQueNoDependenDeNotas')]
    public function test_los_demas_tipos_no_heredan_la_prohibicion_del_ccf(string $tipoDte): void
    {
        $tipo = TipoDte::from($tipoDte);
        $documento = $this->documentoAceptado($tipo);
        $this->crearNota($documento);

        // La relación EXISTE y se puede listar…
        $this->assertTrue($documento->tieneNotaFiscalVigente());
        $this->assertCount(1, app(ValidadorReglasInvalidacion::class)->notasVigentes($documento));

        // …pero no bloquea: este tipo no depende de esas notas.
        $this->assertFalse(PoliticaInvalidacion::dependeDeNotasVigentes($tipo));
        $this->assertCount(0, app(ValidadorReglasInvalidacion::class)->notasQueBloquean($documento));

        $c = app(DteInvalidacionService::class)->evaluarCandados($documento, $this->evento(), true, true);
        $this->assertFalse($c['bloqueado'], implode(' | ', $c['razones']));
        $this->assertStringNotContainsString('nota de crédito vigente', implode(' ', $c['razones']));
    }

    /** Y el mock, que comparte reglas con el envío real, tampoco los bloquea. */
    #[DataProvider('tiposQueNoDependenDeNotas')]
    public function test_el_mock_tampoco_bloquea_a_los_demas_tipos(string $tipoDte): void
    {
        $documento = $this->documentoAceptado(TipoDte::from($tipoDte));
        $this->crearNota($documento);

        $r = app(DteInvalidacionMockService::class)->firmarMock(
            $documento, $this->evento(), persistir: true, permitirSinMock: true
        );

        $this->assertTrue($r['persistido']);
    }

    /** El CCF sí depende, y sigue bloqueado: R1 no reabrió nada. */
    public function test_el_ccf_sigue_siendo_el_unico_tipo_que_depende_de_las_notas(): void
    {
        $this->assertTrue(PoliticaInvalidacion::dependeDeNotasVigentes(TipoDte::CreditoFiscal));

        foreach ([TipoDte::Factura, TipoDte::NotaCredito, TipoDte::NotaDebito, TipoDte::FacturaExportacion] as $tipo) {
            $this->assertFalse(
                PoliticaInvalidacion::dependeDeNotasVigentes($tipo),
                'Solo el CCF depende de las notas vigentes; '.$tipo->value.' no.'
            );
        }

        $this->assertFalse(PoliticaInvalidacion::dependeDeNotasVigentes(null));
    }

    /**
     * La consola refleja la MISMA decisión: el preflight de una FEX con nota vigente no
     * reporta el bloqueo que sí reporta para un CCF.
     */
    public function test_el_preflight_de_consola_no_bloquea_a_un_tipo_que_no_depende(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $fex = $this->documentoAceptado(TipoDte::FacturaExportacion);
        $this->crearNota($fex);

        $this->artisan('dte:invalidacion-preflight', [
            'dte' => $fex->id,
            '--tipo' => TipoAnulacionMh::RescindirOperacion->value,
        ])->expectsOutputToContain('ninguna nota vigente bloquea');
    }

    /** Invalidada la nota, el comprobante vuelve a ser invalidable. */
    public function test_invalidada_la_nota_el_comprobante_se_desbloquea(): void
    {
        $ccf = $this->ccfAceptado();
        $nota = $this->crearNota($ccf);

        $this->assertTrue($ccf->tieneNotaFiscalVigente());

        $nota->estado = EstadoDte::Invalidado;
        $nota->save();

        $this->assertFalse($ccf->fresh()->tieneNotaFiscalVigente());
        $c = app(DteInvalidacionService::class)->evaluarCandados($ccf->fresh(), $this->evento(), true, true);
        $this->assertFalse($c['bloqueado'], implode(' | ', $c['razones']));
    }
}
