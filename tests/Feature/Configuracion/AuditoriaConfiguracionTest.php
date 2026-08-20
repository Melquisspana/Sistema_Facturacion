<?php

namespace Tests\Feature\Configuracion;

use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría del marco fiscal y de la configuración.
 *
 * Hasta ahora, cambiar el precio de un producto dejaba rastro pero cambiar el NIT del
 * emisor, el código de un establecimiento o el último correlativo no dejaba ninguno.
 * Estos tests fijan que sí quede, y —más importante— fijan la regla de secretos de la
 * tabla `configuraciones`: el valor de una clave solo se registra si está en la LISTA
 * BLANCA. Cualquier clave nueva se audita como hecho, sin su contenido, hasta que
 * alguien la declare pública a propósito.
 */
class AuditoriaConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Configuracion::olvidarCache();
    }

    private function actividades(string $subject): Collection
    {
        return Activity::query()
            ->where('log_name', 'configuracion')
            ->where('subject_type', $subject)
            ->get();
    }

    private function ultima(string $subject): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'configuracion')
            ->where('subject_type', $subject)
            ->latest('id')
            ->first();
    }

    private function emisor(): Establecimiento
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'activo' => true]);

        return Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Empresa / Establecimiento / Punto de venta
    // -----------------------------------------------------------------------

    public function test_crear_y_modificar_la_empresa_queda_auditado_con_antes_y_despues(): void
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'activo' => true]);

        $creacion = $this->ultima(Empresa::class);
        $this->assertNotNull($creacion);
        $this->assertSame('registró la empresa emisora', $creacion->description);

        $empresa->update(['nit' => '0614-999999-999-9']);

        $cambio = $this->ultima(Empresa::class);
        $this->assertSame('modificó los datos de la empresa emisora', $cambio->description);
        // El NIT del emisor no es secreto: se quiere ver exactamente de qué a qué cambió.
        $this->assertSame('0614-999999-999-9', $cambio->properties['attributes']['nit']);
        $this->assertSame('0614-000000-000-0', $cambio->properties['old']['nit']);
    }

    public function test_el_codigo_del_establecimiento_queda_auditado(): void
    {
        $estab = $this->emisor();
        $estab->update(['codigo' => 'M002']);

        $cambio = $this->ultima(Establecimiento::class);
        $this->assertSame('modificó el establecimiento', $cambio->description);
        $this->assertSame('M002', $cambio->properties['attributes']['codigo']);
        $this->assertSame('M001', $cambio->properties['old']['codigo']);
    }

    public function test_el_codigo_del_punto_de_venta_queda_auditado(): void
    {
        $estab = $this->emisor();
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        $pv->update(['codigo' => 'P002']);

        $cambio = $this->ultima(PuntoVenta::class);
        $this->assertSame('modificó el punto de venta', $cambio->description);
        $this->assertSame('P002', $cambio->properties['attributes']['codigo']);
        $this->assertSame('P001', $cambio->properties['old']['codigo']);
    }

    // -----------------------------------------------------------------------
    // Correlativos — el registro más crítico
    // -----------------------------------------------------------------------

    public function test_mover_un_correlativo_hacia_atras_queda_auditado(): void
    {
        $estab = $this->emisor();
        $corr = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => null,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 1093, 'activo' => true,
        ]);

        // Retroceder el correlativo puede provocar numeración DUPLICADA ante Hacienda:
        // es exactamente el cambio que tiene que dejar rastro.
        $corr->update(['ultimo_numero' => 1000]);

        $cambio = $this->ultima(Correlativo::class);
        $this->assertSame('modificó el correlativo', $cambio->description);
        $this->assertSame(1000, $cambio->properties['attributes']['ultimo_numero']);
        $this->assertSame(1093, $cambio->properties['old']['ultimo_numero']);
    }

    public function test_un_salto_hacia_adelante_se_distingue_del_consumo_normal(): void
    {
        $estab = $this->emisor();
        $corr = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => null,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 100, 'activo' => true,
        ]);

        // +1 = el motor de emisión consumiendo un número.
        $corr->update(['ultimo_numero' => 101]);
        $this->assertSame('consumió el correlativo', $this->ultima(Correlativo::class)->description);

        // Cualquier otro salto es una edición manual, y se lee distinto en la auditoría.
        $corr->update(['ultimo_numero' => 200]);
        $this->assertSame('modificó el correlativo', $this->ultima(Correlativo::class)->description);
    }

    public function test_desactivar_un_correlativo_no_cuenta_como_consumo(): void
    {
        $estab = $this->emisor();
        $corr = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => null,
            'ambiente' => '01', 'serie' => null, 'ultimo_numero' => 100, 'activo' => true,
        ]);

        $corr->update(['activo' => false]);

        $cambio = $this->ultima(Correlativo::class);
        $this->assertSame('modificó el correlativo', $cambio->description);
        $this->assertFalse($cambio->properties['attributes']['activo']);
    }

    // -----------------------------------------------------------------------
    // Configuración clave/valor — la regla de secretos
    // -----------------------------------------------------------------------

    public function test_una_clave_de_la_lista_blanca_registra_su_valor(): void
    {
        Configuracion::set('contabilidad.correo', 'contabilidad@ejemplo.com');

        $act = $this->ultima(Configuracion::class);
        $this->assertSame('configuró «contabilidad.correo»', $act->description);
        $this->assertSame('contabilidad@ejemplo.com', $act->properties['attributes']['valor']);
    }

    public function test_una_clave_fuera_de_la_lista_blanca_se_audita_sin_su_valor(): void
    {
        // Simula una clave futura que alguien agrega sin declararla pública: por ejemplo
        // una contraseña SMTP cuando la tabla empiece a guardar secretos.
        Configuracion::set('smtp.password', 'un-secreto-que-no-debe-aparecer');

        $act = $this->ultima(Configuracion::class);
        $this->assertNotNull($act);
        $this->assertSame('configuró «smtp.password»', $act->description);

        // El hecho queda; el valor no aparece por ningún lado del registro.
        $this->assertArrayNotHasKey('valor', $act->properties['attributes'] ?? []);
        $this->assertStringNotContainsString('un-secreto-que-no-debe-aparecer', json_encode($act->properties));
        $this->assertStringNotContainsString('un-secreto-que-no-debe-aparecer', (string) $act->description);
    }

    public function test_cambiar_el_valor_de_una_clave_no_declarada_tampoco_lo_filtra(): void
    {
        Configuracion::set('smtp.password', 'valor-viejo');
        Configuracion::set('smtp.password', 'valor-nuevo');

        $todas = $this->actividades(Configuracion::class);
        $json = json_encode($todas->pluck('properties'));

        $this->assertStringNotContainsString('valor-viejo', $json);
        $this->assertStringNotContainsString('valor-nuevo', $json);
        // Pero el cambio SÍ quedó registrado como hecho.
        $this->assertSame('cambió la configuración «smtp.password»', $this->ultima(Configuracion::class)->description);
    }

    public function test_la_lista_blanca_no_contiene_la_plantilla_de_correo(): void
    {
        // Es pública, pero admite 5000 caracteres: registrar antes y después llenaría el
        // log. Se audita el hecho, no el cuerpo.
        Configuracion::set('correo.plantilla', str_repeat('x', 4000));

        $act = $this->ultima(Configuracion::class);
        $this->assertSame('configuró «correo.plantilla»', $act->description);
        $this->assertArrayNotHasKey('valor', $act->properties['attributes'] ?? []);
    }

    public function test_la_marca_de_progreso_de_ppq_no_se_audita(): void
    {
        // La sincronización de albaranes corre cada 5 minutos: auditarla sería ~288
        // registros por día que entierran los cambios reales.
        Configuracion::set('ppq.albaranes.ultimo_dia_completo', '2026-08-19');
        Configuracion::set('ppq.albaranes.ultimo_dia_completo', '2026-08-20');

        $this->assertCount(0, $this->actividades(Configuracion::class));
    }

    public function test_el_log_name_es_configuracion_en_los_cinco_modelos(): void
    {
        $estab = $this->emisor();
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'serie' => null, 'ultimo_numero' => 0, 'activo' => true,
        ]);
        Configuracion::set('contabilidad.correo', 'x@y.com');

        // Un solo filtro por log_name alcanza para ver todo el marco fiscal junto.
        $tipos = Activity::where('log_name', 'configuracion')->pluck('subject_type')->unique()->values()->all();

        foreach ([Empresa::class, Establecimiento::class, PuntoVenta::class, Correlativo::class, Configuracion::class] as $modelo) {
            $this->assertContains($modelo, $tipos, $modelo.' no dejó registro con log_name=configuracion.');
        }
    }
}
