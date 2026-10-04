<?php

namespace Tests\Feature\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteInvalidacionMockService;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\PoliticaInvalidacion;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MATRIZ OFICIAL de la invalidación (Manual Funcional del Sistema de Transmisión v2.0,
 * mayo 2026, páginas impresas 13-16), aplicada a los cuatro tipos habilitados:
 *
 * | Documento | 1 · Error                 | 2 · Rescindir | 3 · Otro                         |
 * |-----------|---------------------------|---------------|----------------------------------|
 * | FE  01    | sustituto ya aceptado     | null          | sustituto ya aceptado + motivo   |
 * | CCF 03    | sustituto ya aceptado     | null          | sustituto ya aceptado + motivo   |
 * | NC  05    | null (corregir después)   | null          | null + motivo                    |
 * | FEX 11    | sustituto ya aceptado     | null          | sustituto ya aceptado + motivo   |
 *
 * Qué prueba este archivo y qué NO:
 *
 *  · SÍ: las doce celdas, la verificación del sustituto, la PARIDAD entre el POST web,
 *    el preview/preflight de consola, el serializador, el mock y la transmisión real, y
 *    que ninguna denegación produzca efectos fiscales, archivos ni llamadas al firmador
 *    o a Hacienda.
 *  · SÍ: bloqueo por plazo en las vías de invalidación. Las fechas y los bordes del
 *    calendario se prueban en PlazoInvalidacionTest (decisión 0005).
 *  · NO: aceptación real del MH. Toda la red va con Http::fake; un test verde aquí NO
 *    acredita que Hacienda acepte el evento.
 *
 * La dependencia fiscal (notas de crédito/débito vigentes) tiene su propio archivo:
 * {@see DteInvalidacionNcRelacionadaTest}.
 */
class MatrizInvalidacionHaciendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_plazo_invalidacion_bloquea_todas_las_vias_sin_efectos(): void
    {
        $this->fakeHttp();
        config(['dte.invalidacion.dias_inhabiles' => ['2026' => ['2026-05-01']]]);
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);
        $ccf->forceFill(['fecha_procesamiento_mh' => '2026-04-25 23:30:00'])->saveQuietly();
        $evento = $this->evento(TipoAnulacionMh::RescindirOperacion);
        $this->travelTo(Carbon::parse('2026-05-15 23:59:59', 'America/El_Salvador'));
        $this->assertSame([], app(ValidadorReglasInvalidacion::class)->problemas($ccf, $evento));
        $this->assertFalse(app(DteInvalidacionService::class)->dryRun($ccf, $evento, true, true)['candados']['bloqueado']);
        $this->artisan('dte:invalidacion-preview', ['dte' => $ccf->id, '--tipo' => 2])->assertSuccessful();
        $this->actingAs($this->admin())->get(route('facturacion.show', $ccf))
            ->assertOk()->assertSee('15/05/2026')->assertSee('23:59:59 (hora de El Salvador)');

        $this->travelTo(Carbon::parse('2026-05-16 00:00:00', 'America/El_Salvador'));
        $this->assertStringContainsString('Fuera de plazo', implode(' ', app(ValidadorReglasInvalidacion::class)->problemas($ccf, $evento)));
        $this->actingAs($this->admin())->post(route('facturacion.invalidacion.transmitir', $ccf), [
            'tipo' => 2, 'confirmacion_invalidacion' => 'INVALIDAR DTE',
        ])->assertSessionHasErrors('confirmacion_invalidacion');

        foreach ([DteInvalidacionMockService::class, DteInvalidacionService::class, SerializadorInvalidacionMh::class] as $servicio) {
            try {
                match ($servicio) {
                    DteInvalidacionMockService::class => app($servicio)->firmarMock($ccf, $evento, persistir: true, permitirSinMock: true),
                    DteInvalidacionService::class => app($servicio)->transmitir($ccf, $evento, true, true),
                    default => app($servicio)->serializar($ccf, $evento),
                };
                $this->fail('Debió bloquear el plazo: '.$servicio);
            } catch (DteInvalidacionException|DteNoSerializableException $e) {
                $detalle = $e instanceof DteNoSerializableException ? implode(' ', $e->problemas) : $e->getMessage();
                $this->assertStringContainsString('Fuera de plazo', $detalle);
            }
        }
        Http::assertNothingSent();
        foreach (['dte:invalidacion-preview', 'dte:invalidacion-preflight'] as $comando) {
            $this->artisan($comando, ['dte' => $ccf->id, '--tipo' => 2])->assertFailed();
        }
        $this->artisan('dte:invalidacion-real', [
            'dte' => $ccf->id, '--tipo' => 2, '--transmitir-real' => true, '--confirmo-invalidar' => true,
        ])->assertFailed();
        Http::assertNothingSent();
        $ccf->refresh();
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $this->assertNull($ccf->sello_invalidacion);
        $this->assertNull($ccf->json_invalidacion_path);
        $this->assertNull($ccf->jws_invalidacion_path);
        $this->assertNull($ccf->codigo_generacion_invalidacion);
        $this->assertNull($ccf->respuesta_mh_invalidacion);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private const NIT_EMISOR = '06140000000901';

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private Cliente $cliente;

    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');

        // Entorno de apitest con TODOS los candados abiertos: lo que bloquee en esta
        // suite bloquea por la REGLA FISCAL, no por el modo seguro del entorno.
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06145555551015']);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', self::NIT_EMISOR);
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        $this->credencialesApitestFicticias();

        $empresa = Empresa::create([
            'razon_social' => 'Titular de Ejemplo', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => self::NIT_EMISOR, 'nrc' => '1000017', 'telefono' => '22220000',
            'correo' => 'facturacion@example.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $this->cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-555555-101-5',
            'telefono' => '22220001', 'correo' => 'responsable@example.com',
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    /** Documento ACEPTADO REALMENTE por el MH del tipo indicado. */
    private function aceptado(
        TipoDte $tipo,
        ?Establecimiento $estab = null,
        ?Cliente $cliente = null,
        string $ambiente = '00',
        bool $selloMock = false,
        EstadoDte $estado = EstadoDte::Aceptado,
    ): Dte {
        $estab ??= $this->estab;
        $this->secuencia++;

        $sello = $selloMock
            ? 'MOCK-SIMULADO-'.strtoupper(Str::random(16))
            : '2026'.strtoupper(Str::random(36));

        return Dte::create([
            'tipo_dte' => $tipo->value,
            'estado' => $estado->value,
            'ambiente' => $ambiente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $this->pv->id,
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'numero_control' => 'DTE-'.$tipo->value.'-M001P001-'.str_pad((string) $this->secuencia, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => $sello,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => $sello],
            'fecha_procesamiento_mh' => $selloMock ? null : HoraNegocio::ahora()->format('Y-m-d H:i:s'),
            'fecha_emision' => '2026-07-20',
            'hora_emision' => '22:26:52',
            'total_pagar' => 113.00,
        ]);
    }

    private function evento(TipoAnulacionMh $motivo, ?string $reemplazo = null, ?string $texto = null): EventoInvalidacionData
    {
        return new EventoInvalidacionData(
            tipoAnulacion: $motivo,
            nombreResponsable: 'Melqui Administrador', tipoDocResponsable: '13', numDocResponsable: '040000000',
            nombreSolicita: 'Calleja CxP', tipoDocSolicita: '36', numDocSolicita: '06145555551015',
            motivoAnulacion: $texto,
            codigoGeneracionReemplazo: $reemplazo,
        );
    }

    /** Red completa simulada: firmador, auth y anulardte. NADA sale de la máquina. */
    private function fakeHttp(): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL', 'descripcionMsg' => 'ok', 'fhProcesamiento' => '21/07/2026 10:00:00'], 200),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // ------------------------------------------------- 1. La matriz, celda por celda

    /**
     * Las doce celdas. `$requiereSustituto` y `$requiereTexto` son la EXPECTATIVA leída
     * del manual, no lo que devuelva el código.
     *
     * @return array<string, array{0: string, 1: int, 2: bool, 3: bool}>
     */
    public static function celdasDeLaMatriz(): array
    {
        $casos = [];

        foreach ([
            'FE' => TipoDte::Factura,
            'CCF' => TipoDte::CreditoFiscal,
            'NC' => TipoDte::NotaCredito,
            'FEX' => TipoDte::FacturaExportacion,
        ] as $etiqueta => $tipo) {
            foreach (TipoAnulacionMh::cases() as $motivo) {
                $sustituto = $tipo !== TipoDte::NotaCredito && $motivo !== TipoAnulacionMh::RescindirOperacion;
                $texto = $motivo === TipoAnulacionMh::Otro;

                $casos[$etiqueta.' motivo '.$motivo->value] = [$tipo->value, $motivo->value, $sustituto, $texto];
            }
        }

        return $casos;
    }

    #[DataProvider('celdasDeLaMatriz')]
    public function test_la_politica_declara_la_matriz_oficial(string $tipoDte, int $motivo, bool $requiereSustituto, bool $requiereTexto): void
    {
        $r = PoliticaInvalidacion::requisitos(TipoDte::from($tipoDte), TipoAnulacionMh::from($motivo));

        $this->assertTrue($r->soportado);
        $this->assertSame($requiereSustituto, $r->requiereReemplazo);
        $this->assertSame($requiereTexto, $r->requiereMotivoTexto);
        // La matriz no deja celdas «opcionales»: o se exige sustituto, o se exige null.
        $this->assertSame(! $requiereSustituto, $r->prohibeReemplazo());
    }

    /**
     * La misma matriz, ejercitada de punta a punta contra el serializador: el caso
     * VÁLIDO produce el JSON correcto y valida contra invalidacion-schema-v3.
     */
    #[DataProvider('celdasDeLaMatriz')]
    public function test_el_caso_valido_de_cada_celda_serializa_y_valida_contra_el_schema(
        string $tipoDte,
        int $motivo,
        bool $requiereSustituto,
        bool $requiereTexto,
    ): void {
        $tipo = TipoDte::from($tipoDte);
        $dte = $this->aceptado($tipo);
        $sustituto = $requiereSustituto ? $this->aceptado($tipo) : null;

        $evento = $this->evento(
            TipoAnulacionMh::from($motivo),
            $sustituto?->codigo_generacion,
            $requiereTexto ? 'Documento emitido por duplicidad.' : null,
        );

        $json = app(SerializadorInvalidacionMh::class)->serializar($dte, $evento);

        $this->assertSame($sustituto?->codigo_generacion, $json['documento']['codigoGeneracionR']);
        $this->assertSame($requiereTexto ? 'Documento emitido por duplicidad.' : null, $json['motivo']['motivoAnulacion']);
        $this->assertSame($motivo, $json['motivo']['tipoAnulacion']);
        $this->assertSame($tipoDte, $json['documento']['tipoDte']);

        $res = app(DteSchemaValidator::class)->validarInvalidacion($json);
        $this->assertTrue($res['valido'], 'Errores de schema: '.implode(' | ', $res['errores']));
    }

    /** Falta el sustituto donde la matriz lo exige → rechazado, sin JSON. */
    #[DataProvider('celdasDeLaMatriz')]
    public function test_la_ausencia_indebida_del_sustituto_se_rechaza(string $tipoDte, int $motivo, bool $requiereSustituto, bool $requiereTexto): void
    {
        if (! $requiereSustituto) {
            $this->assertTrue(true, 'Esta celda no exige sustituto; su ausencia es lo correcto.');

            return;
        }

        $dte = $this->aceptado(TipoDte::from($tipoDte));
        $evento = $this->evento(TipoAnulacionMh::from($motivo), null, $requiereTexto ? 'Duplicado.' : null);

        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $evento);
            $this->fail('Debió rechazar la falta del documento sustituto.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('sustituye al invalidado', implode(' ', $e->problemas));
        }
    }

    /** Sustituto presente donde la matriz exige null → rechazado, sin JSON. */
    #[DataProvider('celdasDeLaMatriz')]
    public function test_la_presencia_indebida_del_sustituto_se_rechaza(string $tipoDte, int $motivo, bool $requiereSustituto, bool $requiereTexto): void
    {
        if ($requiereSustituto) {
            $this->assertTrue(true, 'Esta celda exige sustituto; su presencia es lo correcto.');

            return;
        }

        $tipo = TipoDte::from($tipoDte);
        $dte = $this->aceptado($tipo);
        // Un sustituto IMPECABLE (mismo tipo, emisor, ambiente y aceptado de verdad):
        // se rechaza por la matriz, no por un defecto del documento.
        $sustituto = $this->aceptado($tipo);

        $evento = $this->evento(TipoAnulacionMh::from($motivo), $sustituto->codigo_generacion, $requiereTexto ? 'Duplicado.' : null);

        try {
            app(SerializadorInvalidacionMh::class)->serializar($dte, $evento);
            $this->fail('Debió rechazar el sustituto: esta celda exige codigoGeneracionR en null.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('no admite documento de reemplazo', implode(' ', $e->problemas));
        }
    }

    /** El motivo 3 exige texto en los cuatro tipos; sin él no se serializa. */
    #[DataProvider('celdasDeLaMatriz')]
    public function test_el_motivo_3_exige_texto_en_todos_los_tipos(string $tipoDte, int $motivo, bool $requiereSustituto, bool $requiereTexto): void
    {
        if (! $requiereTexto) {
            $this->assertTrue(true, 'Solo el motivo 3 exige texto.');

            return;
        }

        $tipo = TipoDte::from($tipoDte);
        $dte = $this->aceptado($tipo);
        $sustituto = $requiereSustituto ? $this->aceptado($tipo) : null;

        try {
            app(SerializadorInvalidacionMh::class)->serializar(
                $dte,
                $this->evento(TipoAnulacionMh::from($motivo), $sustituto?->codigo_generacion)
            );
            $this->fail('El motivo 3 exige motivo.motivoAnulacion en texto.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('texto', implode(' ', $e->problemas));
        }
    }

    // --------------------------------------- 2. Tipos sin regla: se dicen, no se asumen

    /**
     * La Nota de Débito 06 no tiene flujo de emisión y el manual no se cubrió para ella
     * en esta entrega. La política lo DICE en vez de adoptar la regla del CCF por defecto.
     */
    public function test_un_tipo_sin_regla_devuelve_explicacion_y_no_hereda_la_del_ccf(): void
    {
        foreach (TipoAnulacionMh::cases() as $motivo) {
            $r = PoliticaInvalidacion::requisitos(TipoDte::NotaDebito, $motivo);

            $this->assertFalse($r->soportado);
            $this->assertFalse($r->requiereReemplazo, 'Un tipo sin regla no puede heredar la exigencia del CCF.');
            $this->assertStringContainsString('06', (string) $r->razonNoSoportado);
            $this->assertStringContainsString('No se aplica por defecto la regla del CCF', (string) $r->razonNoSoportado);
        }

        $nd = $this->aceptado(TipoDte::NotaDebito);

        try {
            app(SerializadorInvalidacionMh::class)->serializar($nd, $this->evento(TipoAnulacionMh::RescindirOperacion));
            $this->fail('No se puede invalidar un tipo sin regla definida.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('No hay regla de invalidación definida', implode(' ', $e->problemas));
        }
    }

    // ------------------------------------------- 3. Verificación dura del sustituto

    /**
     * Cada forma de sustituto inválido, todas rechazadas ANTES de cualquier HTTP. Un UUID
     * bien formado no es prueba de aceptación: por eso el «inexistente» también cae.
     */
    public function test_sustitutos_invalidos_se_rechazan_antes_de_cualquier_llamada(): void
    {
        $this->fakeHttp();
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);

        $otraEmpresa = Empresa::create([
            'razon_social' => 'Otro Contribuyente, S.A. de C.V.', 'nombre_comercial' => 'Otro',
            'nit' => '06149901031031', 'nrc' => '999999', 'telefono' => '22000000',
            'correo' => 'otro@example.test', 'ambiente' => '00', 'activo' => true,
        ]);
        $estabAjeno = Establecimiento::create(['empresa_id' => $otraEmpresa->id, 'codigo' => 'M002', 'nombre' => 'Ajeno', 'activo' => true]);

        $casos = [
            'el mismo documento que se invalida' => [$ccf->codigo_generacion, 'no puede ser el mismo DTE'],
            'inexistente (UUID bien formado)' => ['00000000-0000-4000-8000-000000000015', 'no existe en este sistema'],
            'formato no oficial' => ['no-es-un-uuid', 'no tiene formato oficial'],
            'de otro tipo' => [$this->aceptado(TipoDte::Factura)->codigo_generacion, 'del mismo tipo'],
            'de otro emisor' => [$this->aceptado(TipoDte::CreditoFiscal, estab: $estabAjeno)->codigo_generacion, 'otro emisor'],
            'de otro ambiente' => [$this->aceptado(TipoDte::CreditoFiscal, ambiente: '01')->codigo_generacion, 'ambiente'],
            'aceptado MOCK' => [$this->aceptado(TipoDte::CreditoFiscal, selloMock: true)->codigo_generacion, 'no está aceptado realmente'],
            'invalidado' => [$this->aceptado(TipoDte::CreditoFiscal, estado: EstadoDte::Invalidado)->codigo_generacion, 'INVALIDADO'],
        ];

        foreach ($casos as $etiqueta => [$codigo, $fragmento]) {
            try {
                app(SerializadorInvalidacionMh::class)->serializar(
                    $ccf,
                    $this->evento(TipoAnulacionMh::ErrorInformacion, $codigo)
                );
                $this->fail("Debió rechazar el sustituto «{$etiqueta}».");
            } catch (DteNoSerializableException $e) {
                $this->assertStringContainsString($fragmento, implode(' ', $e->problemas), "Caso: {$etiqueta}");
            }
        }

        // Nada salió a la red en ninguno de los casos.
        Http::assertNothingSent();
    }

    /**
     * Un sustituto sin sello de recepción tampoco vale, aunque el estado diga «aceptado»:
     * sin sello no hay aceptación que Hacienda reconozca.
     */
    public function test_un_sustituto_sin_sello_se_rechaza(): void
    {
        $this->fakeHttp();
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);
        $sinSello = $this->aceptado(TipoDte::CreditoFiscal);
        $sinSello->forceFill(['sello_recepcion' => null, 'fecha_procesamiento_mh' => null])->saveQuietly();

        try {
            app(SerializadorInvalidacionMh::class)->serializar(
                $ccf,
                $this->evento(TipoAnulacionMh::ErrorInformacion, $sinSello->codigo_generacion)
            );
            $this->fail('Un sustituto sin sello no está aceptado por Hacienda.');
        } catch (DteNoSerializableException $e) {
            $this->assertStringContainsString('no está aceptado realmente', implode(' ', $e->problemas));
        }

        Http::assertNothingSent();
    }

    /**
     * CORRECCIÓN DEL RECEPTOR: un sustituto con OTRO cliente es válido. Es el caso típico
     * del motivo 1 (se facturó al receptor equivocado), y exigir el mismo `cliente_id`
     * bloquearía justo la operación que la norma contempla.
     */
    public function test_un_sustituto_con_otro_receptor_no_se_bloquea(): void
    {
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);
        $otroReceptor = Cliente::factory()->contribuyente()->create(['nombre' => 'Super Selectos, S.A. de C.V.']);
        $sustituto = $this->aceptado(TipoDte::CreditoFiscal, cliente: $otroReceptor);

        $this->assertNotSame($ccf->cliente_id, $sustituto->cliente_id);

        $json = app(SerializadorInvalidacionMh::class)->serializar(
            $ccf,
            $this->evento(TipoAnulacionMh::ErrorInformacion, $sustituto->codigo_generacion)
        );

        $this->assertSame($sustituto->codigo_generacion, $json['documento']['codigoGeneracionR']);
        // El bloque `documento` sigue describiendo al receptor del DTE INVALIDADO.
        $this->assertSame($ccf->cliente->nombre, $json['documento']['nombre']);
    }

    // ------------------------------------------------------------ 4. Paridad de vías

    /**
     * La MISMA celda inválida (CCF motivo 3 sin sustituto) se rechaza por las cinco vías:
     * POST web manipulado —sin pasar por el asistente—, preview de consola, preflight,
     * mock y transmisión real. Y en ninguna sale una petición HTTP.
     */
    public function test_paridad_de_rechazo_entre_web_consola_serializador_mock_y_real(): void
    {
        $this->fakeHttp();
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);
        $evento = $this->evento(TipoAnulacionMh::Otro, null, 'Duplicado.');

        // (a) POST web manipulado: sin el asistente, con la frase correcta y sin sustituto.
        $this->actingAs($this->admin())
            ->post(route('facturacion.invalidacion.transmitir', $ccf), [
                'tipo' => TipoAnulacionMh::Otro->value,
                'motivo' => 'Duplicado.',
                'confirmacion_invalidacion' => 'INVALIDAR DTE',
            ])
            ->assertSessionHasErrors('reemplazo');

        // (b) Serializador.
        try {
            app(SerializadorInvalidacionMh::class)->serializar($ccf, $evento);
            $this->fail('El serializador debió rechazarlo.');
        } catch (DteNoSerializableException) {
            $this->assertTrue(true);
        }

        // (c) Mock.
        try {
            app(DteInvalidacionMockService::class)->firmarMock($ccf, $evento, persistir: true, permitirSinMock: true);
            $this->fail('El mock debió rechazarlo.');
        } catch (DteInvalidacionException) {
            $this->assertTrue(true);
        }

        // (d) Transmisión real.
        try {
            app(DteInvalidacionService::class)->transmitir($ccf, $evento, true, true);
            $this->fail('La transmisión real debió rechazarlo.');
        } catch (DteInvalidacionException) {
            $this->assertTrue(true);
        }

        // (e) Consola: preview y preflight.
        $this->artisan('dte:invalidacion-preview', [
            'dte' => $ccf->id, '--tipo' => TipoAnulacionMh::Otro->value, '--motivo' => 'Duplicado.',
        ])->assertFailed();

        $this->artisan('dte:invalidacion-preflight', [
            'dte' => $ccf->id, '--tipo' => TipoAnulacionMh::Otro->value, '--motivo' => 'Duplicado.',
        ])->assertFailed();

        // Ninguna vía firmó, autenticó ni transmitió. El preflight sí hace un health-check
        // GET del firmador (diagnóstico de solo lectura, anterior a este cambio); lo que
        // no puede ocurrir es un POST: firmar, autenticar y anulardte son todos POST.
        Http::assertNotSent(fn ($peticion) => $peticion->method() === 'POST');

        // Y el documento siguió intacto: sin evento, sin archivos de envío.
        $ccf->refresh();
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $this->assertNull($ccf->sello_invalidacion);
        $this->assertNull($ccf->json_invalidacion_path);
        $this->assertNull($ccf->jws_invalidacion_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * La celda VÁLIDA, en cambio, llega igual por las cinco vías. Se comprueba en la
     * transmisión real con HTTP simulado: el evento se firma, se transmite y el documento
     * pasa a Invalidado, SIN que se toque la evidencia de recepción original.
     */
    public function test_paridad_de_aceptacion_y_conservacion_de_la_evidencia_original(): void
    {
        $this->fakeHttp();
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);
        $sustituto = $this->aceptado(TipoDte::CreditoFiscal);

        $selloOriginal = $ccf->sello_recepcion;
        $respuestaOriginal = $ccf->respuesta_mh;
        $procesamientoOriginal = $ccf->fecha_procesamiento_mh;

        $evento = $this->evento(TipoAnulacionMh::Otro, $sustituto->codigo_generacion, 'Duplicado.');

        // Dry-run: no bloquea y el schema es válido.
        $d = app(DteInvalidacionService::class)->dryRun($ccf, $evento, true, true);
        $this->assertFalse($d['candados']['bloqueado'], implode(' | ', $d['candados']['razones']));
        $this->assertTrue($d['schema']['valido'], implode(' | ', $d['schema']['errores']));

        // Transmisión real (contra el fake).
        $r = app(DteInvalidacionService::class)->transmitir($ccf, $evento, true, true);

        $this->assertSame('aceptado', $r['resultado']);
        $this->assertTrue($r['invalidado']);

        $ccf->refresh();
        $this->assertSame(EstadoDte::Invalidado, $ccf->estado);
        $this->assertSame('SELLO-INVAL-REAL', $ccf->respuesta_mh_invalidacion['selloRecibido']);
        $this->assertSame(TipoAnulacionMh::Otro->value, (int) $ccf->tipo_anulacion?->value);

        // EVIDENCIA ORIGINAL intacta: el evento se guarda en columnas dedicadas.
        $this->assertSame($selloOriginal, $ccf->sello_recepcion);
        $this->assertSame($respuestaOriginal, $ccf->respuesta_mh);
        $this->assertEquals($procesamientoOriginal, $ccf->fecha_procesamiento_mh);
        $this->assertSame('SELLO-INVAL-REAL', $ccf->sello_invalidacion);

        // Y el JSON del evento guardado en disco lleva el sustituto verificado.
        $guardado = json_decode(Storage::disk('local')->get($ccf->json_invalidacion_path), true);
        $this->assertSame($sustituto->codigo_generacion, $guardado['documento']['codigoGeneracionR']);
    }

    // ------------------------------------------------ 5. Formulario: sin valores residuales

    /**
     * El asistente limpia el sustituto y el texto cuando el motivo elegido deja de
     * pedirlos, y el hidden viaja vacío en ese caso. Es la contraparte de pantalla de la
     * matriz: sin esto, cambiar de motivo dejaría un valor residual que el servidor
     * rechazaría.
     */
    public function test_el_asistente_limpia_lo_que_el_motivo_deja_de_pedir(): void
    {
        $ccf = $this->aceptado(TipoDte::CreditoFiscal);

        $this->actingAs($this->admin())
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            // El valor solo viaja si el motivo elegido lo exige.
            ->assertSee(':value="requiereReemplazo ? reemplazo : \'\'"', false)
            ->assertSee(':value="requiereMotivo ? motivo : \'\'"', false)
            // Y al cambiar de motivo se limpia de inmediato, no un tick después.
            ->assertSee('limpiarLoQueNoAplica()', false)
            ->assertSee('x-on:change="alCambiarTipo()"', false);
    }

    /**
     * Y si aun así llega un valor residual vacío (el hidden viaja SIEMPRE), el servidor lo
     * normaliza a null en vez de tratarlo como un sustituto enviado indebidamente.
     */
    public function test_un_reemplazo_vacio_se_normaliza_a_null_y_no_bloquea(): void
    {
        $this->fakeHttp();
        $nc = $this->aceptado(TipoDte::NotaCredito);

        $this->actingAs($this->admin())
            ->post(route('facturacion.invalidacion.mock', $nc), [
                'tipo' => TipoAnulacionMh::ErrorInformacion->value,
                'reemplazo' => '   ',
                'motivo' => '',
                'confirmar_sin_flag' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $nc->refresh();
        $this->assertStringStartsWith('MOCK-INVAL-', (string) $nc->sello_invalidacion);

        $guardado = json_decode(Storage::disk('local')->get($nc->json_invalidacion_path), true);
        $this->assertNull($guardado['documento']['codigoGeneracionR']);
        $this->assertNull($guardado['motivo']['motivoAnulacion']);
    }

    // --------------------------------------------------- 6. Denegación sin efectos

    /**
     * Una denegación no puede dejar rastro fiscal: ni estado, ni columnas del evento, ni
     * archivos en disco, ni una sola llamada al firmador o al MH. Se comprueba sobre el
     * caso más goloso: la transmisión REAL con todos los candados del entorno abiertos.
     */
    public function test_una_denegacion_no_crea_efectos_fiscales_ni_archivos_ni_llamadas(): void
    {
        $this->fakeHttp();
        $fex = $this->aceptado(TipoDte::FacturaExportacion);

        // Sustituto de otro ambiente: verificación fallida, no un problema del entorno.
        $ajeno = $this->aceptado(TipoDte::FacturaExportacion, ambiente: '01');

        try {
            app(DteInvalidacionService::class)->transmitir(
                $fex,
                $this->evento(TipoAnulacionMh::ErrorInformacion, $ajeno->codigo_generacion),
                true,
                true,
            );
            $this->fail('Debió bloquearse.');
        } catch (DteInvalidacionException $e) {
            $this->assertStringContainsString('ambiente', $e->getMessage());
        }

        $fex->refresh();
        $this->assertSame(EstadoDte::Aceptado, $fex->estado);
        $this->assertNull($fex->sello_invalidacion);
        $this->assertNull($fex->codigo_generacion_invalidacion);
        $this->assertNull($fex->respuesta_mh_invalidacion);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Una denegación no debe escribir JSON/JWS de envío.');
        Http::assertNothingSent();
    }
}
