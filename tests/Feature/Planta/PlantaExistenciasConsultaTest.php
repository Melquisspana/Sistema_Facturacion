<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaTraslado;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\ExistenciaQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla de existencias: qué muestra, qué filtra y qué NO toca.
 *
 * La afirmación central que se prueba aquí es que los tres estados de
 * disponibilidad jamás se suman entre sí. No es una preferencia de formato: el
 * saldo `retenido` existe físicamente pero está fuera de la operación, y un
 * total que lo mezclara con el disponible haría que alguien prometiera
 * mercancía que no puede mover.
 *
 * El escenario se construye SIEMPRE por el camino real —recepciones, cambios de
 * disponibilidad y traslados confirmados— y nunca escribiendo en
 * `planta_existencias`. Un saldo fabricado a mano probaría la pantalla contra
 * datos que el dominio no puede producir.
 */
class PlantaExistenciasConsultaTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    /**
     * Un mismo insumo, lote y ubicación repartido entre los TRES estados:
     * 200 disponibles, 200 retenidos y 100 rechazados.
     *
     * Se llega ahí como se llegaría de verdad: entran 500 retenidos, se liberan
     * 200 y se rechazan 100.
     */
    private function escenarioTresEstados(): PlantaRecepcion
    {
        $admin = $this->admin();
        $recepcion = $this->saldoRetenido();

        $liberacion = $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, '200', EstadoDisponibilidad::Disponible),
            $admin,
        );
        $this->servicioCambio()->confirmar($liberacion, $admin);

        $rechazo = $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Rechazado),
            $admin,
        );
        $this->servicioCambio()->confirmar($rechazo, $admin);

        return $recepcion;
    }

    /** Traslado enviado: deja 200 viajando y el resto quieto en el origen. */
    private function escenarioEnTransito(): array
    {
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');

        $this->servicioTraslado()->enviar($traslado, $this->admin());

        return [$e, $traslado->refresh()];
    }

    /** Huella completa de las dos tablas de inventario. */
    private function huellaInventario(): array
    {
        $existencias = DB::table('planta_existencias')
            ->selectRaw(
                'COUNT(*) as filas, '
                .'COALESCE(SUM(CAST(ROUND(cantidad * 10000) AS INTEGER)), 0) as suma, '
                .'COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'mayor' => $this->huellaMayor(),
            'existencias' => [
                'filas' => (int) $existencias->filas,
                'suma' => bcdiv((string) (int) $existencias->suma, '10000', 4),
                'max_id' => (int) $existencias->max_id,
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Totales por estado
    // ---------------------------------------------------------------

    public function test_los_totales_van_separados_por_estado_y_no_se_mezclan(): void
    {
        $this->escenarioTresEstados();

        $totales = collect((new ExistenciaQuery)->totalesPorEstado())
            ->keyBy(fn (array $t) => $t['estado']->value);

        $this->assertSame('200.0000', $totales['disponible']['total']);
        $this->assertSame('200.0000', $totales['retenido']['total']);
        $this->assertSame('100.0000', $totales['rechazado']['total']);

        // Y NO existe un total agregado: la suma física (500) no se ofrece en
        // ninguna parte, porque no es una cantidad utilizable.
        $this->assertCount(3, $totales);
    }

    public function test_los_totales_son_cadenas_decimales_no_floats(): void
    {
        $this->escenarioTresEstados();

        foreach ((new ExistenciaQuery)->totalesPorEstado() as $total) {
            $this->assertIsString($total['total']);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{4}$/', $total['total']);
        }
    }

    public function test_los_totales_abarcan_todo_el_filtro_y_no_solo_la_pagina(): void
    {
        // Doce buckets disponibles de 500 cada uno, con una página de 5: el total
        // debe ser 6000, no 2500.
        for ($i = 0; $i < 12; $i++) {
            $this->saldoDisponibleEn($this->bodega());
        }

        $consulta = new ExistenciaQuery;
        $pagina = $consulta->paginar(5);
        $totales = collect($consulta->totalesPorEstado())->keyBy(fn (array $t) => $t['estado']->value);

        $this->assertCount(5, $pagina->items());
        $this->assertSame(12, $pagina->total());
        $this->assertSame('6000.0000', $totales['disponible']['total']);
        $this->assertSame(12, $totales['disponible']['buckets']);
    }

    public function test_el_total_respeta_el_filtro_de_estado(): void
    {
        $this->escenarioTresEstados();

        $totales = (new ExistenciaQuery(['estado' => 'retenido']))->totalesPorEstado();

        $this->assertCount(1, $totales);
        $this->assertSame(EstadoDisponibilidad::Retenido, $totales[0]['estado']);
        $this->assertSame('200.0000', $totales[0]['total']);
    }

    // ---------------------------------------------------------------
    // Filtros
    // ---------------------------------------------------------------

    public function test_el_filtro_de_transito_solo_devuelve_saldo_de_un_traslado_activo(): void
    {
        [$e, $traslado] = $this->escenarioEnTransito();

        $enTransito = (new ExistenciaQuery(['transito' => ExistenciaQuery::TRANSITO_SI]))->paginar();

        $this->assertCount(1, $enTransito->items());
        $fila = $enTransito->items()[0];
        $this->assertSame($traslado->id, (int) $fila->planta_traslado_id);
        $this->assertSame($e['transito']->id, (int) $fila->planta_ubicacion_id);
        $this->assertSame('200.0000', (string) $fila->cantidad);
    }

    public function test_lo_que_esta_quieto_no_aparece_como_en_transito(): void
    {
        [$e] = $this->escenarioEnTransito();

        $quieto = (new ExistenciaQuery(['transito' => ExistenciaQuery::TRANSITO_NO]))->paginar();

        foreach ($quieto->items() as $fila) {
            $this->assertSame(0, (int) $fila->planta_traslado_id);
        }

        // El origen conserva lo no enviado y sigue siendo saldo quieto.
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
    }

    /**
     * Un traslado recibido deja su bucket de tránsito en cero pero la fila
     * sobrevive con el id del traslado intacto. Si «en tránsito» se resolviera
     * mirando solo `planta_traslado_id > 0`, esa fila seguiría apareciendo y la
     * pantalla diría que hay mercancía viajando que llegó hace tiempo.
     */
    public function test_un_traslado_ya_recibido_deja_de_estar_en_transito(): void
    {
        [, $traslado] = $this->escenarioEnTransito();

        $this->servicioTraslado()->recibir($traslado, $this->admin());

        $enTransito = (new ExistenciaQuery([
            'transito' => ExistenciaQuery::TRANSITO_SI,
            'saldo' => ExistenciaQuery::SALDO_TODOS,
        ]))->paginar();

        $this->assertCount(0, $enTransito->items());
        $this->assertSame(
            PlantaTraslado::find($traslado->id)->estado->value,
            'recibido',
        );
    }

    public function test_el_saldo_cero_se_oculta_por_defecto_y_aparece_con_su_filtro(): void
    {
        [, $traslado] = $this->escenarioEnTransito();
        $this->servicioTraslado()->recibir($traslado, $this->admin());

        // El bucket de tránsito quedó en 0 y su fila sigue existiendo.
        $ceros = DB::table('planta_existencias')->where('cantidad', 0)->count();
        $this->assertGreaterThan(0, $ceros, 'El escenario debe dejar al menos un bucket en cero.');

        $porDefecto = (new ExistenciaQuery)->paginar();
        $todos = (new ExistenciaQuery(['saldo' => ExistenciaQuery::SALDO_TODOS]))->paginar();

        foreach ($porDefecto->items() as $fila) {
            $this->assertSame(1, bccomp((string) $fila->cantidad, '0', 4), 'El listado por defecto no muestra ceros.');
        }

        $this->assertSame($porDefecto->total() + $ceros, $todos->total());
    }

    public function test_los_filtros_de_insumo_lote_ubicacion_y_tipo_acotan(): void
    {
        $recepcion = $this->escenarioTresEstados();
        $this->saldoDisponibleEn($this->bodega());

        $bucket = $this->bucketDe($recepcion);

        $this->assertSame(3, (new ExistenciaQuery([
            'insumo' => $bucket->insumoId,
            'saldo' => ExistenciaQuery::SALDO_TODOS,
        ]))->paginar()->total());

        $this->assertSame(3, (new ExistenciaQuery([
            'lote' => $bucket->loteId,
            'saldo' => ExistenciaQuery::SALDO_TODOS,
        ]))->paginar()->total());

        $this->assertSame(3, (new ExistenciaQuery([
            'ubicacion' => $bucket->ubicacionId,
            'saldo' => ExistenciaQuery::SALDO_TODOS,
        ]))->paginar()->total());

        // Ambos insumos son materia prima; ninguno es bolsa.
        $this->assertSame(0, (new ExistenciaQuery(['tipo' => 'bolsa']))->paginar()->total());
        $this->assertGreaterThan(0, (new ExistenciaQuery(['tipo' => 'materia_prima']))->paginar()->total());
    }

    public function test_un_filtro_invalido_se_ignora_en_vez_de_reventar(): void
    {
        $this->escenarioTresEstados();

        $this->encenderModulo();

        // Querystring escrito a mano con basura: la pantalla responde igual.
        $this->actingAs($this->admin())
            ->get(route('planta.existencias.index', [
                'estado' => 'inventado',
                'insumo' => 'abc',
                'transito' => 'quiza',
            ]))
            ->assertOk();

        $this->assertSame(3, (new ExistenciaQuery([
            'estado' => 'inventado',
            'insumo' => 'abc',
        ]))->paginar()->total());
    }

    // ---------------------------------------------------------------
    // Orden determinista
    // ---------------------------------------------------------------

    public function test_el_orden_es_estable_entre_cargas(): void
    {
        $this->escenarioTresEstados();
        $this->saldoDisponibleEn($this->bodega());

        $primera = collect((new ExistenciaQuery)->paginar()->items())->map(fn ($f) => $f->id)->all();
        $segunda = collect((new ExistenciaQuery)->paginar()->items())->map(fn ($f) => $f->id)->all();

        $this->assertSame($primera, $segunda);
        $this->assertNotEmpty($primera);
    }

    // ---------------------------------------------------------------
    // Solo lectura
    // ---------------------------------------------------------------

    public function test_visitar_y_filtrar_no_escribe_nada_en_inventario(): void
    {
        $this->escenarioTresEstados();
        $this->escenarioEnTransito();
        $this->encenderModulo();

        $antes = $this->huellaInventario();
        $usuario = $this->actingAs($this->admin());

        $usuario->get(route('planta.existencias.index'))->assertOk();
        $usuario->get(route('planta.existencias.index', ['estado' => 'retenido']))->assertOk();
        $usuario->get(route('planta.existencias.index', ['transito' => 'si']))->assertOk();
        $usuario->get(route('planta.existencias.index', ['saldo' => 'todos']))->assertOk();
        $usuario->get(route('planta.existencias.index', ['page' => 2]))->assertOk();

        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_la_reconciliacion_sigue_sin_diferencias_despues_de_consultar(): void
    {
        $this->escenarioTresEstados();
        $this->escenarioEnTransito();
        $this->encenderModulo();

        $this->actingAs($this->admin())->get(route('planta.existencias.index'))->assertOk();

        $this->assertTrue(app(ReconciliacionExistenciasService::class)->analizar()->sinDiferencias());
    }

    // ---------------------------------------------------------------
    // Rendimiento
    // ---------------------------------------------------------------

    /**
     * El número de consultas NO puede CRECER con el número de filas.
     *
     * Se compara 2 buckets contra 12. La aserción es «no crece», no «es igual»,
     * porque hay costes de primera petición —caché de permisos de Spatie, sesión—
     * que solo se pagan una vez y harían fallar una igualdad estricta por razones
     * que no tienen nada que ver con N+1. Por eso además se calienta con una
     * petición previa que no se mide. Si la pantalla resolviera insumo, lote,
     * ubicación o traslado fila a fila, doce filas costarían decenas de consultas
     * más que dos y la comparación lo delataría igual.
     */
    public function test_no_hay_n_mas_uno_al_crecer_el_listado(): void
    {
        $this->encenderModulo();
        $admin = $this->admin();

        $this->saldoDisponibleEn($this->bodega());
        $this->saldoDisponibleEn($this->bodega());

        // Calentamiento: no se mide.
        $this->actingAs($admin)->get(route('planta.existencias.index'))->assertOk();

        $consultasConDos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.existencias.index'))->assertOk());

        for ($i = 0; $i < 10; $i++) {
            $this->saldoDisponibleEn($this->bodega());
        }

        $consultasConDoce = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.existencias.index'))->assertOk());

        $this->assertSame(12, (new ExistenciaQuery)->paginar()->total(), 'El escenario debe tener 12 buckets.');
        $this->assertLessThanOrEqual(
            $consultasConDos,
            $consultasConDoce,
            "La pantalla hace {$consultasConDoce} consultas con 12 filas y {$consultasConDos} con 2: crece con las filas.",
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

        // Los listeners no se pueden quitar, así que se desarma el contador para
        // que la siguiente medición no sume también en este.
        $midiendo = false;

        return $consultas;
    }

    // ---------------------------------------------------------------
    // Contenido de la pantalla
    // ---------------------------------------------------------------

    public function test_la_pantalla_muestra_los_tres_estados_con_su_cantidad(): void
    {
        $this->escenarioTresEstados();
        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.existencias.index'))
            ->assertOk()
            ->assertSee('Disponible')
            ->assertSee('Retenido')
            ->assertSee('Rechazado')
            ->assertSee('200.0000')
            ->assertSee('100.0000');
    }

    public function test_la_pantalla_no_ofrece_ninguna_accion_de_escritura(): void
    {
        $this->escenarioTresEstados();
        $this->encenderModulo();

        $html = $this->actingAs($this->admin())
            ->get(route('planta.existencias.index'))
            ->assertOk()
            ->getContent();

        // Ningún formulario de escritura apunta al área. El layout trae el suyo
        // —cerrar sesión— y por eso la aserción mira la ACCIÓN de cada formulario
        // POST, no cuántos hay: lo que no puede existir es uno que escriba en
        // Planta desde una pantalla de consulta.
        foreach ($this->formulariosDeEscritura($html) as $accion) {
            $this->assertStringNotContainsString('/planta', $accion, "Un formulario POST apunta a {$accion}.");
        }

        // Y el de filtros existe y es GET.
        $this->assertStringContainsString('<form method="GET"', $html);
    }

    /**
     * Acciones de todos los formularios que no son GET.
     *
     * @return array<int, string>
     */
    private function formulariosDeEscritura(string $html): array
    {
        preg_match_all('/<form\b[^>]*>/i', $html, $formularios);

        $acciones = [];

        foreach ($formularios[0] as $etiqueta) {
            if (preg_match('/method=["\']get["\']/i', $etiqueta) === 1) {
                continue;
            }

            $acciones[] = preg_match('/action=["\']([^"\']*)["\']/i', $etiqueta, $m) === 1 ? $m[1] : '';
        }

        return $acciones;
    }
}
