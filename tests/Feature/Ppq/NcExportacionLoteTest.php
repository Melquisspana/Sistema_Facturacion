<?php

namespace Tests\Feature\Ppq;

use App\Enums\EstadoDte;
use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\NcExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * LOTE de notas de crédito para el formato del cliente: una fila por nota, sin duplicados
 * y regenerable.
 *
 * Un lote NO es un corte diario. Las notas se acumulan durante días o semanas y se
 * exportan cuando toca llenar el formato, así que estas pruebas exigen expresamente que un
 * mismo archivo pueda llevar notas de fechas distintas
 * ({@see test_dorada_un_lote_mezcla_notas_de_tres_fechas_distintas}).
 *
 * Lo demás que protegen no es el formato en sí sino dos cosas que, si se rompen, el
 * cliente lo ve antes que nosotros: que `001065` y `0033` sigan siendo texto con sus
 * ceros, y que una nota no pueda viajar dos veces en dos archivos distintos.
 */
class NcExportacionLoteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    /** Correlativo compartido por todas las tandas de una misma prueba. */
    private int $correlativoNc = 0;

    /**
     * Emisor y correlativos, creados UNA vez por prueba: dos emisores con el mismo
     * establecimiento/punto de venta reiniciarían el correlativo y chocarían en
     * `dtes.numero_interno`, igual que en producción.
     *
     * @var array{estab: Establecimiento, pv: PuntoVenta}|null
     */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        foreach (['ppq.ver', 'ppq.gestionar'] as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findByName('administrador', 'web')->givePermissionTo(['ppq.ver', 'ppq.gestionar']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);

        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '001065',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);

        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value,
            'codigo_externo' => 'AC04',
            'descuento_origen' => OrigenDescuentoNc::Ninguno->value,
        ]);

        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisorUnico(): array
    {
        if ($this->emisor !== null) {
            return $this->emisor;
        }

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return $this->emisor = compact('estab', 'pv');
    }

    /**
     * Crea N notas de crédito ACEPTADAS con su albarán, emitidas en la fecha indicada.
     * Sin fecha, hoy.
     *
     * @return array<int, Dte>
     */
    private function notasAceptadas(Cliente $cliente, int $cantidad, ?Carbon $emitidas = null): array
    {
        $emitidas ??= Carbon::today();
        ['estab' => $estab, 'pv' => $pv] = $this->emisorUnico();

        $producto = Producto::factory()->create([
            'nombre' => 'MANI HORNEADO',
            'precio_unitario' => 1.04,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: 500);
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf);
        $lineaOriginal = $ccf->lineas()->firstOrFail();

        $notas = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $n = ++$this->correlativoNc;

            $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::DevolucionProducto->value]);
            // La fecha de emisión se fija MIENTRAS es borrador: DteObserver la bloquea en
            // cuanto el documento pasa a generado, y con razón. Así el andamiaje respeta
            // la misma inmutabilidad que el flujo real.
            $nc->forceFill(['fecha_emision' => $emitidas->toDateString()])->save();
            $this->borradores->acreditarLinea($nc, $lineaOriginal, 6);

            DteAlbaran::create([
                'dte_id' => $nc->id,
                'numero_canonico' => 'AC04/0033/00/'.(3200 + $n),
                'tipo_codigo' => 'AC04',
                'sala_codigo' => '0033',
                'numero' => (string) (3200 + $n),
                'fecha' => $emitidas->toDateString(),
                'total' => 7.05,
            ]);

            app(DteGeneracionService::class)->generar($nc->refresh());
            $nc->numero_control = 'DTE-05-M001P002-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT);
            $nc->sello_recepcion = '2026SELLO'.str_pad((string) $n, 31, 'X');
            $nc->fecha_procesamiento_mh = now();
            $nc->estado = EstadoDte::Aceptado;
            $nc->save();

            $notas[] = $nc->refresh();
        }

        return $notas;
    }

    /** @return array<string, array<string, string>> celdas [fila][columna] como texto */
    private function leer(string $ruta): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $celdas = [];
        foreach ($hoja->getRowIterator() as $fila) {
            $n = $fila->getRowIndex();
            foreach (range('A', 'Q') as $col) {
                $celdas[$n][$col] = (string) $hoja->getCell($col.$n)->getValue();
            }
        }

        return $celdas;
    }

    // ------------------------------------------------------------------ doradas

    /**
     * DORADA · el caso que define el módulo: un solo formato con notas aceptadas en TRES
     * fechas distintas. Si alguien vuelve a atar el lote a una fecha, esta se pone roja.
     */
    public function test_dorada_un_lote_mezcla_notas_de_tres_fechas_distintas(): void
    {
        $cliente = $this->cliente();

        $viejas = $this->notasAceptadas($cliente, 2, Carbon::today()->subDays(12));
        $medias = $this->notasAceptadas($cliente, 3, Carbon::today()->subDays(5));
        $nuevas = $this->notasAceptadas($cliente, 1, Carbon::today());
        $todas = [...$viejas, ...$medias, ...$nuevas];

        $servicio = app(NcExportacionService::class);

        // Sin filtros aparecen las seis, de la más antigua a la más reciente.
        $pendientes = $servicio->pendientes($cliente);
        $this->assertCount(6, $pendientes);
        $fechas = $pendientes->map(fn (Dte $n) => $n->fecha_emision->toDateString())->all();
        $this->assertSame($fechas, collect($fechas)->sort()->values()->all(), 'Deben venir de la más antigua a la más reciente.');
        $this->assertCount(3, array_unique($fechas));

        $lote = $servicio->crear($cliente, array_map(fn (Dte $n) => $n->id, $todas), $this->usuario());
        $this->assertSame(6, $lote->items()->count());

        $celdas = $this->leer($servicio->archivo($lote));

        // Seis filas de datos (3..8) y ni una más.
        $this->assertArrayHasKey(8, $celdas);
        $this->assertArrayNotHasKey(9, $celdas);

        // Las tres fechas de emisión distintas viajan en el mismo archivo, columna E.
        $fechasExcel = array_unique(array_map(fn (int $f) => $celdas[$f]['E'], range(3, 8)));
        $this->assertCount(3, $fechasExcel, 'El archivo debe contener notas de tres fechas distintas.');
        $this->assertContains(Carbon::today()->subDays(12)->format('d/m/Y'), $fechasExcel);
        $this->assertContains(Carbon::today()->subDays(5)->format('d/m/Y'), $fechasExcel);
        $this->assertContains(Carbon::today()->format('d/m/Y'), $fechasExcel);

        // La más antigua quedó primera también dentro del archivo.
        $this->assertSame(Carbon::today()->subDays(12)->format('d/m/Y'), $celdas[3]['E']);
    }

    /** DORADA · diez notas: diez filas, ceros iniciales intactos y sin repetidos. */
    public function test_dorada_lote_de_diez_notas_produce_diez_filas_sin_perder_ceros(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 10);

        $lote = app(NcExportacionService::class)->crear(
            $cliente,
            array_map(fn (Dte $n) => $n->id, $notas),
            $this->usuario(),
        );

        $this->assertSame(10, $lote->items()->count());

        $celdas = $this->leer(app(NcExportacionService::class)->archivo($lote));

        $this->assertSame('INFORMACION DE NOTA DE CREDITO', $celdas[1]['A']);
        $this->assertSame('INFORMACION DEL ALBARAN', $celdas[1]['F']);
        $this->assertSame('VALORES DE NOTA DE CREDITO', $celdas[1]['K']);
        $this->assertSame('CODIGO PROVEEDOR', $celdas[2]['A']);
        $this->assertSame('TIPO DE ALBARAN', $celdas[2]['G']);
        $this->assertSame('TOTAL', $celdas[2]['O']);

        $this->assertArrayHasKey(12, $celdas);
        $this->assertArrayNotHasKey(13, $celdas);

        $this->assertSame('001065', $celdas[3]['A']);
        $this->assertSame('0033', $celdas[3]['F']);

        $albaranes = array_map(fn (int $f) => $celdas[$f]['H'], range(3, 12));
        $this->assertSame($albaranes, array_unique($albaranes));

        $controles = array_map(fn (int $f) => $celdas[$f]['C'], range(3, 12));
        $this->assertSame($controles, array_unique($controles));
    }

    /** DORADA · regenerar da el mismo contenido y no marca ni una nota más. */
    public function test_dorada_regenerar_el_lote_da_el_mismo_contenido_sin_marcar_mas_documentos(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 4);
        $servicio = app(NcExportacionService::class);

        $lote = $servicio->crear($cliente, array_map(fn (Dte $n) => $n->id, $notas), $this->usuario());

        $primero = $this->leer($servicio->archivo($lote));
        $itemsAntes = $lote->items()->pluck('dte_id')->sort()->values()->all();

        // Aparecen notas nuevas DESPUÉS de crear el lote, incluso de otra fecha.
        $this->notasAceptadas($cliente, 1, Carbon::today()->subDays(3));

        $segundo = $this->leer($servicio->archivo($lote->refresh()));

        $this->assertSame($primero, $segundo);
        $this->assertSame($itemsAntes, $lote->items()->pluck('dte_id')->sort()->values()->all());
        $this->assertSame(4, $lote->items()->count());
        $this->assertSame(1, NcExportacion::count());
    }

    // ------------------------------------------------------------------ filtros

    /** Los filtros acotan lo que se ve; quitarlos devuelve el universo completo. */
    public function test_los_filtros_acotan_pero_no_limitan_el_lote(): void
    {
        $cliente = $this->cliente();
        $viejas = $this->notasAceptadas($cliente, 2, Carbon::today()->subDays(20));
        $this->notasAceptadas($cliente, 3, Carbon::today());
        $servicio = app(NcExportacionService::class);

        $this->assertCount(5, $servicio->pendientes($cliente));

        // Rango de fechas.
        $soloViejas = $servicio->pendientes($cliente, ['hasta' => Carbon::today()->subDays(10)->toDateString()]);
        $this->assertCount(2, $soloViejas);

        // Tipo de albarán.
        $this->assertCount(5, $servicio->pendientes($cliente, ['tipo' => 'AC04']));
        $this->assertCount(0, $servicio->pendientes($cliente, ['tipo' => 'AC02']));

        // Sala.
        $this->assertCount(5, $servicio->pendientes($cliente, ['sala' => '0033']));
        $this->assertCount(0, $servicio->pendientes($cliente, ['sala' => '9999']));

        // Búsqueda por número de albarán y por número de control.
        $unaVieja = $viejas[0];
        $this->assertCount(1, $servicio->pendientes($cliente, ['q' => $unaVieja->albaran->numero]));
        $this->assertCount(1, $servicio->pendientes($cliente, ['q' => $unaVieja->numero_control]));

        // Pero el lote NO queda limitado por el filtro: se pueden incluir todas.
        $lote = $servicio->crear($cliente, $servicio->pendientes($cliente)->pluck('id')->all(), $this->usuario());
        $this->assertSame(5, $lote->items()->count());
    }

    /** Sin albarán no es elegible: faltarían cuatro columnas del formato. */
    public function test_una_nota_sin_albaran_no_aparece_como_pendiente(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 2);
        $notas[0]->albaran()->delete();

        $pendientes = app(NcExportacionService::class)->pendientes($cliente);
        $this->assertCount(1, $pendientes);
        $this->assertNotContains($notas[0]->id, $pendientes->pluck('id')->all());
    }

    // ------------------------------------------------------------------ duplicados

    public function test_una_nota_exportada_deja_de_estar_pendiente(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 3);
        $servicio = app(NcExportacionService::class);

        $this->assertCount(3, $servicio->pendientes($cliente));

        $servicio->crear($cliente, [$notas[0]->id], $this->usuario());

        $pendientes = $servicio->pendientes($cliente);
        $this->assertCount(2, $pendientes);
        $this->assertNotContains($notas[0]->id, $pendientes->pluck('id')->all());
        $this->assertCount(1, $servicio->yaExportadas($cliente));
    }

    public function test_no_se_puede_exportar_dos_veces_la_misma_nota(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 2);
        $servicio = app(NcExportacionService::class);

        $servicio->crear($cliente, [$notas[0]->id], $this->usuario());

        $this->expectException(ValidationException::class);
        $servicio->crear($cliente, [$notas[0]->id, $notas[1]->id], $this->usuario());
    }

    public function test_lote_fallido_no_deja_rastro(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 2);
        $servicio = app(NcExportacionService::class);
        $servicio->crear($cliente, [$notas[0]->id], $this->usuario());

        try {
            $servicio->crear($cliente, [$notas[0]->id, $notas[1]->id], $this->usuario());
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame(1, NcExportacion::count());
        $this->assertSame(1, NcExportacionItem::count());
    }

    // -------------------------------------------------------- valores de la fila

    /**
     * La columna GRAVADO lleva el gravado NETO (el formato no tiene columna de descuento)
     * y TOTAL lleva el total real de la nota como VALOR, no la fórmula `=I` del archivo
     * de muestra. Se comprueba con una avería, que sí lleva descuento.
     */
    public function test_gravado_va_neto_y_total_va_como_valor_no_como_formula(): void
    {
        $cliente = $this->cliente();
        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $cliente->perfilDocumento->id,
            'tipo_nota_credito' => TipoNotaCredito::Averia->value,
            'codigo_externo' => 'AC02',
            'descuento_origen' => OrigenDescuentoNc::Ccf->value,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        ['estab' => $estab, 'pv' => $pv] = $this->emisorUnico();

        $productos = [
            Producto::factory()->create(['nombre' => 'HUEVITOS', 'precio_unitario' => 0.90, 'tipo_impuesto' => TipoImpuesto::Gravado->value]),
            Producto::factory()->create(['nombre' => 'DULCE DE MIEL', 'precio_unitario' => 0.95, 'tipo_impuesto' => TipoImpuesto::Gravado->value]),
            Producto::factory()->create(['nombre' => 'MANI HORNEADO', 'precio_unitario' => 1.04, 'tipo_impuesto' => TipoImpuesto::Gravado->value]),
        ];

        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
        ]);
        foreach ($productos as $p) {
            $this->borradores->agregarLineaDesdeProducto($ccf, $p, cantidad: 20);
        }
        app(DteGeneracionService::class)->generar($ccf);
        $ccf = $this->aceptarCcf($ccf);

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => TipoNotaCredito::Averia->value]);
        foreach ($productos as $p) {
            $this->borradores->agregarProductoNotaCreditoAveria($nc, $p, 1);
        }
        DteAlbaran::create([
            'dte_id' => $nc->id, 'numero_canonico' => 'AC02/0045/00/2270', 'tipo_codigo' => 'AC02',
            'sala_codigo' => '0045', 'numero' => '2270', 'fecha' => Carbon::today()->toDateString(), 'total' => 3.11,
        ]);
        app(DteGeneracionService::class)->generar($nc->refresh());
        $nc->numero_control = 'DTE-05-M001P002-000000000000900';
        $nc->sello_recepcion = '2026SELLOAVERIA'.str_pad('', 25, 'X');
        $nc->fecha_procesamiento_mh = now();
        $nc->estado = EstadoDte::Aceptado;
        $nc->fecha_emision = Carbon::today()->toDateString();
        $nc->save();

        $servicio = app(NcExportacionService::class);
        $lote = $servicio->crear($cliente, [$nc->id], $this->usuario());
        $celdas = $this->leer($servicio->archivo($lote));

        $this->assertSame('001065', $celdas[3]['A']);
        $this->assertSame('ac02', $celdas[3]['G']);       // el formato del cliente lo lleva en minúsculas
        $this->assertSame('0045', $celdas[3]['F']);
        $this->assertSame('2270', $celdas[3]['H']);
        $this->assertSame('3.11', (string) round((float) $celdas[3]['I'], 2));
        $this->assertSame('0', (string) round((float) $celdas[3]['K'], 2));    // exento
        $this->assertSame('2.75', (string) round((float) $celdas[3]['L'], 2)); // GRAVADO NETO, no 2.89
        $this->assertSame('0.36', (string) round((float) $celdas[3]['M'], 2)); // IVA de cabecera
        $this->assertSame('0', (string) round((float) $celdas[3]['N'], 2));    // retención
        $this->assertSame('3.11', (string) round((float) $celdas[3]['O'], 2)); // total REAL

        $this->assertStringNotContainsString('=', $celdas[3]['O']);
        $this->assertSame('', $celdas[3]['P']);
        $this->assertSame('', $celdas[3]['Q']);
    }

    /** Un cliente sin perfil activo no puede exportar, y se dice por qué. */
    public function test_cliente_sin_perfil_no_exporta(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->expectException(ValidationException::class);
        app(NcExportacionService::class)->crear($cliente, [1], $this->usuario());
    }
}
