<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\Empresa;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\LectorSinPing;
use App\Services\Planilla\ContratoTrabajo;
use App\Services\Planilla\SalarioMinimo;
use App\Services\Ppq\ValidadorCodigoProveedorTxt;
use App\Services\Rutas\EntregasCcf;
use App\Support\Exportaciones\DatosExportador;
use App\Support\OrdenCompra;
use App\Support\PpqConciliacion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Módulos › <módulo>: las opciones de negocio que vivían solo en el
 * .env. Se prueba la lista blanca, la validación en el servidor, la ceremonia N2, que
 * solo se escribe lo que cambia, que un campo vacío no borra, que las páginas de un
 * módulo apagado no existen y —lo que importa— que cada lector usa el valor guardado
 * en vez del .env. Todos los datos son ficticios.
 */
class OpcionesModuloTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    // ------------------------------------------------------------ lista blanca

    public function test_cada_opcion_esta_en_la_lista_blanca_y_se_guarda_en_la_tabla_nueva(): void
    {
        $catalogo = app(CatalogoAjustes::class);
        $porSeccion = ['ppq', 'cobros', 'alertas', 'planilla', 'rutas', 'gastos', 'exportaciones', 'compras', 'asistencia'];

        foreach ($porSeccion as $seccion) {
            $definiciones = $catalogo->porSeccion($seccion);
            $this->assertNotEmpty($definiciones, "La sección {$seccion} no declara opciones.");

            foreach ($definiciones as $clave => $definicion) {
                $this->assertFalse($definicion->tipo->esSecreto(), "{$clave} no puede ser un secreto en una página de opciones.");
                $this->assertTrue($definicion->editabilidad->permiteEscritura(), "{$clave} debería poder escribirse.");
                $this->assertSame(Persistencia::Nueva, $definicion->persistencia);
                // Misma clave que config(): sin nada guardado manda el .env de siempre.
                $this->assertSame($clave, $definicion->claveConfig, "{$clave} debe caer al mismo config().");
            }
        }
    }

    /** Lo que §13 prohíbe y lo de Hacienda no entra, ni de rebote. */
    public function test_no_entran_rutas_del_servidor_ni_lo_de_hacienda(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach ([
            'ppq.conciliacion.storage_dir', 'ppq.albaranes.storage_dir', 'cobros.solicitudes.storage_dir',
            'exportaciones.plantilla', 'rutas.ambiente_ccf', 'dte.contingencia.enabled',
            'dte.contingencia.url_local', 'alertas.salud.firmadores', 'alertas.salud.disco_minimo_mb',
        ] as $clave) {
            $this->assertFalse($catalogo->existe($clave), "{$clave} no debe ser un ajuste web.");
        }
    }

    public function test_el_nivel_sigue_el_criterio_del_documento(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        // Dinero, planilla o lo que puede dejar a alguien sin trabajar: N2.
        foreach ([
            'ppq.diferencia_coincide', 'ppq.codigo_proveedor', 'ppq.albaranes.sincronizacion_automatica',
            'cobros.inicio_seguimiento', 'cobros.alta.automatica', 'alertas.enabled', 'alertas.correo',
            'planilla.sector_salario_minimo', 'planilla.contrato.patrono.dui', 'rutas.entregas_desde',
            'gastos.recurrencias.generacion_automatica', 'documentos_recibidos.buzon_adicional.host',
        ] as $clave) {
            $this->assertSame(NivelConfirmacion::N2, $catalogo->definicion($clave)->nivel, "{$clave} debería ser N2.");
        }

        foreach (['cobros.correo.query', 'alertas.tope_diario', 'alertas.lector.horario.1', 'gastos.avisos.automaticos'] as $clave) {
            $this->assertSame(NivelConfirmacion::N1, $catalogo->definicion($clave)->nivel, "{$clave} debería ser N1.");
        }
    }

    // --------------------------------------------------------------- permisos

    public function test_sin_permiso_no_se_ve_ni_se_cambia(): void
    {
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->get(route('configuracion.opciones.cobros'))->assertForbidden();
        $this->actingAs($facturacion)->put(route('configuracion.opciones.cobros.update'), [
            $this->campo('cobros.correo.limite') => '20',
        ])->assertForbidden();

        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('cobros.correo.limite'));
    }

    public function test_la_pagina_muestra_cada_opcion_con_su_fuente(): void
    {
        config(['cobros.correo.limite' => 40]);

        $this->actingAs($this->admin())->get(route('configuracion.opciones.cobros'))->assertOk()
            ->assertSee('Respuestas que se leen por vez')
            ->assertSee('value="40"', false)
            ->assertSee('Archivo de configuración / .env')
            ->assertSee('Guardar cambios')
            ->assertSee('Cancelar');
    }

    // ------------------------------------------------------------ módulos

    public function test_la_pagina_de_un_modulo_apagado_no_existe_ni_sale_en_el_menu(): void
    {
        config(['gastos.enabled' => false, 'planilla.enabled' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracion.opciones.gastos'))->assertNotFound();
        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), [
            $this->campo('gastos.avisos.automaticos') => '1',
        ])->assertNotFound();
        $this->actingAs($admin)->get(route('configuracion.opciones.planilla'))->assertNotFound();

        $this->actingAs($admin)->get(route('configuracion.opciones.cobros'))->assertOk()
            ->assertDontSee(route('configuracion.opciones.gastos'))
            ->assertDontSee(route('configuracion.opciones.planilla'));

        $this->assertFalse(Ajustes::bool('gastos.avisos.automaticos'));
    }

    public function test_con_el_modulo_encendido_la_pagina_aparece(): void
    {
        config(['gastos.enabled' => true]);

        $this->actingAs($this->admin())->get(route('configuracion.opciones.gastos'))->assertOk()
            ->assertSee('Crear solos los gastos que se repiten')
            ->assertSee(route('configuracion.opciones.gastos'));
    }

    /** El horario del lector depende de Asistencia: apagada, ni se ve ni se procesa. */
    public function test_el_grupo_del_lector_sigue_a_asistencia(): void
    {
        config(['asistencia.enabled' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracion.opciones.avisos'))->assertOk()
            ->assertSee('Avisar por correo cuando algo falla')
            ->assertDontSee('Horario del lector');

        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), [
            $this->campo('alertas.lector.horario.1') => '09:00-10:00',
        ])->assertRedirect(route('configuracion.opciones.avisos'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('alertas.lector.horario.1'));

        config(['asistencia.enabled' => true]);
        $this->actingAs($admin)->get(route('configuracion.opciones.avisos'))->assertOk()
            ->assertSee('Horario del lector: lunes');
    }

    // ------------------------------------------------------------ validación

    public function test_valida_tipo_rango_correo_hora_y_fecha_en_el_servidor(): void
    {
        $admin = $this->admin();

        $casos = [
            ['configuracion.opciones.avisos', 'alertas.respaldo_hora_limite', '25:00'],
            ['configuracion.opciones.avisos', 'alertas.correo', 'avisos@ejemplo.com, no-es-correo'],
            ['configuracion.opciones.avisos', 'alertas.tope_diario', '0'],
            ['configuracion.opciones.avisos', 'alertas.reaviso_minutos', 'diez'],
            ['configuracion.opciones.cobros', 'cobros.inicio_seguimiento', '2026-02-30'],
            ['configuracion.opciones.cobros', 'cobros.txt_correo.remitente', 'pagos@'],
            ['configuracion.opciones.pronto-pago', 'ppq.diferencia_coincide', '-1'],
            ['configuracion.opciones.pronto-pago', 'ppq.diferencia_coincide', '1e2'],
            ['configuracion.opciones.rutas', 'rutas.entregas_desde', '28/09/2026'],
        ];

        foreach ($casos as [$ruta, $clave, $valor]) {
            $this->actingAs($admin)->from(route($ruta))
                ->put(route($ruta.'.update'), [$this->campo($clave) => $valor, 'confirmacion' => '1'])
                ->assertSessionHasErrors($this->campo($clave));

            $this->assertNotSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente($clave), "{$clave} no debió guardarse con «{$valor}».");
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_el_horario_exige_desde_antes_que_hasta(): void
    {
        $definicion = app(CatalogoAjustes::class)->definicion('alertas.lector.horario.1');
        $conversor = app(ConversorValor::class);

        $this->assertSame('08:00-17:00', $conversor->validarYNormalizar($definicion, ' 08:00-17:00 '));

        foreach (['17:00-08:00', '08:00', '8:00-17:00', '08:00-24:00'] as $malo) {
            try {
                $conversor->validarYNormalizar($definicion, $malo);
                $this->fail("«{$malo}» no debió aceptarse.");
            } catch (ValorAjusteInvalidoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_lista_de_correos_se_guarda_normalizada(): void
    {
        $definicion = app(CatalogoAjustes::class)->definicion('alertas.correo');

        $this->assertSame(
            'uno@ejemplo.com, dos@ejemplo.com',
            app(ConversorValor::class)->validarYNormalizar($definicion, ' Uno@Ejemplo.com ,dos@ejemplo.com,, '),
        );
    }

    // ----------------------------------------------------------------- N1 / N2

    public function test_un_n1_se_guarda_directo_y_queda_auditado(): void
    {
        config(['cobros.correo.limite' => 50]);

        $this->actingAs($this->admin())->put(route('configuracion.opciones.cobros.update'), [
            $this->campo('cobros.correo.limite') => '20',
        ])->assertRedirect(route('configuracion.opciones.cobros'))->assertSessionHas('status');

        $this->assertSame(20, Ajustes::entero('cobros.correo.limite'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('cobros.correo.limite'));

        $actividad = Activity::query()->where('log_name', 'ajustes')->sole();
        $this->assertSame('cobros.correo.limite', $actividad->properties['clave']);
        $this->assertSame('50', (string) $actividad->properties['valor_antes']);
        $this->assertSame('20', (string) $actividad->properties['valor_despues']);
    }

    public function test_un_n2_pide_confirmacion_antes_de_guardar(): void
    {
        config(['ppq.diferencia_coincide' => 0.05]);
        $admin = $this->admin();
        $campo = $this->campo('ppq.diferencia_coincide');

        $this->actingAs($admin)->put(route('configuracion.opciones.pronto-pago.update'), [$campo => '0.10'])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('0.05')
            ->assertSee('0.10')
            ->assertSee('name="'.$campo.'" value="0.10"', false);

        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.pronto-pago.update'), [$campo => '0.10', 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.pronto-pago'));

        $this->assertSame('0.10', Ajustes::decimal('ppq.diferencia_coincide'));
    }

    /** Solo se escribe lo que cambia: lo demás sigue obedeciendo al .env. */
    public function test_solo_se_escribe_lo_que_cambia_y_un_vacio_no_borra(): void
    {
        config(['cobros.correo.limite' => 50, 'cobros.correo.query' => 'in:inbox', 'cobros.txt_correo.carpeta' => 'Pagos']);
        Ajustes::guardarComoSistema('cobros.dias_albaran_reciente', 7);

        $this->actingAs($this->admin())->put(route('configuracion.opciones.cobros.update'), [
            $this->campo('cobros.correo.limite') => '50',          // igual al .env
            $this->campo('cobros.correo.query') => 'in:inbox nuevo', // cambia
            $this->campo('cobros.txt_correo.carpeta') => '',        // vacío: no se toca
            $this->campo('cobros.dias_albaran_reciente') => '',     // vacío: no borra lo guardado
        ])->assertRedirect(route('configuracion.opciones.cobros'));

        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('cobros.correo.limite'));
        $this->assertSame('in:inbox nuevo', Ajustes::texto('cobros.correo.query'));
        $this->assertSame('Pagos', Ajustes::texto('cobros.txt_correo.carpeta'));
        $this->assertSame(7, Ajustes::entero('cobros.dias_albaran_reciente'));
        $this->assertDatabaseCount('ajustes_sistema', 2);
    }

    /** Un campo manipulado no elige qué ajuste se escribe. */
    public function test_un_campo_ajeno_a_la_pagina_se_ignora(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.ejemplo.com']);

        $this->actingAs($this->admin())->put(route('configuracion.opciones.rutas.update'), [
            $this->campo('mail.smtp.host') => 'smtp.malicioso.com',
            $this->campo('ppq.codigo_proveedor') => '999999',
            'confirmacion' => '1',
        ])->assertRedirect(route('configuracion.opciones.rutas'));

        $this->assertSame('smtp.ejemplo.com', Ajustes::texto('mail.smtp.host'));
        $this->assertNotSame('999999', Ajustes::texto('ppq.codigo_proveedor'));
        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // ---------------------------------------- los lectores obedecen a la pantalla

    public function test_ppq_lee_lo_guardado(): void
    {
        config(['ppq.diferencia_coincide' => 0.05, 'ppq.diferencia_pequena' => 1.00, 'ppq.sala_oc_prefix' => 4, 'ppq.sala_oc_length' => 4,
            'ppq.albaranes.tipo_entrega' => 'AC01', 'ppq.codigo_proveedor' => '000123']);

        $this->assertNotSame('coincide', PpqConciliacion::estado('100.00', '99.90')['key']);

        Ajustes::guardarComoSistema('ppq.diferencia_coincide', '0.20');
        Ajustes::guardarComoSistema('ppq.sala_oc_prefix', 2);
        Ajustes::guardarComoSistema('ppq.sala_oc_length', 3);
        Ajustes::guardarComoSistema('ppq.albaranes.tipo_entrega', 'zz09');
        Ajustes::guardarComoSistema('ppq.codigo_proveedor', '000777');

        $this->assertSame('coincide', PpqConciliacion::estado('100.00', '99.90')['key']);
        $this->assertSame('060', OrdenCompra::salaDesde('2606026002401'));
        $this->assertSame('ZZ09', PpqAlbaran::tipoDeEntrega());
        $this->assertSame('000777', ValidadorCodigoProveedorTxt::codigoConfigurado());
    }

    public function test_cobros_y_rutas_leen_lo_guardado(): void
    {
        config(['rutas.entregas_desde' => '2026-09-28']);
        Ajustes::guardarComoSistema('rutas.entregas_desde', '2026-10-15');

        $this->assertSame('2026-10-15', app(EntregasCcf::class)->desde()->toDateString());
    }

    public function test_los_avisos_leen_lo_guardado(): void
    {
        config(['alertas.correo' => 'env@ejemplo.com', 'asistencia.enabled' => true, 'alertas.lector.sin_ping_minutos' => 15]);
        Ajustes::guardarComoSistema('alertas.correo', 'uno@ejemplo.com, dos@ejemplo.com');

        $this->assertSame(['uno@ejemplo.com', 'dos@ejemplo.com'], app(Avisador::class)->destinatarios());

        // Domingo: el .env no lo vigila; guardado, sí. Lunes guardado vacío de horas: no.
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica());

        Ajustes::guardarComoSistema('alertas.lector.horario.7', '09:00-12:00');
        Ajustes::guardarComoSistema('alertas.lector.sin_ping_minutos', 5);
        $this->assertTrue(app(LectorSinPing::class)->aplica());

        Carbon::setTestNow(Carbon::parse('2026-11-01 12:30', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica());
        Carbon::setTestNow();
    }

    public function test_las_tareas_programadas_obedecen_a_la_pantalla(): void
    {
        config(['cobros.alta.automatica' => false, 'alertas.enabled' => false]);

        $evento = fn (string $comando) => collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, $comando));

        $this->assertFalse($evento('cobros:sincronizar')->filtersPass($this->app));
        $this->assertFalse($evento('alertas:revisar')->filtersPass($this->app));

        Ajustes::guardarComoSistema('cobros.alta.automatica', true);
        Ajustes::guardarComoSistema('alertas.enabled', true);

        $this->assertTrue($evento('cobros:sincronizar')->filtersPass($this->app));
        $this->assertTrue($evento('alertas:revisar')->filtersPass($this->app));
    }

    public function test_el_comando_respeta_la_llave_guardada(): void
    {
        config(['ppq.albaranes.sincronizacion_automatica' => true]);
        Ajustes::guardarComoSistema('ppq.albaranes.sincronizacion_automatica', false);

        $this->artisan('ppq:sincronizar-albaranes --aplicar')->assertFailed();
    }

    public function test_planilla_lee_lo_guardado(): void
    {
        config([
            'planilla.sector_salario_minimo' => 'industria',
            'planilla.contrato.patrono.ciudad' => 'Ciudad del env',
            'planilla.contrato.modelos.produccion.lugar_trabajo' => 'Planta del env',
        ]);

        Ajustes::guardarComoSistema('planilla.sector_salario_minimo', 'agropecuario');
        Ajustes::guardarComoSistema('planilla.contrato.patrono.ciudad', 'Ciudad Ejemplo');
        Ajustes::guardarComoSistema('planilla.contrato.modelos.produccion.lugar_trabajo', 'Planta de ejemplo');

        $this->assertSame(27253, app(SalarioMinimo::class)->vigenteEn('2026-01-01'));
        $this->assertSame('Planta de ejemplo', ContratoTrabajo::modelos()['produccion']['lugar_trabajo']);

        $patrono = (fn () => $this->patrono())->call(app(ContratoTrabajo::class));
        $this->assertSame('Ciudad Ejemplo', $patrono['ciudad']);
    }

    public function test_exportaciones_lee_lo_guardado_tambien_para_una_lista_nueva(): void
    {
        config(['exportaciones.fda_reg_number' => '00000000001', 'exportaciones.exportador_nombre' => 'Nombre del env']);
        Empresa::create(['razon_social' => 'Empresa Ejemplo', 'ambiente' => '00', 'activo' => true]);

        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000002');
        Ajustes::guardarComoSistema('exportaciones.exportador_nombre', 'Exportadora Ejemplo');

        $this->assertSame('00000000002', app(DatosExportador::class)->fdaRegNumber());
        $this->assertSame('Exportadora Ejemplo', app(DatosExportador::class)->paraEncabezado()['exportador_nombre']);
    }
}
