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
use App\Support\Ubicacion\CoherenciaUbicacion;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * HOTFIX CAT-013 — Ciudad Delgado y Cuscatancingo pertenecen a SAN SALVADOR CENTRO.
 *
 * Auditoría del DTE técnico 353 (Súper Selectos Ciudad Delgado): Hacienda lo rechazó con
 * «[receptor.direccion.distrito] VALOR NO ES PERMITIDO» porque salió con el trío
 * 06/22/19. El distrito era correcto (CAT-008 19 = CIUDAD DELGADO); la agrupación no
 * (CAT-013 22 = SAN SALVADOR ESTE, cuando le toca 23 = SAN SALVADOR CENTRO). El trío
 * correcto es 06/23/19. Cuscatancingo (CAT-008 04) arrastraba el mismo error.
 *
 * El dato malo venía del CSV fuente, única fuente de la relación distrito → municipio
 * (el XLSX oficial trae CAT-008 y CAT-013 como listas planas, sin esa relación).
 *
 * Estas pruebas son PERMANENTES y de no regresión. Cierran el circuito completo en las
 * TRES capas donde el error podía reaparecer:
 *
 *      CSV fuente  →  base de datos  →  salida fiscal del JSON oficial
 *
 * y fijan además que la corrección no se derramó al resto de San Salvador, que la
 * migración se puede volver a correr sin efecto y que ninguna sala cambia de identidad.
 *
 * Ninguna prueba firma ni transmite; el CCF se genera en local con Storage::fake.
 */
class AgrupacionSanSalvadorCentroTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const DEPARTAMENTO = '06'; // CAT-012 San Salvador

    /** Los dos distritos corregidos: nombre => [agrupación 2024, CAT-013, CAT-008]. */
    private const CORREGIDOS = [
        'Ciudad Delgado' => ['San Salvador Centro', '23', '19'],
        'Cuscatancingo' => ['San Salvador Centro', '23', '04'],
    ];

    /**
     * El reparto COMPLETO de los 19 distritos de San Salvador tras la corrección.
     * Es la referencia contra la que se comparan el CSV y la base.
     *
     * @var array<string, array<int, string>>
     */
    private const AGRUPACIONES_SAN_SALVADOR = [
        'San Salvador Norte' => ['Aguilares', 'El Paisnal', 'Guazapa'],
        'San Salvador Oeste' => ['Apopa', 'Nejapa'],
        'San Salvador Este' => ['Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque'],
        'San Salvador Centro' => ['Ayutuxtepeque', 'Ciudad Delgado', 'Cuscatancingo', 'Mejicanos', 'San Salvador'],
        'San Salvador Sur' => ['Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santiago Texacuangos', 'Santo Tomás'],
    ];

    /** Código CAT-013 de cada agrupación de San Salvador. */
    private const CODIGOS_AGRUPACION = [
        'San Salvador Norte' => '20',
        'San Salvador Oeste' => '21',
        'San Salvador Este' => '22',
        'San Salvador Centro' => '23',
        'San Salvador Sur' => '24',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // 1. La salida fiscal: el trío que Hacienda espera
    // ---------------------------------------------------------------------

    public function test_ciudad_delgado_serializa_06_23_19(): void
    {
        $direccion = $this->direccionReceptorDe('Ciudad Delgado');

        $this->assertSame('06', $direccion['departamento'], 'San Salvador es el CAT-012 06.');
        $this->assertSame('23', $direccion['municipio'],
            'Ciudad Delgado es San Salvador Centro = CAT-013 23; con 22 el MH rechaza el CCF.');
        $this->assertSame('19', $direccion['distrito'], 'Ciudad Delgado es el CAT-008 19.');
    }

    public function test_cuscatancingo_serializa_06_23_04(): void
    {
        $direccion = $this->direccionReceptorDe('Cuscatancingo');

        $this->assertSame('06', $direccion['departamento']);
        $this->assertSame('23', $direccion['municipio'], 'Cuscatancingo también es San Salvador Centro.');
        $this->assertSame('04', $direccion['distrito'], 'Cuscatancingo es el CAT-008 04.');
    }

    /** Soyapango sigue siendo San Salvador Este: la corrección no se derramó. */
    public function test_soyapango_sigue_serializando_06_22_17(): void
    {
        $direccion = $this->direccionReceptorDe('Soyapango');

        $this->assertSame('06', $direccion['departamento']);
        $this->assertSame('22', $direccion['municipio'], 'Soyapango sí es San Salvador Este.');
        $this->assertSame('17', $direccion['distrito']);
    }

    // ---------------------------------------------------------------------
    // 2. El CSV fuente: donde nació el error
    // ---------------------------------------------------------------------

    public function test_el_csv_fuente_agrupa_los_dos_distritos_en_san_salvador_centro(): void
    {
        $csv = $this->sanSalvadorSegunElCsv();

        foreach (self::CORREGIDOS as $distrito => [$agrupacion]) {
            $this->assertSame($agrupacion, $csv[$distrito] ?? null,
                "El CSV fuente debe agrupar {$distrito} en {$agrupacion}: es la ÚNICA fuente de "
                .'la relación distrito → municipio, porque el XLSX oficial no la trae.');
        }
    }

    public function test_el_csv_y_la_base_dicen_exactamente_lo_mismo(): void
    {
        $csv = $this->sanSalvadorSegunElCsv();

        $base = Distrito::where('departamento_id', $this->departamentoId())
            ->orderBy('nombre')
            ->pluck('municipio', 'nombre')
            ->all();
        ksort($base);

        $this->assertSame($csv, $base,
            'La base sembrada debe reproducir el CSV fuente distrito por distrito.');

        // Y ese reparto es el esperado, no simplemente "los dos coinciden".
        $esperado = [];
        foreach (self::AGRUPACIONES_SAN_SALVADOR as $agrupacion => $distritos) {
            foreach ($distritos as $distrito) {
                $esperado[$distrito] = $agrupacion;
            }
        }
        ksort($esperado);

        $this->assertSame($esperado, $csv);
    }

    // ---------------------------------------------------------------------
    // 3. La base: cada distrito en su agrupación, con su código CAT-013
    // ---------------------------------------------------------------------

    public function test_los_19_distritos_de_san_salvador_estan_en_su_agrupacion_correcta(): void
    {
        $distritos = Distrito::where('departamento_id', $this->departamentoId())->get();

        $this->assertCount(19, $distritos, 'San Salvador tiene exactamente 19 distritos.');

        foreach (self::AGRUPACIONES_SAN_SALVADOR as $agrupacion => $nombres) {
            $codigo = self::CODIGOS_AGRUPACION[$agrupacion];

            foreach ($nombres as $nombre) {
                $distrito = $distritos->firstWhere('nombre', $nombre);

                $this->assertNotNull($distrito, "Falta el distrito {$nombre} en San Salvador.");
                $this->assertSame($agrupacion, $distrito->municipio, "{$nombre}: agrupación equivocada.");
                $this->assertSame($codigo, $distrito->municipio_codigo, "{$nombre}: CAT-013 equivocado.");
            }
        }

        // Reparto por código: 3 Norte / 2 Oeste / 4 Este / 5 Centro / 5 Sur.
        $porCodigo = $distritos->groupBy('municipio_codigo')->map->count()->all();
        ksort($porCodigo);
        $this->assertSame(['20' => 3, '21' => 2, '22' => 4, '23' => 5, '24' => 5], $porCodigo);
    }

    public function test_el_cat008_de_los_dos_distritos_corregidos_no_cambia(): void
    {
        foreach (self::CORREGIDOS as $nombre => [, , $cat008]) {
            $this->assertSame($cat008, $this->distrito($nombre)->codigo,
                "{$nombre}: su CAT-008 nunca estuvo mal y no debía tocarse.");
        }
    }

    public function test_el_catalogo_oficial_confirma_los_codigos_usados(): void
    {
        $this->assertSame('SAN SALVADOR CENTRO',
            CatalogoMh::where('cat', '013')->where('codigo', '23')
                ->where('valor', 'like', 'SAN SALVADOR%')->value('valor'));

        $this->assertSame('19',
            CatalogoMh::where('cat', '008')->where('valor', 'CIUDAD DELGADO')->value('codigo'));

        // Y el nombre fiscal que ve la interfaz sale del mismo lado.
        $this->assertSame('San Salvador Centro',
            $this->municipioHomonimo('Ciudad Delgado')->nombreFiscal());
    }

    // ---------------------------------------------------------------------
    // 4. La migración: corrige, no se derrama y es idempotente
    // ---------------------------------------------------------------------

    public function test_la_migracion_corrige_el_estado_agrupado_en_el_este(): void
    {
        $this->agruparEnElEsteComoAntesDelHotfix();

        // Punto de partida: el error que había en producción.
        foreach (array_keys(self::CORREGIDOS) as $nombre) {
            $this->assertSame('San Salvador Este', $this->distrito($nombre)->municipio);
            $this->assertSame('22', $this->distrito($nombre)->municipio_codigo);
            $this->assertSame('22', $this->municipioHomonimo($nombre)->codigo);
        }

        $this->correrMigracion();

        foreach (array_keys(self::CORREGIDOS) as $nombre) {
            $this->assertSame('San Salvador Centro', $this->distrito($nombre)->municipio);
            $this->assertSame('23', $this->distrito($nombre)->municipio_codigo);
            $this->assertSame('23', $this->municipioHomonimo($nombre)->codigo,
                "La fila de municipios {$nombre} es la que viaja en direccion.municipio.");
        }
    }

    public function test_la_migracion_es_idempotente(): void
    {
        $this->agruparEnElEsteComoAntesDelHotfix();

        $this->correrMigracion();
        $despuesDeLaPrimera = $this->huellaSanSalvador();

        // La segunda corrida no debe escribir nada: ni valores, ni `updated_at`.
        $this->correrMigracion();

        $this->assertSame($despuesDeLaPrimera, $this->huellaSanSalvador(),
            'Volver a correr la migración no debe cambiar una sola fila.');

        // Y el estado final es el correcto, no simplemente "estable".
        $this->assertSame('23', $this->distrito('Ciudad Delgado')->municipio_codigo);
        $this->assertSame('23', $this->distrito('Cuscatancingo')->municipio_codigo);
    }

    /** Sobre una base ya correcta (CSV corregido) la migración no escribe nada. */
    public function test_la_migracion_no_escribe_sobre_una_base_ya_correcta(): void
    {
        $antes = $this->huellaSanSalvador();

        $this->correrMigracion();

        $this->assertSame($antes, $this->huellaSanSalvador(),
            'Una base sembrada del CSV corregido ya nace bien: la migración no debe tocarla.');
    }

    public function test_las_otras_agrupaciones_de_san_salvador_quedan_intactas(): void
    {
        $intactos = ['Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque', 'Aguilares',
            'El Paisnal', 'Guazapa', 'Apopa', 'Nejapa', 'Ayutuxtepeque', 'Mejicanos',
            'San Salvador', 'Panchimalco', 'Rosario de Mora', 'San Marcos',
            'Santiago Texacuangos', 'Santo Tomás'];

        $antes = $this->huellaDistritosPorNombre($intactos);

        $this->agruparEnElEsteComoAntesDelHotfix();
        $this->correrMigracion();

        $this->assertSame($antes, $this->huellaDistritosPorNombre($intactos),
            'Los otros 17 distritos de San Salvador no debían moverse.');
    }

    public function test_la_migracion_no_toca_ubicaciones_fuera_de_san_salvador(): void
    {
        $sanSalvadorId = $this->departamentoId();

        $antesDistritos = $this->huellaDistritos($sanSalvadorId, dentro: false);
        $antesMunicipios = $this->huellaMunicipios($sanSalvadorId, dentro: false);

        $this->agruparEnElEsteComoAntesDelHotfix();
        $this->correrMigracion();

        $this->assertSame($antesDistritos, $this->huellaDistritos($sanSalvadorId, dentro: false),
            'Ningún distrito fuera de San Salvador debía cambiar.');
        $this->assertSame($antesMunicipios, $this->huellaMunicipios($sanSalvadorId, dentro: false),
            'Ningún municipio fuera de San Salvador debía cambiar.');
    }

    public function test_los_distritos_codigo_cat008_no_se_modifican_en_ninguna_fila(): void
    {
        $antes = Distrito::orderBy('id')->pluck('codigo', 'id')->all();

        $this->agruparEnElEsteComoAntesDelHotfix();
        $this->correrMigracion();

        $this->assertSame($antes, Distrito::orderBy('id')->pluck('codigo', 'id')->all(),
            'La migración no debe tocar distritos.codigo (CAT-008) en ninguna fila.');
    }

    // ---------------------------------------------------------------------
    // 5. La sala 173 queda coherente SIN cambiar de identidad
    // ---------------------------------------------------------------------

    /**
     * Réplica de la sala productiva 173 «Súper Selectos Ciudad Delgado», que ya apunta a
     * las entidades correctas (`municipios` y `distritos` de Ciudad Delgado). La
     * corrección actúa sobre el CÓDIGO del catálogo referenciado, no sobre la sala: la
     * fila no se reasigna ni por nombre ni de ninguna otra forma.
     */
    public function test_la_sala_de_ciudad_delgado_queda_coherente_sin_reasignarse(): void
    {
        $this->agruparEnElEsteComoAntesDelHotfix();

        $sala = $this->salaComoEnProduccion('Súper Selectos Ciudad Delgado', 'Ciudad Delgado');
        $identidadAntes = $sala->only(['id', 'nombre', 'departamento_id', 'municipio_id', 'distrito_id']);

        $this->correrMigracion();
        $sala->refresh();

        $this->assertSame($identidadAntes,
            $sala->only(['id', 'nombre', 'departamento_id', 'municipio_id', 'distrito_id']),
            'La sala no debía cambiar de identidad ni de referencias.');

        $this->assertNull(CoherenciaUbicacion::problemaDe($sala),
            'Corregido el catálogo, la sala debe quedar coherente.');

        $this->assertSame('23', $sala->municipio->codigo);
        $this->assertSame('19', $sala->distrito->codigo);
    }

    // ---------------------------------------------------------------------
    // 6. El catálogo completo sigue sano
    // ---------------------------------------------------------------------

    public function test_el_vinculo_distrito_municipio_sigue_siendo_inequivoco(): void
    {
        $this->assertSame(262, Distrito::count());
        $this->assertSame(0, Distrito::whereNull('municipio_codigo')->count());

        // Siguen siendo 44 agrupaciones: mover dos distritos de una a otra no crea ni
        // destruye ninguna (San Salvador Este conserva 4 distritos).
        $this->assertCount(44, VinculaMunicipioDistrito::mapaNombreACodigo());

        $agrupaciones = Distrito::get(['departamento_id', 'municipio_codigo'])
            ->map(fn (Distrito $d) => $d->departamento_id.'-'.$d->municipio_codigo)
            ->unique();
        $this->assertCount(44, $agrupaciones);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Agrupación de cada distrito de San Salvador SEGÚN EL CSV FUENTE, leído del archivo
     * real del repo (no de la base).
     *
     * @return array<string, string>
     */
    private function sanSalvadorSegunElCsv(): array
    {
        $lineas = preg_split('/\r\n|\r|\n/',
            trim((string) File::get(database_path('data/distritos_el_salvador_2024.csv'))));
        array_shift($lineas); // encabezado

        $mapa = [];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            [$depto, $municipio, $distrito] = array_pad(str_getcsv($linea), 3, null);
            if (trim((string) $depto) !== self::DEPARTAMENTO) {
                continue;
            }
            $mapa[trim((string) $distrito)] = trim((string) $municipio);
        }
        ksort($mapa);

        return $mapa;
    }

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
     * Sala con la MISMA forma que la productiva 173: apunta a la fila de `municipios`
     * cuyo `nombre` es el del distrito (herencia de la reforma 2024) y al distrito
     * homónimo.
     */
    private function salaComoEnProduccion(string $nombre, string $distrito, ?Cliente $cliente = null): ClienteSucursal
    {
        return ClienteSucursal::factory()->create([
            'cliente_id' => ($cliente ?? Cliente::factory()->contribuyente()->create())->id,
            'nombre' => $nombre,
            'departamento_id' => $this->departamentoId(),
            'municipio_id' => $this->municipioHomonimo($distrito)->id,
            'distrito_id' => $this->distrito($distrito)->id,
        ]);
    }

    /**
     * Deja los dos distritos EXACTAMENTE como estaban antes del hotfix (agrupados en San
     * Salvador Este), para poder demostrar qué corrige la migración. No toca ningún otro
     * departamento ni las otras agrupaciones de San Salvador.
     */
    private function agruparEnElEsteComoAntesDelHotfix(): void
    {
        DB::table('distritos')
            ->where('departamento_id', $this->departamentoId())
            ->whereIn('nombre', array_keys(self::CORREGIDOS))
            ->update(['municipio' => 'San Salvador Este', 'municipio_codigo' => '22']);

        DB::table('municipios')
            ->where('departamento_id', $this->departamentoId())
            ->whereIn('nombre', array_keys(self::CORREGIDOS))
            ->update(['codigo' => '22']);

        Municipio::olvidarNombresFiscales();
    }

    /** Ejecuta la migración del hotfix tal cual la correría `php artisan migrate`. */
    private function correrMigracion(): void
    {
        static $migracion = null;

        $migracion ??= require database_path(
            'migrations/2026_09_04_090000_corregir_cat013_ciudad_delgado_y_cuscatancingo.php'
        );

        /** @var Migration $migracion */
        $migracion->up();
    }

    /** Huella completa de San Salvador, incluida `updated_at`: detecta cualquier escritura. */
    private function huellaSanSalvador(): array
    {
        return [
            'distritos' => $this->huellaDistritos($this->departamentoId(), dentro: true),
            'municipios' => $this->huellaMunicipios($this->departamentoId(), dentro: true),
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

    /**
     * @param  array<int, string>  $nombres
     * @return array<int, array<string, mixed>>
     */
    private function huellaDistritosPorNombre(array $nombres): array
    {
        return DB::table('distritos')
            ->where('departamento_id', $this->departamentoId())
            ->whereIn('nombre', $nombres)
            ->orderBy('id')
            ->get(['id', 'nombre', 'municipio', 'municipio_codigo', 'codigo', 'updated_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function departamentoId(): int
    {
        return (int) Departamento::where('codigo', self::DEPARTAMENTO)->value('id');
    }

    private function distrito(string $nombre): Distrito
    {
        return Distrito::where('departamento_id', $this->departamentoId())
            ->where('nombre', $nombre)->firstOrFail();
    }

    /** La fila de `municipios` homónima del distrito: la que referencian las salas. */
    private function municipioHomonimo(string $nombre): Municipio
    {
        return Municipio::where('departamento_id', $this->departamentoId())
            ->where('nombre', $nombre)->firstOrFail();
    }
}
