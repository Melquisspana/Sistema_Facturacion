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
use App\Services\Ppq\Exportadores\ExportadorNcCargaMasivaV1;
use App\Services\Ppq\NcExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Formato de CARGA MASIVA de notas de crédito (el que se sube al portal del cliente).
 *
 * Lo que protege no es el aspecto del Excel sino las cuatro cosas que, si se rompen, el
 * archivo lo rechaza el portal o —peor— lo acepta clasificado mal:
 *
 *  1. Los OCHO encabezados, en su orden y con su ortografía exacta, incluido el doble
 *     espacio de «MES  (en numero)».
 *  2. Que año y mes salgan de la fecha DEL ALBARÁN y no de la de emisión de la nota. La
 *     prueba dorada usa un albarán de un año y un mes distintos a propósito.
 *  3. Que un dato faltante detenga el lote entero con su nombre en el mensaje, en vez de
 *     escribir una celda supuesta.
 *  4. Que los lotes anteriores sigan bajándose con su formato viejo aunque el cliente ya
 *     use este.
 */
class NcExportacionCargaMasivaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    /** Correlativo compartido por todas las tandas de una misma prueba. */
    private int $correlativoNc = 0;

    /** @var array{estab: Establecimiento, pv: PuntoVenta}|null */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Descargar archiva una copia: en pruebas va a un disco fingido.
        Storage::fake((string) config('dte.storage.disk', 'local'));
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

    /** Cliente con perfil que pide el formato de carga masiva. */
    private function cliente(string $formato = 'carga_masiva_nc_v1'): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]);

        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => $formato,
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

    /** Cambia el formato del perfil, como haría el administrador en la ficha del cliente. */
    private function cambiarFormato(Cliente $cliente, string $formato): void
    {
        $cliente->perfilDocumento()->update(['formato_export' => $formato]);
        app(PerfilDocumentoResolver::class)->olvidar();
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
     * Crea N notas de crédito ACEPTADAS con su albarán. La fecha de EMISIÓN y la del
     * ALBARÁN se piden por separado a propósito: son datos distintos y el formato los
     * trata distinto.
     *
     * @param  array{sala?: ?string, tipo?: ?string, fecha?: ?Carbon}  $albaran  datos del albarán (null = sin dato)
     * @return array<int, Dte>
     */
    private function notasAceptadas(
        Cliente $cliente,
        int $cantidad,
        ?Carbon $emitidas = null,
        array $albaran = [],
    ): array {
        $emitidas ??= Carbon::today();
        $sala = array_key_exists('sala', $albaran) ? $albaran['sala'] : '0033';
        $tipo = array_key_exists('tipo', $albaran) ? $albaran['tipo'] : 'AC04';
        $fechaAlbaran = array_key_exists('fecha', $albaran) ? $albaran['fecha'] : $emitidas;

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
            // cuanto el documento pasa a generado.
            $nc->forceFill(['fecha_emision' => $emitidas->toDateString()])->save();
            $this->borradores->acreditarLinea($nc, $lineaOriginal, 6);

            DteAlbaran::create([
                'dte_id' => $nc->id,
                'numero_canonico' => ($tipo ?? 'AC04').'/'.($sala ?? '0000').'/00/'.(3200 + $n),
                'tipo_codigo' => $tipo,
                'sala_codigo' => $sala,
                'numero' => (string) (3200 + $n),
                'fecha' => $fechaAlbaran?->toDateString(),
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

    /**
     * @param  string  $hasta  última columna a leer
     * @return array<int, array<string, string>> celdas [fila][columna] como texto
     */
    private function leer(string $ruta, string $hasta = 'H'): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $celdas = [];
        foreach ($hoja->getRowIterator() as $fila) {
            $n = $fila->getRowIndex();
            foreach (range('A', $hasta) as $col) {
                $celdas[$n][$col] = (string) $hoja->getCell($col.$n)->getValue();
            }
        }

        return $celdas;
    }

    // ------------------------------------------------------------------ doradas

    /**
     * DORADA · los ocho encabezados exactos en la fila 1, y una fila cuyos cinco datos de
     * albarán y cuyo código de generación salen de donde deben.
     *
     * El albarán es de MAYO de un año anterior y la nota se emite HOY: si alguien vuelve a
     * sacar el año o el mes de `fecha_emision`, esta prueba se pone roja.
     */
    public function test_dorada_encabezados_exactos_y_anio_y_mes_del_albaran_no_de_la_nota(): void
    {
        $cliente = $this->cliente();
        $emitida = Carbon::today();
        // Un año y cuatro meses atrás: garantiza que NI el año NI el mes coincidan con los
        // de la emisión, corra la prueba el día que corra.
        $delAlbaran = $emitida->copy()->subYear()->subMonths(4);

        $notas = $this->notasAceptadas($cliente, 1, $emitida, [
            'sala' => '0033',
            'tipo' => 'AC04',
            'fecha' => $delAlbaran,
        ]);
        $nc = $notas[0];

        $servicio = app(NcExportacionService::class);
        $lote = $servicio->crear($cliente, [$nc->id], $this->usuario());
        $celdas = $this->leer($servicio->archivo($lote));

        // 1 · Encabezados: los ocho, en su orden, carácter a carácter.
        $esperados = [
            'A' => 'CODIGO SALA O CD',
            'B' => '# ALBARAN',
            'C' => 'AÑO (ultimos 2 digitos)',
            'D' => 'MES  (en numero)',   // dos espacios: así viene la plantilla
            'E' => 'TIPO ALBARAN',
            'F' => 'COD DE GENERACION DE LA NC ASOCIADA',
            'G' => 'Advaloren (si aplica)',
            'H' => 'Especificos (si aplica)',
        ];
        foreach ($esperados as $col => $titulo) {
            $this->assertSame($titulo, $celdas[1][$col], "El encabezado de la columna {$col} cambió.");
        }

        // Ni una columna más allá de la H.
        $this->assertSame('', $this->leer($servicio->archivo($lote), 'I')[1]['I'] ?? '');

        // 2 · La fila de datos arranca en la 2 y es la única.
        $this->assertArrayHasKey(2, $celdas);
        $this->assertArrayNotHasKey(3, $celdas);

        // 3 · Los datos, cada uno de su fuente.
        $nc->refresh();
        $this->assertSame('0033', $celdas[2]['A'], 'La sala conserva su cero inicial.');
        $this->assertSame($nc->albaran->numero, $celdas[2]['B']);
        $this->assertSame($delAlbaran->format('y'), $celdas[2]['C'], 'El año son los 2 últimos dígitos del AÑO DEL ALBARÁN.');
        $this->assertSame((string) $delAlbaran->month, $celdas[2]['D'], 'El mes es el MES DEL ALBARÁN, no el de la nota.');
        $this->assertSame('AC04', $celdas[2]['E']);
        $this->assertSame($nc->codigo_generacion, $celdas[2]['F'], 'El código de generación es el de la NOTA.');

        // Y explícitamente: NO son los de la fecha de emisión.
        $this->assertNotSame($emitida->format('y'), $celdas[2]['C']);
        $this->assertNotSame((string) $emitida->month, $celdas[2]['D']);

        // 4 · Impuestos: vacíos, no en cero. No se declara lo que no consta.
        $this->assertSame('', $celdas[2]['G']);
        $this->assertSame('', $celdas[2]['H']);
    }

    /**
     * DORADA · un dato faltante detiene el lote ENTERO y lo dice con nombre y apellido.
     * No se exporta «lo que sí está»: las que entraran no podrían volver a salir.
     */
    public function test_dorada_sin_fecha_de_albaran_no_se_genera_nada_y_se_dice_que_falta(): void
    {
        $cliente = $this->cliente();
        $completas = $this->notasAceptadas($cliente, 2);
        $sinFecha = $this->notasAceptadas($cliente, 1, null, ['fecha' => null])[0];

        $servicio = app(NcExportacionService::class);

        // La pantalla lo sabe ANTES de generar nada.
        $faltantes = $servicio->faltantes($cliente, $servicio->pendientes($cliente));
        $this->assertArrayHasKey($sinFecha->id, $faltantes);
        $this->assertStringContainsString('fecha del albarán', implode(' ', $faltantes[$sinFecha->id]));
        foreach ($completas as $ok) {
            $this->assertArrayNotHasKey($ok->id, $faltantes);
        }

        try {
            $servicio->crear(
                $cliente,
                [$completas[0]->id, $completas[1]->id, $sinFecha->id],
                $this->usuario(),
            );
            $this->fail('Una nota sin fecha de albarán no debe poder exportarse.');
        } catch (ValidationException $e) {
            $mensaje = implode(' ', $e->errors()['dtes']);
            $this->assertStringContainsString($sinFecha->numero_control, $mensaje);
            $this->assertStringContainsString('fecha del albarán', $mensaje);
        }

        // Ni lote ni items: el intento no dejó rastro y las tres siguen pendientes.
        $this->assertSame(0, NcExportacion::count());
        $this->assertSame(0, NcExportacionItem::count());
        $this->assertCount(3, $servicio->pendientes($cliente));

        // Las completas sí se exportan solas.
        $lote = $servicio->crear($cliente, [$completas[0]->id, $completas[1]->id], $this->usuario());
        $this->assertSame(2, $lote->items()->count());
    }

    /**
     * DORADA · compatibilidad: un lote generado con el formato viejo se sigue bajando con
     * el formato viejo después de que el cliente pasó al de carga masiva. Lo que ya viajó
     * no se reescribe.
     */
    public function test_dorada_un_lote_anterior_se_regenera_con_su_formato_original(): void
    {
        $cliente = $this->cliente('albaran_nc_v1');
        $viejas = $this->notasAceptadas($cliente, 2);

        $servicio = app(NcExportacionService::class);
        $loteViejo = $servicio->crear($cliente, array_map(fn (Dte $n) => $n->id, $viejas), $this->usuario());
        $this->assertSame('albaran_nc_v1', $loteViejo->formato);

        $antes = $this->leer($servicio->archivo($loteViejo), 'Q');

        // El cliente pasa al formato nuevo.
        $this->cambiarFormato($cliente, 'carga_masiva_nc_v1');

        // El lote viejo se regenera IGUAL: sus 17 columnas, sus bandas, su contenido.
        $despues = $this->leer($servicio->archivo($loteViejo->refresh()), 'Q');
        $this->assertSame($antes, $despues);
        $this->assertSame('INFORMACION DE NOTA DE CREDITO', $despues[1]['A']);
        $this->assertSame('CODIGO PROVEEDOR', $despues[2]['A']);
        $this->assertSame('ADVALOREM', $despues[2]['Q']);

        // Y el lote NUEVO sale con el formato nuevo.
        $nuevas = $this->notasAceptadas($cliente, 1);
        $loteNuevo = $servicio->crear($cliente, [$nuevas[0]->id], $this->usuario());
        $this->assertSame('carga_masiva_nc_v1', $loteNuevo->formato);

        $celdas = $this->leer($servicio->archivo($loteNuevo));
        $this->assertSame('CODIGO SALA O CD', $celdas[1]['A']);
        $this->assertSame('COD DE GENERACION DE LA NC ASOCIADA', $celdas[1]['F']);
        $this->assertArrayHasKey(2, $celdas);
        $this->assertArrayNotHasKey(3, $celdas);
    }

    // --------------------------------------------------------- duplicados y descargas

    /** Una nota ya exportada no vuelve a entrar en otro archivo, y se dice en cuál viajó. */
    public function test_el_control_de_duplicados_sigue_vigente_con_el_formato_nuevo(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 2);
        $servicio = app(NcExportacionService::class);

        $servicio->crear($cliente, [$notas[0]->id], $this->usuario());

        $this->assertCount(1, $servicio->pendientes($cliente));
        $this->assertCount(1, $servicio->yaExportadas($cliente));

        try {
            $servicio->crear($cliente, [$notas[0]->id, $notas[1]->id], $this->usuario());
            $this->fail('Una nota ya exportada no debe poder entrar en un segundo lote.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya entraron en un lote anterior', implode(' ', $e->errors()['dtes']));
        }

        $this->assertSame(1, NcExportacion::count());
        $this->assertSame(1, NcExportacionItem::count());
    }

    /** Bajar el mismo lote otra vez da el mismo archivo y no marca ni una nota más. */
    public function test_el_mismo_lote_se_puede_volver_a_descargar_con_el_mismo_contenido(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 3);
        $servicio = app(NcExportacionService::class);

        $lote = $servicio->crear($cliente, array_map(fn (Dte $n) => $n->id, $notas), $this->usuario());
        $primero = $this->leer($servicio->archivo($lote));

        // Aparecen notas nuevas después de crear el lote: no deben colarse.
        $this->notasAceptadas($cliente, 1);

        $segundo = $this->leer($servicio->archivo($lote->refresh()));

        $this->assertSame($primero, $segundo);
        $this->assertSame(3, $lote->items()->count());
        $this->assertSame(1, NcExportacion::count());
    }

    /** El archivo se puede bajar por la ruta, y la descarga queda registrada. */
    public function test_la_descarga_por_la_ruta_registra_sin_afectar_el_contenido(): void
    {
        $cliente = $this->cliente();
        $notas = $this->notasAceptadas($cliente, 1);
        $servicio = app(NcExportacionService::class);
        $lote = $servicio->crear($cliente, [$notas[0]->id], $this->usuario());

        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertOk();
        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertOk();

        $lote->refresh();
        $this->assertSame(2, $lote->descargas);
        $this->assertSame(1, $lote->items()->count());
    }

    // ------------------------------------------------------------------ pantalla

    /** La pantalla dice qué formato se usa, que se sube al portal y que bajarlo no es entregarlo. */
    public function test_la_pantalla_explica_el_portal_y_que_descargar_no_es_entregar(): void
    {
        $cliente = $this->cliente();
        $this->notasAceptadas($cliente, 1);

        $respuesta = $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk();

        $respuesta->assertSee(ExportadorNcCargaMasivaV1::nombre());
        $respuesta->assertSee('portal de Calleja');
        $respuesta->assertSee('no significa que Calleja');
    }

    /** Lo que le falta a una nota se ve en la lista, antes de generar nada. */
    public function test_la_pantalla_muestra_el_dato_que_falta_antes_de_generar(): void
    {
        $cliente = $this->cliente();
        $this->notasAceptadas($cliente, 1, null, ['fecha' => null]);

        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertSee('no se pueden incluir todavía')
            ->assertSee('fecha del albarán', false);
    }
}
