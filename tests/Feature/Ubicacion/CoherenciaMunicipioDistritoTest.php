<?php

namespace Tests\Feature\Ubicacion;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Municipio;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\ValidacionPreJsonService;
use App\Support\Ubicacion\CoherenciaUbicacion;
use App\Support\Ubicacion\OpcionesUbicacion;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * COHERENCIA de la ubicación fiscal: departamento → municipio 2024 (CAT-013) → distrito
 * (CAT-008).
 *
 * Antes municipio y distrito se validaban por separado, cada uno solo contra el
 * departamento, y el selector de distrito listaba TODO el departamento usando el nombre de
 * la agrupación como simple adorno. Así se podía guardar (y emitir) el municipio
 * «Cabañas Este» junto al distrito «Ilobasco», que pertenece a «Cabañas Oeste»: el par que
 * Hacienda rechaza con «[receptor.direccion.distrito] VALOR NO ES PERMITIDO».
 *
 * Estas pruebas fijan la regla general —no un parche para Ilobasco— en sus cuatro capas:
 * catálogo, selector, FormRequest y validación previa a generar el JSON.
 *
 * Ninguna prueba emite, firma ni transmite documentos reales.
 */
class CoherenciaMunicipioDistritoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Distrito por nombre + departamento (CAT-012). */
    private function distrito(string $nombre, string $codigoDepto): Distrito
    {
        return Distrito::whereHas('departamento', fn ($q) => $q->where('codigo', $codigoDepto))
            ->where('nombre', $nombre)
            ->firstOrFail();
    }

    /** Municipio 2024 por código CAT-013 dentro de un departamento. */
    private function municipio(string $codigoMunicipio, string $codigoDepto): Municipio
    {
        $depto = Departamento::where('codigo', $codigoDepto)->firstOrFail();

        return Municipio::firstOrCreate(
            ['departamento_id' => $depto->id, 'codigo' => $codigoMunicipio],
            ['nombre' => 'Municipio '.$codigoMunicipio, 'activo' => true],
        );
    }

    // --- 1 y 2. El caso Ilobasco, y su contraparte inválida ---

    public function test_cabanas_oeste_con_ilobasco_es_valido(): void
    {
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasOeste = $this->municipio('11', '09');

        // Datos del catálogo oficial vigente (2026-07-01): Ilobasco es el distrito 03 de
        // Cabañas y su agrupación, Cabañas Oeste, es el CAT-013 11.
        $this->assertSame('03', $ilobasco->codigo);
        $this->assertSame('Cabañas Oeste', $ilobasco->municipio);
        $this->assertSame('11', $ilobasco->municipio_codigo);

        $this->assertNull(CoherenciaUbicacion::problema(
            $ilobasco->departamento_id, $cabanasOeste->id, $ilobasco->id
        ));
        $this->assertTrue($ilobasco->perteneceAMunicipio($cabanasOeste));
    }

    public function test_cabanas_este_con_ilobasco_es_invalido(): void
    {
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasEste = $this->municipio('10', '09');

        $problema = CoherenciaUbicacion::problema(
            $ilobasco->departamento_id, $cabanasEste->id, $ilobasco->id
        );

        $this->assertNotNull($problema, 'Cabañas Este + Ilobasco debe rechazarse.');
        $this->assertStringContainsString('Ilobasco', $problema);
        $this->assertStringContainsString('Cabañas Oeste', $problema);
        $this->assertFalse($ilobasco->perteneceAMunicipio($cabanasEste));
    }

    // --- 3. Caso de referencia realmente aceptado por Hacienda ---

    public function test_san_salvador_centro_con_distrito_san_salvador_es_valido(): void
    {
        $sanSalvador = $this->distrito('San Salvador', '06');
        $centro = $this->municipio('23', '06');

        // Trío 06 / 23 / 14, igual al de los CCF aceptados en producción.
        $this->assertSame('14', $sanSalvador->codigo);
        $this->assertSame('23', $sanSalvador->municipio_codigo);
        $this->assertNull(CoherenciaUbicacion::problema(
            $sanSalvador->departamento_id, $centro->id, $sanSalvador->id
        ));
    }

    // --- 4. Niveles de departamentos distintos ---

    public function test_municipio_y_distrito_de_departamentos_distintos_es_invalido(): void
    {
        $ilobasco = $this->distrito('Ilobasco', '09');   // Cabañas
        $centroSs = $this->municipio('23', '06');        // San Salvador

        // Declarado en Cabañas, con un municipio de San Salvador.
        $problema = CoherenciaUbicacion::problema($ilobasco->departamento_id, $centroSs->id, $ilobasco->id);
        $this->assertNotNull($problema);
        $this->assertStringContainsString('no pertenece al departamento', $problema);

        // Y al revés: declarado en San Salvador, con un distrito de Cabañas.
        $problemaInverso = CoherenciaUbicacion::problema($centroSs->departamento_id, $centroSs->id, $ilobasco->id);
        $this->assertNotNull($problemaInverso);
        $this->assertStringContainsString('no pertenece al departamento', $problemaInverso);
    }

    // --- 5 y 6. Selector: filtra por municipio y limpia lo incompatible ---

    public function test_el_selector_filtra_los_distritos_por_el_municipio_elegido(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $html = $this->htmlLegible(route('clientes.sucursales.create', $cliente));

        // El filtro del tercer select compara el municipio del distrito, no solo el depto.
        $this->assertStringContainsString('d.municipio_codigo === m.codigo', $html);
        $this->assertStringContainsString('d.departamento_id === m.departamento_id', $html);
        // Y cada distrito viaja con su municipio_codigo para poder filtrarlo.
        $this->assertMatchesRegularExpression('/"municipio_codigo":"\d{2}"/', $html);
        // Ilobasco debe viajar marcado con el código de Cabañas Oeste (11 en el catálogo
        // vigente), no con el de Cabañas Este (10). Se compara sin el acento porque el JSON
        // del atributo escapa la ñ, lo que no es objeto de esta prueba.
        $this->assertMatchesRegularExpression(
            '/"nombre":"Ilobasco","municipio":"Caba\S*as Oeste","municipio_codigo":"11"/',
            $html
        );
    }

    /**
     * HTML de una vista con el JS legible: Blade emite el estado de Alpine con
     * `Illuminate\Support\Js::from()`, que escapa las comillas como `"` dentro de un
     * atributo HTML. Se normaliza para poder afirmar sobre el código tal como lo ejecuta
     * el navegador.
     */
    private function htmlLegible(string $url): string
    {
        $html = html_entity_decode(
            $this->actingAs($this->admin())->get($url)->assertOk()->getContent(),
            ENT_QUOTES | ENT_HTML5
        );

        // " → " y \uXXXX de los acentos → su carácter real.
        return preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'),
            $html
        );
    }

    public function test_al_cambiar_de_municipio_se_limpia_un_distrito_incompatible(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $html = $this->htmlLegible(route('clientes.sucursales.create', $cliente));

        // Al cambiar municipio se descarta el distrito si ya no pertenece a la lista válida.
        $this->assertStringContainsString('onMunicipioChange()', $html);
        $this->assertStringContainsString(
            "if (! this.distritosFiltrados.some(d => d.id === this.distritoId)) { this.distritoId = ''; }",
            $html
        );
        // Y cambiar de departamento limpia municipio y distrito.
        $this->assertStringContainsString('onDepartamentoChange()', $html);
        $this->assertStringContainsString("this.municipioId = ''; this.distritoId = '';", $html);
    }

    public function test_el_selector_muestra_el_municipio_fiscal_2024_y_no_el_nombre_anterior(): void
    {
        // La fila de código 11 en Cabañas debe mostrarse como "Cabañas Oeste" aunque su
        // columna `nombre` diga "Ilobasco" (nombre municipal anterior).
        $depto = Departamento::where('codigo', '09')->firstOrFail();
        $municipio = Municipio::firstOrCreate(
            ['departamento_id' => $depto->id, 'nombre' => 'Ilobasco'],
            ['codigo' => '11', 'activo' => true],
        );
        $municipio->update(['codigo' => '11']);

        $this->assertSame('Cabañas Oeste', $municipio->fresh()->nombreFiscal());
        $this->assertSame('Ilobasco', $municipio->fresh()->nombre, 'El nombre histórico no debe destruirse.');

        // Y la fila 10 como "Cabañas Este".
        $este = $this->municipio('10', '09');
        $this->assertSame('Cabañas Este', $este->nombreFiscal());
    }

    public function test_el_selector_no_duplica_municipios_que_comparten_codigo(): void
    {
        // San Salvador tiene varias filas históricas con el mismo código CAT-013: son la
        // MISMA agrupación fiscal y deben ofrecerse una sola vez.
        //
        // Los tres nombres son los distritos que REALMENTE forman San Salvador Este (22).
        // Antes figuraba Cuscatancingo, que pertenece a San Salvador Centro (23): el
        // ejemplo contradecía el catálogo aunque la prueba pasara.
        $depto = Departamento::where('codigo', '06')->firstOrFail();
        foreach (['Soyapango', 'Ilopango', 'San Martín'] as $nombre) {
            Municipio::updateOrCreate(
                ['departamento_id' => $depto->id, 'nombre' => $nombre],
                ['codigo' => '22', 'activo' => true],
            );
        }

        $ofrecidos = OpcionesUbicacion::municipios()
            ->where('departamento_id', $depto->id)
            ->where('codigo', '22');

        $this->assertCount(1, $ofrecidos, 'Las filas con el mismo código CAT-013 deben colapsar en una opción.');
    }

    // --- FormRequest: la sala no se guarda con un par imposible ---

    public function test_no_se_puede_guardar_una_sala_con_municipio_y_distrito_incompatibles(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasEste = $this->municipio('10', '09');

        $this->actingAs($this->admin())
            ->post(route('clientes.sucursales.store', $cliente), [
                'nombre' => 'Sala incoherente',
                'departamento_id' => $ilobasco->departamento_id,
                'municipio_id' => $cabanasEste->id,
                'distrito_id' => $ilobasco->id,
                'activo' => '1',
                'requiere_orden_compra' => '',
            ])
            ->assertSessionHasErrors('distrito_id');

        $this->assertDatabaseCount('cliente_sucursales', 0);
    }

    public function test_si_se_puede_guardar_una_sala_con_el_par_correcto(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasOeste = $this->municipio('11', '09');

        $this->actingAs($this->admin())
            ->post(route('clientes.sucursales.store', $cliente), [
                'nombre' => 'Súper Selectos Ilobasco',
                'departamento_id' => $ilobasco->departamento_id,
                'municipio_id' => $cabanasOeste->id,
                'distrito_id' => $ilobasco->id,
                'activo' => '1',
                'requiere_orden_compra' => '',
            ])
            ->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('cliente_sucursales', [
            'nombre' => 'Súper Selectos Ilobasco',
            'municipio_id' => $cabanasOeste->id,
            'distrito_id' => $ilobasco->id,
        ]);
    }

    // --- 7 y 8. Preemisión: sin distrito no se emite, y el JSON nunca lleva "" ---

    public function test_una_sala_sin_distrito_no_puede_emitir_un_ccf(): void
    {
        ['dte' => $ccf] = $this->ccfConSala(['distrito_id' => null]);

        $problemas = app(ValidacionPreJsonService::class)->validar($ccf);

        $this->assertNotEmpty($problemas);
        $this->assertStringContainsString(
            'distrito',
            implode(' | ', $problemas),
            'Debe bloquearse por falta de distrito antes de construir el JSON.'
        );
    }

    public function test_generar_falla_si_la_sala_no_tiene_distrito(): void
    {
        ['dte' => $ccf] = $this->ccfConSala(['distrito_id' => null]);

        $this->expectException(GeneracionException::class);
        app(DteGeneracionService::class)->generar($ccf);
    }

    public function test_generar_falla_si_el_par_municipio_distrito_es_incompatible(): void
    {
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasEste = $this->municipio('10', '09');

        ['dte' => $ccf] = $this->ccfConSala([
            'departamento_id' => $ilobasco->departamento_id,
            'municipio_id' => $cabanasEste->id,
            'distrito_id' => $ilobasco->id,
        ]);

        $problemas = app(ValidacionPreJsonService::class)->validar($ccf);
        $this->assertStringContainsString('Ilobasco', implode(' | ', $problemas));

        $this->expectException(GeneracionException::class);
        app(DteGeneracionService::class)->generar($ccf);
    }

    public function test_el_json_del_ccf_nunca_lleva_distrito_vacio(): void
    {
        ['dte' => $ccf] = $this->ccfConSala();

        app(DteGeneracionService::class)->generar($ccf);
        $json = json_decode(Storage::disk('local')->get($ccf->refresh()->json_generado_path), true);

        foreach (['emisor', 'receptor'] as $parte) {
            $this->assertArrayHasKey('distrito', $json[$parte]['direccion']);
            $this->assertNotSame('', $json[$parte]['direccion']['distrito'],
                "El {$parte} salió con distrito vacío: el MH lo rechaza.");
            $this->assertMatchesRegularExpression('/^\d{2}$/', $json[$parte]['direccion']['distrito']);
        }
    }

    /** La Nota de crédito v3 no tiene `distrito`: no debe exigirlo ni inventarlo. */
    public function test_la_nota_de_credito_v3_no_exige_ni_envia_distrito(): void
    {
        ['dte' => $ccf, 'sala' => $sala] = $this->ccfConSala();
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf->refresh());

        // Sala sin distrito: la NC debe poder emitirse igual (su esquema no lo lleva).
        $sala->update(['distrito_id' => null]);

        $borradores = app(DteBorradorService::class);
        $nc = $borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::ProntoPago->value]);
        $borradores->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => 5]);

        app(DteGeneracionService::class)->generar($nc);
        $json = json_decode(Storage::disk('local')->get($nc->refresh()->json_generado_path), true);

        $this->assertArrayNotHasKey('distrito', $json['receptor']['direccion']);
        $this->assertArrayNotHasKey('distrito', $json['emisor']['direccion']);
    }

    // --- 9. La auditoría detecta sin modificar ---

    public function test_la_auditoria_detecta_inconsistencias_sin_modificar_datos(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasEste = $this->municipio('10', '09');

        // Se fuerza la incoherencia en BD (saltando la validación del formulario), como
        // podría existir en datos históricos cargados antes de esta regla.
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Sala histórica incoherente',
            'departamento_id' => $ilobasco->departamento_id,
            'municipio_id' => $cabanasEste->id,
            'distrito_id' => $ilobasco->id,
        ]);
        $antes = $sala->only(['departamento_id', 'municipio_id', 'distrito_id']);

        $this->artisan('ubicaciones:auditar')
            ->expectsOutputToContain('Sala histórica incoherente')
            ->assertExitCode(1); // 1 = hubo hallazgos

        // Nada cambió: la auditoría es de solo lectura.
        $this->assertSame($antes, $sala->fresh()->only(['departamento_id', 'municipio_id', 'distrito_id']));
    }

    public function test_la_auditoria_con_aplicar_corrige_solo_lo_inequivoco(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ilobasco = $this->distrito('Ilobasco', '09');
        $cabanasOeste = $this->municipio('11', '09');
        $cabanasEste = $this->municipio('10', '09');

        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Sala a corregir',
            'departamento_id' => $ilobasco->departamento_id,
            'municipio_id' => $cabanasEste->id,   // incorrecto
            'distrito_id' => $ilobasco->id,       // correcto
        ]);

        $this->artisan('ubicaciones:auditar --aplicar')->assertExitCode(0);

        // El municipio se reasigna a la agrupación del distrito; el distrito no se toca.
        $sala->refresh();
        $this->assertSame($cabanasOeste->id, $sala->municipio_id);
        $this->assertSame($ilobasco->id, $sala->distrito_id);
        $this->assertNull(CoherenciaUbicacion::problemaDe($sala));
    }

    public function test_la_auditoria_no_inventa_un_distrito_faltante(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Sala sin distrito',
            'distrito_id' => null,
        ]);

        $this->artisan('ubicaciones:auditar --aplicar')->assertExitCode(0);

        $this->assertNull($sala->fresh()->distrito_id, 'El distrito faltante debe quedar para decisión manual.');
    }

    // --- 10. Integridad del catálogo ---

    public function test_los_262_distritos_tienen_vinculo_inequivoco_a_su_municipio(): void
    {
        $this->assertSame(262, Distrito::count());
        $this->assertSame(0, Distrito::whereNull('municipio_codigo')->count());

        // 44 agrupaciones municipales distintas (departamento + código).
        $agrupaciones = Distrito::get(['departamento_id', 'municipio_codigo'])
            ->map(fn (Distrito $d) => $d->departamento_id.'-'.$d->municipio_codigo)
            ->unique();
        $this->assertCount(44, $agrupaciones);

        // Cada NOMBRE de agrupación resuelve a un único código: sin ambigüedad.
        $mapa = VinculaMunicipioDistrito::mapaNombreACodigo();
        $this->assertCount(44, $mapa);

        // Y ningún nombre de agrupación quedó con dos códigos dentro del mismo departamento.
        Distrito::get(['departamento_id', 'municipio', 'municipio_codigo'])
            ->groupBy(fn (Distrito $d) => $d->departamento_id.'|'.$d->municipio)
            ->each(function ($grupo, $clave) {
                $this->assertCount(1, $grupo->pluck('municipio_codigo')->unique(),
                    "La agrupación {$clave} tiene más de un código CAT-013.");
            });
    }

    public function test_vincular_municipio_es_idempotente(): void
    {
        $primera = VinculaMunicipioDistrito::ejecutar();
        $segunda = VinculaMunicipioDistrito::ejecutar();

        $this->assertSame(0, $segunda['vinculados'], 'La segunda corrida no debería cambiar nada.');
        $this->assertSame($primera['total'], $segunda['total']);
        $this->assertSame(262, $segunda['sin_cambios']);
    }

    // --- 11. No regresión con los tríos realmente aceptados por Hacienda ---

    /**
     * Tríos (departamento, municipio CAT-013, distrito CAT-008) tomados de CCF y NC
     * ACEPTADOS realmente por Hacienda. Deben seguir siendo válidos: si esta prueba falla,
     * la regla nueva estaría rechazando algo que el MH sí acepta.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function triosAceptadosProvider(): array
    {
        return [
            'San Salvador Centro / San Salvador' => ['06', '23', '14'],
            'San Salvador Este / Soyapango' => ['06', '22', '17'],
            'La Libertad Sur / Santa Tecla' => ['05', '28', '11'],
            'La Libertad Este / Antiguo Cuscatlán' => ['05', '26', '01'],
            'Morazán Sur / San Francisco Gotera' => ['13', '28', '19'],
            'Cuscatlán Sur / Cojutepeque' => ['07', '18', '02'],
            'Ahuachapán Centro / Ahuachapán' => ['01', '14', '01'],
            'Ahuachapán Sur / San Francisco Menéndez' => ['01', '15', '08'],
            'San Miguel Centro / San Miguel' => ['12', '22', '17'],
            'La Paz Oeste / Olocuilta' => ['08', '23', '05'],
        ];
    }

    #[DataProvider('triosAceptadosProvider')]
    public function test_los_trios_ya_aceptados_por_hacienda_siguen_siendo_validos(
        string $codigoDepto,
        string $codigoMunicipio,
        string $codigoDistrito
    ): void {
        $depto = Departamento::where('codigo', $codigoDepto)->firstOrFail();
        $distrito = Distrito::where('departamento_id', $depto->id)
            ->where('codigo', $codigoDistrito)
            ->firstOrFail();

        // El catálogo debe confirmar que ese distrito pertenece a ese municipio.
        $this->assertSame($codigoMunicipio, $distrito->municipio_codigo,
            "El distrito {$codigoDistrito} de {$codigoDepto} debería pertenecer al municipio {$codigoMunicipio}.");

        $municipio = $this->municipio($codigoMunicipio, $codigoDepto);
        $this->assertNull(CoherenciaUbicacion::problema($depto->id, $municipio->id, $distrito->id));
    }

    /**
     * CCF en borrador con una sala; los override se aplican a la SALA.
     *
     * @param  array<string, mixed>  $salaOverride
     * @return array{dte: Dte, sala: ClienteSucursal, cliente: Cliente}
     */
    private function ccfConSala(array $salaOverride = []): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = ClienteSucursal::factory()->create(array_merge([
            'cliente_id' => $cliente->id,
            'nombre' => 'Sala de prueba',
        ], $salaOverride));

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 5);

        return ['dte' => $dte->refresh(), 'sala' => $sala, 'cliente' => $cliente];
    }
}
