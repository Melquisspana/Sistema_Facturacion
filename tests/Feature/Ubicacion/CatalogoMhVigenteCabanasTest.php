<?php

namespace Tests\Feature\Ubicacion;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Municipio;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\Dte\CatalogoOficialMh;
use App\Support\Ubicacion\CoherenciaUbicacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * HOTFIX CAT-013 — catálogo oficial del MH vigente desde el 1 de julio de 2026.
 *
 * El catálogo de mayo traía invertidas las dos agrupaciones de Cabañas (10 = OESTE,
 * 11 = ESTE). El oficial dice 10 = CABAÑAS ESTE y 11 = CABAÑAS OESTE, así que los CCF de
 * Sensuntepeque salían con el municipio de Ilobasco y viceversa.
 *
 * Estas pruebas son PERMANENTES y de no regresión: fijan el trío que Hacienda espera para
 * los dos distritos afectados, que la corrección no se derramó al resto del país, que la
 * migración se puede volver a correr sin efecto, y que el catálogo activo sigue siendo el
 * archivo oficial verificado por hash.
 *
 * Ninguna prueba firma ni transmite; el CCF se genera en local con Storage::fake.
 */
class CatalogoMhVigenteCabanasTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** Los 9 distritos de Cabañas con su agrupación y su CAT-008, según el catálogo oficial. */
    private const DISTRITOS_CABANAS = [
        // distrito       => [agrupación 2024, CAT-013, CAT-008]
        'Dolores' => ['Cabañas Este', '10', '09'],
        'Guacotecti' => ['Cabañas Este', '10', '02'],
        'Sensuntepeque' => ['Cabañas Este', '10', '06'],
        'San Isidro' => ['Cabañas Este', '10', '05'],
        'Victoria' => ['Cabañas Este', '10', '08'],
        'Cinquera' => ['Cabañas Oeste', '11', '01'],
        'Ilobasco' => ['Cabañas Oeste', '11', '03'],
        'Jutiapa' => ['Cabañas Oeste', '11', '04'],
        'Tejutepeque' => ['Cabañas Oeste', '11', '07'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // 1 y 2. El trío que viaja en el JSON oficial
    // ---------------------------------------------------------------------

    public function test_sensuntepeque_serializa_09_10_06(): void
    {
        $direccion = $this->direccionReceptorDe('Sensuntepeque');

        $this->assertSame('09', $direccion['departamento'], 'Cabañas es el CAT-012 09.');
        $this->assertSame('10', $direccion['municipio'], 'Sensuntepeque es Cabañas Este = CAT-013 10.');
        $this->assertSame('06', $direccion['distrito'], 'Sensuntepeque es el CAT-008 06.');
    }

    public function test_ilobasco_serializa_09_11_03(): void
    {
        $direccion = $this->direccionReceptorDe('Ilobasco');

        $this->assertSame('09', $direccion['departamento']);
        $this->assertSame('11', $direccion['municipio'], 'Ilobasco es Cabañas Oeste = CAT-013 11.');
        $this->assertSame('03', $direccion['distrito'], 'Ilobasco es el CAT-008 03.');
    }

    // ---------------------------------------------------------------------
    // 3. Los nueve distritos, en su municipio correcto
    // ---------------------------------------------------------------------

    public function test_los_nueve_distritos_de_cabanas_pertenecen_al_municipio_correcto(): void
    {
        $cabanas = Departamento::where('codigo', '09')->firstOrFail();
        $distritos = Distrito::where('departamento_id', $cabanas->id)->get();

        $this->assertCount(9, $distritos, 'Cabañas tiene exactamente 9 distritos.');

        foreach (self::DISTRITOS_CABANAS as $nombre => [$agrupacion, $cat013, $cat008]) {
            $distrito = $distritos->firstWhere('nombre', $nombre);

            $this->assertNotNull($distrito, "Falta el distrito {$nombre} en Cabañas.");
            $this->assertSame($agrupacion, $distrito->municipio, "{$nombre}: agrupación equivocada.");
            $this->assertSame($cat013, $distrito->municipio_codigo, "{$nombre}: CAT-013 equivocado.");
            $this->assertSame($cat008, $distrito->codigo, "{$nombre}: su CAT-008 no debía cambiar.");
        }

        // Y el reparto es 5 (Este) / 4 (Oeste), no otro.
        $porCodigo = $distritos->groupBy('municipio_codigo')->map->count();
        $this->assertSame(5, $porCodigo['10'], 'Cabañas Este (10) debe tener 5 distritos.');
        $this->assertSame(4, $porCodigo['11'], 'Cabañas Oeste (11) debe tener 4 distritos.');
    }

    public function test_el_catalogo_cargado_nombra_10_este_y_11_oeste(): void
    {
        $cabanas = CatalogoMh::where('cat', '013')
            ->where('valor', 'like', 'CABA%')
            ->pluck('valor', 'codigo');

        $this->assertSame('CABAÑAS ESTE', $cabanas['10']);
        $this->assertSame('CABAÑAS OESTE', $cabanas['11']);

        // Y el nombre fiscal que ve la interfaz sale del mismo lado.
        $municipio = Municipio::where('departamento_id', Departamento::where('codigo', '09')->value('id'))
            ->where('codigo', '10')
            ->firstOrFail();
        $this->assertSame('Cabañas Este', $municipio->nombreFiscal());
    }

    // ---------------------------------------------------------------------
    // 4. El resto del país no se movió
    // ---------------------------------------------------------------------

    public function test_la_migracion_no_toca_ubicaciones_fuera_de_cabanas(): void
    {
        $cabanasId = Departamento::where('codigo', '09')->value('id');

        $antesDistritos = $this->huellaDistritos($cabanasId, dentro: false);
        $antesMunicipios = $this->huellaMunicipios($cabanasId, dentro: false);

        $this->invertirCabanasComoAntesDelHotfix();
        $this->correrMigracion();

        $this->assertSame($antesDistritos, $this->huellaDistritos($cabanasId, dentro: false),
            'Ningún distrito fuera de Cabañas debía cambiar.');
        $this->assertSame($antesMunicipios, $this->huellaMunicipios($cabanasId, dentro: false),
            'Ningún municipio fuera de Cabañas debía cambiar.');
    }

    public function test_los_distritos_codigo_cat008_no_se_modifican(): void
    {
        $antes = Distrito::orderBy('id')->pluck('codigo', 'id')->all();

        $this->invertirCabanasComoAntesDelHotfix();
        $this->correrMigracion();

        $this->assertSame($antes, Distrito::orderBy('id')->pluck('codigo', 'id')->all(),
            'La migración no debe tocar distritos.codigo (CAT-008) en ninguna fila.');
    }

    // ---------------------------------------------------------------------
    // 5. Idempotencia
    // ---------------------------------------------------------------------

    public function test_la_migracion_es_idempotente(): void
    {
        $this->invertirCabanasComoAntesDelHotfix();

        $this->correrMigracion();
        $despuesDeLaPrimera = $this->huellaCabanas();

        // La segunda corrida no debe escribir nada: ni valores, ni `updated_at`.
        $this->correrMigracion();

        $this->assertSame($despuesDeLaPrimera, $this->huellaCabanas(),
            'Volver a correr la migración no debe cambiar una sola fila.');

        // Y el estado final es el correcto, no simplemente "estable".
        $this->assertSame('10', $this->codigoDistrito('Sensuntepeque'));
        $this->assertSame('11', $this->codigoDistrito('Ilobasco'));
    }

    public function test_la_migracion_corrige_el_estado_invertido(): void
    {
        $this->invertirCabanasComoAntesDelHotfix();

        // Punto de partida: el error que había en producción.
        $this->assertSame('11', $this->codigoDistrito('Sensuntepeque'));
        $this->assertSame('10', $this->codigoDistrito('Ilobasco'));
        $this->assertSame('11', $this->codigoMunicipio('Sensuntepeque'));
        $this->assertSame('10', $this->codigoMunicipio('Ilobasco'));

        $this->correrMigracion();

        $this->assertSame('10', $this->codigoDistrito('Sensuntepeque'));
        $this->assertSame('11', $this->codigoDistrito('Ilobasco'));
        $this->assertSame('10', $this->codigoMunicipio('Sensuntepeque'));
        $this->assertSame('11', $this->codigoMunicipio('Ilobasco'));
    }

    // ---------------------------------------------------------------------
    // 6. Las salas productivas quedan coherentes SIN cambiar de identidad
    // ---------------------------------------------------------------------

    /**
     * Réplica de las salas productivas 196, 218 (Ilobasco) y 224 (Sensuntepeque).
     *
     * En producción apuntan a `municipios` 39 (Ilobasco) / 38 (Sensuntepeque) y a
     * `distritos` 210 (Ilobasco) / 215 (Sensuntepeque). Acá se reproduce esa MISMA forma
     * —sala → fila de municipio homónima + distrito homónimo— porque es lo que decide el
     * resultado: la corrección actúa sobre el catálogo referenciado, no sobre la sala.
     */
    public function test_las_salas_productivas_quedan_coherentes_sin_cambiar_de_identidad(): void
    {
        $this->invertirCabanasComoAntesDelHotfix();

        $salas = [
            '0059' => $this->salaComoEnProduccion('Súper Selectos Ilobasco II', 'Ilobasco'),
            '0214' => $this->salaComoEnProduccion('Súper Selectos Ilobasco', 'Ilobasco'),
            '0220' => $this->salaComoEnProduccion('Súper Selectos Sensuntepeque', 'Sensuntepeque'),
        ];

        $identidadAntes = collect($salas)->map(
            fn (ClienteSucursal $s) => $s->only(['id', 'nombre', 'departamento_id', 'municipio_id', 'distrito_id'])
        )->all();

        $this->correrMigracion();

        $esperado = ['0059' => '11', '0214' => '11', '0220' => '10'];

        foreach ($salas as $codigo => $sala) {
            $sala->refresh();

            // a) La sala es la MISMA fila apuntando a las MISMAS filas de catálogo.
            $this->assertSame(
                $identidadAntes[$codigo],
                $sala->only(['id', 'nombre', 'departamento_id', 'municipio_id', 'distrito_id']),
                "La sala {$codigo} no debía cambiar de identidad ni de referencias."
            );

            // b) Y ahora es coherente, porque el catálogo al que apunta se corrigió.
            $this->assertNull(CoherenciaUbicacion::problemaDe($sala),
                "La sala {$codigo} debería quedar coherente tras corregir el catálogo.");

            $this->assertSame($esperado[$codigo], $sala->municipio->codigo,
                "La sala {$codigo} debería serializar el municipio {$esperado[$codigo]}.");
        }
    }

    // ---------------------------------------------------------------------
    // 7. El catálogo activo es el oficial, verificado
    // ---------------------------------------------------------------------

    public function test_el_catalogo_activo_tiene_la_version_y_el_sha_esperados(): void
    {
        $this->assertSame('2026-07-01', CatalogoOficialMh::version(),
            'La versión activa debe ser la vigente desde el 1 de julio de 2026.');

        $this->assertSame(
            'e86d7edc503d876564cd2bf9b251fb100f838199330c0c513048f4075669b2c6',
            CatalogoOficialMh::sha256Esperado(),
            'El SHA-256 registrado no es el del catálogo oficial.'
        );

        // El archivo en disco es EXACTAMENTE ese, no uno editado a mano.
        $ruta = CatalogoOficialMh::rutaVerificada();
        $this->assertFileExists($ruta);
        $this->assertSame(CatalogoOficialMh::sha256Esperado(), hash_file('sha256', $ruta));
    }

    public function test_se_conserva_versionada_la_revision_anterior(): void
    {
        $this->assertSame(['2026-05', '2026-07-01'], CatalogoOficialMh::versiones(),
            'La revisión de mayo debe seguir registrada para poder reproducir DTE anteriores.');

        $this->assertFileExists(CatalogoOficialMh::ruta('2026-05'));
        $this->assertTrue(CatalogoOficialMh::integro('2026-05'),
            'El archivo de mayo debe conservarse íntegro, no solo declarado.');
    }

    public function test_un_catalogo_alterado_no_se_importa(): void
    {
        config()->set('catalogos_mh.versiones.2026-07-01.sha256', str_repeat('a', 64));

        $this->expectExceptionMessageMatches('/NO coincide con su SHA-256/');
        CatalogoOficialMh::rutaVerificada();
    }

    // ---------------------------------------------------------------------
    // CAT-027: el recinto fiscal nuevo
    // ---------------------------------------------------------------------

    public function test_cat027_incorpora_el_codigo_43(): void
    {
        $recinto = CatalogoMh::where('cat', '027')->where('codigo', '43')->first();

        $this->assertNotNull($recinto, 'CAT-027 debe incluir el código 43 del catálogo vigente.');
        $this->assertSame('Z.F. INHDELVA', $recinto->valor);

        // Y no se perdió ninguno de los que ya estaban.
        $this->assertSame(48, CatalogoMh::where('cat', '027')->count());
        $this->assertTrue(CatalogoMh::where('cat', '027')->where('codigo', '42')->exists());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Genera un CCF real para una sala del distrito indicado y devuelve la dirección del
     * receptor tal como sale en el JSON oficial.
     *
     * @return array<string, string>
     */
    private function direccionReceptorDe(string $nombreDistrito): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->salaComoEnProduccion('Sala '.$nombreDistrito, $nombreDistrito, $cliente);

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
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);

        app(DteGeneracionService::class)->generar($dte->refresh());

        /** @var Dte $dte */
        $json = json_decode(Storage::disk('local')->get($dte->refresh()->json_generado_path), true);

        return $json['receptor']['direccion'];
    }

    /**
     * Sala con la MISMA forma que las productivas: apunta a la fila de `municipios` cuyo
     * `nombre` es el del distrito (herencia de la reforma 2024) y al distrito homónimo.
     */
    private function salaComoEnProduccion(string $nombre, string $distrito, ?Cliente $cliente = null): ClienteSucursal
    {
        $cabanas = Departamento::where('codigo', '09')->firstOrFail();
        $filaDistrito = Distrito::where('departamento_id', $cabanas->id)
            ->where('nombre', $distrito)->firstOrFail();
        $filaMunicipio = Municipio::where('departamento_id', $cabanas->id)
            ->where('nombre', $distrito)->firstOrFail();

        return ClienteSucursal::factory()->create([
            'cliente_id' => ($cliente ?? Cliente::factory()->contribuyente()->create())->id,
            'nombre' => $nombre,
            'departamento_id' => $cabanas->id,
            'municipio_id' => $filaMunicipio->id,
            'distrito_id' => $filaDistrito->id,
        ]);
    }

    /**
     * Deja Cabañas EXACTAMENTE como estaba antes del hotfix (catálogo de mayo invertido),
     * para poder demostrar qué corrige la migración. No toca ningún otro departamento.
     */
    private function invertirCabanasComoAntesDelHotfix(): void
    {
        $cabanasId = Departamento::where('codigo', '09')->value('id');

        DB::table('distritos')->where('departamento_id', $cabanasId)
            ->where('municipio', 'Cabañas Este')->update(['municipio_codigo' => '11']);
        DB::table('distritos')->where('departamento_id', $cabanasId)
            ->where('municipio', 'Cabañas Oeste')->update(['municipio_codigo' => '10']);

        DB::table('municipios')->where('departamento_id', $cabanasId)
            ->where('nombre', 'Sensuntepeque')->update(['codigo' => '11']);
        DB::table('municipios')->where('departamento_id', $cabanasId)
            ->where('nombre', 'Ilobasco')->update(['codigo' => '10']);

        Municipio::olvidarNombresFiscales();
    }

    /** Ejecuta la migración del hotfix tal cual la correría `php artisan migrate`. */
    private function correrMigracion(): void
    {
        static $migracion = null;

        $migracion ??= require database_path(
            'migrations/2026_09_03_090000_corregir_cat013_cabanas_catalogo_julio_2026.php'
        );

        /** @var Migration $migracion */
        $migracion->up();
    }

    /** Huella completa de Cabañas, incluida `updated_at`: detecta cualquier escritura. */
    private function huellaCabanas(): array
    {
        $cabanasId = Departamento::where('codigo', '09')->value('id');

        return [
            'distritos' => $this->huellaDistritos($cabanasId, dentro: true),
            'municipios' => $this->huellaMunicipios($cabanasId, dentro: true),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function huellaDistritos(?int $departamentoId, bool $dentro): array
    {
        return DB::table('distritos')
            ->when($dentro,
                fn ($q) => $q->where('departamento_id', $departamentoId),
                fn ($q) => $q->where('departamento_id', '!=', $departamentoId))
            ->orderBy('id')
            ->get(['id', 'municipio', 'municipio_codigo', 'codigo', 'updated_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function huellaMunicipios(?int $departamentoId, bool $dentro): array
    {
        return DB::table('municipios')
            ->when($dentro,
                fn ($q) => $q->where('departamento_id', $departamentoId),
                fn ($q) => $q->where('departamento_id', '!=', $departamentoId))
            ->orderBy('id')
            ->get(['id', 'nombre', 'codigo', 'updated_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function codigoDistrito(string $nombre): ?string
    {
        return Distrito::whereHas('departamento', fn ($q) => $q->where('codigo', '09'))
            ->where('nombre', $nombre)->value('municipio_codigo');
    }

    private function codigoMunicipio(string $nombre): ?string
    {
        return Municipio::whereHas('departamento', fn ($q) => $q->where('codigo', '09'))
            ->where('nombre', $nombre)->value('codigo');
    }
}
