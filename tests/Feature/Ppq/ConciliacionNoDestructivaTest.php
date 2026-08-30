<?php

namespace Tests\Feature\Ppq;

use App\Enums\OrigenConciliacionPpq;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqConciliacion;
use App\Models\PpqConciliacionMovimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ConciliadorPpq;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La conciliación NO puede borrar un cobro que ya estaba registrado.
 *
 * Es la prueba de la regresión más cara que tenía el módulo: cada corrida recorría todos
 * los renglones del lote y al que no encontraba en el TXT le vaciaba el estado, la fecha y
 * el importe. Bastaba subir un archivo parcial, viejo o del lote equivocado para borrar
 * pagos reales —sin dejar rastro, sin guardar el archivo y sin forma de deshacerlo—, y el
 * documento volvía a figurar como deuda.
 *
 * El error de fondo era confundir «el archivo no lo menciona» con «no está pagado». Un
 * archivo solo puede hablar de lo que trae dentro; de lo demás no dice nada.
 *
 * Estas pruebas fijan las dos mitades de la regla nueva:
 *
 *   · lo que el archivo NOMBRA se actualiza;
 *   · lo que el archivo NO nombra se queda exactamente como estaba.
 *
 * Y que quitar un cobro siga siendo posible, pero solo como acto explícito, autorizado y
 * con motivo.
 */
class ConciliacionNoDestructivaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'contabilidad', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);

        // El TXT se guarda en disco: se finge el disco para no escribir de verdad.
        Storage::fake('local');
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    /** CCF cobrable: producción y aceptado de verdad por Hacienda. */
    private function ccf(string $control, float $monto = 100.00): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => $control,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => $monto,
        ]);
    }

    private function lote(): PpqLote
    {
        return PpqLote::create([
            'referencia' => 'Pronto pago de prueba',
            'fecha' => now(),
            'estado' => 'listo',
            'cliente_id' => $this->calleja()->id,
        ]);
    }

    /** Renglón del lote, como snapshot local (con `dte_id`). */
    private function item(PpqLote $lote, string $control, float $monto = 100.00, string $tipo = '03'): PpqItem
    {
        return $lote->items()->create([
            'origen' => 'local',
            'numero_control' => $control,
            'tipo_dte' => $tipo,
            'monto_dte' => $monto,
            'numero_orden_compra' => '260600232002345',
            'sin_albaran' => true,
        ]);
    }

    /**
     * Una línea del TXT real de Calleja. El separador es «;» y la fecha viene con el mes
     * abreviado, como la escribe el sistema del cliente.
     */
    private function linea(string $tipo, string $control, string $fecha, float $valor): string
    {
        return '001065;ELSA ESPANA;'.$tipo.';'.str_replace('-', '', $control).';'.$fecha.';'.$valor;
    }

    private function subir(PpqLote $lote, User $usuario, string $contenido, string $nombre = 'pagos.txt')
    {
        return $this->actingAs($usuario)->post(route('ppq.lotes.conciliar', $lote), [
            'archivo' => UploadedFile::fake()->createWithContent($nombre, $contenido),
        ]);
    }

    // ══════════════════════════════ 1) primera conciliación

    public function test_la_primera_conciliacion_marca_los_pagos_y_deja_constancia(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $ccf = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $nc = $this->item($lote, 'DTE-05-M001P001-000000000000339', 5.30, '05');
        $pendiente = $this->item($lote, 'DTE-03-M001P001-000000000000968', 80.00);

        $txt = implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44),
            $this->linea('NC', 'DTE-05-M001P001-000000000000339', '08-JUN-26', -5.30),
        ]);

        $this->subir($lote, $usuario, $txt)->assertOk();

        $this->assertSame('pagado', $ccf->refresh()->conciliacion_estado);
        $this->assertSame('2026-06-05', $ccf->fecha_pago->toDateString());
        $this->assertSame('126.44', (string) $ccf->monto_pagado);

        // La NC se aplica, no se paga: es su equivalente y tiene nombre propio.
        $this->assertSame('aplicada', $nc->refresh()->conciliacion_estado);

        // El que no venía en el archivo queda pendiente, que es lo que era.
        $this->assertNull($pendiente->refresh()->conciliacion_estado);

        // Constancia: quién, con qué archivo y con qué resultado.
        $corrida = PpqConciliacion::sole();
        $this->assertSame($usuario->id, $corrida->user_id);
        $this->assertSame(OrigenConciliacionPpq::Txt, $corrida->origen);
        $this->assertSame('pagos.txt', $corrida->archivo_nombre);
        $this->assertSame(hash('sha256', $txt), $corrida->archivo_hash);
        $this->assertSame(2, $corrida->items_cambiados);
        $this->assertSame(1, $corrida->items_sin_cambio);

        // Un movimiento por renglón que CAMBIÓ; el que no cambió no genera ruido.
        $this->assertSame(2, PpqConciliacionMovimiento::count());
        $movimiento = PpqConciliacionMovimiento::where('ppq_item_id', $ccf->id)->sole();
        $this->assertNull($movimiento->estado_anterior);
        $this->assertSame('pagado', $movimiento->estado_nuevo);

        $this->assertTrue(Activity::where('log_name', 'ppq_conciliacion')
            ->where('causer_id', $usuario->id)->exists());
    }

    public function test_el_archivo_queda_guardado_y_es_verificable_por_su_hash(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $txt = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);

        $this->subir($lote, $usuario, $txt)->assertOk();

        $corrida = PpqConciliacion::sole();

        // La copia existe y su contenido es EXACTAMENTE el que se procesó: sin eso, la
        // afirmación «este documento se cobró» no tiene documento de respaldo.
        Storage::disk('local')->assertExists($corrida->archivo_path);
        $this->assertSame($txt, Storage::disk('local')->get($corrida->archivo_path));
        $this->assertSame($corrida->archivo_hash, hash('sha256', Storage::disk('local')->get($corrida->archivo_path)));
    }

    // ══════════════════════════════ 2) segunda conciliación PARCIAL

    public function test_una_segunda_conciliacion_parcial_no_borra_los_pagos_anteriores(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $primero = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $segundo = $this->item($lote, 'DTE-03-M001P001-000000000000968', 80.00);

        // Corrida 1: paga el primero.
        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))
            ->assertOk();
        $this->assertSame('pagado', $primero->refresh()->conciliacion_estado);

        // Corrida 2: un archivo que SOLO trae el segundo. Antes, esto vaciaba el primero.
        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000968', '12-JUN-26', 80.00), 'pagos-2.txt')
            ->assertOk();

        // El pago anterior sigue intacto: estado, fecha e importe.
        $primero->refresh();
        $this->assertSame('pagado', $primero->conciliacion_estado);
        $this->assertSame('2026-06-05', $primero->fecha_pago->toDateString());
        $this->assertSame('126.44', (string) $primero->monto_pagado);

        // Y el nuevo quedó registrado.
        $this->assertSame('pagado', $segundo->refresh()->conciliacion_estado);

        // Ningún movimiento deshizo un pago: es la prueba de que nada se borró.
        $this->assertSame(0, PpqConciliacionMovimiento::get()
            ->filter(fn (PpqConciliacionMovimiento $m) => $m->deshizoUnPago())->count());
    }

    public function test_el_renglon_que_el_archivo_no_menciona_se_reporta_como_conservado(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $this->item($lote, 'DTE-03-M001P001-000000000000968', 80.00);

        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        // La segunda corrida no lo nombra: no es «pendiente», es «ya cobrado y conservado».
        $respuesta = $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000968', '12-JUN-26', 80.00), 'pagos-2.txt');

        $reporte = $respuesta->viewData('reporte');
        $this->assertCount(1, $reporte['conservados']);
        $this->assertSame(1, $reporte['totales']['cantidad_conservados']);
    }

    // ══════════════════════════════ 3) TXT vacío

    public function test_un_txt_vacio_no_cambia_ningun_pago(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        // Un archivo sin ninguna línea de datos: se registra el intento y no se toca nada.
        $this->subir($lote, $usuario, "\n\n", 'vacio.txt')->assertOk();

        $item->refresh();
        $this->assertSame('pagado', $item->conciliacion_estado);
        $this->assertSame('126.44', (string) $item->monto_pagado);

        $ultima = PpqConciliacion::orderByDesc('id')->first();
        $this->assertSame(0, $ultima->total_filas);
        $this->assertSame(0, $ultima->items_cambiados);
        $this->assertSame(1, $ultima->items_sin_cambio);
    }

    // ══════════════════════════════ 4) TXT repetido

    public function test_el_mismo_archivo_subido_dos_veces_no_se_vuelve_a_aplicar(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $txt = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);

        $this->subir($lote, $usuario, $txt)->assertOk();
        $conciliadoEn = $item->refresh()->conciliado_en;

        // Mismo contenido, otro nombre: la identidad del archivo es su huella, no su nombre.
        $this->subir($lote, $usuario, $txt, 'pagos-copia.txt')
            ->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('status');

        // Una sola corrida en la bitácora y ni un movimiento nuevo.
        $this->assertSame(1, PpqConciliacion::count());
        $this->assertSame(1, PpqConciliacionMovimiento::count());
        $this->assertEquals($conciliadoEn, $item->refresh()->conciliado_en);
    }

    public function test_el_mismo_archivo_en_otro_lote_si_se_procesa(): void
    {
        $usuario = $this->usuario();
        $txt = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);

        $primero = $this->lote();
        $this->item($primero, 'DTE-03-M001P001-000000000000967', 126.44);
        $this->subir($primero, $usuario, $txt)->assertOk();

        // El candado es por LOTE: el mismo archivo puede pagar renglones de otro paquete.
        $segundo = $this->lote();
        $item = $this->item($segundo, 'DTE-03-M001P001-000000000000967', 126.44);
        $this->subir($segundo, $usuario, $txt)->assertOk();

        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
        $this->assertSame(2, PpqConciliacion::count());
    }

    // ══════════════════════════════ 5) corrección explícita

    public function test_la_correccion_explicita_quita_el_cobro_y_deja_motivo_y_usuario(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        $this->actingAs($usuario)
            ->post(route('ppq.lotes.items.revertir-cobro', [$lote, $item]), [
                'motivo' => 'Calleja reversó el abono: el documento se cobró en el paquete equivocado.',
            ])
            ->assertRedirect(route('ppq.lotes.show', $lote));

        // Vuelve a pendiente: sigue en el lote y vuelve a contar como algo por cobrar.
        $item->refresh();
        $this->assertNull($item->conciliacion_estado);
        $this->assertNull($item->fecha_pago);
        $this->assertNull($item->monto_pagado);
        $this->assertNull($item->conciliado_en);

        $reversion = PpqConciliacion::where('origen', OrigenConciliacionPpq::Reversion->value)->sole();
        $this->assertSame($usuario->id, $reversion->user_id);
        $this->assertStringContainsString('paquete equivocado', $reversion->motivo);

        // El movimiento conserva lo que había ANTES: sin eso, el cobro sería irrecuperable.
        $movimiento = $reversion->movimientos()->sole();
        $this->assertSame('pagado', $movimiento->estado_anterior);
        $this->assertNull($movimiento->estado_nuevo);
        $this->assertSame('126.44', (string) $movimiento->monto_pagado_anterior);
        $this->assertTrue($movimiento->deshizoUnPago());
    }

    public function test_la_correccion_exige_un_motivo_que_explique_la_decision(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        // Un motivo de relleno no explica nada, y el día que el saldo no cuadre la
        // pregunta va a ser exactamente esa.
        $this->actingAs($usuario)
            ->post(route('ppq.lotes.items.revertir-cobro', [$lote, $item]), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
    }

    public function test_no_se_puede_revertir_un_renglon_que_nunca_se_cobro(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->actingAs($usuario)
            ->post(route('ppq.lotes.items.revertir-cobro', [$lote, $item]), [
                'motivo' => 'Intento de revertir algo que no estaba cobrado.',
            ])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, PpqConciliacion::count());
    }

    public function test_revertir_un_cobro_exige_su_propio_permiso(): void
    {
        $admin = $this->usuario('administrador');
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $this->subir($lote, $admin, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        // Contabilidad ve PPQ pero no lo gestiona: no puede contradecir un cobro.
        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('ppq.lotes.items.revertir-cobro', [$lote, $item]), [
                'motivo' => 'Debería quedar bloqueado por permisos.',
            ])
            ->assertForbidden();

        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
    }

    public function test_no_se_puede_revertir_un_renglon_de_otro_lote(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $otro = $this->lote();
        $item = $this->item($otro, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->actingAs($usuario)
            ->post(route('ppq.lotes.items.revertir-cobro', [$lote, $item]), ['motivo' => 'Enlace viejo o id manipulado.'])
            ->assertNotFound();
    }

    // ══════════════════════════════ 6) el archivo se contradice

    public function test_el_mismo_documento_dos_veces_con_montos_distintos_rechaza_el_archivo(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $primero = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $segundo = $this->item($lote, 'DTE-03-M001P001-000000000000968', 80.00);

        // El segundo renglón sí se cobró antes: hay que comprobar que un archivo rechazado
        // tampoco le hace nada.
        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000968', '01-JUN-26', 80.00))->assertOk();
        $conciliadoEn = $segundo->refresh()->conciliado_en;

        $txt = implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44),
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 99.99),
        ]);

        $respuesta = $this->subir($lote, $usuario, $txt, 'contradictorio.txt');

        $respuesta->assertRedirect(route('ppq.lotes.show', $lote))->assertSessionHas('error');
        $this->assertStringContainsString('se contradice', session('error'));

        // ROLLBACK COMPLETO: ni el documento repetido se cobró, ni el que ya estaba
        // cobrado se movió, ni quedó corrida en la bitácora.
        $this->assertNull($primero->refresh()->conciliacion_estado);
        $this->assertSame('pagado', $segundo->refresh()->conciliacion_estado);
        $this->assertEquals($conciliadoEn, $segundo->conciliado_en);
        $this->assertSame(1, PpqConciliacion::count()); // solo la primera corrida, la buena
        $this->assertSame(1, PpqConciliacionMovimiento::count());
    }

    public function test_tambien_rechaza_si_difiere_la_fecha_o_el_tipo(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        // Mismo importe, distinta fecha.
        $this->subir($lote, $usuario, implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44),
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '06-JUN-26', 126.44),
        ]), 'fechas.txt')->assertSessionHas('error');

        // El mismo número como CF y como NC es igual de contradictorio: no se puede estar
        // pagado y aplicado a la vez.
        $this->subir($lote, $usuario, implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44),
            $this->linea('NC', 'DTE-03-M001P001-000000000000967', '05-JUN-26', -126.44),
        ]), 'tipos.txt')->assertSessionHas('error');

        $this->assertNull($item->refresh()->conciliacion_estado);
        $this->assertSame(0, PpqConciliacion::count());
    }

    public function test_un_duplicado_identico_se_acepta_y_se_informa(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        // Las dos filas dicen exactamente lo mismo: no hay nada que decidir.
        $linea = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);
        $respuesta = $this->subir($lote, $usuario, $linea."\n".$linea);

        $respuesta->assertOk();
        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
        $this->assertSame('126.44', (string) $item->monto_pagado);

        // Se informa, porque un archivo que repite filas suele venir mal armado.
        $reporte = $respuesta->viewData('reporte');
        $this->assertCount(1, $reporte['repetidas']);
        $this->assertSame(2, $reporte['repetidas'][0]['veces']);

        // Y cuenta como UN documento, no dos.
        $this->assertSame(1, PpqConciliacion::sole()->filas_cf);
        $this->assertSame(1, PpqConciliacionMovimiento::count());
    }

    public function test_una_referencia_qd_repetida_no_rechaza_el_archivo(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        // Los QD no identifican un documento del lote y no se imputan a ningún renglón:
        // dos ajustes con la misma referencia y distinto importe son posibles.
        $txt = implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44),
            '001065;ELSA ESPANA;QD;PPQ/19891;;-121.98',
            '001065;ELSA ESPANA;QD;PPQ/19891;;-3.50',
        ]);

        $this->subir($lote, $usuario, $txt)->assertOk();

        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
        $this->assertSame(2, PpqConciliacion::sole()->filas_qd);
    }

    // ══════════════════════════════ 7) aritmética exacta

    public function test_los_totales_no_arrastran_error_de_coma_flotante(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();

        // Importes elegidos porque su suma en binario NO da exacto: 0.1 + 0.2 != 0.3.
        foreach (['0.10' => '967', '0.20' => '968'] as $monto => $sufijo) {
            $this->item($lote, 'DTE-03-M001P001-00000000000'.$sufijo, (float) $monto);
        }

        $txt = implode("\n", [
            $this->linea('CF', 'DTE-03-M001P001-00000000000967', '05-JUN-26', 0.10),
            $this->linea('CF', 'DTE-03-M001P001-00000000000968', '05-JUN-26', 0.20),
        ]);

        $reporte = $this->subir($lote, $usuario, $txt)->viewData('reporte');

        // Con float, este total llegaba como 0.30000000000000004.
        $this->assertSame('0.30', (string) $reporte['totales']['total_ccf_pagado']);
        $this->assertSame('0.30', (string) $reporte['totales']['neto_final']);
    }

    public function test_reprocesar_el_mismo_importe_escrito_distinto_no_genera_movimiento(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();
        $this->assertSame(1, PpqConciliacionMovimiento::count());

        // Mismo dinero, otra escritura: «126.440». No es un cambio, así que no puede
        // producir un movimiento fantasma en la bitácora.
        $otraEscritura = '001065;ELSA ESPANA;CF;DTE03M001P001000000000000967;05-JUN-26;126.440';
        $respuesta = $this->subir($lote, $usuario, $otraEscritura, 'otra-escritura.txt');

        $respuesta->assertOk();
        $this->assertSame(1, PpqConciliacionMovimiento::count());
        $this->assertSame(0, PpqConciliacion::orderByDesc('id')->first()->items_cambiados);
        $this->assertSame('126.44', (string) $item->refresh()->monto_pagado);
    }

    // ══════════════════════════════ 8) la evidencia no se publica

    public function test_el_archivo_de_pagos_no_es_descargable_por_web(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);
        $this->subir($lote, $usuario, $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44))->assertOk();

        $ruta = PpqConciliacion::sole()->archivo_path;

        // El disco `local` no declara `visibility: public`, así que la ruta que Laravel
        // registra para servirlo exige URL FIRMADA. Sin firma no se entrega, ni siquiera a
        // un usuario autenticado: un archivo de pagos del cliente no se sirve por web.
        $this->actingAs($usuario)->get('/storage/'.$ruta)->assertForbidden();
        $this->get('/storage/'.$ruta)->assertForbidden();
    }

    // ══════════════════════════════ 9) rollback

    public function test_si_el_procesamiento_falla_no_queda_nada_a_medias(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        // Falla JUSTO al ir a dejar constancia, o sea después de haber tocado el renglón.
        // Si la transacción no cubriera todo, el item quedaría pagado sin bitácora que lo
        // explique: un pago sin origen conocido es peor que ningún pago.
        PpqConciliacion::creating(function () {
            throw new \RuntimeException('falla simulada a mitad del procesamiento');
        });

        $txt = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);

        try {
            app(ConciliadorPpq::class)->conciliar(
                $lote,
                app(ConciliacionTxtParser::class)->parse($txt),
                $usuario,
                ArchivoConciliacion::desdeContenido($txt, 'pagos.txt'),
            );
            $this->fail('Se esperaba que el procesamiento fallara.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('falla simulada', $e->getMessage());
        }

        // Nada quedó escrito: ni el renglón, ni la corrida, ni los movimientos.
        $this->assertNull($item->refresh()->conciliacion_estado);
        $this->assertSame(0, PpqConciliacion::count());
        $this->assertSame(0, PpqConciliacionMovimiento::count());
    }

    public function test_una_corrida_fallida_se_puede_reintentar_con_el_mismo_archivo(): void
    {
        $usuario = $this->usuario();
        $lote = $this->lote();
        $item = $this->item($lote, 'DTE-03-M001P001-000000000000967', 126.44);

        $txt = $this->linea('CF', 'DTE-03-M001P001-000000000000967', '05-JUN-26', 126.44);
        $archivo = ArchivoConciliacion::desdeContenido($txt, 'pagos.txt');

        PpqConciliacion::creating(fn () => throw new \RuntimeException('falla simulada'));

        try {
            app(ConciliadorPpq::class)->conciliar($lote, [], $usuario, $archivo);
        } catch (\RuntimeException) {
            // esperado
        }

        // Como la corrida fallida no dejó fila, el único de (lote, hash) no la bloquea: el
        // mismo archivo se puede volver a subir sin renombrarlo.
        PpqConciliacion::flushEventListeners();
        $this->subir($lote, $usuario, $txt)->assertOk();

        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
    }
}
