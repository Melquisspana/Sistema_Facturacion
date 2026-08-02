<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaTraslado;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\DocumentoOrigen;
use App\Support\Planta\MovimientoQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Historial del libro mayor: qué muestra, cómo ordena y qué NO toca.
 *
 * Dos afirmaciones son el núcleo de estas pruebas:
 *
 *  1. Preguntar por un DOCUMENTO devuelve el documento entero —todas sus líneas
 *     y los dos lados de cada par compensado—, no una línea suelta. Media
 *     operación en pantalla hace creer que el inventario no cuadra.
 *  2. Una REVERSIÓN no se disfraza de hecho físico. El par destino->origen que
 *     compensa un traslado recibido se parece a un traslado, y si se contase
 *     como tal, «cuánto viajó de Casa a Fábrica» daría el doble.
 */
class PlantaMovimientosConsultaTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    /** Traslado completo: enviado, recibido y después reversado. */
    private function trasladoReversado(): PlantaTraslado
    {
        $admin = $this->admin();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $admin);
        $this->servicioTraslado()->recibir($traslado->refresh(), $admin);
        $this->servicioTraslado()->reversar($traslado->refresh(), 'La mercancía volvió por avería', $admin);

        return $traslado->refresh();
    }

    /** Recepción de dos líneas: dos movimientos, un solo documento. */
    private function recepcionDeDosLineas(): PlantaRecepcion
    {
        $admin = $this->admin();
        $ubicacion = $this->bodega();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($this->insumoConLotes()),
            $this->linea($this->insumoConLotes(), ['cantidad_recibida' => '3']),
        ]), $admin);

        return $this->servicioRecepcion()->confirmar($recepcion, $admin);
    }

    private function huellaExistencias(): array
    {
        $fila = DB::table('planta_existencias')
            ->selectRaw(
                'COUNT(*) as filas, '
                .'COALESCE(SUM(CAST(ROUND(cantidad * 10000) AS INTEGER)), 0) as suma, '
                .'COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'filas' => (int) $fila->filas,
            'suma' => bcdiv((string) (int) $fila->suma, '10000', 4),
            'max_id' => (int) $fila->max_id,
        ];
    }

    // ---------------------------------------------------------------
    // Orden
    // ---------------------------------------------------------------

    public function test_ordena_por_fecha_efectiva_y_luego_por_id_descendente(): void
    {
        $this->recepcionDeDosLineas();
        $this->trasladoReversado();

        $filas = (new MovimientoQuery)->paginar()->items();

        $this->assertGreaterThan(3, count($filas));

        for ($i = 1; $i < count($filas); $i++) {
            $anterior = $filas[$i - 1];
            $actual = $filas[$i];

            $comparacion = $anterior->fecha_efectiva->toDateString() <=> $actual->fecha_efectiva->toDateString();

            $this->assertGreaterThanOrEqual(0, $comparacion, 'La fecha efectiva debe ir descendente.');

            if ($comparacion === 0) {
                $this->assertGreaterThan($actual->id, $anterior->id, 'A igual fecha, el id desempata descendente.');
            }
        }
    }

    public function test_el_orden_es_estable_entre_cargas(): void
    {
        $this->recepcionDeDosLineas();
        $this->trasladoReversado();

        $primera = collect((new MovimientoQuery)->paginar()->items())->map(fn ($m) => $m->id)->all();
        $segunda = collect((new MovimientoQuery)->paginar()->items())->map(fn ($m) => $m->id)->all();

        $this->assertSame($primera, $segunda);
    }

    // ---------------------------------------------------------------
    // Filtro por documento
    // ---------------------------------------------------------------

    public function test_el_filtro_por_documento_devuelve_todas_las_lineas_no_una(): void
    {
        $recepcion = $this->recepcionDeDosLineas();
        $this->trasladoReversado();

        $filas = (new MovimientoQuery([
            'documento' => 'recepcion',
            'documento_id' => $recepcion->id,
        ]))->paginar();

        // Dos líneas, dos movimientos, y todos del mismo documento.
        $this->assertSame(2, $filas->total());

        foreach ($filas->items() as $movimiento) {
            $this->assertSame(PlantaRecepcion::class, $movimiento->documento_type);
            $this->assertSame($recepcion->id, (int) $movimiento->documento_id);
        }

        // Y ninguna línea se quedó fuera respecto de lo que hay en el mayor.
        $this->assertSame(
            PlantaMovimiento::where('documento_type', PlantaRecepcion::class)
                ->where('documento_id', $recepcion->id)->count(),
            $filas->total(),
        );
    }

    /**
     * Un cambio de disponibilidad escribe un PAR compensado: -retenido y
     * +disponible. Preguntar por ese documento debe devolver los dos lados, o el
     * historial parecería no cuadrar.
     */
    public function test_el_filtro_por_documento_incluye_los_dos_lados_del_par(): void
    {
        $admin = $this->admin();
        $recepcion = $this->saldoRetenido();

        $cambio = $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, '200', EstadoDisponibilidad::Disponible),
            $admin,
        );
        $this->servicioCambio()->confirmar($cambio, $admin);

        $filas = (new MovimientoQuery([
            'documento' => 'disponibilidad',
            'documento_id' => $cambio->id,
        ]))->paginar();

        $this->assertSame(2, $filas->total());

        $estados = collect($filas->items())->map(fn ($m) => $m->estado->value)->sort()->values()->all();
        $this->assertSame(['disponible', 'retenido'], $estados);

        // Suman cero: el inventario físico no cambió, solo su disponibilidad.
        $suma = collect($filas->items())->reduce(fn (string $acc, $m) => bcadd($acc, (string) $m->cantidad, 4), '0.0000');
        $this->assertSame(0, bccomp($suma, '0', 4));

        // Y comparten grupo.
        $this->assertCount(1, collect($filas->items())->pluck('grupo_uuid')->unique());
    }

    public function test_el_filtro_por_tipo_de_documento_sin_id_devuelve_todos_los_de_ese_tipo(): void
    {
        $this->recepcionDeDosLineas();
        $this->recepcionDeDosLineas();
        $this->trasladoReversado();

        $filas = (new MovimientoQuery(['documento' => 'recepcion']))->paginar();

        // Cuatro líneas de recepción propias más la del escenario de traslado.
        $this->assertSame(
            PlantaMovimiento::where('documento_type', PlantaRecepcion::class)->count(),
            $filas->total(),
        );

        foreach ($filas->items() as $movimiento) {
            $this->assertSame(PlantaRecepcion::class, $movimiento->documento_type);
        }
    }

    // ---------------------------------------------------------------
    // Traslados frente a reversiones
    // ---------------------------------------------------------------

    public function test_las_reversiones_se_distinguen_de_los_traslados_fisicos(): void
    {
        $this->trasladoReversado();

        $fisicos = (new MovimientoQuery(['naturaleza' => MovimientoQuery::NATURALEZA_OPERATIVO]))->paginar();
        $reversiones = (new MovimientoQuery(['naturaleza' => MovimientoQuery::NATURALEZA_REVERSION]))->paginar();

        $this->assertGreaterThan(0, $reversiones->total());

        foreach ($fisicos->items() as $movimiento) {
            $this->assertFalse($movimiento->tipo->esReversion(), 'La naturaleza operativa no incluye reversiones.');
        }

        foreach ($reversiones->items() as $movimiento) {
            $this->assertTrue($movimiento->tipo->esReversion());
            // La invariante del mayor: toda reversión apunta a lo que deshace.
            $this->assertNotNull($movimiento->movimiento_revertido_id);
        }
    }

    /**
     * La pregunta que motiva la separación: cuánto viajó DE VERDAD. Se responde
     * filtrando los tipos de traslado físico, y la compensación de la reversión
     * no debe colarse en el resultado.
     */
    public function test_lo_que_viajo_de_verdad_no_incluye_la_compensacion(): void
    {
        $this->trasladoReversado();

        $envios = (new MovimientoQuery(['tipo' => TipoMovimientoPlanta::TrasladoEnvio->value]))->paginar();

        foreach ($envios->items() as $movimiento) {
            $this->assertSame(TipoMovimientoPlanta::TrasladoEnvio, $movimiento->tipo);
            $this->assertFalse($movimiento->tipo->esReversion());
        }

        // La reversión del traslado recibido existe y NO se tipa como traslado.
        $this->assertTrue(
            PlantaMovimiento::where('tipo', TipoMovimientoPlanta::ReversionTrasladoRecepcion->value)->exists(),
        );
    }

    // ---------------------------------------------------------------
    // Resto de filtros
    // ---------------------------------------------------------------

    public function test_los_filtros_de_insumo_lote_ubicacion_estado_y_usuario_acotan(): void
    {
        $recepcion = $this->recepcionDeDosLineas();
        $this->trasladoReversado();

        $bucket = $this->bucketDe($recepcion);

        $this->assertSame(1, (new MovimientoQuery(['insumo' => $bucket->insumoId]))->paginar()->total());
        $this->assertSame(1, (new MovimientoQuery(['lote' => $bucket->loteId]))->paginar()->total());
        $this->assertSame(2, (new MovimientoQuery(['ubicacion' => $bucket->ubicacionId]))->paginar()->total());

        $todos = (new MovimientoQuery)->paginar()->total();
        $disponibles = (new MovimientoQuery(['estado' => 'disponible']))->paginar()->total();
        $this->assertSame($todos, $disponibles, 'En este escenario todo el movimiento es disponible.');

        $usuario = PlantaMovimiento::whereNotNull('user_id')->value('user_id');
        $this->assertGreaterThan(0, (new MovimientoQuery(['usuario' => $usuario]))->paginar()->total());
    }

    public function test_el_rango_de_fecha_efectiva_acota(): void
    {
        $this->recepcionDeDosLineas();

        // Todo el escenario ocurre el 2026-07-30 (fecha operativa del payload).
        $this->assertGreaterThan(0, (new MovimientoQuery(['desde' => '2026-07-30']))->paginar()->total());
        $this->assertSame(0, (new MovimientoQuery(['desde' => '2026-07-31']))->paginar()->total());
        $this->assertSame(0, (new MovimientoQuery(['hasta' => '2026-07-29']))->paginar()->total());
        $this->assertGreaterThan(0, (new MovimientoQuery([
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]))->paginar()->total());
    }

    public function test_un_filtro_invalido_se_ignora_en_vez_de_reventar(): void
    {
        $this->recepcionDeDosLineas();
        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.movimientos.index', [
                'tipo' => 'inventado',
                'documento' => 'ninguno',
                'desde' => 'ayer',
                'usuario' => 'abc',
            ]))
            ->assertOk();

        $this->assertSame(
            (new MovimientoQuery)->paginar()->total(),
            (new MovimientoQuery(['tipo' => 'inventado', 'desde' => 'ayer']))->paginar()->total(),
        );
    }

    // ---------------------------------------------------------------
    // Documento de origen
    // ---------------------------------------------------------------

    public function test_el_documento_de_origen_se_resuelve_por_mapa_explicito(): void
    {
        $this->assertSame('planta.recepciones.show', DocumentoOrigen::ruta(PlantaRecepcion::class));
        $this->assertSame('Recepción', DocumentoOrigen::etiqueta(PlantaRecepcion::class));
        $this->assertSame('planta.traslados.show', DocumentoOrigen::ruta(PlantaTraslado::class));
    }

    /**
     * El mayor puede contener tipos sin pantalla —documentos de fases futuras o
     * filas escritas por pruebas—. Leer el historial NO puede romperse por eso.
     */
    public function test_un_tipo_de_documento_sin_pantalla_se_muestra_como_texto(): void
    {
        $this->assertNull(DocumentoOrigen::ruta('App\\Models\\Planta\\DocumentoQueNoExiste'));
        $this->assertNull(DocumentoOrigen::permiso('App\\Models\\Planta\\DocumentoQueNoExiste'));
        $this->assertSame('DocumentoQueNoExiste', DocumentoOrigen::etiqueta('App\\Models\\Planta\\DocumentoQueNoExiste'));
        $this->assertSame('Sin documento', DocumentoOrigen::etiqueta(null));
    }

    public function test_la_pantalla_enlaza_el_documento_de_origen(): void
    {
        $recepcion = $this->recepcionDeDosLineas();
        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.movimientos.index'))
            ->assertOk()
            ->assertSee(route('planta.recepciones.show', $recepcion), false)
            ->assertSee('Recepción')
            ->assertSee('#'.$recepcion->numero);
    }

    public function test_los_numeros_de_documento_se_resuelven_en_lote(): void
    {
        $recepcion = $this->recepcionDeDosLineas();
        $movimientos = (new MovimientoQuery)->paginar();

        $numeros = MovimientoQuery::numerosDeDocumento($movimientos);

        $this->assertSame(
            (int) $recepcion->numero,
            $numeros[PlantaRecepcion::class.'#'.$recepcion->id],
        );
    }

    // ---------------------------------------------------------------
    // Solo lectura
    // ---------------------------------------------------------------

    public function test_visitar_y_filtrar_no_escribe_nada_en_inventario(): void
    {
        $this->recepcionDeDosLineas();
        $this->trasladoReversado();
        $this->encenderModulo();

        $antesMayor = $this->huellaMayor();
        $antesExistencias = $this->huellaExistencias();
        $usuario = $this->actingAs($this->admin());

        $usuario->get(route('planta.movimientos.index'))->assertOk();
        $usuario->get(route('planta.movimientos.index', ['naturaleza' => 'reversion']))->assertOk();
        $usuario->get(route('planta.movimientos.index', ['documento' => 'traslado']))->assertOk();
        $usuario->get(route('planta.movimientos.index', ['desde' => '2026-07-01']))->assertOk();

        $this->assertSame($antesMayor, $this->huellaMayor());
        $this->assertSame($antesExistencias, $this->huellaExistencias());
    }

    public function test_la_reconciliacion_sigue_sin_diferencias_despues_de_consultar(): void
    {
        $this->recepcionDeDosLineas();
        $this->trasladoReversado();
        $this->encenderModulo();

        $this->actingAs($this->admin())->get(route('planta.movimientos.index'))->assertOk();

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    // ---------------------------------------------------------------
    // Rendimiento
    // ---------------------------------------------------------------

    public function test_no_hay_n_mas_uno_al_crecer_el_historial(): void
    {
        $this->encenderModulo();
        $admin = $this->admin();

        $this->recepcionDeDosLineas();
        $this->actingAs($admin)->get(route('planta.movimientos.index'))->assertOk();

        $pocos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.movimientos.index'))->assertOk());
        $filasPocos = (new MovimientoQuery)->paginar()->total();

        for ($i = 0; $i < 6; $i++) {
            $this->recepcionDeDosLineas();
        }

        $muchos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.movimientos.index'))->assertOk());
        $filasMuchos = (new MovimientoQuery)->paginar()->total();

        $this->assertGreaterThan($filasPocos, $filasMuchos);
        $this->assertLessThanOrEqual(
            $pocos,
            $muchos,
            "El historial hace {$muchos} consultas con {$filasMuchos} filas y {$pocos} con {$filasPocos}: crece con las filas.",
        );
    }

    private function contarConsultas(callable $accion): int
    {
        $consultas = 0;
        $midiendo = true;

        DB::listen(function () use (&$consultas, &$midiendo): void {
            if ($midiendo) {
                $consultas++;
            }
        });

        $accion();

        $midiendo = false;

        return $consultas;
    }
}
