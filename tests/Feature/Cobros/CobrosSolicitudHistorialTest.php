<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudItem;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\RegistroDescargas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Historial COMPLETO de solicitudes de quedan en Cobros (paginado, con búsqueda) y la ficha
 * de solo lectura de cada solicitud.
 */
class CobrosSolicitudHistorialTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------------------ utilidades

    private function usuario(string $nombre = 'Persona Lectora'): User
    {
        return User::factory()->create(['name' => $nombre])->assignRole(RolSistema::Administrador->value);
    }

    private function cliente(string $nombre = 'Calleja, S.A. de C.V.', string $codigo = '000123'): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => $nombre]);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => $codigo,
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** Solicitud directa en la base, con referencia única y los datos que se indiquen. */
    private function solicitud(Cliente $cliente, array $datos = []): CobroSolicitud
    {
        $this->n++;

        return CobroSolicitud::create(array_merge([
            'cliente_id' => $cliente->id,
            'referencia' => 'SOL-'.$cliente->id.'-20260901-'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT),
            'formato' => 'carga_masiva_v1',
            'archivo_nombre' => '00012320260901'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT).'.xlsx',
            'estado' => EstadoSolicitudCobro::Generada->value,
        ], $datos));
    }

    /** Renglón congelado con su documento. */
    private function renglon(CobroSolicitud $solicitud, string $albaran, string $monto, int $orden, string $tipo = '03'): CobroSolicitudItem
    {
        $this->n++;
        $documento = CobroDocumento::create([
            'cliente_id' => $solicitud->cliente_id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => $tipo,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-08-20',
            'monto' => $monto,
            'cobro_solicitud_id' => $solicitud->id,
        ]);

        return CobroSolicitudItem::create([
            'cobro_solicitud_id' => $solicitud->id,
            'cobro_documento_id' => $documento->id,
            'orden' => $orden,
            'sala_codigo' => '0017',
            'albaran_numero' => $albaran,
            'albaran_anio' => 26,
            'albaran_mes' => 8,
            'albaran_tipo' => 'AC01',
            'monto' => $monto,
        ]);
    }

    /** Texto del HTML sin etiquetas y con los espacios colapsados. */
    private function textoVisible(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** @return array<int, string> referencias de una página del historial */
    private function pagina(Cliente $cliente, User $usuario, array $params = []): array
    {
        return $this->actingAs($usuario)
            ->get(route('cobros.index', ['cliente_id' => $cliente->id] + $params))
            ->assertOk()
            ->viewData('solicitudes')
            ->getCollection()->pluck('referencia')->all();
    }

    // ------------------------------------------------------------------ historial

    public function test_el_historial_pagina_todas_las_solicitudes_sin_perder_ni_repetir(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $todas = collect(range(1, 45))->map(fn () => $this->solicitud($cliente));

        $vistas = array_merge(
            $this->pagina($cliente, $usuario),
            $this->pagina($cliente, $usuario, ['solicitudes_page' => 2]),
            $this->pagina($cliente, $usuario, ['solicitudes_page' => 3]),
        );

        $this->assertCount(45, $vistas);
        $this->assertSame($vistas, array_values(array_unique($vistas)), 'Ninguna se repite.');
        $this->assertSame($todas->sortByDesc('id')->pluck('referencia')->values()->all(), $vistas, 'Orden estable, más reciente primero.');
        $this->assertSame([], $this->pagina($cliente, $usuario, ['solicitudes_page' => 4]));
    }

    public function test_la_pagina_de_solicitudes_no_mueve_la_de_documentos_y_conserva_filtros(): void
    {
        $cliente = $this->cliente();
        foreach (range(1, 25) as $_) {
            $this->solicitud($cliente, ['estado' => EstadoSolicitudCobro::Presentada->value]);
        }

        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.index', [
            'cliente_id' => $cliente->id,
            'sol_estado' => 'presentada',
            'tipo' => '03',
            'solicitudes_page' => 2,
        ]))->assertOk();

        $this->assertSame(2, $respuesta->viewData('solicitudes')->currentPage());
        $this->assertSame(1, $respuesta->viewData('documentos')->currentPage());
        $this->assertCount(5, $respuesta->viewData('solicitudes')->items());

        // Los enlaces de página del historial conservan cliente y los dos juegos de filtros.
        $enlace = $respuesta->viewData('solicitudes')->url(1);
        foreach (['cliente_id='.$cliente->id, 'sol_estado=presentada', 'tipo=03'] as $parte) {
            $this->assertStringContainsString($parte, $enlace);
        }
    }

    public function test_los_filtros_acotan_por_estado_referencia_y_fecha_de_generacion(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $vieja = $this->solicitud($cliente, ['estado' => EstadoSolicitudCobro::Recibida->value, 'referencia_calleja' => '31001']);
        $vieja->forceFill(['created_at' => '2026-03-10 09:00:00'])->save();
        $nueva = $this->solicitud($cliente);
        $nueva->forceFill(['created_at' => '2026-09-10 09:00:00'])->save();

        $this->assertSame([$vieja->referencia], $this->pagina($cliente, $usuario, ['sol_estado' => 'recibida']));
        $this->assertSame([$vieja->referencia], $this->pagina($cliente, $usuario, ['sol_q' => '31001']));
        $this->assertSame([$nueva->referencia], $this->pagina($cliente, $usuario, ['sol_q' => $nueva->referencia]));
        $this->assertSame([$vieja->referencia], $this->pagina($cliente, $usuario, ['sol_desde' => '2026-03-01', 'sol_hasta' => '2026-03-31']));
        // Un estado desconocido se ignora: se ve todo, no nada.
        $this->assertCount(2, $this->pagina($cliente, $usuario, ['sol_estado' => 'inventado']));
    }

    public function test_valores_get_no_escalares_o_invalidos_no_rompen_la_bandeja(): void
    {
        $cliente = $this->cliente();
        $this->solicitud($cliente);

        $this->actingAs($this->usuario())->get(route('cobros.index').'?cliente_id='.$cliente->id
            .'&sol_q[]=x&sol_estado[]=generada&sol_desde[]=2026-01-01&sol_hasta=no-es-fecha'
            .'&q[]=y&tipo[a]=03&solicitudes_page[]=2&page[]=3')
            ->assertOk()
            ->assertViewHas('filtrosSolicitud', fn ($f) => $f === ['sol_q' => '', 'sol_estado' => '', 'sol_desde' => '', 'sol_hasta' => '']);

        $this->cliente('Otro cliente', '002200');
        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.index').'?cliente_id[]='.$cliente->id)
            ->assertOk();
        $this->assertNull($respuesta->viewData('cliente'));
    }

    public function test_el_historial_no_muestra_solicitudes_de_otro_cliente(): void
    {
        $propio = $this->cliente('Cliente Propio');
        $ajeno = $this->cliente('Cliente Ajeno', '002200');
        $mia = $this->solicitud($propio);
        $otra = $this->solicitud($ajeno);

        $vistas = $this->pagina($propio, $this->usuario());

        $this->assertSame([$mia->referencia], $vistas);
        $this->assertNotContains($otra->referencia, $vistas);
    }

    // ------------------------------------------------------------------ ficha

    public function test_la_ficha_muestra_sus_renglones_congelados_y_nada_de_otra_solicitud(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitud($cliente);
        $a = $this->renglon($solicitud, '5131', '123.74', 1);
        $b = $this->renglon($solicitud, '5132', '99.10', 2);
        $otra = $this->solicitud($this->cliente('Cliente Ajeno', '002200'));
        $ajeno = $this->renglon($otra, '7777', '55.55', 1);
        CobroCorreo::create(['cliente_id' => $otra->cliente_id, 'gmail_message_id' => 'm-ajeno', 'asunto' => 'Acuse ajeno XYZ', 'cobro_solicitud_id' => $otra->id]);
        CobroCorreo::create(['cliente_id' => $cliente->id, 'gmail_message_id' => 'm-propio', 'asunto' => 'Acuse propio ABC', 'cobro_solicitud_id' => $solicitud->id]);

        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.solicitudes.show', $solicitud))->assertOk();
        $html = $respuesta->getContent();

        $this->assertSame(
            [$a->id, $b->id],
            $respuesta->viewData('renglones')->getCollection()->pluck('id')->all(),
            'Exactamente sus renglones, en su orden.',
        );
        foreach (['5131', '5132', '123.74', '99.10', $a->documento->numero_control, 'Acuse propio ABC', '222.84'] as $esperado) {
            $this->assertTrue(str_contains($html, $esperado), "Falta {$esperado}.");
        }
        foreach (['7777', '55.55', $ajeno->documento->numero_control, 'Acuse ajeno XYZ', $otra->referencia] as $ajenoTexto) {
            $this->assertFalse(str_contains($html, $ajenoTexto), "Fuga de {$ajenoTexto}.");
        }
    }

    /**
     * El total cubre TODOS los renglones aunque la ficha muestre 50 por página, las NC
     * restan y coincide al centavo con {@see CobroSolicitud::total()}.
     */
    public function test_el_total_es_de_todo_el_lote_con_las_nc_restando(): void
    {
        $solicitud = $this->solicitud($this->cliente());
        foreach (range(1, 54) as $i) {
            $this->renglon($solicitud, (string) (6000 + $i), '10.10', $i);   // 54 × 10.10 = 545.40
        }
        $this->renglon($solicitud, '6100', '0.30', 55, '05');                // NC: resta 0.30

        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.solicitudes.show', $solicitud))->assertOk();

        $this->assertCount(50, $respuesta->viewData('renglones')->items(), 'La página sigue siendo de 50.');
        $this->assertSame('545.10', $respuesta->viewData('total'));
        $this->assertSame($solicitud->refresh()->total(), $respuesta->viewData('total'), 'Misma cuenta que el modelo.');
        $this->assertTrue(str_contains($this->textoVisible($respuesta->getContent()), '55 · 545.10'));
    }

    public function test_los_estados_se_muestran_por_separado_sin_confundir_descarga_con_presentacion(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();

        // Descargada pero NO presentada ni recibida.
        $solo = $this->solicitud($cliente);
        $solo->forceFill(['descargas' => 3, 'descargada_en' => now()])->save();
        $html = $this->actingAs($usuario)->get(route('cobros.solicitudes.show', $solo))->assertOk()->getContent();
        $this->assertTrue(str_contains($html, 'Nadie declaró haberla presentado.'));
        $this->assertTrue(str_contains($html, 'Sin acuse registrado.'));

        // Presentada y con acuse: cada hecho en su bloque.
        $declarante = $this->usuario('Persona Declarante');
        $completa = $this->solicitud($cliente, [
            'estado' => EstadoSolicitudCobro::Recibida->value,
            'presentada_en' => '2026-09-19 09:30:00',
            'presentada_por' => $declarante->id,
            'presentada_nota' => 'Subida a las 9:30.',
            'referencia_calleja' => '31001',
            'recibida_en' => '2026-09-20 10:00:00',
            'fecha_programada_pago' => '2026-10-15',
        ]);
        // Texto visible con los espacios colapsados: se verifica el contenido, no el marcado.
        $html = $this->textoVisible($this->actingAs($usuario)->get(route('cobros.solicitudes.show', $completa))->assertOk()->getContent());
        foreach (['Persona Declarante declaró haberla subido al portal el 19/09/2026', 'Subida a las 9:30.', '31001', '15/10/2026'] as $texto) {
            $this->assertTrue(str_contains($html, $texto), "Falta {$texto}.");
        }
        $this->assertFalse(str_contains($html, 'Nadie declaró haberla presentado.'));
    }

    public function test_descargas_antiguas_no_inventan_usuario_y_las_registradas_muestran_actor_y_huella(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitud($cliente);
        $solicitud->forceFill(['descargas' => 2, 'descargada_en' => now()->subMonth()])->save();
        $lector = $this->usuario();

        $html = $this->actingAs($lector)->get(route('cobros.solicitudes.show', $solicitud))->assertOk()->getContent();
        $this->assertTrue(str_contains($html, 'Sin descargas registradas.'));
        $this->assertTrue(str_contains($html, '2 descarga(s) anterior(es) a este registro solo quedaron en el contador, sin usuario ni hora.'));
        $this->assertFalse(str_contains($html, 'Usuario no disponible'), 'No se fabrica una fila para el contador.');

        // Una descarga registrada ahora sí dice quién y con qué huella.
        $descargador = $this->usuario('Persona Descargadora');
        $temporal = tempnam(sys_get_temp_dir(), 'sol_');
        file_put_contents($temporal, 'bytes servidos');
        try {
            app(RegistroDescargas::class)->registrar($solicitud, $temporal, (string) $solicitud->archivo_nombre, $descargador, (string) $solicitud->referencia);
        } finally {
            @unlink($temporal);
        }
        $antes = Activity::count();

        $html = $this->actingAs($lector)->get(route('cobros.solicitudes.show', $solicitud))->assertOk()->getContent();

        $this->assertSame($antes, Activity::count(), 'Abrir la ficha no registra actividad.');
        $this->assertTrue(str_contains($html, 'Persona Descargadora'));
        $this->assertTrue(str_contains($html, hash('sha256', 'bytes servidos')));
        $this->assertTrue(str_contains($html, '2 descarga(s) anterior(es)'), 'Contador 3, una registrada: dos quedan sin detalle.');
    }

    public function test_la_ficha_y_el_historial_exigen_permiso_de_cobros(): void
    {
        $cliente = $this->cliente();
        $solicitud = $this->solicitud($cliente);

        $this->get(route('cobros.solicitudes.show', $solicitud))->assertRedirect(route('login'));

        $sinPermiso = User::factory()->create();
        $this->actingAs($sinPermiso)->get(route('cobros.solicitudes.show', $solicitud))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('cobros.index', ['cliente_id' => $cliente->id]))->assertForbidden();

    }
}
