<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\Dte\OpcionesInvalidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Sección «Acciones del documento» (facturacion.show) y asistente de invalidación.
 *
 * Cubre la UNIFICACIÓN de la interfaz de invalidación/reversión: los cuatro tipos
 * (FCF 01, CCF 03, NC 05, FEX 11) renderizan el MISMO componente y el mismo flujo, y
 * las diferencias entran solo como permisos y acciones disponibles.
 *
 * Es una suite de PRESENTACIÓN y CONTROL: no transmite nada (toda la red va con
 * Http::fake y se comprueba que no salga ni una llamada) y no relaja ningún candado.
 * La lógica fiscal —candados, CAT-024, frase-barrera, transiciones de estado— la
 * siguen cubriendo DteInvalidacionUiTest, DteInvalidacionNcRelacionadaTest y
 * SerializadorInvalidacionMhTest; aquí se verifica que la nueva UI llame exactamente a
 * esa misma lógica.
 */
class AccionesDocumentoUiTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** Los cuatro tipos que deben compartir estructura visual y flujo. */
    private const TIPOS = [
        TipoDte::Factura,
        TipoDte::CreditoFiscal,
        TipoDte::NotaCredito,
        TipoDte::FacturaExportacion,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');

        // Entorno por defecto = MODO SEGURO (mock encendido): los candados de la
        // transmisión real están cerrados, como en la máquina de trabajo.
        config()->set('dte.invalidacion.mock', true);
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06141101690011']);
        $this->credencialesApitestFicticias();
    }

    // ---------------------------------------------------------------- helpers

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Abre TODOS los candados del entorno (apitest), igual que DteInvalidacionUiTest. */
    private function abrirCandados(): void
    {
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '10132512610012');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return compact('estab', 'pv');
    }

    /**
     * Documento ACEPTADO REALMENTE por el MH (sello real + fecha de procesamiento) del
     * tipo indicado. Se crea directo en BD: esta suite prueba la ficha, no la emisión.
     */
    private function documentoAceptado(TipoDte $tipo, ?Cliente $cliente = null, int $secuencia = 1, string $ambiente = '00'): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente ??= Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);

        return Dte::create([
            'tipo_dte' => $tipo->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => $ambiente,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-'.$tipo->value.'-M001P001-'.str_pad((string) $secuencia, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20',
            'hora_emision' => '22:26:52',
            'total_pagar' => 113.00,
        ]);
    }

    /**
     * CCF con una línea gravada, generado y ACEPTADO REALMENTE. Se construye por el
     * camino real (no insertando en BD) porque la reversión con NC copia las líneas y
     * necesita saldo acreditable.
     */
    private function ccfConLineasAceptado(): Dte
    {
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        $borradores = app(DteBorradorService::class);
        $ccf = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'ambiente' => '00',
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf->refresh());
    }

    // -------------------------------------- 1. Los 4 tipos, mismo componente y flujo

    /**
     * FCF, CCF, NC y FEX renderizan la MISMA sección y la misma tarjeta de invalidación.
     * Antes cada tipo componía la zona de corrección de una forma distinta.
     */
    public function test_los_cuatro_tipos_usan_la_misma_seccion_de_acciones(): void
    {
        Http::fake();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $i => $tipo) {
            $dte = $this->documentoAceptado($tipo, secuencia: $i + 1);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte))
                ->assertOk()
                ->assertSee('Acciones del documento')
                ->assertSee('Invalidación oficial (evento anulardte)')
                // Acciones comunes a los cuatro tipos.
                ->assertSee('Descargar PDF')
                ->assertSee('Imprimir')
                ->assertSee('Invalidar oficialmente');
        }

        Http::assertNothingSent();
    }

    /** Con los candados abiertos, los cuatro tipos abren el MISMO asistente de 3 pasos. */
    public function test_los_cuatro_tipos_abren_el_mismo_asistente(): void
    {
        $this->abrirCandados();
        Http::fake();
        $admin = $this->usuario('administrador');

        foreach (self::TIPOS as $i => $tipo) {
            $dte = $this->documentoAceptado($tipo, secuencia: $i + 1);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte))
                ->assertOk()
                // Los tres pasos del asistente, iguales en todos los tipos.
                ->assertSee('Motivo')
                ->assertSee('Detalles')
                ->assertSee('Confirmar')
                ->assertSee('¿Por qué se invalida este documento?')
                ->assertSee('Transmitir invalidación a Hacienda')
                // Y siempre contra la MISMA ruta y con el mismo payload.
                ->assertSee(route('facturacion.invalidacion.transmitir', $dte), false)
                ->assertSee('name="tipo"', false)
                ->assertSee('name="motivo"', false)
                ->assertSee('name="reemplazo"', false)
                ->assertSee('name="confirmacion_invalidacion"', false);
        }

        Http::assertNothingSent();
    }

    /**
     * El acceso rápido al correo es un ANCLA a la sección, no un segundo botón de envío.
     * El sistema mantiene UN solo punto de envío (ver DteCorreoClienteRapidoTest, que
     * cuenta las apariciones de «Enviar correo» y exige que no exista el atajo del
     * encabezado): la barra de acciones no puede reintroducirlo.
     */
    public function test_el_acceso_rapido_al_correo_no_duplica_el_boton_de_envio(): void
    {
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $contenido = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('Correo del cliente')
            ->getContent();

        // El enlace apunta a la sección, no a la ruta de envío.
        $this->assertStringContainsString('href="#correo-cliente"', $contenido);
        $this->assertStringNotContainsString(route('facturacion.correo.cliente', $dte), $contenido);

        // Y no aporta una segunda copia de la frase del botón real.
        $this->assertLessThanOrEqual(
            1,
            substr_count($contenido, 'Enviar correo'),
            'La barra de acciones no debe duplicar el botón de envío de correo.'
        );

        Http::assertNothingSent();
    }

    // ----------------------------------------------- 2. Opciones CAT-024 correctas

    /**
     * El paso 1 ofrece EXACTAMENTE los valores de CAT-024, ni uno más ni uno menos, con
     * su etiqueta oficial como texto secundario. La opción se muestra en lenguaje humano
     * pero el código oficial sigue siendo el que viaja.
     */
    public function test_el_asistente_ofrece_exactamente_las_opciones_de_cat_024(): void
    {
        $this->abrirCandados();
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $dte))
            ->assertOk();

        // El asistente recibe EXACTAMENTE las opciones de CAT-024 —ni una de más, ni una
        // de menos— serializadas igual que las emite la vista.
        $respuesta->assertSee(
            (string) Js::from(OpcionesInvalidacion::opciones()),
            false
        );

        // Y cada una lleva su etiqueta oficial de catálogo y su título humano.
        $opciones = collect(OpcionesInvalidacion::opciones())->keyBy('valor');
        foreach (TipoAnulacionMh::cases() as $caso) {
            $this->assertArrayHasKey($caso->value, $opciones->all());
            $this->assertSame($caso->label(), $opciones[$caso->value]['etiqueta_oficial']);
        }
        $this->assertSame(
            ['Reemplazar el documento', 'Rescindir la operación', 'Otro motivo permitido'],
            $opciones->pluck('titulo')->all()
        );

        // En el asistente el código técnico va SOLO como texto secundario.
        $respuesta->assertSee('Código oficial CAT-024');

        // El select crudo de CAT-024 ya no está en el flujo principal: queda UNA sola
        // copia, dentro del bloque «Avanzado · modo prueba (MOCK)», que es diagnóstico
        // y viene colapsado. Antes había dos (transmisión real + mock).
        $this->assertSame(
            1,
            substr_count($respuesta->getContent(), 'Tipo de anulación (CAT-024) *'),
            'El select CAT-024 solo debe quedar en el bloque avanzado de diagnóstico.'
        );

        Http::assertNothingSent();
    }

    /**
     * Las banderas de campos condicionales que consume la UI salen del enum, no de una
     * copia: si mañana cambia `requiereDocumentoReemplazo()`, la UI cambia con él.
     */
    public function test_las_banderas_de_campos_condicionales_vienen_del_enum(): void
    {
        $opciones = collect(OpcionesInvalidacion::opciones())->keyBy('valor');

        $this->assertSame(
            collect(TipoAnulacionMh::cases())->pluck('value')->all(),
            $opciones->keys()->all(),
            'El paso 1 debe ofrecer los mismos valores de CAT-024 que el enum, en el mismo orden.'
        );

        foreach (TipoAnulacionMh::cases() as $caso) {
            $this->assertSame($caso->requiereDocumentoReemplazo(), $opciones[$caso->value]['requiere_reemplazo']);
            $this->assertSame($caso->requiereMotivoTexto(), $opciones[$caso->value]['requiere_motivo']);
            $this->assertSame($caso->label(), $opciones[$caso->value]['etiqueta_oficial']);
        }
    }

    // ------------------------------- 3. Documento relacionado solo cuando aplica

    /**
     * El buscador de reemplazo solo lo consume el tipo que lo exige (CAT-024 = 1). La UI
     * lo esconde para los demás y, además, vacía el campo: el serializador rechaza un
     * reemplazo en los tipos 2 y 3.
     */
    public function test_el_buscador_de_reemplazo_solo_se_muestra_cuando_el_tipo_lo_exige(): void
    {
        $this->abrirCandados();
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            // El bloque existe pero va condicionado a la bandera del tipo elegido.
            ->assertSee('¿Qué documento lo reemplaza?')
            ->assertSee('x-show="requiereReemplazo"', false)
            ->assertSee('x-show="requiereMotivo"', false)
            // Y el valor que viaja se anula cuando el tipo no lo admite.
            ->assertSee(':value="requiereReemplazo ? reemplazo : \'\'"', false)
            ->assertSee(':value="requiereMotivo ? motivo : \'\'"', false);

        Http::assertNothingSent();
    }

    /**
     * El buscador ofrece SOLO documentos con aceptación real del MH en el mismo ambiente,
     * nunca el propio documento ni un aceptado MOCK (cuyo código no existe en Hacienda).
     * Es un filtro de conveniencia; la regla dura sigue en el serializador.
     */
    public function test_el_buscador_solo_ofrece_documentos_con_aceptacion_real_del_mismo_ambiente(): void
    {
        Http::fake();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);

        $invalidado = $this->documentoAceptado(TipoDte::CreditoFiscal, $cliente, 1);
        $candidato = $this->documentoAceptado(TipoDte::Factura, $cliente, 2);

        // Aceptado MOCK: fuera del universo (su código no existe en el MH).
        $mock = $this->documentoAceptado(TipoDte::Factura, $cliente, 3);
        $mock->update(['sello_recepcion' => 'MOCK-SIMULADO-'.$mock->id, 'fecha_procesamiento_mh' => null]);

        // Otro ambiente (producción): fuera del universo. El ambiente se fija al crear
        // porque un DTE aceptado es inmutable (ver DteObserver).
        $otroAmbiente = $this->documentoAceptado(TipoDte::Factura, $cliente, 4, ambiente: '01');

        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $invalidado))
            ->assertOk();

        $codigos = collect($respuesta->json('resultados'))->pluck('codigo_generacion')->all();

        $this->assertContains($candidato->codigo_generacion, $codigos);
        $this->assertNotContains($invalidado->codigo_generacion, $codigos, 'Un documento no puede reemplazarse a sí mismo.');
        $this->assertNotContains($mock->codigo_generacion, $codigos, 'Una aceptación MOCK no es ofrecible como reemplazo.');
        $this->assertNotContains($otroAmbiente->codigo_generacion, $codigos, 'El reemplazo debe ser del mismo ambiente.');

        Http::assertNothingSent();
    }

    /** Cada resultado trae los datos que la UI muestra: tipo, control, cliente, fecha, total y estado. */
    public function test_cada_resultado_del_buscador_trae_los_datos_de_la_fila(): void
    {
        Http::fake();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $invalidado = $this->documentoAceptado(TipoDte::CreditoFiscal, $cliente, 1);
        $this->documentoAceptado(TipoDte::Factura, $cliente, 2);

        $fila = $this->actingAs($this->usuario('administrador'))
            ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $invalidado))
            ->assertOk()
            ->json('resultados.0');

        foreach (['codigo_generacion', 'numero_control', 'tipo_label', 'cliente', 'fecha', 'total', 'estado'] as $clave) {
            $this->assertArrayHasKey($clave, $fila);
            $this->assertNotNull($fila[$clave], "El resultado debe traer «{$clave}» para pintar la fila.");
        }
    }

    /**
     * El buscador tope a 20 resultados y NO expone ningún parámetro de request para
     * subir ese techo: es un autocomplete, no un exportador de documentos.
     */
    public function test_el_buscador_limita_los_resultados_y_no_se_puede_ampliar(): void
    {
        Http::fake();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $invalidado = $this->documentoAceptado(TipoDte::CreditoFiscal, $cliente, 1);

        // Más candidatos que el tope.
        for ($i = 2; $i <= 3 + \App\Services\Dte\BusquedaDocumentoReemplazo::LIMITE; $i++) {
            $this->documentoAceptado(TipoDte::Factura, $cliente, $i);
        }

        $url = route('facturacion.invalidacion.buscar-reemplazo', $invalidado);

        // Sin parámetros y con intentos explícitos de ampliar el techo: siempre el tope.
        foreach (['', '?limite=500', '?limit=500', '?per_page=500', '?q=&limite=500'] as $query) {
            $this->assertCount(
                \App\Services\Dte\BusquedaDocumentoReemplazo::LIMITE,
                $this->actingAs($this->usuario('administrador'))->getJson($url.$query)->assertOk()->json('resultados'),
                "El buscador no debe devolver más de su tope con «{$query}»."
            );
        }

        Http::assertNothingSent();
    }

    /**
     * Un término de búsqueda desmesurado se recorta en vez de convertirse en un LIKE
     * costoso: responde normal y sin resultados, no con un error ni con una consulta larga.
     */
    public function test_un_termino_de_busqueda_enorme_no_rompe_el_buscador(): void
    {
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario('administrador'))
            ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $dte).'?q='.str_repeat('A', 5000))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertNothingSent();
    }

    /**
     * El JSON devuelve SOLO lo que la fila necesita pintar. Nada de sello de recepción,
     * respuesta del MH, rutas de archivos ni ningún otro dato fiscal o de infraestructura.
     */
    public function test_el_buscador_no_devuelve_secretos_ni_datos_fiscales_de_mas(): void
    {
        Http::fake();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $invalidado = $this->documentoAceptado(TipoDte::CreditoFiscal, $cliente, 1);
        $candidato = $this->documentoAceptado(TipoDte::Factura, $cliente, 2);

        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $invalidado))
            ->assertOk();

        // Contrato exacto de cada fila: ni una clave de más.
        $this->assertSame(
            ['id', 'codigo_generacion', 'numero_control', 'tipo', 'tipo_label', 'cliente', 'fecha', 'total', 'estado'],
            array_keys($respuesta->json('resultados.0'))
        );

        // Y nada del material sensible del documento aparece en el cuerpo.
        $cuerpo = $respuesta->getContent();
        foreach ([$candidato->sello_recepcion, $invalidado->sello_recepcion] as $sello) {
            $this->assertStringNotContainsString((string) $sello, $cuerpo, 'El sello de recepción no debe viajar al autocomplete.');
        }
        foreach (['sello_recepcion', 'respuesta_mh', 'json_generado_path', 'json_firmado_path', 'token', 'password'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo);
        }

        Http::assertNothingSent();
    }

    /** El buscador es SOLO consulta: no firma, no transmite y no toca el documento. */
    public function test_el_buscador_no_transmite_ni_modifica_nada(): void
    {
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);
        $antes = $dte->fresh()->toArray();

        $this->actingAs($this->usuario('administrador'))
            ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $dte).'?q=Calleja')
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame($antes, $dte->fresh()->toArray());
    }

    // ------------------------------------------------- 4. No transmite sin confirmación

    /**
     * La frase-barrera se sigue exigiendo en SERVIDOR aunque el asistente la pida al
     * final: enviar el POST sin ella (o con ella mal escrita) no transmite nada.
     */
    public function test_sin_la_frase_exacta_no_se_transmite_nada(): void
    {
        $this->abrirCandados();
        Http::fake();
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        foreach ([[], ['confirmacion_invalidacion' => ''], ['confirmacion_invalidacion' => 'invalidar dte']] as $payload) {
            $this->actingAs($this->usuario('administrador'))
                ->post(route('facturacion.invalidacion.transmitir', $dte), array_merge(
                    ['tipo' => TipoAnulacionMh::RescindirOperacion->value],
                    $payload
                ))
                ->assertSessionHasErrors('confirmacion_invalidacion');
        }

        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Aceptado, $dte->fresh()->estado);
        $this->assertFalse($dte->fresh()->tieneEventoInvalidacion());
    }

    /**
     * Si Hacienda RECHAZA el evento, el documento conserva su estado: la nueva UI no
     * cambia en nada esa garantía (la aplica DteInvalidacionService, no la vista).
     */
    public function test_si_hacienda_rechaza_el_documento_conserva_su_estado(): void
    {
        $this->abrirCandados();
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response([
                'estado' => 'RECHAZADO', 'codigoMsg' => '004',
                'descripcionMsg' => 'Documento no encontrado', 'observaciones' => [],
            ], 200),
        ]);
        $dte = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $dte), [
                'tipo' => TipoAnulacionMh::RescindirOperacion->value,
                'confirmacion_invalidacion' => 'INVALIDAR DTE',
            ])
            ->assertRedirect(route('facturacion.show', $dte));

        $dte->refresh();
        $this->assertSame(EstadoDte::Aceptado, $dte->estado, 'Un rechazo del MH no debe cambiar el estado local.');
        $this->assertNull($dte->sello_invalidacion);
    }

    // ------------------------------------------ 5. Reversión NC: separada y solo borrador

    /**
     * Los dos conceptos correctivos se presentan por separado y con textos que dicen
     * qué hace cada uno: la invalidación transmite a Hacienda, la reversión no.
     */
    public function test_la_reversion_con_nc_esta_separada_de_la_invalidacion_oficial(): void
    {
        Http::fake();
        $ccf = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Revertir con nota de crédito')
            // Cada tarjeta declara su efecto sin ambigüedad.
            ->assertSee('Anula el documento')
            ->assertSee('no se transmite nada a Hacienda')
            ->assertSee('Crea un borrador');

        Http::assertNothingSent();
    }

    /**
     * La reversión con NC crea un BORRADOR y no transmite: mismo servicio de siempre.
     *
     * Aquí el CCF sí se construye por el camino real (borrador → línea → generar →
     * aceptar), porque la reversión copia líneas y necesita saldo acreditable: un
     * documento insertado a mano no tendría nada que revertir.
     */
    public function test_la_reversion_con_nc_solo_crea_un_borrador(): void
    {
        Http::fake();
        $ccf = $this->ccfConLineasAceptado();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.nota-credito.revertir', $ccf))
            ->assertRedirect();

        $nc = Dte::where('dte_relacionado_id', $ccf->id)->where('tipo_dte', TipoDte::NotaCredito->value)->first();

        $this->assertNotNull($nc, 'La reversión debe dejar una nota de crédito.');
        $this->assertSame(EstadoDte::Borrador, $nc->estado, 'La NC de reversión nace en BORRADOR.');
        $this->assertNull($nc->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $ccf->fresh()->estado, 'El CCF original no cambia de estado.');

        Http::assertNothingSent();
    }

    /** Solo el CCF tiene reversión con NC; los demás tipos no la ofrecen. */
    public function test_solo_el_ccf_ofrece_reversion_con_nota_de_credito(): void
    {
        Http::fake();
        $admin = $this->usuario('administrador');

        foreach ([TipoDte::Factura, TipoDte::NotaCredito, TipoDte::FacturaExportacion] as $i => $tipo) {
            $dte = $this->documentoAceptado($tipo, secuencia: $i + 1);

            $this->actingAs($admin)
                ->get(route('facturacion.show', $dte))
                ->assertOk()
                ->assertSee('Acciones del documento')
                ->assertDontSee('Revertir con nota de crédito');
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ 6. Permisos

    /** Sin permiso `dte.invalidar` no hay tarjeta, ni asistente, ni buscador. */
    public function test_sin_permiso_de_invalidar_no_hay_asistente_ni_buscador(): void
    {
        $this->abrirCandados();
        Http::fake();
        $ccf = $this->documentoAceptado(TipoDte::CreditoFiscal);

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $usuario = $this->usuario($rol);

            $this->actingAs($usuario)
                ->get(route('facturacion.show', $ccf))
                ->assertOk()
                ->assertDontSee('Invalidación oficial (evento anulardte)')
                ->assertDontSee('Invalidar oficialmente')
                ->assertDontSee('¿Por qué se invalida este documento?');

            $this->actingAs($usuario)
                ->getJson(route('facturacion.invalidacion.buscar-reemplazo', $ccf))
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    /** El buscador exige sesión: un invitado no lista documentos del sistema. */
    public function test_un_invitado_no_puede_usar_el_buscador(): void
    {
        Http::fake();
        $ccf = $this->documentoAceptado(TipoDte::CreditoFiscal);

        $this->get(route('facturacion.invalidacion.buscar-reemplazo', $ccf))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    /**
     * Un documento que no es candidato (aceptación MOCK) muestra la tarjeta con las
     * razones, pero NO monta el asistente: el acceso rápido lleva a la explicación.
     */
    public function test_documento_no_candidato_no_monta_el_asistente(): void
    {
        $this->abrirCandados();
        Http::fake();
        $ccf = $this->documentoAceptado(TipoDte::CreditoFiscal);
        $ccf->update(['sello_recepcion' => 'MOCK-SIMULADO-'.$ccf->id, 'fecha_procesamiento_mh' => null]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('aceptación simulada')
            ->assertSee('Botón deshabilitado')
            ->assertDontSee('¿Por qué se invalida este documento?')
            ->assertDontSee('Transmitir invalidación a Hacienda');

        Http::assertNothingSent();
    }
}
