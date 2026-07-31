<?php

namespace Tests\Feature\Planta;

use App\Models\Dte;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Secuencia;
use App\Services\Planta\PlantaRecepcionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Numeración propia de las recepciones.
 *
 * Es un contador del MÓDULO, no fiscal. Estas pruebas fijan las dos cosas que
 * importan: que los números no se repiten, y que este contador no roza nada del
 * dominio fiscal —ni `numero_sistema`, ni los correlativos del MH—.
 *
 * LO QUE NO SE PRUEBA AQUÍ: que el bloqueo de fila funcione bajo concurrencia
 * real. La suite corre en SQLite, donde `lockForUpdate` es un no-op y la base se
 * serializa entera, así que pasaría igual sin bloqueo. Eso se verifica con
 * `planta:diagnostico-concurrencia` contra MySQL y con procesos separados.
 */
class PlantaRecepcionNumeracionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    public function test_cada_recepcion_recibe_un_numero_distinto(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        $numeros = [];

        for ($i = 0; $i < 5; $i++) {
            $numeros[] = $this->servicioRecepcion()
                ->crearBorrador($this->payload($ubicacion, [$this->linea($insumo)]), $usuario)
                ->numero;
        }

        $this->assertCount(5, array_unique($numeros));
        $this->assertSame([1, 2, 3, 4, 5], $numeros, 'La serie arranca en 1 y avanza de uno en uno.');
    }

    public function test_el_numero_se_asigna_al_crear_el_borrador(): void
    {
        $recepcion = $this->borrador();

        // La columna es NOT NULL: un borrador sin número no podría existir, y la
        // operación se refiere a «la recepción 1» desde que la guarda.
        $this->assertNotNull($recepcion->numero);
        $this->assertSame(1, $recepcion->numero);
    }

    public function test_anular_un_borrador_deja_un_hueco_y_no_reutiliza_el_numero(): void
    {
        $anulada = $this->borrador();
        $this->servicioRecepcion()->anular($anulada);

        $siguiente = $this->borrador();

        // El hueco es el precio de numerar al crear, y es aceptable porque esta
        // numeración NO es fiscal: no hay obligación de continuidad.
        $this->assertSame($anulada->numero + 1, $siguiente->numero);
    }

    public function test_la_secuencia_usa_su_propia_clave(): void
    {
        $this->borrador();

        $this->assertSame(1, Secuencia::ultimo(PlantaRecepcionService::CLAVE_SECUENCIA));
        $this->assertSame('planta_recepcion', PlantaRecepcionService::CLAVE_SECUENCIA);
    }

    public function test_la_secuencia_exige_transaccion(): void
    {
        // `RefreshDatabase` envuelve cada prueba en una transacción, así que aquí
        // el nivel vale 1 y la guarda no podría observarse. Se cierra el
        // envoltorio a propósito para verla con el nivel realmente en 0, y se
        // restituye al salir para que el tearDown encuentre lo que espera.
        DB::rollBack();

        try {
            $this->assertSame(0, DB::transactionLevel());

            $lanzada = null;

            try {
                Secuencia::siguiente(PlantaRecepcionService::CLAVE_SECUENCIA);
            } catch (\LogicException $e) {
                $lanzada = $e;
            }

            // Es lo que hace segura la numeración: sin transacción el FOR UPDATE se
            // libera de inmediato y dos procesos leerían el mismo valor.
            $this->assertInstanceOf(\LogicException::class, $lanzada);
            $this->assertStringContainsString('planta_recepcion', $lanzada->getMessage());
        } finally {
            DB::beginTransaction();
        }
    }

    // --- Aislamiento del dominio fiscal ---

    public function test_no_toca_la_secuencia_del_numero_sistema(): void
    {
        $antes = Secuencia::ultimo(Secuencia::NUMERO_SISTEMA);

        $this->borrador();
        $this->borrador();

        $this->assertSame($antes, Secuencia::ultimo(Secuencia::NUMERO_SISTEMA));
    }

    public function test_no_crea_ni_toca_dte_ni_correlativos(): void
    {
        $dtesAntes = Dte::count();
        $correlativosAntes = Schema::hasTable('correlativos') ? DB::table('correlativos')->count() : 0;

        $recepcion = $this->borrador();
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->assertSame($dtesAntes, Dte::count(), 'Una recepción no emite documentos fiscales.');

        if (Schema::hasTable('correlativos')) {
            $this->assertSame($correlativosAntes, DB::table('correlativos')->count());
        }
    }

    public function test_la_recepcion_no_tiene_columna_numero_sistema(): void
    {
        // El número de la recepción es suyo y se llama `numero`. Que no exista
        // `numero_sistema` deja claro que no comparte serie con la facturación.
        $this->assertFalse(Schema::hasColumn('planta_recepciones', 'numero_sistema'));
        $this->assertTrue(Schema::hasColumn('planta_recepciones', 'numero'));
    }

    public function test_el_lote_interno_usa_su_propio_contador_diario(): void
    {
        $recepcion = $this->borrador();
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $lote = $recepcion->refresh()->detalles->first()->lote;

        $this->assertSame('INT-20260730-0001', $lote->codigo_interno);
        $this->assertSame(1, Secuencia::ultimo('planta_lote_interno_20260730'));
    }

    public function test_dos_recepciones_del_mismo_dia_reciben_correlativos_de_lote_distintos(): void
    {
        $primera = $this->borrador();
        $this->servicioRecepcion()->confirmar($primera, $this->admin());

        $segunda = $this->borrador();
        $this->servicioRecepcion()->confirmar($segunda, $this->admin());

        $codigos = PlantaRecepcion::with('detalles.lote')->get()
            ->flatMap->detalles->pluck('lote.codigo_interno')->all();

        $this->assertSame(['INT-20260730-0001', 'INT-20260730-0002'], $codigos);
    }
}
