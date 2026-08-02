<?php

namespace Tests\Feature\Planta;

use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaUbicacion;
use App\Support\Planta\LoteQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pantalla de lotes: qué lista, qué filtra y qué NO toca.
 *
 * La afirmación central es que el LOTE GENÉRICO no existe para esta interfaz.
 * `GEN-<insumo_id>` es un detalle interno del motor —la quinta dimensión del
 * bucket no admite nulos— y no algo que alguien haya recibido: no aparece en el
 * listado, su ficha responde 404 y no hay filtro que lo saque a la superficie.
 *
 * Los lotes se crean SIEMPRE por el camino real —una recepción confirmada— y
 * nunca con la factory: un lote fabricado a mano no tiene movimientos detrás y
 * probaría la pantalla contra datos que el dominio no puede producir.
 */
class PlantaLotesConsultaTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    /**
     * Recibe y confirma una entrada, y devuelve el lote real que creó.
     *
     * @param  array<string, mixed>  $linea
     * @param  array<string, mixed>  $cabecera
     */
    private function loteDe(
        ?PlantaInsumo $insumo = null,
        ?PlantaUbicacion $ubicacion = null,
        array $linea = [],
        array $cabecera = [],
    ): PlantaLote {
        $admin = $this->admin();
        $insumo ??= $this->insumoConLotes();
        $ubicacion ??= $this->bodega();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo, $linea)], $cabecera),
            $admin,
        );

        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        return $recepcion->detalles()->orderBy('id')->first()->lote()->firstOrFail();
    }

    /** Recibe un insumo SIN control de lotes: crea el genérico `GEN-<insumo_id>`. */
    private function loteGenerico(): PlantaLote
    {
        $lote = $this->loteDe($this->insumoSinLotes());

        $this->assertTrue($lote->es_generico, 'El escenario debe producir el lote genérico.');

        return $lote;
    }

    /** @return array<int, string> Códigos internos de la página, en orden. */
    private function codigos(LoteQuery $consulta): array
    {
        return collect($consulta->paginar()->items())
            ->map(fn (PlantaLote $l) => $l->codigo_interno)
            ->all();
    }

    /** Huella de las dos tablas de inventario: demuestra que algo NO las tocó. */
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
    // El genérico no existe para esta pantalla
    // ---------------------------------------------------------------

    public function test_el_listado_excluye_los_lotes_genericos(): void
    {
        $real = $this->loteDe();
        $generico = $this->loteGenerico();

        $codigos = $this->codigos(new LoteQuery);

        $this->assertContains($real->codigo_interno, $codigos);
        $this->assertNotContains($generico->codigo_interno, $codigos);
        $this->assertCount(1, $codigos);
    }

    /**
     * Ni buscándolo por su código. La exclusión no es un valor por defecto que
     * un querystring pueda desactivar: es incondicional.
     */
    public function test_ningun_filtro_saca_al_generico_a_la_superficie(): void
    {
        $generico = $this->loteGenerico();

        foreach ([
            ['q' => 'GEN-'],
            ['q' => $generico->codigo_interno],
            ['insumo' => $generico->planta_insumo_id],
            ['activo' => LoteQuery::ACTIVO_SI],
            ['generico' => '1'],
            ['es_generico' => '1'],
        ] as $filtros) {
            $this->assertNotContains(
                $generico->codigo_interno,
                $this->codigos(new LoteQuery($filtros)),
                'El genérico apareció con los filtros '.json_encode($filtros),
            );
        }
    }

    public function test_la_ficha_del_lote_generico_responde_404(): void
    {
        $generico = $this->loteGenerico();
        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.lotes.show', $generico))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Filtros, uno a uno
    // ---------------------------------------------------------------

    public function test_el_filtro_de_insumo_acota(): void
    {
        $insumoA = $this->insumoConLotes(['nombre' => 'Harina']);
        $insumoB = $this->insumoConLotes(['nombre' => 'Azúcar']);

        $a = $this->loteDe($insumoA);
        $b = $this->loteDe($insumoB);

        $this->assertSame([$a->codigo_interno], $this->codigos(new LoteQuery(['insumo' => $insumoA->id])));
        $this->assertSame([$b->codigo_interno], $this->codigos(new LoteQuery(['insumo' => $insumoB->id])));
    }

    public function test_el_filtro_de_proveedor_acota(): void
    {
        $proveedor = $this->proveedor(['nombre' => 'Molinos del Norte']);

        $conProveedor = $this->loteDe(cabecera: ['planta_proveedor_id' => $proveedor->id]);
        $sinProveedor = $this->loteDe();

        $codigos = $this->codigos(new LoteQuery(['proveedor' => $proveedor->id]));

        $this->assertSame([$conProveedor->codigo_interno], $codigos);
        $this->assertNotContains($sinProveedor->codigo_interno, $codigos);
    }

    public function test_la_busqueda_encuentra_por_codigo_interno(): void
    {
        $lote = $this->loteDe();
        $otro = $this->loteDe();

        $codigos = $this->codigos(new LoteQuery(['q' => $lote->codigo_interno]));

        $this->assertSame([$lote->codigo_interno], $codigos);
        $this->assertNotContains($otro->codigo_interno, $codigos);
    }

    /**
     * Quien tiene el saco en la mano lee el código impreso por el proveedor, no
     * el interno. La búsqueda mira los dos.
     */
    public function test_la_busqueda_encuentra_por_codigo_del_proveedor(): void
    {
        $lote = $this->loteDe(linea: ['lote_codigo_proveedor' => 'PRV-9981']);
        $otro = $this->loteDe();

        $codigos = $this->codigos(new LoteQuery(['q' => 'PRV-99']));

        $this->assertSame([$lote->codigo_interno], $codigos);
        $this->assertNotContains($otro->codigo_interno, $codigos);
    }

    public function test_el_filtro_de_estado_separa_activos_de_retirados(): void
    {
        $activo = $this->loteDe();
        $retirado = $this->loteDe();
        $retirado->update(['activo' => false]);

        $this->assertSame([$activo->codigo_interno], $this->codigos(new LoteQuery(['activo' => LoteQuery::ACTIVO_SI])));
        $this->assertSame([$retirado->codigo_interno], $this->codigos(new LoteQuery(['activo' => LoteQuery::ACTIVO_NO])));

        // Sin filtro se ven los dos: un lote retirado sigue existiendo.
        $this->assertCount(2, $this->codigos(new LoteQuery));
    }

    public function test_el_filtro_de_vencidos_solo_devuelve_lo_que_ya_venció(): void
    {
        $vencido = $this->loteDe(linea: ['fecha_vencimiento' => Carbon::today()->subDay()->toDateString()]);
        $vigente = $this->loteDe(linea: ['fecha_vencimiento' => Carbon::today()->addDays(10)->toDateString()]);
        $sinFecha = $this->loteDe();

        $codigos = $this->codigos(new LoteQuery(['vencimiento' => LoteQuery::VENCIMIENTO_VENCIDOS]));

        $this->assertSame([$vencido->codigo_interno], $codigos);
        $this->assertNotContains($vigente->codigo_interno, $codigos);
        // Un insumo que no vence no está vencido: no se cuela en la lista.
        $this->assertNotContains($sinFecha->codigo_interno, $codigos);
    }

    public function test_el_filtro_de_por_vencer_respeta_la_ventana_de_dias(): void
    {
        $enDiez = $this->loteDe(linea: ['fecha_vencimiento' => Carbon::today()->addDays(10)->toDateString()]);
        $enNoventa = $this->loteDe(linea: ['fecha_vencimiento' => Carbon::today()->addDays(90)->toDateString()]);
        $vencido = $this->loteDe(linea: ['fecha_vencimiento' => Carbon::today()->subDay()->toDateString()]);

        // Ventana por defecto (30 días): entra el de diez, no el de noventa.
        $porDefecto = $this->codigos(new LoteQuery(['vencimiento' => LoteQuery::VENCIMIENTO_POR_VENCER]));
        $this->assertSame([$enDiez->codigo_interno], $porDefecto);

        // Ventana ampliada: entran los dos, y lo ya vencido sigue fuera.
        $ampliada = $this->codigos(new LoteQuery([
            'vencimiento' => LoteQuery::VENCIMIENTO_POR_VENCER,
            'dias' => '120',
        ]));

        $this->assertContains($enDiez->codigo_interno, $ampliada);
        $this->assertContains($enNoventa->codigo_interno, $ampliada);
        $this->assertNotContains($vencido->codigo_interno, $ampliada);
    }

    // ---------------------------------------------------------------
    // Entrada mal escrita
    // ---------------------------------------------------------------

    public function test_los_filtros_invalidos_se_ignoran_en_vez_de_reventar(): void
    {
        $lote = $this->loteDe();
        $this->encenderModulo();

        $basura = [
            'insumo' => 'abc',
            'proveedor' => '-4',
            'activo' => 'quiza',
            'vencimiento' => 'inventado',
            'dias' => 'muchos',
        ];

        $this->actingAs($this->admin())
            ->get(route('planta.lotes.index', $basura))
            ->assertOk()
            ->assertSee($lote->codigo_interno);

        // Y la consulta devuelve el listado completo, no uno vacío ni un error.
        $this->assertSame([$lote->codigo_interno], $this->codigos(new LoteQuery($basura)));
        $this->assertSame(LoteQuery::DIAS_POR_DEFECTO, (new LoteQuery($basura))->dias());
    }

    public function test_una_ventana_de_dias_fuera_de_rango_vuelve_al_valor_por_defecto(): void
    {
        foreach (['0', '999', '-5'] as $valor) {
            $this->assertSame(
                LoteQuery::DIAS_POR_DEFECTO,
                (new LoteQuery(['dias' => $valor]))->dias(),
                "La ventana «{$valor}» debía descartarse.",
            );
        }

        $this->assertSame(45, (new LoteQuery(['dias' => '45']))->dias());
    }

    // ---------------------------------------------------------------
    // Ficha
    // ---------------------------------------------------------------

    public function test_la_ficha_muestra_el_lote_su_saldo_y_sus_movimientos(): void
    {
        $proveedor = $this->proveedor(['nombre' => 'Molinos del Norte']);
        $ubicacion = $this->bodega(['codigo' => 'CASA', 'nombre' => 'Casa']);

        $lote = $this->loteDe(
            ubicacion: $ubicacion,
            linea: ['lote_codigo_proveedor' => 'PRV-9981'],
            cabecera: ['planta_proveedor_id' => $proveedor->id],
        );

        $ajeno = $this->loteDe();

        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.lotes.show', $lote))
            ->assertOk()
            ->assertSee($lote->codigo_interno)
            ->assertSee('PRV-9981')
            ->assertSee('Molinos del Norte')
            ->assertSee('CASA')
            // 5 × 100 × 1 = 500 unidades base, todas disponibles.
            ->assertSee('500.0000')
            ->assertSee('Disponible')
            ->assertSee('Recepción')
            // Y nada de otro lote se cuela en la ficha.
            ->assertDontSee($ajeno->codigo_interno);
    }

    public function test_la_ficha_enlaza_a_las_consultas_filtradas_por_el_lote(): void
    {
        $lote = $this->loteDe();
        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.lotes.show', $lote))
            ->assertOk()
            ->assertSee(route('planta.existencias.index', ['lote' => $lote->id]), false)
            ->assertSee(route('planta.movimientos.index', ['lote' => $lote->id]), false);
    }

    /**
     * Un lote sin saldo NO pierde su historial: el mayor es append-only y la
     * ficha lo sigue mostrando aunque las existencias hayan quedado en cero.
     */
    public function test_un_lote_sin_saldo_conserva_su_historial_en_la_ficha(): void
    {
        $admin = $this->admin();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo)], []),
            $admin,
        );
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        $lote = $recepcion->detalles()->orderBy('id')->first()->lote()->firstOrFail();

        // Reversar deja el saldo en cero y añade movimientos de compensación.
        $this->servicioRecepcion()->reversar($recepcion->refresh(), 'prueba de historial sin saldo', $admin);

        $this->encenderModulo();

        $this->actingAs($admin)
            ->get(route('planta.lotes.show', $lote))
            ->assertOk()
            ->assertSee($lote->codigo_interno)
            ->assertSee('no tiene saldo en ninguna ubicación', false);
    }

    // ---------------------------------------------------------------
    // La única escritura no toca inventario
    // ---------------------------------------------------------------

    public function test_retirar_y_reincorporar_no_escribe_nada_en_inventario(): void
    {
        $lote = $this->loteDe();
        $this->encenderModulo();

        $antes = $this->huellaInventario();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertRedirect();

        $this->assertFalse($lote->fresh()->activo);
        $this->assertSame($antes, $this->huellaInventario(), 'Retirar un lote no puede mover inventario.');

        $this->actingAs($admin)
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertRedirect();

        $this->assertTrue($lote->fresh()->activo);
        $this->assertSame($antes, $this->huellaInventario(), 'Reincorporar un lote no puede mover inventario.');
    }

    /** Cambia `activo` y NADA más: identidad y fechas quedan intactas. */
    public function test_retirar_un_lote_solo_cambia_la_bandera(): void
    {
        $lote = $this->loteDe(linea: [
            'lote_codigo_proveedor' => 'PRV-1',
            'fecha_vencimiento' => Carbon::today()->addDays(30)->toDateString(),
        ]);

        $this->encenderModulo();

        // Se comparan VALORES y no el modelo entero: las fechas son objetos
        // Carbon distintos en cada lectura aunque representen el mismo día.
        $identidad = fn (PlantaLote $l): array => [
            'planta_insumo_id' => $l->planta_insumo_id,
            'planta_proveedor_id' => $l->planta_proveedor_id,
            'codigo_interno' => $l->codigo_interno,
            'codigo_proveedor' => $l->codigo_proveedor,
            'es_generico' => $l->es_generico,
            'fecha_recepcion' => $l->fecha_recepcion?->toDateString(),
            'fecha_elaboracion' => $l->fecha_elaboracion?->toDateString(),
            'fecha_vencimiento' => $l->fecha_vencimiento?->toDateString(),
        ];

        $intactos = $identidad($lote);

        $this->actingAs($this->admin())
            ->patch(route('planta.lotes.toggle-activo', $lote))
            ->assertRedirect();

        $this->assertSame($intactos, $identidad($lote->fresh()));
    }

    public function test_visitar_y_filtrar_no_escribe_nada_en_inventario(): void
    {
        $lote = $this->loteDe();
        $this->loteGenerico();
        $this->encenderModulo();

        $antes = $this->huellaInventario();
        $usuario = $this->actingAs($this->admin());

        $usuario->get(route('planta.lotes.index'))->assertOk();
        $usuario->get(route('planta.lotes.index', ['vencimiento' => 'vencidos']))->assertOk();
        $usuario->get(route('planta.lotes.index', ['activo' => '0']))->assertOk();
        $usuario->get(route('planta.lotes.show', $lote))->assertOk();

        $this->assertSame($antes, $this->huellaInventario());
    }

    // ---------------------------------------------------------------
    // Rendimiento
    // ---------------------------------------------------------------

    /**
     * El número de consultas NO puede CRECER con el número de filas.
     *
     * Se compara 2 lotes contra 12. La aserción es «no crece», no «es igual»,
     * porque hay costes de primera petición —caché de permisos de Spatie,
     * sesión— que solo se pagan una vez; por eso además se calienta con una
     * petición previa que no se mide. Si el listado resolviera insumo, proveedor
     * o el conteo de movimientos fila a fila, doce filas costarían decenas de
     * consultas más que dos y la comparación lo delataría.
     */
    public function test_no_hay_n_mas_uno_al_crecer_el_listado(): void
    {
        $admin = $this->admin();
        $proveedor = $this->proveedor();

        $this->loteDe(cabecera: ['planta_proveedor_id' => $proveedor->id]);
        $this->loteDe(cabecera: ['planta_proveedor_id' => $proveedor->id]);

        $this->encenderModulo();

        // Calentamiento: no se mide.
        $this->actingAs($admin)->get(route('planta.lotes.index'))->assertOk();

        $conDos = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.lotes.index'))->assertOk());

        for ($i = 0; $i < 10; $i++) {
            $this->loteDe(cabecera: ['planta_proveedor_id' => $proveedor->id]);
        }

        $conDoce = $this->contarConsultas(fn () => $this->actingAs($admin)
            ->get(route('planta.lotes.index'))->assertOk());

        $this->assertSame(12, (new LoteQuery)->paginar()->total(), 'El escenario debe tener 12 lotes.');
        $this->assertLessThanOrEqual(
            $conDos,
            $conDoce,
            "El listado hace {$conDoce} consultas con 12 lotes y {$conDos} con 2: crece con las filas.",
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
    // La pantalla no ofrece lo que no existe
    // ---------------------------------------------------------------

    public function test_el_listado_no_ofrece_crear_ni_editar_lotes(): void
    {
        $this->loteDe();
        $this->encenderModulo();

        $html = $this->actingAs($this->admin())
            ->get(route('planta.lotes.index'))
            ->assertOk()
            ->getContent();

        // Los lotes nacen en las recepciones: no hay pantalla de creación ni de
        // edición, y el listado no puede insinuar ninguna de las dos.
        $this->assertStringNotContainsString('planta/lotes/crear', $html);
        $this->assertStringNotContainsString('/editar', $html);
    }

    public function test_no_existen_rutas_de_creacion_ni_edicion_de_lotes(): void
    {
        foreach (['planta.lotes.create', 'planta.lotes.store', 'planta.lotes.edit', 'planta.lotes.update', 'planta.lotes.destroy'] as $nombre) {
            $this->assertFalse(
                app('router')->has($nombre),
                "La ruta {$nombre} no debe existir: los lotes no se crean ni se editan a mano.",
            );
        }
    }

    /**
     * Los proveedores y los insumos del selector salen del catálogo, no de los
     * lotes: se filtra por lo que existe, no solo por lo que ya tiene lotes.
     */
    public function test_el_selector_de_insumos_solo_ofrece_los_que_controlan_lotes(): void
    {
        $conLotes = $this->insumoConLotes(['nombre' => 'Harina fina']);
        $sinLotes = $this->insumoSinLotes(['nombre' => 'Bolsa mediana']);
        PlantaProveedor::factory()->create(['nombre' => 'Proveedor sin entregas']);

        $this->encenderModulo();

        $this->actingAs($this->admin())
            ->get(route('planta.lotes.index'))
            ->assertOk()
            ->assertSee('Harina fina')
            ->assertSee('Proveedor sin entregas')
            ->assertDontSee('Bolsa mediana');

        $this->assertNotNull($sinLotes->id);
        $this->assertNotNull($conLotes->id);
    }
}
