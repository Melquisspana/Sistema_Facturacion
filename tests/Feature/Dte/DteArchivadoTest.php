<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteLinea;
use App\Models\User;
use App\Services\Ppq\PpqBusquedaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * ARCHIVADO de un DTE RECHAZADO por Hacienda: lo retira de la operación diaria sin
 * borrarlo. No usa SoftDeletes, no libera correlativos, no cambia el estado fiscal y
 * conserva líneas, JSON, respuesta del MH, número de control, código de generación e
 * historial. Un archivado no admite ninguna acción operativa.
 *
 * Nace del DTE #150 (NC rechazada con "[resumen.montoTotalOperacion] CALCULO
 * INCORRECTO"), que debía salir de la operación sin perder la evidencia.
 */
class DteArchivadoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        $this->seedCatalogosDte();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /**
     * DTE con datos fiscales completos (líneas, JSON, respuesta del MH) en el estado
     * indicado y en el ambiente ACTIVO de la instalación (el del listado operativo).
     */
    private function dte(EstadoDte $estado = EstadoDte::Rechazado, array $extra = []): Dte
    {
        static $n = 0;
        $n++; // numero_control único por ambiente (índice único en `dtes`)

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte('M001', 'P00'.$n);
        Correlativo::create(['tipo_dte' => '05', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 7, 'activo' => true]);

        // Se crea en BORRADOR para poder cargar las líneas (DteLineaObserver bloquea
        // tocar líneas fuera de borrador) y luego se lleva al estado final, igual que el
        // flujo real. `estado` está en la whitelist del DteObserver, así que la
        // transición desde borrador es válida.
        $dte = Dte::create($extra + [
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => EstadoDte::Borrador->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE ARCHIVADO SA'])->id,
            'numero_control' => 'DTE-05-M001P002-'.str_pad((string) (150 + $n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'total_gravado' => 121.73,
            'iva' => 15.82,
            'iva_retenido' => 1.22,
            'total_pagar' => 136.33,
            'respuesta_mh' => ['estado' => 'RECHAZADO', 'descripcionMsg' => '[resumen.montoTotalOperacion] CALCULO INCORRECTO'],
        ]);

        DteLinea::create([
            'dte_id' => $dte->id, 'numero_linea' => 1, 'descripcion' => 'Producto revertido',
            'cantidad' => 1, 'precio_unitario' => 121.73, 'venta_gravada' => 121.73,
            'tipo_impuesto' => \App\Enums\TipoImpuesto::Gravado->value,
        ]);

        $ruta = 'dte/json/dte-05-'.$dte->id.'.json';
        Storage::disk('local')->put($ruta, '{"resumen":{"montoTotalOperacion":137.55}}');
        $dte->json_generado_path = $ruta;
        $dte->estado = $estado;
        $dte->save();

        return $dte->refresh();
    }

    // ---------- Quién y qué se puede archivar ----------

    public function test_un_rechazado_se_puede_archivar(): void
    {
        $dte = $this->dte();
        $this->assertTrue($dte->esArchivable());

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.archivar', $dte))
            ->assertRedirect()
            ->assertSessionHas('status');

        $dte->refresh();
        $this->assertTrue($dte->estaArchivado());
        $this->assertNotNull($dte->archivado_en);
    }

    public function test_no_se_puede_archivar_un_aceptado_ni_un_invalidado_ni_uno_en_curso(): void
    {
        $usuario = $this->usuario('facturacion');

        foreach ([EstadoDte::Aceptado, EstadoDte::Invalidado, EstadoDte::Generado, EstadoDte::Firmado, EstadoDte::Enviado] as $estado) {
            $dte = $this->dte($estado);
            $this->assertFalse($dte->esArchivable(), 'No debería ser archivable en estado '.$estado->value);

            $this->actingAs($usuario)->post(route('facturacion.archivar', $dte))->assertForbidden();
            $this->assertFalse((bool) $dte->refresh()->archivado);
        }
    }

    public function test_permisos_del_archivado(): void
    {
        $dte = $this->dte();

        // Sin dte.gestionar no se puede.
        foreach (['jefatura', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))->post(route('facturacion.archivar', $dte))->assertForbidden();
        }
        $this->assertFalse((bool) $dte->refresh()->archivado);

        $this->actingAs($this->usuario('administrador'))->post(route('facturacion.archivar', $dte))->assertRedirect();
        $this->assertTrue((bool) $dte->refresh()->archivado);
    }

    // ---------- Visibilidad ----------

    public function test_desaparece_del_listado_normal_y_aparece_en_rechazados_archivados(): void
    {
        $dte = $this->dte();
        $usuario = $this->usuario('facturacion');

        // Antes de archivar sí está en el listado normal.
        $this->actingAs($usuario)->get(route('facturacion.index'))->assertOk()->assertSee($dte->numero_control);

        $this->actingAs($usuario)->post(route('facturacion.archivar', $dte));

        $this->actingAs($usuario)->get(route('facturacion.index'))
            ->assertOk()->assertDontSee($dte->numero_control);
        $this->actingAs($usuario)->get(route('facturacion.index', ['estado' => 'rechazados_invalidados']))
            ->assertOk()->assertDontSee($dte->numero_control);
        $this->actingAs($usuario)->get(route('facturacion.index', ['estado' => 'rechazados_archivados']))
            ->assertOk()->assertSee($dte->numero_control);
    }

    public function test_sigue_accesible_por_url_directa(): void
    {
        $dte = $this->dte();
        $usuario = $this->usuario('facturacion');
        $this->actingAs($usuario)->post(route('facturacion.archivar', $dte));

        $this->actingAs($usuario)->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee($dte->numero_control)
            ->assertSee('Documento archivado');
    }

    public function test_desaparece_de_la_busqueda_rapida_ppq(): void
    {
        $dte = $this->dte();
        $filtros = ['q' => $dte->numero_control];

        $antes = app(PpqBusquedaService::class)->buscar($filtros);
        $this->assertTrue($antes->pluck('id')->contains($dte->id));

        $this->actingAs($this->usuario('facturacion'))->post(route('facturacion.archivar', $dte));

        $despues = app(PpqBusquedaService::class)->buscar($filtros);
        $this->assertFalse($despues->pluck('id')->contains($dte->id));
    }

    // ---------- Ninguna acción operativa ----------

    public function test_un_archivado_no_admite_acciones_operativas(): void
    {
        $dte = $this->dte();
        $gestor = $this->usuario('facturacion');
        $this->actingAs($gestor)->post(route('facturacion.archivar', $dte));
        $dte->refresh();

        // Correo: es el único flujo que por estado aceptaría un rechazado.
        $this->actingAs($gestor)->post(route('facturacion.correo.cliente', $dte))->assertForbidden();
        $this->actingAs($gestor)->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'x@x.com'])->assertForbidden();

        // Firma/transmisión, emisión de producción y edición.
        $this->actingAs($gestor)->post(route('facturacion.firmar-transmitir', $dte))->assertForbidden();
        $this->actingAs($gestor)->post(route('facturacion.generar-transmitir-produccion', $dte))->assertForbidden();
        $this->actingAs($gestor)->get(route('facturacion.edit', $dte))->assertForbidden();

        // Y las policies lo niegan de forma explícita.
        $this->assertFalse($gestor->can('enviarCorreo', $dte));
        $this->assertFalse($gestor->can('firmarTransmitir', $dte));
        $this->assertFalse($gestor->can('generarTransmitirProduccion', $dte));
        $this->assertFalse($gestor->can('update', $dte));
    }

    // ---------- Nada fiscal se toca ----------

    public function test_archivar_no_toca_datos_fiscales_ni_el_correlativo(): void
    {
        $dte = $this->dte();
        $antes = [
            'estado' => $dte->estado->value,
            'numero_control' => $dte->numero_control,
            'codigo_generacion' => $dte->codigo_generacion,
            'total_pagar' => (string) $dte->total_pagar,
            'iva_retenido' => (string) $dte->iva_retenido,
            'json' => $dte->json_generado_path,
            'respuesta' => $dte->respuesta_mh,
            'lineas' => $dte->lineas()->count(),
        ];
        $correlAntes = Correlativo::orderBy('id')->get(['id', 'tipo_dte', 'ambiente', 'ultimo_numero'])->toArray();
        $dtesAntes = Dte::count();

        $this->actingAs($this->usuario('facturacion'))->post(route('facturacion.archivar', $dte));
        $dte->refresh();

        $this->assertSame($antes['estado'], $dte->estado->value);   // sigue rechazado
        $this->assertSame($antes['numero_control'], $dte->numero_control);
        $this->assertSame($antes['codigo_generacion'], $dte->codigo_generacion);
        $this->assertSame($antes['total_pagar'], (string) $dte->total_pagar);
        $this->assertSame($antes['iva_retenido'], (string) $dte->iva_retenido);
        $this->assertSame($antes['json'], $dte->json_generado_path);
        $this->assertSame($antes['respuesta'], $dte->respuesta_mh);
        $this->assertSame($antes['lineas'], $dte->lineas()->count());
        $this->assertTrue(Storage::disk('local')->exists((string) $dte->json_generado_path));

        // El correlativo NUNCA se libera ni retrocede, y no se borra ningún documento.
        $this->assertEquals($correlAntes, Correlativo::orderBy('id')->get(['id', 'tipo_dte', 'ambiente', 'ultimo_numero'])->toArray());
        $this->assertSame($dtesAntes, Dte::count());
        $this->assertNull($dte->deleted_at); // no se usa SoftDeletes para esto
    }

    // ---------- Auditoría y reversibilidad ----------

    public function test_auditoria_registra_archivar_y_desarchivar(): void
    {
        $dte = $this->dte();
        $usuario = $this->usuario('administrador');

        $this->actingAs($usuario)->post(route('facturacion.archivar', $dte));
        $this->actingAs($usuario)->post(route('facturacion.desarchivar', $dte->refresh()));

        $logs = Activity::where('log_name', 'dte_archivado')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('archivado', $logs[0]->getExtraProperty('accion'));
        $this->assertSame('desarchivado', $logs[1]->getExtraProperty('accion'));
        foreach ($logs as $log) {
            $this->assertSame($usuario->id, $log->causer_id);
            $this->assertSame($dte->numero_control, $log->getExtraProperty('numero_control'));
            $this->assertSame($dte->id, $log->subject_id);
        }
    }

    public function test_desarchivar_lo_devuelve_al_listado(): void
    {
        $dte = $this->dte();
        $usuario = $this->usuario('facturacion');
        $this->actingAs($usuario)->post(route('facturacion.archivar', $dte));

        $this->actingAs($usuario)->post(route('facturacion.desarchivar', $dte->refresh()))
            ->assertRedirect()->assertSessionHas('status');

        $dte->refresh();
        $this->assertFalse($dte->estaArchivado());
        $this->assertNull($dte->archivado_en);
        $this->actingAs($usuario)->get(route('facturacion.index'))->assertOk()->assertSee($dte->numero_control);
        // Y vuelve a admitir correo (la acción operativa que el archivado cerraba).
        $this->assertTrue($usuario->can('enviarCorreo', $dte));
    }

    public function test_no_se_puede_desarchivar_uno_que_no_esta_archivado(): void
    {
        $dte = $this->dte();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.desarchivar', $dte))
            ->assertForbidden();
    }
}
