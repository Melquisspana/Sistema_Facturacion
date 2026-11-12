<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use App\Models\SalidaRuta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\MapaVentas;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\VentasRutas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El mapa de ventas del tablero de Rutas (paso 1, 09/11/2026; segunda versión, 11/11/2026):
 * ventas nacionales por departamento y distrito con las reglas de Ventas, salas atrasadas o
 * por visitar según su ritmo, última entrega, zonas que pasan, salas sin ubicación y las
 * listas que dicen lo mismo que el mapa en palabras («Quién vendió más» y «A dónde ir primero»).
 */
class MapaVentasTest extends TestCase
{
    use RefreshDatabase;

    private int $correlativo = 0;

    private Establecimiento $establecimiento;

    private Producto $producto;

    private Ruta $oriente;

    private Ruta $costa;

    private ClienteSucursal $norte;

    private ClienteSucursal $sur;

    private ClienteSucursal $sinUbicacion;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->travelTo('2026-11-09 10:00:00');

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'un']);
        $this->producto = Producto::factory()->create(['nombre' => 'Dulce de prueba']);
        $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);
        $this->establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);

        $sanSalvador = $this->departamento('06', 'San Salvador');
        $sanMiguel = $this->departamento('12', 'San Miguel');
        $centro = $this->distrito($sanSalvador, '14', 'Distrito Centro');
        $playa = $this->distrito($sanSalvador, '15', 'Distrito Playa');
        $this->distrito($sanMiguel, '01', 'Distrito Oriente');

        $this->oriente = Ruta::create(['nombre' => 'Ruta Oriente', 'activa' => true]);
        $this->costa = Ruta::create(['nombre' => 'Ruta Costa', 'activa' => true]);
        // Costa cubre San Miguel entero, donde todavía no hay salas.
        RutaCobertura::create(['ruta_id' => $this->costa->id, 'departamento_id' => $sanMiguel->id]);

        // Las salas ya existían en los meses que se miran.
        $this->travelTo('2026-06-01 10:00:00');
        $this->norte = $this->sala('Sala de prueba Norte', $this->oriente, $centro);
        $this->sur = $this->sala('Sala de prueba Sur', $this->costa, $playa);
        $this->sinUbicacion = $this->sala('Sala de prueba sin lugar', $this->costa, null);
        $this->travelTo('2026-11-09 10:00:00');
    }

    private function departamento(string $codigo, string $nombre): Departamento
    {
        return Departamento::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'activo' => true]);
    }

    private function distrito(Departamento $departamento, string $codigo, string $nombre): Distrito
    {
        return Distrito::create(['departamento_id' => $departamento->id, 'municipio' => 'Municipio de prueba', 'codigo' => $codigo, 'nombre' => $nombre, 'activo' => true]);
    }

    private function sala(string $nombre, Ruta $ruta, ?Distrito $distrito): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create([
            'nombre' => $nombre,
            'ruta_id' => $ruta->id,
            'departamento_id' => $distrito?->departamento_id,
            'distrito_id' => $distrito?->id,
            'activo' => true,
        ]);
    }

    /** Compra cada 10 días y la última vez fue el 01/10: al 09/11 está atrasada. */
    private function atrasada(string $nombre, Ruta $ruta, Distrito $distrito): ClienteSucursal
    {
        $sala = $this->sala($nombre, $ruta, $distrito);
        foreach (['2026-09-11', '2026-09-21', '2026-10-01'] as $fecha) {
            $this->documento($sala, $fecha, 10);
        }

        return $sala;
    }

    /** Compra cada 7 días y la última vez fue el 02/11: al 09/11 ya le toca visita. */
    private function porVisitar(string $nombre, Ruta $ruta, Distrito $distrito): ClienteSucursal
    {
        $sala = $this->sala($nombre, $ruta, $distrito);
        foreach (['2026-10-19', '2026-10-26', '2026-11-02'] as $fecha) {
            $this->documento($sala, $fecha, 10);
        }

        return $sala;
    }

    /** Un documento con una línea, aceptado de verdad por Hacienda salvo que `$datos` diga otra cosa. */
    private function documento(?ClienteSucursal $sala, string $fecha, float $monto, array $datos = [], array $linea = []): DteLinea
    {
        $this->correlativo++;
        $tipo = $datos['tipo_dte'] ?? '03';
        $numero = 'DTE-'.$tipo.'-M001P001-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT);

        return Dte::withoutEvents(function () use ($sala, $fecha, $monto, $datos, $linea, $numero) {
            $dte = Dte::create($datos + [
                'establecimiento_id' => $this->establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'sello_recepcion' => Str::uuid()->toString(), 'fecha_procesamiento_mh' => now(),
                'cliente_id' => $sala?->cliente_id, 'cliente_sucursal_id' => $sala?->id, 'numero_control' => $numero,
                'fecha_emision' => $fecha, 'hora_emision' => '10:00:00', 'subtotal' => $monto, 'total_descuento' => 0, 'total_pagar' => $monto,
            ]);

            return DteLinea::withoutEvents(fn () => DteLinea::create($linea + [
                'dte_id' => $dte->id, 'numero_linea' => 1, 'tipo_impuesto' => 'gravado', 'producto_id' => $this->producto->id,
                'descripcion' => 'Dulce de prueba', 'cantidad' => 1, 'precio_unitario' => $monto, 'venta_gravada' => $monto, 'total_linea' => $monto,
            ]));
        });
    }

    private function nc(ClienteSucursal $sala, string $fecha, float $monto, DteLinea $original): void
    {
        $this->documento($sala, $fecha, $monto, ['tipo_dte' => '05', 'dte_relacionado_id' => $original->dte_id], ['dte_linea_original_id' => $original->id]);
    }

    private function entregada(ClienteSucursal $sala, Ruta $ruta, string $cuando): void
    {
        $dte = Dte::where('cliente_sucursal_id', $sala->id)->where('tipo_dte', '03')->orderByDesc('fecha_emision')->firstOrFail();
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => substr($cuando, 0, 10), 'fecha_fin_real' => substr($cuando, 0, 10), 'estado' => EstadoSalidaRuta::Finalizada]);
        $salida->entregas()->create([
            'dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id,
            'resultado' => ResultadoEntrega::Entregado, 'fecha_resultado' => $cuando,
        ]);
    }

    /**
     * Norte: vendió en noviembre y en el mismo tramo de octubre, con notas que restan en el
     * mes del CCF que corrigen. Sur no compra desde el 01/10. La sala sin lugar vende aparte.
     */
    private function ventasDeEjemplo(): void
    {
        $noviembre = $this->documento($this->norte, '2026-11-03', 100);
        $octubre = $this->documento($this->norte, '2026-10-05', 80);
        // Del 10 al 31 de octubre no es el mismo tramo: no se compara.
        $this->documento($this->norte, '2026-10-20', 500);
        // Notas emitidas en noviembre: restan en el mes del CCF que corrigen.
        $this->nc($this->norte, '2026-11-05', 30, $octubre);
        $this->nc($this->norte, '2026-11-06', 10, $noviembre);
        // Exportación, invalidado y archivado: nunca cuentan.
        $this->documento($this->norte, '2026-11-04', 1000, ['tipo_dte' => '11'], ['venta_gravada' => 0, 'venta_exportacion' => 1000]);
        $this->documento($this->norte, '2026-11-04', 70, ['estado' => 'invalidado']);
        $this->documento($this->norte, '2026-11-04', 40, ['archivado' => true]);

        $this->documento($this->sur, '2026-10-01', 60);
        $this->documento($this->sinUbicacion, '2026-11-02', 25);

        $this->entregada($this->norte, $this->oriente, '2026-11-04 11:00:00');
    }

    private function mapa(?string $mes = null, ?int $ruta = null): array
    {
        return app(MapaVentas::class)->calcular($mes ?? '2026-11', $ruta);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    public function test_ventas_por_zona_con_notas_sin_exportacion_ni_invalidados(): void
    {
        $this->ventasDeEjemplo();

        $mapa = $this->mapa();
        $centro = $mapa['distritos']['06-14'];

        // Noviembre del 1 al 9: 100 − 10 de la nota. Octubre del 1 al 9: 80 − 30 de la nota.
        $this->assertSame(90.0, $centro['ventas']);
        $this->assertSame(50.0, $centro['anterior']);
        $this->assertSame(90.0, $mapa['departamentos']['06']['ventas']);
        $this->assertSame(1, $centro['compraron']);
        // Sur compró el 01/10: entra en el tramo de octubre.
        $this->assertSame(60.0, $mapa['distritos']['06-15']['anterior']);
        // El total incluye a la sala sin ubicación, que se cuenta aparte.
        $this->assertSame(115.0, $mapa['totales']['ventas']);
        $this->assertSame(110.0, $mapa['totales']['anterior']);
        $this->assertSame(2, $mapa['totales']['compraron']);
        $this->assertSame(3, $mapa['totales']['salas']);
        $this->assertTrue($mapa['enCurso']);
        $this->assertSame(['desde' => '2026-11-01', 'hasta' => '2026-11-09', 'anteriorDesde' => '2026-10-01', 'anteriorHasta' => '2026-10-09'], $mapa['periodo']);
    }

    /**
     * «Vendido en el mes» es lo mismo que muestra Ventas (nacional): también lo vendido sin
     * sala (consumidor final) y lo de salas borradas o desactivadas, aunque no salgan en el
     * mapa. Una sala desactivada que no compró en el mes no se cuenta como sala.
     */
    public function test_el_total_es_el_de_la_pantalla_de_ventas(): void
    {
        $this->ventasDeEjemplo();
        // Consumidor final: 113 con IVA, 100 sin IVA.
        $this->documento(null, '2026-11-04', 113, ['tipo_dte' => '01', 'iva' => 13]);
        $this->documento(null, '2026-10-04', 56.5, ['tipo_dte' => '01', 'iva' => 6.5]);
        $borrada = $this->sala('Sala de prueba cerrada', $this->oriente, Distrito::where('codigo', '14')->first());
        $this->documento($borrada, '2026-11-02', 20);
        $borrada->delete();
        $desactivada = $this->sala('Sala de prueba desactivada', $this->costa, Distrito::where('codigo', '15')->first());
        $this->documento($desactivada, '2026-10-03', 15);
        $desactivada->update(['activo' => false]);

        $mapa = $this->mapa();

        // 90 de Norte + 25 de la sala sin lugar + 20 de la borrada + 100 de consumidor final.
        $this->assertSame(235.0, $mapa['totales']['ventas']);
        $this->assertSame(app(VentasRutas::class)->consultar()['totales']['actual'], $mapa['totales']['ventas']);
        $this->assertSame(app(VentasRutas::class)->consultar(['periodo' => 'mes_pasado'])['totales']['actual'], $this->mapa('2026-10')['totales']['ventas']);
        // Octubre del 1 al 9: 50 de Norte + 60 de Sur + 15 de la desactivada + 50 de consumidor final.
        $this->assertSame(175.0, $mapa['totales']['anterior']);
        $this->assertSame(100.0, $mapa['sinUbicacion']['sinSala']);
        // La borrada compró en el mes: es sala. La desactivada no: su plata cuenta, ella no.
        $this->assertSame(3, $mapa['totales']['compraron']);
        $this->assertSame(4, $mapa['totales']['salas']);
        $this->assertSame(110.0, $mapa['distritos']['06-14']['ventas']);
        $this->assertSame(75.0, $mapa['distritos']['06-15']['anterior']);
        $this->assertSame(1, $mapa['distritos']['06-15']['salas']);
        $this->assertSame(1, $mapa['distritos']['06-15']['sinRitmo']);
    }

    /**
     * «Dónde falta» ya no es «sin compra 30 días»: cada sala con su ritmo (la mediana de días
     * entre sus visitas). Le toca si llegó su fecha; atrasada si pasó con margen.
     */
    public function test_donde_falta_cuenta_las_salas_que_les_toca_o_estan_atrasadas(): void
    {
        $this->ventasDeEjemplo();
        // Norte: 05/10, 20/10 y 03/11, más la entrega del 04/11: cada 15 días, al día.
        // Sur: cada 10 días hasta el 01/10: atrasada 29 días.
        foreach (['2026-09-01', '2026-09-11', '2026-09-21'] as $fecha) {
            $this->documento($this->sur, $fecha, 10);
        }
        // Este: cada 7 días; la última visita hace 7: le toca hoy.
        $este = $this->sala('Sala de prueba Este', $this->oriente, Distrito::where('codigo', '14')->first());
        foreach (['2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02'] as $fecha) {
            $this->documento($este, $fecha, 10);
        }

        $mapa = $this->mapa();

        // Cada sala lleva su distrito (`lugar`): la ficha del departamento dice dónde queda.
        $sur = ['nombre' => 'Sala de prueba Sur', 'lugar' => 'Distrito Playa', 'estado' => Ritmo::ATRASADA, 'dias' => 39, 'faltan' => -29];
        $leToca = ['nombre' => 'Sala de prueba Este', 'lugar' => 'Distrito Centro', 'estado' => Ritmo::PRONTO, 'dias' => 7, 'faltan' => 0];
        $this->assertSame([1, 0, 1], [$mapa['distritos']['06-15']['faltan'], $mapa['distritos']['06-15']['leToca'], $mapa['distritos']['06-15']['atrasadas']]);
        $this->assertSame([1, 1, 0], [$mapa['distritos']['06-14']['faltan'], $mapa['distritos']['06-14']['leToca'], $mapa['distritos']['06-14']['atrasadas']]);
        $this->assertSame([$sur], $mapa['distritos']['06-15']['porVisitar']);
        $this->assertSame([$leToca], $mapa['distritos']['06-14']['porVisitar']);
        // Las atrasadas primero.
        $this->assertSame([$sur, $leToca], $mapa['departamentos']['06']['porVisitar']);
        $this->assertSame([2, 1], [$mapa['totales']['faltan'], $mapa['totales']['atrasadas']]);

        $html = $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa'))->assertOk()->getContent();
        $this->assertStringNotContainsString('30+ días', $html);
        // El resumen: cuántas hay para visitar, y lleva a «A dónde ir».
        $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa'))
            ->assertSeeInOrder(['Salas para visitar', '>2<', '1 atrasada', 'y 1 por visitar', 'Ver a dónde ir'], false)
            ->assertSeeInOrder(['A dónde ir', '2<span class="sr-only"> salas para visitar</span>'], false)
            ->assertDontSee('Distritos sin ninguna sala')
            ->assertDontSee('Dónde falta');

        // La última entrega sale de las entregas de Rutas.
        $this->assertSame('2026-11-04', $mapa['distritos']['06-14']['entrega']);
        $this->assertSame(5, $mapa['distritos']['06-14']['entregaDias']);
        $this->assertSame('2026-11-04', $mapa['departamentos']['06']['entrega']);
        $this->assertNull($mapa['distritos']['06-15']['entrega']);
    }

    /**
     * La ficha nombra hasta 5 salas por lugar; `faltan` es el total, para decir «y N más».
     */
    public function test_la_ficha_nombra_cinco_salas_y_el_lugar_dice_cuantas_son(): void
    {
        $playa = Distrito::where('codigo', '15')->first();
        for ($i = 1; $i <= 6; $i++) {
            $sala = $this->sala('Sala de prueba '.$i, $this->costa, $playa);
            // Cada 10 días hasta el 01/10: atrasada. La 6 compró un día después: es la menos atrasada.
            foreach (['2026-09-11', '2026-09-21', $i === 6 ? '2026-10-02' : '2026-10-01'] as $fecha) {
                $this->documento($sala, $fecha, 10);
            }
        }

        $playa = $this->mapa()['distritos']['06-15'];

        $this->assertSame([6, 6], [$playa['faltan'], $playa['atrasadas']]);
        $this->assertSame(['Sala de prueba 1', 'Sala de prueba 2', 'Sala de prueba 3', 'Sala de prueba 4', 'Sala de prueba 5'], array_column($playa['porVisitar'], 'nombre'));
    }

    /** Sin 3 visitas no hay ritmo: no se la da por atrasada, se cuenta aparte. */
    public function test_una_sala_que_nunca_compro_no_tiene_ritmo_todavia(): void
    {
        $mapa = $this->mapa();

        $this->assertSame([], $mapa['distritos']['06-15']['porVisitar']);
        $this->assertSame([0, 2], [$mapa['departamentos']['06']['faltan'], $mapa['departamentos']['06']['sinRitmo']]);
        $this->assertSame(0.0, $mapa['totales']['ventas']);
    }

    public function test_las_salas_sin_distrito_se_cuentan_aparte(): void
    {
        $this->ventasDeEjemplo();

        $mapa = $this->mapa();

        $this->assertSame(['salas' => 1, 'ventas' => 25.0, 'sinSala' => 0.0], $mapa['sinUbicacion']);
        $this->assertSame(90.0, array_sum(array_column($mapa['departamentos'], 'ventas')));
        $this->assertSame(2, array_sum(array_column($mapa['departamentos'], 'salas')));
    }

    public function test_rutas_que_pasan_por_cada_zona(): void
    {
        $mapa = $this->mapa();

        // Por la ruta de sus salas y por la cobertura.
        $this->assertSame([$this->oriente->id], $mapa['distritos']['06-14']['rutas']);
        $this->assertSame([$this->costa->id], $mapa['distritos']['06-15']['rutas']);
        $this->assertSame([$this->costa->id], $mapa['distritos']['12-01']['rutas']);
        $this->assertSame(0, $mapa['distritos']['12-01']['salas']);
        $this->assertEqualsCanonicalizing([$this->oriente->id, $this->costa->id], $mapa['departamentos']['06']['rutas']);
        $this->assertSame([$this->costa->id], $mapa['departamentos']['12']['rutas']);
        $this->assertSame(['id' => $this->costa->id, 'nombre' => 'Ruta Costa'], $mapa['rutas'][0]);
        // Cuántos distritos tiene cada departamento: la lista dice cuántos quedan sin salas.
        $this->assertSame([2, 1], [$mapa['departamentos']['06']['distritos'], $mapa['departamentos']['12']['distritos']]);
        $this->assertArrayNotHasKey('distritos', $mapa['distritos']['06-14']);
        $this->assertArrayNotHasKey('distritosSinSalas', $mapa['totales']);
    }

    public function test_filtro_de_ruta(): void
    {
        $this->ventasDeEjemplo();

        $mapa = $this->mapa(ruta: $this->oriente->id);

        $this->assertSame(90.0, $mapa['totales']['ventas']);
        $this->assertSame(1, $mapa['totales']['salas']);
        $this->assertSame(0, $mapa['distritos']['06-15']['salas'] ?? 0);
        $this->assertSame(['salas' => 0, 'ventas' => 0.0, 'sinSala' => 0.0], $mapa['sinUbicacion']);
        // Las rutas que pasan no dependen del filtro: el modo «Rutas» las necesita todas.
        $this->assertSame([$this->costa->id], $mapa['distritos']['06-15']['rutas']);
        // Ni las salas que tiene la zona: Sur es de Costa, así que la zona no está vacía.
        $this->assertSame(1, $mapa['distritos']['06-15']['todas']);
        $this->assertSame(2, $mapa['departamentos']['06']['todas']);
    }

    /**
     * «Quién vendió más»: lo mismo que el mapa, en palabras. Primero los que vendieron, del que
     * más al que menos; después los que tienen salas y nadie compró; al final los sin salas.
     * Adentro de un departamento, sus distritos, y cuántos quedan sin salas.
     */
    public function test_la_lista_de_ventas_va_del_que_mas_vendio_al_que_menos(): void
    {
        $this->ventasDeEjemplo();
        $puerto = $this->distrito($this->departamento('05', 'La Libertad'), '01', 'Distrito Puerto');
        $this->documento($this->sala('Sala de prueba Puerto', $this->costa, $puerto), '2026-11-03', 200);
        $this->sala('Sala de prueba Izalco', $this->oriente, $this->distrito($this->departamento('03', 'Sonsonate'), '01', 'Distrito Izalco'));
        // Un distrito de San Salvador sin salas ni zona: no viaja en los datos, pero se cuenta.
        $this->distrito(Departamento::where('codigo', '06')->first(), '16', 'Distrito Cerro');

        $listas = app(MapaVentas::class)->listas($this->mapa());

        $this->assertSame(['05', '06'], $listas['pais']['ventas']['con']);
        $this->assertSame(['03'], $listas['pais']['ventas']['cero']);
        // San Miguel: Costa lo cubre y todavía no hay salas.
        $this->assertSame(['12'], $listas['pais']['ventas']['sin']);
        $this->assertSame(200.0, $listas['pais']['ventas']['maximo']);
        $this->assertSame(['06-14'], $listas['06']['ventas']['con']);
        $this->assertSame(['06-15'], $listas['06']['ventas']['cero']);
        $this->assertSame(1, $listas['06']['ventas']['sinSalas']);
        $this->assertSame([[], [], 1], [$listas['12']['ventas']['con'], $listas['12']['ventas']['cero'], $listas['12']['ventas']['sinSalas']]);

        $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa'))->assertOk()
            ->assertSeeInOrder(['Quién vendió más', 'La Libertad', '$200.00', '1 sala · compró', 'San Salvador', '$90.00', '2 salas · compró 1',
                'Tienen salas y este mes nadie compró', 'Sonsonate', '1 sala', 'Sin salas', 'San Miguel', 'todavía no vendemos acá'])
            ->assertSeeInOrder(['Distritos de San Salvador', 'Distrito Centro', '$90.00', 'Tienen salas y este mes nadie compró', 'Distrito Playa',
                'El otro distrito de San Salvador no tiene salas.'])
            ->assertSeeInOrder(['Lugar', 'Vendido', 'Cambio', 'Salas', 'Compraron', 'Atrasadas', 'Por visitar', 'Zonas', 'Última entrega']);
    }

    /**
     * Con una zona elegida, el mapa apaga lo que esa zona no cubre (`fuera`): un lugar donde la
     * zona no pasa y no tiene salas ni ventas suyas. Las listas no lo cuentan como «sin salas».
     */
    public function test_con_una_zona_elegida_lo_que_no_cubre_queda_fuera(): void
    {
        $this->ventasDeEjemplo();
        $puerto = $this->distrito($this->departamento('05', 'La Libertad'), '01', 'Distrito Puerto');
        $this->documento($this->sala('Sala de prueba Puerto', $this->costa, $puerto), '2026-11-03', 200);
        $servicio = app(MapaVentas::class);

        // Sin zona elegida nada queda fuera.
        $todas = $this->mapa();
        $this->assertSame([false, false, false], [$todas['departamentos']['05']['fuera'], $todas['departamentos']['12']['fuera'], $todas['distritos']['06-15']['fuera']]);

        // Oriente: La Libertad y San Miguel son de Costa; el distrito de Sur, también.
        $oriente = $this->mapa(ruta: $this->oriente->id);
        $this->assertSame([false, true, true], [$oriente['departamentos']['06']['fuera'], $oriente['departamentos']['05']['fuera'], $oriente['departamentos']['12']['fuera']]);
        $this->assertSame([false, true], [$oriente['distritos']['06-14']['fuera'], $oriente['distritos']['06-15']['fuera']]);
        $listas = $servicio->listas($oriente);
        $this->assertSame([['06'], [], []], [$listas['pais']['ventas']['con'], $listas['pais']['ventas']['cero'], $listas['pais']['ventas']['sin']]);
        $this->assertSame(0, $listas['06']['ventas']['sinSalas']);

        // Costa: San Miguel no queda fuera (lo cubre) y sigue sin salas; San Salvador tiene a Sur, que no compró.
        $costa = $this->mapa(ruta: $this->costa->id);
        $this->assertFalse($costa['departamentos']['12']['fuera']);
        $listas = $servicio->listas($costa);
        $this->assertSame([['05'], ['06'], ['12']], [$listas['pais']['ventas']['con'], $listas['pais']['ventas']['cero'], $listas['pais']['ventas']['sin']]);

        $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa', ['ruta' => $this->costa->id]))->assertOk()
            ->assertSeeInOrder(['Quién vendió más', 'La Libertad', 'Tienen salas y este mes nadie compró', 'San Salvador', 'Sin salas', 'San Miguel', 'sin salas de esta zona'])
            ->assertSee('"fuera":false', false);
        $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa', ['ruta' => $this->oriente->id]))->assertOk()
            ->assertSee('"fuera":true', false);
    }

    /**
     * «A dónde ir primero»: por urgencia. Primero donde hay más salas atrasadas; a igual
     * cantidad, donde hay más por visitar; después, por nombre. Los lugares al día no se listan.
     */
    public function test_a_donde_ir_va_por_urgencia(): void
    {
        $distrito = fn (string $dep, string $nombre) => $this->distrito($this->departamento($dep, $nombre), '01', 'Distrito de '.$nombre);
        $sonsonate = $distrito('03', 'Sonsonate');
        $libertad = $distrito('05', 'La Libertad');
        $santaAna = $distrito('02', 'Santa Ana');
        $sanMiguel = Distrito::where('nombre', 'Distrito Oriente')->first();
        $this->atrasada('Sala de prueba A', $this->oriente, $sonsonate);
        $this->atrasada('Sala de prueba B', $this->oriente, $sonsonate);
        $this->atrasada('Sala de prueba C', $this->oriente, $libertad);
        $this->porVisitar('Sala de prueba D', $this->costa, $libertad);
        $this->atrasada('Sala de prueba E', $this->costa, Distrito::where('nombre', 'Distrito Playa')->first());
        $this->atrasada('Sala de prueba F', $this->oriente, $santaAna);
        $this->porVisitar('Sala de prueba G', $this->costa, $sanMiguel);
        $this->porVisitar('Sala de prueba H', $this->costa, $sanMiguel);
        // Ahuachapán: una sala sin ritmo todavía. No está atrasada ni por visitar.
        $this->sala('Sala de prueba I', $this->oriente, $distrito('01', 'Ahuachapán'));

        $listas = app(MapaVentas::class)->listas($this->mapa());

        // Sonsonate (2 atrasadas), La Libertad (1 y 1 por visitar), San Salvador y Santa Ana (1), San Miguel (2 por visitar).
        $this->assertSame(['03', '05', '06', '02', '12'], $listas['pais']['ir']['con']);
        $this->assertSame(1, $listas['pais']['ir']['alDia']);
        // Adentro de San Salvador: Playa (la atrasada); Centro, con Norte sin ritmo, está al día.
        $this->assertSame([['06-15'], 1], [$listas['06']['ir']['con'], $listas['06']['ir']['alDia']]);

        $this->actingAs($this->usuario('jefe_vendedores'))->get(route('rutas.mapa'))->assertOk()
            ->assertSeeInOrder(['A dónde ir primero', 'Sonsonate', 'Atrasada', '2 salas atrasadas', 'Zona Ruta Oriente',
                'La Libertad', 'Atrasada', '1 sala atrasada y 1 por visitar', 'Zonas Ruta Costa y Ruta Oriente',
                'San Salvador', '1 sala atrasada', 'Santa Ana', '1 sala atrasada',
                'San Miguel', 'Por visitar', '2 salas por visitar', 'Zona Ruta Costa',
                'El otro departamento con salas está al día.'])
            ->assertSeeInOrder(['A dónde ir en San Salvador', 'Distrito Playa', 'Atrasada', '1 sala atrasada', 'El otro distrito con salas está al día.']);
    }

    public function test_sin_salas_atrasadas_ni_por_visitar_la_lista_lo_dice(): void
    {
        $listas = app(MapaVentas::class)->listas($this->mapa());

        $this->assertSame([[], 1], [$listas['pais']['ir']['con'], $listas['pais']['ir']['alDia']]);
        $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa'))->assertOk()
            ->assertSeeInOrder(['Salas para visitar', '>0<', 'Ninguna atrasada ni por visitar'], false)
            ->assertSeeInOrder(['A dónde ir primero', 'Ninguna sala atrasada ni por visitar', 'Todos los departamentos con salas están al día.']);
    }

    /** Como en PropuestaRutas: el distrito con cobertura propia es de su ruta, no de la del departamento. */
    public function test_la_cobertura_del_distrito_gana_a_la_del_departamento(): void
    {
        $sanMiguel = Departamento::where('codigo', '12')->first();
        $this->distrito($sanMiguel, '02', 'Distrito Volcán');
        $tercero = $this->distrito($sanMiguel, '03', 'Distrito Laguna');
        RutaCobertura::create(['ruta_id' => $this->oriente->id, 'distrito_id' => Distrito::where('departamento_id', $sanMiguel->id)->where('codigo', '01')->value('id')]);
        // La cobertura de una ruta inactiva no le quita el distrito a nadie.
        $vieja = Ruta::create(['nombre' => 'Ruta Vieja', 'activa' => false]);
        RutaCobertura::create(['ruta_id' => $vieja->id, 'distrito_id' => $tercero->id]);

        $mapa = $this->mapa();

        $this->assertSame([$this->oriente->id], $mapa['distritos']['12-01']['rutas']);
        $this->assertSame([$this->costa->id], $mapa['distritos']['12-02']['rutas']);
        $this->assertSame([$this->costa->id], $mapa['distritos']['12-03']['rutas']);
        // El departamento tiene las dos: Costa lo cubre y Oriente pasa por uno de sus distritos.
        $this->assertSame([$this->costa->id, $this->oriente->id], $mapa['departamentos']['12']['rutas']);
    }

    /**
     * Un mes pasado cuenta las salas que ya existían: una dada de alta después no es «sin
     * compra» en ese mes ni baja el «compraron X de N».
     */
    public function test_en_un_mes_pasado_no_cuentan_las_salas_dadas_de_alta_despues(): void
    {
        $this->ventasDeEjemplo();
        $this->travelTo('2026-11-05 09:00:00');
        $this->sala('Sala de prueba nueva', $this->oriente, Distrito::where('codigo', '14')->first());
        $this->travelTo('2026-11-09 10:00:00');

        $octubre = $this->mapa('2026-10');

        // Norte, Sur y la sala sin lugar; la nueva no existía.
        $this->assertSame(3, $octubre['totales']['salas']);
        $this->assertSame(2, $octubre['totales']['compraron']);
        $this->assertSame([1, 1], [$octubre['distritos']['06-14']['salas'], $octubre['distritos']['06-14']['sinRitmo']]);

        // En noviembre sí está, todavía sin ritmo; Norte ya tiene el suyo.
        $noviembre = $this->mapa();
        $this->assertSame(4, $noviembre['totales']['salas']);
        $this->assertSame([2, 1], [$noviembre['distritos']['06-14']['salas'], $noviembre['distritos']['06-14']['sinRitmo']]);
    }

    /**
     * En un mes pasado, los días sin compra y la última entrega se cuentan al cierre del mes, y
     * la pantalla lo dice. La comparación dice contra qué tramo es.
     */
    public function test_un_mes_pasado_se_cuenta_al_cierre_y_dice_contra_que_compara(): void
    {
        $this->ventasDeEjemplo();
        $this->entregada($this->norte, $this->oriente, '2026-10-21 11:00:00');
        $admin = $this->usuario('administrador');

        // La entrega del 04/11 es posterior al cierre de octubre.
        $octubre = $this->mapa('2026-10');
        $this->assertSame('2026-10-21', $octubre['distritos']['06-14']['entrega']);
        $this->assertSame(10, $octubre['distritos']['06-14']['entregaDias']);
        $this->assertSame('2026-11-04', $this->mapa()['distritos']['06-14']['entrega']);

        $html = $this->actingAs($admin)->get(route('rutas.mapa', ['mes' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('Contadas al 31/10, el cierre del mes', $html);
        $this->assertStringContainsString('se cuentan al 31/10, el cierre del mes', $html);
        $this->assertStringContainsString('21/10/2026', $html);
        $this->assertStringContainsString('frente a septiembre', $html);
        $this->assertStringContainsString('Vendido en octubre', $html);
        $this->assertStringContainsString('"corte":"31\/10"', $html);

        // El mes en curso compara contra el mismo tramo, y lo dice también en la tabla.
        $html = $this->actingAs($admin)->get(route('rutas.mapa'))->assertOk()->getContent();
        $this->assertStringContainsString('frente al 1 al 9 de octubre', $html);
        $this->assertStringContainsString('Vendido en noviembre', $html);
        $this->assertStringNotContainsString('frente al mes anterior', $html);
        $this->assertStringContainsString('"corte":null', $html);
        $this->assertStringNotContainsString('el cierre del mes', $html);
    }

    public function test_filtro_de_mes_compara_contra_el_mes_anterior_completo(): void
    {
        $this->ventasDeEjemplo();

        $mapa = $this->mapa('2026-10');

        // Octubre entero: 80 − 30 + 500, y el CCF de Sur. Septiembre: nada.
        $this->assertSame(550.0, $mapa['distritos']['06-14']['ventas']);
        $this->assertSame(60.0, $mapa['distritos']['06-15']['ventas']);
        $this->assertSame(0.0, $mapa['distritos']['06-14']['anterior']);
        $this->assertFalse($mapa['enCurso']);
        $this->assertSame(['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'anteriorDesde' => '2026-09-01', 'anteriorHasta' => '2026-09-30'], $mapa['periodo']);
        // Sur compra cada 10 días: al 31/10 lleva 30 sin visita, atrasada 20.
        foreach (['2026-09-11', '2026-09-21'] as $fecha) {
            $this->documento($this->sur, $fecha, 10);
        }
        $mapa = $this->mapa('2026-10');
        $this->assertSame([['nombre' => 'Sala de prueba Sur', 'lugar' => 'Distrito Playa', 'estado' => Ritmo::ATRASADA, 'dias' => 30, 'faltan' => -20]], $mapa['distritos']['06-15']['porVisitar']);
        $this->assertSame(1, $mapa['distritos']['06-15']['atrasadas']);
    }

    public function test_el_mismo_tramo_no_se_pasa_del_fin_del_mes_anterior(): void
    {
        $this->travelTo('2026-03-31 10:00:00');

        $this->assertSame(['desde' => '2026-03-01', 'hasta' => '2026-03-31', 'anteriorDesde' => '2026-02-01', 'anteriorHasta' => '2026-02-28'], $this->mapa('2026-03')['periodo']);
    }

    public function test_quien_entra_al_mapa(): void
    {
        foreach (['administrador' => 200, 'jefe_vendedores' => 200, 'jefatura' => 200, 'facturacion' => 200, 'vendedor' => 403, 'contabilidad' => 403, 'produccion' => 403] as $rol => $estado) {
            $this->actingAs($this->usuario($rol))->get(route('rutas.mapa'))->assertStatus($estado);
        }
        $this->get(route('rutas.mapa'))->assertStatus(403);
        auth()->logout();
        $this->get(route('rutas.mapa'))->assertRedirect(route('login'));
    }

    public function test_el_jefe_ve_los_montos_del_mapa_pero_no_la_pantalla_de_ventas(): void
    {
        $this->ventasDeEjemplo();
        $jefe = $this->usuario('jefe_vendedores');

        $this->assertTrue($jefe->can('rutas.mapa.ver'));
        $this->assertFalse($jefe->can('rutas.ventas.ver'));
        $this->actingAs($jefe)->get(route('rutas.ventas.index'))->assertForbidden();
        $this->actingAs($jefe)->get(route('rutas.mapa'))->assertOk()->assertSee('"ventas":90', false);
    }

    /**
     * Quien no ve Ventas (el jefe de vendedores, Facturación) mira lo suyo en Resumen y Mapa,
     * y ahí la exportación no entra ni sumada (Melqui, 11/11/2026).
     */
    public function test_el_resumen_y_el_mapa_del_tablero_no_muestran_cifras_de_exportacion(): void
    {
        $this->documento($this->norte, '2026-11-03', 100);
        $this->documento($this->norte, '2026-11-04', 7654.32, ['tipo_dte' => '11'], ['venta_gravada' => 0, 'venta_exportacion' => 7654.32]);

        foreach (['jefe_vendedores', 'facturacion'] as $rol) {
            $usuario = $this->usuario($rol);
            $resumen = $this->actingAs($usuario)->get(route('rutas.dashboard'))->assertOk()->assertSee('$100.00')->getContent();
            $mapa = $this->actingAs($usuario)->get(route('rutas.mapa'))->assertOk()->assertSee('"ventas":100', false)->getContent();
            // Ni el monto de la exportación ni la suma con lo nacional (7,754.32).
            foreach (['7654', '7,654', '7754', '7,754'] as $cifra) {
                $this->assertStringNotContainsString($cifra, $resumen);
                $this->assertStringNotContainsString($cifra, $mapa);
            }
        }
    }

    public function test_el_tablero_ofrece_la_pestana_del_mapa_solo_a_quien_puede_verlo(): void
    {
        $this->actingAs($this->usuario('jefe_vendedores'))->get(route('rutas.dashboard'))
            ->assertOk()->assertSee(route('rutas.mapa'), false);

        // Con `rutas.ver` y sin `rutas.mapa.ver`, la pestaña no se dibuja.
        $sinMapa = User::factory()->create(['activo' => true])->givePermissionTo('rutas.ver');
        $this->actingAs($sinMapa)->get(route('rutas.dashboard'))
            ->assertOk()->assertDontSee(route('rutas.mapa'), false);
    }

    public function test_valida_el_mes_y_la_ruta(): void
    {
        $admin = $this->usuario('administrador');

        foreach (['2026-13', 'noviembre', '2026-12', '2025-10', '2026-11-01'] as $mes) {
            $this->actingAs($admin)->getJson(route('rutas.mapa', ['mes' => $mes]))->assertUnprocessable()->assertJsonValidationErrors('mes');
        }
        foreach (['abc', '999999', '-1'] as $ruta) {
            $this->actingAs($admin)->getJson(route('rutas.mapa', ['ruta' => $ruta]))->assertUnprocessable()->assertJsonValidationErrors('ruta');
        }
        $this->actingAs($admin)->get(route('rutas.mapa', ['mes' => '2025-11', 'ruta' => $this->oriente->id]))->assertOk();
    }

    public function test_la_pantalla_lleva_los_datos_escapados_y_la_atribucion(): void
    {
        $rara = $this->sala('Sala </script><b>de prueba</b>', $this->oriente, Distrito::where('codigo', '14')->first());
        // Atrasada: su nombre viaja en la lista de la ficha.
        foreach (['2026-09-11', '2026-09-21', '2026-10-01'] as $fecha) {
            $this->documento($rara, $fecha, 10);
        }

        $html = $this->actingAs($this->usuario('administrador'))->get(route('rutas.mapa'))->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Mapa de ventas')
            ->assertSee('vendor/d3/d3.min.js', false)
            ->assertSee('geoBoundaries')
            ->assertSee('OpenStreetMap')
            ->assertSee('1 sala sin ubicación')
            ->getContent();

        // El nombre viaja escapado dentro del JSON («<» va como <): no cierra el <script>.
        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertStringContainsString(trim(json_encode('Sala </script>', JSON_HEX_TAG), '"'), $html);

        // Solo la ficha avisa al lector de pantalla; la leyenda se redibuja en cada clic.
        $this->assertMatchesRegularExpression('/id="mapa-ficha"[^>]*aria-live="polite"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="mapa-leyenda"[^>]*aria-live/', $html);
        // rutas-mapa.js le suma el #hash a este formulario al filtrar.
        $this->assertStringContainsString('id="mapa-filtros"', $html);
    }

    public function test_hace_las_mismas_consultas_con_mas_salas_y_zonas(): void
    {
        $admin = $this->usuario('administrador');
        $sanSalvador = Departamento::where('codigo', '06')->first();

        $poblar = function (int $cuantas) use ($sanSalvador) {
            for ($i = 0; $i < $cuantas; $i++) {
                $distrito = $this->distrito($sanSalvador, (string) (40 + $this->correlativo), 'Distrito '.Str::random(6));
                $ruta = Ruta::create(['nombre' => 'Ruta '.Str::random(6), 'activa' => true]);
                RutaCobertura::create(['ruta_id' => $ruta->id, 'distrito_id' => $distrito->id]);
                $sala = $this->sala('Sala '.Str::random(6), $ruta, $distrito);
                $this->documento($sala, '2026-11-02', 10);
                $this->documento($sala, '2026-10-02', 10);
                $this->entregada($sala, $ruta, '2026-11-03 09:00:00');
            }
        };

        $contar = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route('rutas.mapa'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $poblar(1);
        $contar(); // La primera vez se cargan los permisos en caché.
        $pocas = $contar();
        $poblar(6);
        $muchas = $contar();

        $this->assertSame($pocas, $muchas, 'El mapa no debería hacer una consulta por sala, zona o ruta.');
        $this->assertLessThan(25, $muchas);
    }
}
