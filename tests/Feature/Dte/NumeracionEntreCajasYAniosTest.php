<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Emisiones seguidas y cruzadas entre cajas, de diciembre de 2026 a enero de 2027: nunca sale
 * un número repetido en una serie, y el primer documento de 2027 sin número fijado sale con
 * el 1. Lo que la oficina fija a mano, antes o después del primer documento del año, se
 * respeta.
 */
class NumeracionEntreCajasYAniosTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $p001;

    private PuntoVenta $p002;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->p001] = $this->crearEmisorDte();
        $this->p002 = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
    }

    private function en(string $fechaHora): void
    {
        $this->travelTo(Carbon::parse($fechaHora, HoraNegocio::zona()));
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Crea el correlativo por la pantalla, como la oficina. */
    private function crear(string $tipo, ?PuntoVenta $caja, int $ultimo = 0): Correlativo
    {
        $this->actingAs($this->admin())->post(route('configuracion.correlativos.store'), [
            'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $caja?->id ?? '',
            'ambiente' => '00', 'ultimo_numero' => (string) $ultimo, 'activo' => '1',
        ])->assertSessionHasNoErrors();

        return Correlativo::where('tipo_dte', $tipo)->where('punto_venta_id', $caja?->id)->firstOrFail();
    }

    /** Lo edita por la pantalla, como la oficina. */
    private function editar(Correlativo $correlativo, array $cambios): void
    {
        $correlativo->refresh();
        $this->actingAs($this->admin())->put(route('configuracion.correlativos.update', $correlativo), $cambios + [
            'tipo_dte' => $correlativo->tipo_dte->value,
            'establecimiento_id' => $correlativo->establecimiento_id,
            'punto_venta_id' => $correlativo->punto_venta_id,
            'ambiente' => $correlativo->ambiente->value,
            'serie' => $correlativo->serie,
            'ultimo_numero' => $correlativo->ultimo_numero,
            'activo' => $correlativo->activo ? '1' : '0',
        ])->assertSessionHasNoErrors();
    }

    /** Como en producción, el reloj avanza entre un documento y el siguiente. */
    private function emitir(PuntoVenta $caja, TipoDte $tipo = TipoDte::CreditoFiscal, ?Dte $ccf = null): Dte
    {
        $this->travel(1)->seconds();
        $borradores = app(DteBorradorService::class);
        if ($tipo === TipoDte::NotaCredito) {
            $dte = $borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago', 'motivo' => 'Descuento inventado']);
            $borradores->agregarConceptoNotaCredito($dte, ['descripcion' => 'Descuento de prueba', 'monto' => 1, 'tipo_impuesto' => 'gravado']);
        } else {
            $cliente = match ($tipo) {
                TipoDte::FacturaExportacion => Cliente::factory()->exportacion()->create(),
                default => Cliente::factory()->contribuyente()->create(),
            };
            $datos = ['tipo_dte' => $tipo, 'cliente_id' => $cliente, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $caja->id];
            if ($tipo === TipoDte::FacturaExportacion) {
                $datos += ['tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09'];
            }
            $dte = $borradores->crearBorrador($datos);
            $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        }
        app(DteGeneracionService::class)->generar($dte->refresh());

        return $dte->refresh();
    }

    private function sellar(Dte $ccf): Dte
    {
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->sello_recepcion = 'SELLO-INVENTADO-DE-PRUEBA-'.$ccf->id;
        $ccf->fecha_procesamiento_mh = now();
        $ccf->saveQuietly();

        return $ccf;
    }

    private function n(Dte $dte): int
    {
        return (int) substr((string) $dte->numero_interno, -15);
    }

    /** Ningún número se repite en una serie del mismo ejercicio, ni en el número de control. */
    private function assertSinRepetidos(): void
    {
        $docs = Dte::withTrashed()->whereNotNull('numero_interno')->get();
        $porSerie = $docs->groupBy(fn (Dte $d) => substr((string) $d->numero_interno, 0, -15));
        foreach ($porSerie as $serie => $grupo) {
            $numeros = $grupo->map(fn (Dte $d) => $this->n($d));
            $this->assertSame($numeros->count(), $numeros->unique()->count(), "Número repetido en la serie {$serie}.");
        }
        $controles = $docs->map(fn (Dte $d) => $d->ambiente->value.'|'.$d->ejercicio.'|'.$d->numero_control)->filter();
        $this->assertSame($controles->count(), $controles->unique()->count(), 'Número de control repetido en el mismo ejercicio.');
    }

    /** Cuántas veces la auditoría anota el último número del correlativo como un cambio a mano. */
    private function cambiosAMano(Correlativo $correlativo): int
    {
        return Activity::query()
            ->where('subject_type', $correlativo->getMorphClass())
            ->where('subject_id', $correlativo->id)
            ->where('description', 'modificó el correlativo')
            ->get()
            ->filter(fn (Activity $a) => isset($a->properties['attributes']['ultimo_numero']))
            ->count();
    }

    /**
     * Con el reloj andando, el avance normal del motor queda como consumo y no como un cambio a
     * mano: si no, el motor tomaba cada documento por un número fijado por la oficina.
     */
    public function test_el_avance_del_motor_con_el_reloj_andando_es_consumo_y_no_cambio_a_mano(): void
    {
        $this->en('2026-12-30 10:00:00');
        $caja = $this->crear('03', $this->p001);
        $this->emitir($this->p001);
        $this->travel(10)->minutes();
        $this->emitir($this->p001);

        $this->assertSame(0, $this->cambiosAMano($caja));
        $this->assertSame(2, Activity::query()
            ->where('subject_type', $caja->getMorphClass())
            ->where('subject_id', $caja->id)
            ->where('description', 'consumió el correlativo')
            ->count());
        $this->assertNull($caja->refresh()->numeroFijadoAManoEl());
    }

    /** Una edición a mano de un solo número más tampoco es un consumo. */
    public function test_subir_uno_a_mano_no_es_consumo(): void
    {
        $this->en('2027-01-04 09:00:00');
        $caja = $this->crear('03', $this->p001, 5);
        $this->travel(5)->minutes();
        $this->editar($caja, ['ultimo_numero' => '6']);

        $this->assertSame(1, $this->cambiosAMano($caja));
        $this->assertNotNull($caja->refresh()->numeroFijadoAManoEl());
    }

    public function test_dos_cajas_cruzadas_entre_diciembre_y_enero_con_general_y_por_caja(): void
    {
        $this->en('2026-11-10 10:00:00');
        $propio = $this->crear('03', $this->p001);
        $general = $this->crear('03', null);

        $this->en('2026-12-30 10:00:00');
        $this->assertSame(1, $this->n($this->emitir($this->p001)));
        $this->assertSame(1, $this->n($this->emitir($this->p002)));
        $this->assertSame(2, $this->n($this->emitir($this->p001)));
        $this->assertSame(2, $this->n($this->emitir($this->p002)));
        $this->en('2026-12-31 23:59:30');
        $this->assertSame(3, $this->n($this->emitir($this->p002)));

        $this->en('2027-01-01 00:00:10');
        $this->assertSame(1, $this->n($this->emitir($this->p002)), 'El general vuelve a 1 en 2027.');
        $this->assertSame(1, $this->n($this->emitir($this->p001)), 'El de la caja vuelve a 1 en 2027.');
        $this->assertSame(2, $this->n($this->emitir($this->p002)));
        $this->assertSame(2, $this->n($this->emitir($this->p001)));

        // La caja 1 se queda sin su correlativo: sigue con el general, por su serie.
        $this->en('2027-01-02 09:00:00');
        $this->editar($propio, ['activo' => '0']);
        $this->assertSame(3, $this->n($this->emitir($this->p001)));
        $this->assertSame(3, $this->n($this->emitir($this->p002)));
        $this->assertSame(4, $this->n($this->emitir($this->p001)));

        // Vuelve su correlativo: no repite lo que numeró el general por ella.
        $this->editar($propio, ['activo' => '1']);
        $this->assertSame(5, $this->n($this->emitir($this->p001)));
        $this->assertSame(4, $this->n($this->emitir($this->p002)));
        $this->assertSame(6, $this->n($this->emitir($this->p001)));

        $this->assertSame(6, $propio->refresh()->ultimo_numero);
        $this->assertSame(4, $general->refresh()->ultimo_numero);
        $this->assertSame(0, $this->cambiosAMano($general), 'Nadie le cambió el número al general.');
        $this->assertSinRepetidos();
    }

    /**
     * Un correlativo creado en cero en 2026 y nunca usado. En enero de 2027 el sistema se cae y
     * Conta Portable emite el 1; la oficina pone 1. El primer documento del sistema sale con
     * el 2: el paso de 0 a 1 lo hizo la oficina, no el motor.
     */
    public function test_la_oficina_pone_uno_en_enero_en_un_correlativo_sin_documentos(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crear('03', $this->p001);

        $this->en('2027-01-04 09:00:00');
        $this->editar($caja, ['ultimo_numero' => '1']);

        $this->assertSame(2, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /** Lo mismo con documentos de 2026 que terminaron justo uno antes del número que se fija. */
    public function test_la_oficina_fija_el_siguiente_del_ultimo_de_2026_en_enero(): void
    {
        $this->en('2026-12-20 10:00:00');
        $caja = $this->crear('03', $this->p001, 27);
        $this->emitir($this->p001);
        $this->assertSame(29, $this->n($this->emitir($this->p001)));

        // Conta Portable emitió del 1 al 30 de 2027.
        $this->en('2027-01-04 09:00:00');
        $this->editar($caja, ['ultimo_numero' => '30']);

        $this->assertSame(31, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /**
     * La caja 1 numeró por fuera el 1 de 2027 y la oficina se lo puso a su correlativo, que
     * después desactiva. El general, que numera por ella, no repite ese 1.
     */
    public function test_el_general_no_repite_el_uno_que_la_oficina_fijo_a_la_caja_en_enero(): void
    {
        $this->en('2026-11-10 10:00:00');
        $caja = $this->crear('03', $this->p001);

        $this->en('2027-01-04 09:00:00');
        $this->editar($caja, ['ultimo_numero' => '1']);
        $this->editar($caja, ['activo' => '0']);
        $this->crear('03', null);

        $this->assertSame(2, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /** Fijado después del primer documento del año: sigue después del número fijado. */
    public function test_numero_fijado_despues_del_primer_documento_del_anio(): void
    {
        $this->en('2026-12-30 10:00:00');
        $caja = $this->crear('03', $this->p001);
        $this->emitir($this->p001);
        $this->emitir($this->p001);

        $this->en('2027-01-02 08:00:00');
        $this->assertSame(1, $this->n($this->emitir($this->p001)));
        $this->assertSame(2, $this->n($this->emitir($this->p001)));

        // Se cayó el sistema y Conta Portable siguió del 3 al 40.
        $this->en('2027-01-03 15:00:00');
        $this->editar($caja, ['ultimo_numero' => '40']);
        $this->assertSame(41, $this->n($this->emitir($this->p001)));
        $this->assertSame(42, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /** Fijado el 31 de diciembre en la noche: es de 2026 y el primer documento de 2027 sale con el 1. */
    public function test_numero_fijado_la_noche_del_31_de_diciembre_es_de_2026(): void
    {
        $this->en('2026-12-31 22:30:00');
        $caja = $this->crear('03', $this->p001);
        $this->editar($caja, ['ultimo_numero' => '900']);

        $this->en('2027-01-01 07:00:00');
        $this->assertSame(1, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /**
     * Un general para dos cajas. La caja 2 numeró por fuera el 2 y el 3 de 2027 y la oficina
     * pone 3 en el general, que es el mismo número que ya tiene la caja 1. La caja que emite
     * primero después del cambio respeta lo que fijó la oficina.
     */
    public function test_el_general_respeta_el_numero_fijado_aunque_coincida_con_otra_caja(): void
    {
        $this->en('2027-01-04 08:00:00');
        $general = $this->crear('03', null);
        $this->emitir($this->p001);
        $this->emitir($this->p001);
        $this->emitir($this->p001);
        $this->assertSame(1, $this->n($this->emitir($this->p002)));

        $this->editar($general, ['ultimo_numero' => '3']);

        $this->assertSame(4, $this->n($this->emitir($this->p002)));
        $this->assertSame(4, $this->n($this->emitir($this->p001)));
        $this->assertSinRepetidos();
    }

    /**
     * Los cuatro tipos (01, 03, 05 y 11) en las dos cajas, con contingencia de diciembre a enero:
     * cada serie arranca 2027 en 1, sin repetir, y los documentos llevan la contingencia.
     */
    public function test_los_cuatro_tipos_en_contingencia_cruzan_el_anio_sin_repetir(): void
    {
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local']);
        $this->en('2026-11-10 10:00:00');
        foreach (['01', '03', '05', '11'] as $tipo) {
            $this->crear($tipo, $this->p001);
            $this->crear($tipo, null);
        }

        $this->en('2026-12-31 20:00:00');
        $admin = $this->admin();
        app(ContingenciaService::class)->activar(3, 'Internet de prueba interrumpido', 'manual', $admin);
        $tipos = [TipoDte::Factura, TipoDte::CreditoFiscal, TipoDte::FacturaExportacion];
        $ccfs = [];
        foreach ([$this->p001, $this->p002] as $caja) {
            foreach ($tipos as $tipo) {
                $this->assertSame(1, $this->n($this->emitir($caja, $tipo)));
                $this->assertSame(2, $this->n($dte = $this->emitir($caja, $tipo)));
                if ($tipo === TipoDte::CreditoFiscal) {
                    $ccfs[$caja->codigo] = $this->sellar($dte);
                }
            }
            $this->assertSame(1, $this->n($this->emitir($caja, TipoDte::NotaCredito, $ccfs[$caja->codigo])));
        }

        $this->en('2027-01-01 00:05:00');
        foreach ([$this->p002, $this->p001] as $caja) {
            foreach ($tipos as $tipo) {
                $dte = $this->emitir($caja, $tipo);
                $this->assertSame(1, $this->n($dte), "Primer {$tipo->value} de 2027 en {$caja->codigo}.");
                $this->assertNotNull($dte->contingencia_id);
                $this->assertSame(2027, (int) $dte->ejercicio);
            }
            $this->assertSame(1, $this->n($this->emitir($caja, TipoDte::NotaCredito, $ccfs[$caja->codigo])));
            $this->assertSame(2, $this->n($this->emitir($caja, TipoDte::NotaCredito, $ccfs[$caja->codigo])));
        }
        $this->assertSame(2, $this->n($this->emitir($this->p002, TipoDte::CreditoFiscal)));
        $this->assertSinRepetidos();
    }
}
