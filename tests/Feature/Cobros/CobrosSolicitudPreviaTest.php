<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudItem;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * Vista previa de la solicitud de quedan: muestra exactamente lo que se preparará, no
 * escribe nada antes de confirmar, y la confirmación solo prepara lo que se vio.
 */
class CobrosSolicitudPreviaTest extends TestCase
{
    use CreaDteVerificableCobros;
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

        Storage::fake((string) config('dte.storage.disk', 'local'));
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function cliente(string $nombre = 'Calleja, S.A. de C.V.'): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => $nombre]);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** CCF listo con su albarán; `$emision` fija el orden por antigüedad. */
    private function listo(Cliente $cliente, string $emision, string $sala = '0017', string $fechaAlbaran = '2026-08-17', string $monto = '123.74'): CobroDocumento
    {
        $this->n++;
        $numero = 'DTE-03-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT);
        $dte = $this->dteVerificableCobros($cliente, $numero, $emision, $monto);
        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $numero,
            'fecha_emision' => $emision,
            'monto' => $monto,
        ]);
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/'.$sala.'/00/'.(5000 + $this->n),
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => $fechaAlbaran,
            'monto_albaran' => $monto,
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => $sala,
        ]);
        $documento->forceFill([
            'ppq_albaran_id' => $albaran->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ])->save();

        return $documento;
    }

    private function previa(Cliente $cliente, array $ids, ?User $usuario = null, array $params = [])
    {
        return $this->actingAs($usuario ?? $this->usuario())
            ->post(route('cobros.solicitudes.previa', ['cliente' => $cliente] + $params), ['documentos' => $ids]);
    }

    private function confirmar(Cliente $cliente, ?string $token, User $usuario, array $extra = [])
    {
        return $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $cliente), ['previa' => $token] + $extra);
    }

    private function assertNadaPreparado(CobroDocumento ...$documentos): void
    {
        $this->assertSame(0, CobroSolicitud::count());
        $this->assertSame(0, CobroSolicitudItem::count());
        $this->assertSame(0, CobroEvento::count());
        $this->assertSame([], Storage::disk((string) config('dte.storage.disk', 'local'))->allFiles());
        foreach ($documentos as $documento) {
            $documento->refresh();
            $this->assertNull($documento->cobro_solicitud_id);
            $this->assertSame(EstadoPresentacionCobro::SinPresentar, $documento->presentacion_estado);
        }
    }

    // ------------------------------------------------------------------ vista previa

    public function test_dos_documentos_salen_del_mas_antiguo_al_mas_reciente(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $antiguo = $this->listo($cliente, '2026-08-01');
        $reciente = $this->listo($cliente, '2026-08-20');

        $previa = $this->previa($cliente, [$reciente->id, $antiguo->id], $usuario)->assertOk();

        $this->assertSame(
            [$antiguo->id, $reciente->id],
            array_map(fn ($fila) => $fila['documento']->id, $previa->viewData('filas')),
        );
        $this->assertNadaPreparado($antiguo, $reciente);
    }

    public function test_la_vista_previa_muestra_exactamente_los_renglones_que_se_preparan(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        // Se crean desordenados: el archivo va de la más antigua a la más reciente.
        $reciente = $this->listo($cliente, '2026-09-10', '0207', '2026-09-09', '10.10');
        $antigua = $this->listo($cliente, '2026-08-01', '0017', '2026-07-31', '20.25');
        $media = $this->listo($cliente, '2026-08-20', '0104', '2026-08-19', '30.00');

        $previa = $this->previa($cliente, [$reciente->id, $antigua->id, $media->id], $usuario)->assertOk();

        $previa->assertSeeText($cliente->nombre);
        $previa->assertSeeText('60.35');
        $previa->assertSeeText('Confirmar y preparar');
        $previa->assertSeeText('Volver a seleccionar');
        $this->assertSame('60.35', $previa->viewData('total'));
        $this->assertSame(
            ['CODIGO DE SALA O CD', '# ALBARAN', 'AÑO (ultimos 2 digitos)', 'MES  (en numero)', 'TIPO ALBARAN'],
            $previa->viewData('columnas'),
        );
        $previa->assertSee('MES  (en numero)', false);

        $filas = $previa->viewData('filas');
        $this->assertSame([$antigua->id, $media->id, $reciente->id], array_map(fn ($f) => $f['documento']->id, $filas));
        $this->assertSame(['0017', '5002', 26, 7, 'AC01'], ExportadorSolicitudCargaMasivaV1::celdas($filas[0]['datos']));

        $html = $previa->getContent();
        $this->assertLessThan(strpos($html, $media->numero_control), strpos($html, $antigua->numero_control));
        $this->assertLessThan(strpos($html, $reciente->numero_control), strpos($html, $media->numero_control));

        $this->assertNadaPreparado($reciente, $antigua, $media);

        // Confirmar congela EXACTAMENTE esos renglones.
        $this->confirmar($cliente, $previa->viewData('token'), $usuario)->assertSessionHasNoErrors();

        $solicitud = CobroSolicitud::sole();
        $items = $solicitud->items()->orderBy('orden')->get();
        $this->assertCount(3, $items);
        foreach ($filas as $i => $fila) {
            $item = $items[$i];
            $this->assertSame($fila['orden'], (int) $item->orden);
            $this->assertSame($fila['documento']->id, $item->cobro_documento_id);
            $this->assertSame(
                ExportadorSolicitudCargaMasivaV1::celdas($fila['datos']),
                ExportadorSolicitudCargaMasivaV1::celdas($item->only(['sala_codigo', 'albaran_numero', 'albaran_anio', 'albaran_mes', 'albaran_tipo'])),
            );
            $this->assertSame((float) $fila['datos']['monto'], (float) $item->monto);
        }
        $this->assertSame(EstadoPresentacionCobro::Preparada, $antigua->refresh()->presentacion_estado);
    }

    public function test_la_confirmacion_prepara_lo_visto_y_no_lo_que_traiga_el_formulario(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $visto = $this->listo($cliente, '2026-08-01');
        $colado = $this->listo($cliente, '2026-08-02');

        $previa = $this->previa($cliente, [$visto->id], $usuario)->assertOk();
        $this->confirmar($cliente, $previa->viewData('token'), $usuario, ['documentos' => [$visto->id, $colado->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$visto->id], CobroSolicitud::sole()->items()->pluck('cobro_documento_id')->all());
        $this->assertNull($colado->refresh()->cobro_solicitud_id);
    }

    public function test_sin_seleccion_es_un_error_controlado(): void
    {
        $cliente = $this->cliente();
        $documento = $this->listo($cliente, '2026-08-01');

        $this->actingAs($this->usuario())
            ->post(route('cobros.solicitudes.previa', $cliente), [])
            ->assertRedirect(route('cobros.index', ['cliente_id' => $cliente->id]))
            ->assertSessionHasErrors('documentos');

        $this->assertNadaPreparado($documento);
    }

    // ------------------------------------------------------------------ confirmación

    public function test_sin_vista_previa_o_con_una_usada_no_se_prepara_nada(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->listo($cliente, '2026-08-01');

        // El POST directo de antes ya no prepara.
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $cliente), ['documentos' => [$documento->id]])
            ->assertSessionHasErrors('documentos');
        $this->confirmar($cliente, 'inventado', $usuario)->assertSessionHasErrors('documentos');
        $this->assertNadaPreparado($documento);

        $token = $this->previa($cliente, [$documento->id], $usuario)->assertOk()->viewData('token');
        $this->confirmar($cliente, $token, $usuario)->assertSessionHasNoErrors();
        // Reenviar el mismo formulario no arma una segunda.
        $this->confirmar($cliente, $token, $usuario)->assertSessionHasErrors('documentos');
        $this->assertSame(1, CobroSolicitud::count());
    }

    public function test_si_la_elegibilidad_cambia_tras_la_vista_previa_no_queda_nada_a_medias(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $sigueLista = $this->listo($cliente, '2026-08-01');
        $seBloquea = $this->listo($cliente, '2026-08-02');

        $token = $this->previa($cliente, [$sigueLista->id, $seBloquea->id], $usuario)->assertOk()->viewData('token');
        $seBloquea->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Ya viajó en otro lote.'])->save();

        $this->confirmar($cliente, $token, $usuario)->assertSessionHasErrors('documentos');

        $this->assertNadaPreparado($sigueLista, $seBloquea);
    }

    public function test_si_cambia_un_dato_del_renglon_tras_la_vista_previa_se_rechaza(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->listo($cliente, '2026-08-01', '0017');

        $token = $this->previa($cliente, [$documento->id], $usuario)->assertOk()->viewData('token');
        // Sigue elegible, pero su renglón ya no sería el que se mostró.
        $documento->albaran->forceFill(['fecha_albaran' => '2026-06-17'])->save();

        $this->confirmar($cliente, $token, $usuario)
            ->assertSessionHasErrors(['documentos' => 'No se preparó nada: los documentos cambiaron desde la vista previa (albarán, importe u orden). Vuelva a revisarla antes de confirmar.']);

        $this->assertNadaPreparado($documento);
    }

    // ------------------------------------------------------------------ permisos

    public function test_solo_lectura_no_ve_la_vista_previa_ni_confirma(): void
    {
        $cliente = $this->cliente();
        $documento = $this->listo($cliente, '2026-08-01');

        $this->previa($cliente, [$documento->id], $this->usuario(RolSistema::Jefatura))->assertForbidden();

        // Permiso retirado entre la vista previa y la confirmación.
        $usuario = $this->usuario();
        $token = $this->previa($cliente, [$documento->id], $usuario)->assertOk()->viewData('token');
        $usuario->syncRoles([RolSistema::Jefatura->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->confirmar($cliente, $token, $usuario->fresh())->assertForbidden();

        $this->assertNadaPreparado($documento);
    }

    public function test_la_vista_previa_de_otro_usuario_no_se_confirma(): void
    {
        $cliente = $this->cliente();
        $documento = $this->listo($cliente, '2026-08-01');

        $token = $this->previa($cliente, [$documento->id], $this->usuario())->assertOk()->viewData('token');

        $this->confirmar($cliente, $token, $this->usuario())->assertSessionHasErrors('documentos');
        $this->assertNadaPreparado($documento);
    }

    // ------------------------------------------------------------------ selección ajena

    public function test_ids_de_otro_cliente_de_otra_pagina_o_manipulados_se_rechazan(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $otro = $this->cliente('Otro cliente, S.A.');
        $ajeno = $this->listo($otro, '2026-08-01');

        // 25 en la página 1 y uno más en la 2: los más recientes van primero, así que el
        // de la página 2 es el más antiguo.
        $pagina1 = collect(range(1, 25))->map(fn ($i) => $this->listo($cliente, Carbon::parse('2026-07-01')->addDays($i)->toDateString()));
        $pagina2 = $this->listo($cliente, '2026-06-20');

        // De otro cliente.
        $this->previa($cliente, [$pagina1[0]->id, $ajeno->id], $usuario)->assertSessionHasErrors('documentos');
        // De otra página: se ve la 1, se cuela uno de la 2.
        $this->previa($cliente, [$pagina1[0]->id, $pagina2->id], $usuario)->assertSessionHasErrors('documentos');
        // Inexistente o no numérico.
        $this->previa($cliente, [$pagina1[0]->id, 999999], $usuario)->assertSessionHasErrors('documentos');
        $this->previa($cliente, ['abc'], $usuario)->assertSessionHasErrors('documentos.0');

        // El rechazo vuelve a la misma página y filtros para volver a seleccionar.
        $this->previa($cliente, [$pagina1[0]->id], $usuario, ['page' => 2, 'tipo' => '03'])
            ->assertRedirect(route('cobros.index', ['cliente_id' => $cliente->id, 'tipo' => '03', 'page' => 2]))
            ->assertSessionHasErrors('documentos');

        $this->assertNadaPreparado($ajeno, $pagina2, ...$pagina1->all());

        // El de la página 2, desde la página 2, sí.
        $this->previa($cliente, [$pagina2->id], $usuario, ['page' => 2])->assertOk();
    }
}
