<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoNcExportacion;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudNota;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\NotasDelQuedan;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Paquete de quedan con sus NC: en el portal de Calleja se cargan PRIMERO las NC de los
 * CCF y DESPUÉS el quedan. La vista previa muestra las NC relacionadas, prepara su archivo
 * sin duplicar, y el quedan solo se prepara con la carga de todas registrada.
 */
class CobrosQuedanNotasCreditoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Storage::fake((string) config('dte.storage.disk', 'local'));

        $this->cliente = $this->cliente('Calleja, S.A. de C.V.');
    }

    private function cliente(string $nombre): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => $nombre]);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '100.00',
        ]);
    }

    /** NC aceptada relacionada con el CCF; con albarán propio completo salvo que se diga. */
    private function nc(Dte $ccf, array $extra = [], bool $conAlbaran = true): Dte
    {
        $nc = $this->dte('05', $extra + ['dte_relacionado_id' => $ccf->id, 'total_pagar' => '12.34']);

        if ($conAlbaran) {
            DteAlbaran::create([
                'dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/'.(3000 + $this->n), 'tipo_codigo' => 'AC04',
                'sala_codigo' => '0017', 'numero' => (string) (3000 + $this->n), 'fecha' => '2026-09-01', 'total' => '12.34',
            ]);
        }

        return $nc;
    }

    /** CCF local en Cobros, listo para quedan (AC01 vinculado). */
    private function ccf(?Dte $dte = null): CobroDocumento
    {
        $dte ??= $this->dte('03');
        $documento = CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $dte->numero_control,
            'fecha_emision' => '2026-09-10',
            'monto' => '100.00',
        ]);
        $this->vincularAc01($documento);

        return $documento;
    }

    private function externo(): CobroDocumento
    {
        $this->n++;
        $documento = CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-09-09',
            'monto' => '50.00',
        ]);
        $this->vincularAc01($documento);

        return $documento;
    }

    private function vincularAc01(CobroDocumento $documento): void
    {
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.(5000 + $documento->id),
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05',
            'monto_albaran' => $documento->monto,
            'numero_orden_compra' => '2609001700'.str_pad((string) $documento->id, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => '0017',
        ]);
        $documento->forceFill([
            'ppq_albaran_id' => $albaran->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ])->save();
    }

    private function lote(array $notas, bool $presentado): NcExportacion
    {
        $lote = NcExportacion::create([
            'cliente_id' => $this->cliente->id,
            'referencia' => 'NC-000123-20260915-'.str_pad((string) (NcExportacion::count() + 1), 2, '0', STR_PAD_LEFT),
            'formato' => 'carga_masiva_nc_v1',
            'archivo_nombre' => '000123202609150800.xlsx',
        ]);
        foreach ($notas as $i => $nota) {
            NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nota->id, 'orden' => $i + 1]);
        }
        $lote->forceFill(['descargas' => 1, 'descargado_en' => now(), 'estado' => EstadoNcExportacion::Descargado->value])->save();
        if ($presentado) {
            $lote->forceFill(['presentada_en' => now(), 'presentada_por' => $this->usuario()->id])->save();
        }

        return $lote;
    }

    private function previa(array $ids, User $usuario)
    {
        return $this->actingAs($usuario)->post(route('cobros.solicitudes.previa', $this->cliente), ['documentos' => $ids]);
    }

    // ------------------------------------------------------------------ vista previa

    public function test_la_previa_muestra_todas_las_nc_y_bloquea_el_quedan_hasta_su_carga(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $aceptada = $this->nc($ccfDte);
        $borrador = $this->nc($ccfDte, ['estado' => 'borrador', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $invalidada = $this->nc($ccfDte, ['estado' => 'invalidado']);
        $documento = $this->ccf($ccfDte);

        $respuesta = $this->previa([$documento->id], $usuario)->assertOk();

        $respuesta->assertSee($aceptada->numero_control);
        $respuesta->assertSee($borrador->numero_control);
        $respuesta->assertSee($invalidada->numero_control);
        $respuesta->assertSeeText('Aceptada por Hacienda');
        $respuesta->assertSeeText('Invalidada: no acredita');
        $respuesta->assertSeeText('Aún no aceptada (En edición)');
        $respuesta->assertSee($aceptada->albaran->numero_canonico);
        $respuesta->assertSeeText('Todavía no se puede preparar el quedan de estos CCF.');
        $respuesta->assertSeeText('Preparar notas de estos CCF');

        $notas = $respuesta->viewData('notas');
        $this->assertSame([$aceptada->id], $notas['por_exportar']);
        $this->assertSame(2, $notas['avisos']);
        $this->assertFalse($notas['completo']);
        $this->assertNotSame([], $respuesta->viewData('bloqueos'));
        // Botón de quedan deshabilitado.
        $this->assertMatchesRegularExpression('/<button type="button" disabled[^>]*>\s*Confirmar y preparar quedan/', $respuesta->getContent());

        $this->assertSame(0, NcExportacion::count());
        $this->assertSame(0, CobroSolicitud::count());
    }

    public function test_una_nc_aceptada_sin_albaran_propio_bloquea_y_no_se_prepara(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $sinAlbaran = $this->nc($ccfDte, [], conAlbaran: false);
        $documento = $this->ccf($ccfDte);

        $respuesta = $this->previa([$documento->id], $usuario)->assertOk();
        $respuesta->assertSeeText('Sin albarán propio');
        $respuesta->assertSeeText('su albarán propio de crédito (AC02/AC04)');
        $respuesta->assertDontSeeText('Preparar notas de estos CCF');
        $this->assertSame(NotasDelQuedan::BLOQUEADA, $respuesta->viewData('notas')['bloqueadas'][0]['situacion']);

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $respuesta->viewData('token')])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, NcExportacion::count());
        $this->assertNull($sinAlbaran->refresh()->exportacionItem);
    }

    public function test_un_albaran_ac01_en_una_nc_no_se_envia_como_credito(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $nc = $this->nc($ccfDte);
        $nc->albaran->forceFill(['tipo_codigo' => 'AC01'])->save();
        $documento = $this->ccf($ccfDte);

        $respuesta = $this->previa([$documento->id], $usuario)->assertOk();
        $respuesta->assertSeeText('un tipo de albarán de crédito AC02 o AC04');

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $respuesta->viewData('token')])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, NcExportacion::count());
    }

    // ------------------------------------------------------------------ preparar NC

    public function test_prepara_solo_las_nc_aceptadas_nuevas_y_no_duplica_las_exportadas(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $yaExportada = $this->nc($ccfDte);
        $nueva = $this->nc($ccfDte);
        $borrador = $this->nc($ccfDte, ['estado' => 'borrador', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $loteViejo = $this->lote([$yaExportada], presentado: true);
        // NC de otro CCF que no está en la selección: no se cuela.
        $ajena = $this->nc($this->dte('03'));
        $documento = $this->ccf($ccfDte);

        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        $respuesta = $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token, 'dtes' => [$ajena->id]])
            ->assertSessionHasNoErrors();

        $lote = NcExportacion::whereKeyNot($loteViejo->id)->sole();
        $respuesta->assertRedirect(route('ppq.nc-exportaciones.show', $lote));
        $this->assertSame([$nueva->id], $lote->items()->pluck('dte_id')->all());
        $this->assertSame('carga_masiva_nc_v1', $lote->formato);
        $this->assertSame($loteViejo->id, $yaExportada->refresh()->exportacionItem->nc_exportacion_id);
        $this->assertNull($borrador->refresh()->exportacionItem);
        $this->assertNull($ajena->refresh()->exportacionItem);
        $this->assertFalse($lote->presentada());

        // La vista previa quedó usada; una nueva ya no tiene notas nuevas y no crea lote vacío.
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');
        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');
        $this->assertSame(2, NcExportacion::count());
    }

    public function test_la_vista_previa_de_otro_usuario_no_prepara_notas(): void
    {
        $ccfDte = $this->dte('03');
        $this->nc($ccfDte);
        $documento = $this->ccf($ccfDte);

        $token = $this->previa([$documento->id], $this->usuario())->assertOk()->viewData('token');

        $this->actingAs($this->usuario())
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');
        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertForbidden();

        $this->assertSame(0, NcExportacion::count());
    }

    // ------------------------------------------------------------------ carga al portal

    public function test_registrar_la_carga_es_un_hecho_aparte_de_la_descarga(): void
    {
        $usuario = $this->usuario();
        $nc = $this->nc($this->dte('03'));
        $lote = NcExportacion::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'NC-000123-20260924-01',
            'formato' => 'carga_masiva_nc_v1', 'archivo_nombre' => '000123202609241000.xlsx',
        ]);
        NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nc->id, 'orden' => 1]);

        // Sin descargar, no se pudo haber subido.
        $this->actingAs($usuario)
            ->post(route('ppq.nc-exportaciones.presentar', $lote), ['presentada_en' => now()->toDateString()])
            ->assertSessionHasErrors('presentacion');
        $this->assertNull($lote->refresh()->presentada_en);

        // Descargar no la registra.
        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk();
        $this->assertNull($lote->refresh()->presentada_en);
        $this->assertSame(EstadoNcExportacion::Descargado, $lote->estado);

        // Solo lectura no la registra.
        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->post(route('ppq.nc-exportaciones.presentar', $lote), [])
            ->assertForbidden();

        $this->actingAs($usuario)
            ->post(route('ppq.nc-exportaciones.presentar', $lote), [
                'presentada_en' => now()->toDateString(), 'referencia_portal' => 'PORTAL-778', 'nota' => 'Subido sin observaciones.',
            ])
            ->assertSessionHasNoErrors();

        $lote->refresh();
        $this->assertTrue($lote->presentada());
        $this->assertSame($usuario->id, (int) $lote->presentada_por);
        $this->assertSame('PORTAL-778', $lote->referencia_portal);
        $this->assertSame(EstadoNcExportacion::Descargado, $lote->estado);
        $this->assertSame('aceptado', $nc->refresh()->estado->value);

        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText('declaró haberlo cargado al portal')
            ->assertSee('PORTAL-778')
            ->assertDontSeeText('Registrar carga al portal');

        // No se registra dos veces.
        $this->actingAs($usuario)
            ->post(route('ppq.nc-exportaciones.presentar', $lote), [])
            ->assertSessionHasErrors('presentacion');

        // Y la copia archivada se sigue redescargando.
        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk();
    }

    // ------------------------------------------------------------------ quedan

    public function test_un_lote_descargado_sin_carga_registrada_no_habilita_el_quedan(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $nc = $this->nc($ccfDte);
        $lote = $this->lote([$nc], presentado: false);
        $documento = $this->ccf($ccfDte);

        $respuesta = $this->previa([$documento->id], $usuario)->assertOk();
        $respuesta->assertSee($lote->referencia);
        $respuesta->assertSeeText('En un lote sin carga registrada');
        $respuesta->assertDontSeeText('Preparar notas de estos CCF');

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $respuesta->viewData('token')])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, CobroSolicitud::count());
        $this->assertNull($documento->refresh()->cobro_solicitud_id);
    }

    public function test_con_la_carga_registrada_se_prepara_y_congela_las_notas(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $nc = $this->nc($ccfDte);
        $lote = $this->lote([$nc], presentado: true);
        $documento = $this->ccf($ccfDte);
        $sinNotas = $this->ccf();

        $respuesta = $this->previa([$documento->id, $sinNotas->id], $usuario)->assertOk();
        $this->assertSame([], $respuesta->viewData('bloqueos'));
        $this->assertTrue($respuesta->viewData('notas')['completo']);
        $this->assertMatchesRegularExpression('/<button class="[^"]*">\s*Confirmar y preparar quedan/', $respuesta->getContent());

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $respuesta->viewData('token')])
            ->assertSessionHasNoErrors();

        $solicitud = CobroSolicitud::sole();
        $vinculo = CobroSolicitudNota::sole();
        $this->assertSame($solicitud->id, $vinculo->cobro_solicitud_id);
        $this->assertSame($documento->id, $vinculo->cobro_documento_id);
        $this->assertSame($nc->id, $vinculo->dte_id);
        $this->assertSame($lote->id, $vinculo->nc_exportacion_id);
        // El quedan sigue siendo de CCF: las NC no entran en sus renglones.
        $this->assertEqualsCanonicalizing([$documento->id, $sinNotas->id], $solicitud->items()->pluck('cobro_documento_id')->all());

        // Pero el ARCHIVO de quedan sí lleva la NC, después de los CCF, con su AC04.
        $ruta = app(\App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1::class)->generar($solicitud->fresh());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);
        $this->assertSame(4, $hoja->getHighestDataRow(), 'Encabezado + 2 CCF + 1 NC.');
        $this->assertSame('AC04', $hoja->getCell('E4')->getValue());
        $this->assertSame('0017', $hoja->getCell('A4')->getValue());
        $this->assertSame(9, $hoja->getCell('D4')->getValue());

        $this->actingAs($usuario)->get(route('cobros.solicitudes.show', $solicitud))
            ->assertOk()
            ->assertSeeText('Notas de crédito que respaldaron esta solicitud')
            ->assertSee($nc->numero_control)
            ->assertSee($lote->referencia);
    }

    public function test_una_nc_aparecida_despues_de_la_previa_impide_confirmar(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $documento = $this->ccf($ccfDte);

        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        $this->nc($ccfDte);

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, CobroSolicitud::count());
        $this->assertSame(0, CobroSolicitudNota::count());
    }

    public function test_una_nc_aparecida_despues_de_la_previa_tampoco_se_exporta_sin_volver_a_revisar(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $documento = $this->ccf($ccfDte);
        $this->nc($ccfDte);

        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        $this->nc($ccfDte);

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, NcExportacion::count());
    }

    public function test_si_cambia_la_identidad_del_albaran_de_nc_se_revisa_otra_vez(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $nc = $this->nc($ccfDte);
        $documento = $this->ccf($ccfDte);

        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        $nc->albaran->forceFill(['numero' => '9999'])->save();

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.notas', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, NcExportacion::count());
    }

    public function test_un_cambio_de_estado_de_una_nc_tras_la_previa_cambia_la_huella(): void
    {
        $usuario = $this->usuario();
        $ccfDte = $this->dte('03');
        $borrador = $this->nc($ccfDte, ['estado' => 'borrador', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $documento = $this->ccf($ccfDte);

        $token = $this->previa([$documento->id], $usuario)->assertOk()->viewData('token');
        // Sigue sin aceptar, pero ya no es la misma situación que se vio.
        $borrador->forceFill(['estado' => 'rechazado'])->save();

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $token])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, CobroSolicitud::count());
    }

    public function test_un_ccf_externo_no_se_da_por_sin_notas(): void
    {
        $usuario = $this->usuario();
        $externo = $this->externo();

        $respuesta = $this->previa([$externo->id], $usuario)->assertOk();

        $respuesta->assertSeeText('NC sin verificar en 1 CCF.');
        $respuesta->assertSeeText('captura masiva de Gmail');
        $respuesta->assertDontSeeText('no tiene notas de crédito relacionadas');
        $this->assertFalse($respuesta->viewData('notas')['documentos'][$externo->id]['verificable']);
        $this->assertFalse($respuesta->viewData('notas')['completo']);
        $this->assertNotEmpty($respuesta->viewData('bloqueos'));

        $this->actingAs($usuario)
            ->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $respuesta->viewData('token')])
            ->assertSessionHasErrors('documentos');

        $this->assertSame(0, CobroSolicitud::count());
    }
}
