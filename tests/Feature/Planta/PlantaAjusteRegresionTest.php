<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaMovimiento;
use App\Services\Planta\ReconciliacionExistenciasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lo que el paso 9 NO debe haber roto.
 *
 * Dos frentes. Hacia adentro: los ajustes son un cliente más del motor de
 * inventario, así que la proyección tiene que seguir cuadrando con el mayor
 * después de todo lo que este paso sabe hacer. Hacia afuera: el módulo de Planta
 * está aislado del dominio fiscal, y un documento nuevo no puede haber abierto
 * una puerta entre los dos.
 */
class PlantaAjusteRegresionTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    private function reconciliacion(): ReconciliacionExistenciasService
    {
        return app(ReconciliacionExistenciasService::class);
    }

    // --- La proyección sigue cuadrando ---

    public function test_la_proyeccion_cuadra_tras_todo_el_ciclo_de_ajustes(): void
    {
        $e = $this->escenarioConSaldo();

        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $this->ajusteConfirmado($e, TipoAjuste::Merma, '40');
        $this->ajusteConfirmado($e, TipoAjuste::Dano, '10');
        $reversado = $this->ajusteConfirmado($e, TipoAjuste::Negativo, '25');
        $this->servicioAjuste()->reversar($reversado, 'El descuento no correspondía', $this->admin());

        $conteo = $this->borradorAjuste($e, TipoAjuste::CorreccionConteo, '0', extraLinea: ['cantidad_conteo' => '600']);
        $this->servicioAjuste()->confirmar($conteo, $this->admin());

        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());
        $this->artisan('planta:reconciliar-existencias')->assertExitCode(0);
    }

    public function test_el_saldo_proyectado_es_la_suma_exacta_del_mayor(): void
    {
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);

        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '33.3333');
        $this->ajusteConfirmado($e, TipoAjuste::Merma, '11.1111');

        $suma = PlantaMovimiento::query()->delBucket($bucket)
            ->get()
            ->reduce(fn (string $acc, $m) => bcadd($acc, (string) $m->cantidad, 4), '0');

        $this->assertSame($suma, $this->saldo($bucket));
        $this->assertSame('522.2222', $this->saldo($bucket));
    }

    public function test_los_ajustes_conviven_con_recepciones_y_traslados_en_el_mismo_bucket(): void
    {
        // El motor es uno solo: los cuatro documentos escriben en el mismo mayor
        // y la proyección tiene que seguir siendo su suma.
        $e = $this->escenarioConSaldo();

        $this->ajusteConfirmado($e, TipoAjuste::Merma, '50');

        // Otra recepción del mismo insumo, en la misma bodega.
        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($e['ubicacion'], [
            $this->linea($e['insumo'], ['cantidad_recibida' => '2']),
        ]), $this->admin());
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());
    }

    public function test_ningun_ajuste_escribe_en_existencias_por_fuera_del_motor(): void
    {
        // La proyección solo se toca desde PlantaInventarioService. Si el servicio
        // de ajustes escribiera directo, habría filas sin movimiento que las
        // respalde y la reconciliación las vería.
        $e = $this->escenarioConSaldo();
        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');

        $existencias = DB::table('planta_existencias')->count();
        $buckets = PlantaMovimiento::query()
            ->select('planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id', 'estado', 'planta_traslado_id')
            ->distinct()
            ->get()
            ->count();

        $this->assertSame($buckets, $existencias);
    }

    public function test_el_mayor_es_de_solo_agregado_tambien_en_los_ajustes(): void
    {
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '100');
        $antes = $this->huellaMayor();

        $this->servicioAjuste()->reversar($ajuste, 'Se ajustó lo que no era', $this->admin());
        $despues = $this->huellaMayor();

        // Se AÑADIÓ un movimiento; ninguno de los anteriores desapareció.
        $this->assertSame($antes['filas'] + 1, $despues['filas']);
        $this->assertGreaterThan($antes['max_id'], $despues['max_id']);
    }

    // --- Aislamiento del dominio fiscal ---

    public function test_los_ajustes_no_tocan_ninguna_tabla_de_facturacion(): void
    {
        $huella = fn () => [
            'dtes' => DB::table('dtes')->count(),
            'clientes' => DB::table('clientes')->count(),
            'productos' => DB::table('productos')->count(),
        ];

        $antes = $huella();

        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Merma, '50');
        $this->servicioAjuste()->reversar($ajuste, 'La merma era de otro lote', $this->admin());

        $this->assertSame($antes, $huella());
    }

    public function test_las_tablas_de_ajuste_no_referencian_el_catalogo_fiscal(): void
    {
        foreach (['planta_ajustes', 'planta_ajuste_detalles'] as $tabla) {
            foreach (['producto_id', 'cliente_id', 'dte_id'] as $columna) {
                $this->assertFalse(
                    Schema::hasColumn($tabla, $columna),
                    "{$tabla} no debe referenciar {$columna} de Facturación."
                );
            }
        }
    }

    // --- Navegación ---

    public function test_produccion_ve_el_enlace_de_ajustes_en_la_sidebar(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.ajustes.index'), false)
            ->assertSee('Ajustes');
    }

    public function test_el_area_de_facturacion_no_ve_rastro_de_los_ajustes(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertDontSee(route('planta.ajustes.index'), false);
    }

    // --- Los documentos anteriores siguen funcionando ---

    public function test_los_ajustes_no_alteran_la_numeracion_de_los_otros_documentos(): void
    {
        $e = $this->escenarioConSaldo();

        $recepcionAntes = DB::table('secuencias')->where('clave', 'planta_recepcion')->value('ultimo');

        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $this->assertSame(
            $recepcionAntes,
            DB::table('secuencias')->where('clave', 'planta_recepcion')->value('ultimo')
        );
    }

    public function test_un_ajuste_no_puede_alcanzar_el_saldo_en_transito_de_un_traslado(): void
    {
        // El quinto eje del bucket aísla el saldo en viaje. Un ajuste siempre
        // trabaja con `planta_traslado_id = 0`, así que ni siquiera puede nombrar
        // el bucket de un traslado en curso.
        $e = $this->escenarioConSaldo();

        $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $enTransito = PlantaMovimiento::query()
            ->delDocumento(PlantaAjuste::class, PlantaAjuste::sole()->id)
            ->where('planta_traslado_id', '>', 0)
            ->count();

        $this->assertSame(0, $enTransito);
    }

    public function test_el_estado_del_bucket_de_un_ajuste_siempre_es_uno_de_los_tres_operativos(): void
    {
        $e = $this->escenarioConSaldo('5', EstadoDisponibilidad::Retenido);

        $this->ajusteConfirmado($e, TipoAjuste::Merma, '10', EstadoDisponibilidad::Retenido);

        $estados = PlantaMovimiento::query()
            ->delDocumento(PlantaAjuste::class, PlantaAjuste::sole()->id)
            ->pluck('estado')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([EstadoDisponibilidad::Retenido], $estados);
    }
}
