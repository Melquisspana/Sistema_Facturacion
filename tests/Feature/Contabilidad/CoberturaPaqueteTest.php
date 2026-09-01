<?php

namespace Tests\Feature\Contabilidad;

use App\Mail\PaqueteContabilidadCorreo;
use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El paquete mensual tiene que decir la verdad sobre su período.
 *
 * Antes contaba lo que había en la base y lo entregaba: no tenía forma de saber que
 * faltaban quince días de correos, así que un período incompleto salía idéntico a uno
 * cerrado. Acá se prueban las tres reglas que cierran ese agujero:
 *
 *  1. el período se arma por la FECHA FISCAL del documento, no por la del correo;
 *  2. lo marcado `ignorado` no entra;
 *  3. un período que no se puede verificar BLOQUEA el envío y marca la descarga.
 */
class CoberturaPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'contabilidad@empresa.com';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function contable(): User
    {
        return User::factory()->create()->assignRole('contabilidad');
    }

    /**
     * Compra con fecha del correo y fecha FISCAL separadas a propósito: es la distinción
     * que el paquete no hacía.
     */
    private function compra(string $fechaCorreo, string $fechaFiscal, array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => 'cob'.$n,
            'identidad' => 'mid:cob'.$n.'@proveedor.example',
            'emisor_nombre' => 'PROVEEDOR '.$n,
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-COB-'.$n,
            'estado' => 'pendiente',
            'total' => 100,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => Carbon::parse($fechaCorreo),
            'fecha_dte' => Carbon::parse($fechaFiscal),
        ]);
    }

    private function cubrir(string $desde, string $hasta): void
    {
        $progreso = app(ProgresoSincronizacionCompras::class);
        for ($d = Carbon::parse($desde); $d->lte(Carbon::parse($hasta)); $d->addDay()) {
            $progreso->marcarCompleto($d->copy(), 'INBOX', 5001, null, []);
        }
    }

    private function agostoCubierto(): void
    {
        $this->cubrir('2026-08-01', '2026-08-31');
    }

    // ---------------------------------------------------- período por fecha fiscal

    /**
     * EL CASO QUE PEDÍA EL NEGOCIO. Un CCF emitido el 31 de agosto que el proveedor manda
     * el 2 de septiembre es de AGOSTO. Antes caía en septiembre, porque el corte era por
     * `fecha_correo`.
     */
    public function test_un_documento_de_agosto_recibido_en_septiembre_entra_en_agosto(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->agostoCubierto();
        $this->compra(fechaCorreo: '2026-09-02 08:00:00', fechaFiscal: '2026-08-31');

        $agosto = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))
            ->assertOk()->viewData('resumen');

        $this->assertSame(1, $agosto['compras_cantidad']);

        $septiembre = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 9, 'anio' => 2026]))
            ->assertOk()->viewData('resumen');

        $this->assertSame(0, $septiembre['compras_cantidad'], 'no puede contarse dos veces');
    }

    /** Y al revés: uno emitido en septiembre que llegó adelantado NO es de agosto. */
    public function test_un_documento_de_septiembre_recibido_en_agosto_no_entra_en_agosto(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->agostoCubierto();
        $this->compra(fechaCorreo: '2026-08-31 23:00:00', fechaFiscal: '2026-09-01');

        $resumen = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))
            ->assertOk()->viewData('resumen');

        $this->assertSame(0, $resumen['compras_cantidad']);
    }

    // ---------------------------------------------------- ignorados y sin fecha

    public function test_las_compras_ignoradas_no_entran_en_el_paquete(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->agostoCubierto();
        $this->compra('2026-08-05', '2026-08-05');
        $this->compra('2026-08-06', '2026-08-06', ['estado' => 'ignorado']);

        $resp = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))->assertOk();

        $this->assertSame(1, $resp->viewData('resumen')['compras_cantidad']);
        // Se cuentan aparte para que no parezca que se perdieron.
        $this->assertSame(1, $resp->viewData('cobertura')['compras_ignoradas']);
    }

    /**
     * Sin fecha fiscal no se sabe a qué mes pertenece la compra, así que NO entra en
     * ningún paquete. Lo que no puede pasar es que desaparezca sin que nadie lo sepa.
     */
    public function test_una_compra_sin_fecha_fiscal_se_señala_y_no_entra_en_ningun_periodo(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->agostoCubierto();
        $this->compra('2026-08-10', '2026-08-10');
        // PDF sin JSON: el correo llegó en agosto, pero no hay fecEmi legible.
        $this->compra('2026-08-11', '2026-08-11', ['fecha_dte' => null, 'tiene_json' => false]);

        $resp = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))->assertOk();

        $this->assertSame(1, $resp->viewData('resumen')['compras_cantidad']);
        $this->assertSame(1, $resp->viewData('cobertura')['compras_sin_fecha_fiscal']);
        $resp->assertSee('sin fecha de emisión legible', false);
    }

    // ---------------------------------------------------- cobertura

    public function test_un_periodo_cubierto_habilita_el_envio(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO);
        Configuracion::olvidarCache();
        $this->agostoCubierto();
        $this->compra('2026-08-05', '2026-08-05');

        $resp = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))->assertOk();

        $this->assertTrue($resp->viewData('cobertura')['cubierto']);
        $this->assertFalse($resp->viewData('bloqueaCobertura'));
        $this->assertTrue($resp->viewData('puedeEnviar'));
        $resp->assertSee('Período completo', false);
    }

    public function test_un_periodo_con_dias_sin_revisar_avisa_y_deshabilita_el_envio(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO);
        Configuracion::olvidarCache();
        // Agosto sin los últimos cuatro días.
        $this->cubrir('2026-08-01', '2026-08-27');
        $this->compra('2026-08-05', '2026-08-05');

        $resp = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))->assertOk();

        $cob = $resp->viewData('cobertura');
        $this->assertFalse($cob['cubierto']);
        $this->assertCount(4, $cob['dias_pendientes']);
        $this->assertTrue($resp->viewData('bloqueaCobertura'));
        $this->assertFalse($resp->viewData('puedeEnviar'));
        $resp->assertSee('Período incompleto', false);
        $resp->assertSee('2026-08-28', false);
    }

    /** Un día con error tampoco cuenta como cubierto, y el motivo llega a la pantalla. */
    public function test_un_dia_con_error_deja_el_periodo_incompleto(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->cubrir('2026-08-01', '2026-08-31');
        app(ProgresoSincronizacionCompras::class)
            ->marcarError(Carbon::parse('2026-08-14'), 'INBOX', 5001, null, 'el servidor cortó la conexión');

        $cob = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))
            ->assertOk()->viewData('cobertura');

        $this->assertFalse($cob['cubierto']);
        $this->assertSame(1, $cob['dias_con_error']);
        $this->assertSame('2026-08-14', $cob['dias_pendientes'][0]['dia']);
    }

    /**
     * Un período del que no hay NINGÚN registro de sincronización no se puede declarar
     * completo. Se trata como no verificable —no como correcto—: es la única lectura que
     * no le miente a quien va a mandar el paquete.
     */
    public function test_un_periodo_sin_registro_de_sincronizacion_no_se_da_por_completo(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-08-05', '2026-08-05');

        $cob = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))
            ->assertOk()->viewData('cobertura');

        $this->assertTrue($cob['sin_datos']);
        $this->assertFalse($cob['cubierto']);
        $this->assertStringContainsString('No hay registro de sincronización', (string) $cob['motivo']);
    }

    // ---------------------------------------------------- descarga y envío

    /** La descarga sigue disponible, pero el aviso viaja CON el archivo. */
    public function test_el_zip_de_un_periodo_incompleto_se_descarga_marcado(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->cubrir('2026-08-01', '2026-08-20');
        $this->compra('2026-08-05', '2026-08-05');

        $this->actingAs($this->admin())
            ->post(route('contabilidad.paquete.generar'), ['mes' => 8, 'anio' => 2026, 'incluir_compras' => 1])
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2026-08_INCOMPLETO.zip');

        Mail::assertNothingSent();
    }

    public function test_el_zip_de_un_periodo_completo_no_lleva_marca(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->agostoCubierto();
        $this->compra('2026-08-05', '2026-08-05');

        $this->actingAs($this->admin())
            ->post(route('contabilidad.paquete.generar'), ['mes' => 8, 'anio' => 2026, 'incluir_compras' => 1])
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2026-08.zip');
    }

    /**
     * El envío se BLOQUEA. Manda un correo hacia afuera y marca las compras como
     * `enviado`: ese cambio de estado es el que después hace invisible el hueco.
     */
    public function test_el_envio_se_bloquea_con_el_periodo_incompleto_y_queda_auditado(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO);
        Configuracion::olvidarCache();
        $this->cubrir('2026-08-01', '2026-08-20');
        $compra = $this->compra('2026-08-05', '2026-08-05');

        $this->actingAs($this->contable())
            ->post(route('contabilidad.paquete.enviar'), [
                'mes' => 8, 'anio' => 2026, 'incluir_compras' => 1, 'incluir_ventas' => 1,
                'frase' => 'ENVIAR A CONTABILIDAD',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no está completo'));

        Mail::assertNothingSent();
        $this->assertSame('pendiente', $compra->refresh()->estado, 'no se marca nada como enviado');

        // El bloqueo queda escrito, con los días que faltaban.
        $act = Activity::where('log_name', 'paquete_contabilidad')->latest('id')->firstOrFail();
        $this->assertSame('bloqueado', $act->getExtraProperty('estado'));
        $this->assertTrue($act->getExtraProperty('cobertura_incompleta'));
        $this->assertContains('2026-08-21', $act->getExtraProperty('dias_faltantes'));
    }

    // ---------------------------------------------------- horizonte exigible

    /**
     * NO se exige cobertura de días que todavía no ocurrieron. Para el mes en curso, el
     * período llega a fin de mes pero solo se puede haber revisado hasta hoy; exigir el
     * resto pondría en ámbar todos los meses corrientes para siempre, y un aviso que
     * está siempre encendido deja de significar algo.
     */
    public function test_no_exige_cobertura_de_dias_futuros(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Septiembre revisado hasta HOY, nada más. El mes termina el 30.
        $this->cubrir('2026-09-01', '2026-09-15');

        $cob = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 9, 'anio' => 2026]))
            ->assertOk()->viewData('cobertura');

        $this->assertTrue($cob['cubierto'], 'los días 16 al 30 todavía no ocurrieron: no pueden faltar');
        $this->assertSame([], $cob['dias_pendientes']);
        $this->assertSame(15, $cob['dias_totales'], 'solo se exigen los días transcurridos');
        $this->assertSame('2026-09-15', $cob['hasta_exigible']);
        $this->assertSame('2026-09-30', $cob['hasta'], 'el período mostrado sigue siendo el mes entero');
        $this->assertTrue($cob['periodo_en_curso']);
        $this->assertStringContainsString('hasta hoy', (string) $cob['motivo']);
    }

    /** Un mes ya cerrado sí exige todos sus días. */
    public function test_un_periodo_cerrado_exige_todos_sus_dias(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->cubrir('2026-08-01', '2026-08-30'); // falta el 31

        $cob = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026]))
            ->assertOk()->viewData('cobertura');

        $this->assertFalse($cob['cubierto']);
        $this->assertSame(31, $cob['dias_totales']);
        $this->assertSame('2026-08-31', $cob['hasta_exigible']);
        $this->assertFalse($cob['periodo_en_curso']);
        $this->assertSame(['2026-08-31'], collect($cob['dias_pendientes'])->pluck('dia')->all());
    }

    /** Un período que todavía no empezó no está incompleto: no hay días que revisar. */
    public function test_un_periodo_futuro_no_esta_incompleto(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));
        $this->seed(DatosInicialesNegritaSeeder::class);

        $cob = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 11, 'anio' => 2026]))
            ->assertOk()->viewData('cobertura');

        $this->assertTrue($cob['cubierto']);
        $this->assertSame(0, $cob['dias_totales']);
        $this->assertFalse($cob['bloquea_envio']);
        $this->assertStringContainsString('todavía no empezó', (string) $cob['motivo']);
    }

    // ---------------------------------------------------- solo ventas

    /** Un paquete SOLO de ventas no depende del buzón de compras: no se bloquea. */
    public function test_un_paquete_solo_de_ventas_no_lo_bloquea_la_cobertura_de_compras(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $this->actingAs($this->admin())
            ->post(route('contabilidad.paquete.generar'), ['mes' => 8, 'anio' => 2026, 'incluir_compras' => 0, 'incluir_ventas' => 1])
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2026-08.zip');
    }

    /**
     * Y el ENVÍO tampoco. Un paquete exclusivamente de ventas no lee el buzón, así que
     * la cobertura de compras no tiene nada que decir sobre él: bloquearlo dejaría a
     * contabilidad esperando por un dato que ese paquete no usa.
     */
    public function test_el_envio_solo_de_ventas_no_lo_bloquea_la_cobertura_de_compras(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO);
        Configuracion::olvidarCache();
        $this->simularProduccionCorreo();

        // Agosto SIN cobertura de compras (ni una fila de progreso) y con una compra que
        // quedaría fuera: si la cobertura contara, esto estaría bloqueado.
        $compra = $this->compra('2026-08-05', '2026-08-05');
        $this->venta('2026-08-10');

        $this->actingAs($this->contable())
            ->post(route('contabilidad.paquete.enviar'), [
                'mes' => 8, 'anio' => 2026,
                'incluir_compras' => 0, 'incluir_ventas' => 1,
                'frase' => 'ENVIAR A CONTABILIDAD',
            ])
            ->assertRedirect()
            ->assertSessionMissing('error')
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'enviado a '.self::CORREO));

        Mail::assertSent(PaqueteContabilidadCorreo::class);
        // Las compras no entraron en el paquete, así que tampoco se marcan como enviadas.
        $this->assertSame('pendiente', $compra->refresh()->estado);
    }

    /** La pantalla tampoco deshabilita el botón cuando las compras están excluidas. */
    public function test_la_pantalla_no_bloquea_un_paquete_solo_de_ventas(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO);
        Configuracion::olvidarCache();
        $this->venta('2026-08-10');

        $resp = $this->actingAs($this->contable())
            ->get(route('contabilidad.paquete', ['mes' => 8, 'anio' => 2026, 'incluir_compras' => 0, 'incluir_ventas' => 1]))
            ->assertOk();

        $this->assertFalse($resp->viewData('bloqueaCobertura'));
        $this->assertTrue($resp->viewData('puedeEnviar'));
    }

    /** Una venta aceptada de verdad por el MH, para los paquetes solo-ventas. */
    private function venta(string $fecha, float $total = 200): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => '2026SELLOREAL'.random_int(1000, 9999),
            'fecha_procesamiento_mh' => Carbon::parse($fecha),
            'fecha_emision' => Carbon::parse($fecha),
            'hora_emision' => '10:00:00',
            'total_gravado' => $total,
            'iva' => round($total * 0.13, 2),
            'total_pagar' => $total,
        ]);
    }
}
