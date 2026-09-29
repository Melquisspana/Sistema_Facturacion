<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\RegistroDescargas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Bitácora de DESCARGAS PREPARADAS (formato de NC y solicitud de quedan): una entrada por
 * descarga servida, con actor, hora y la huella del archivo servido; ninguna cuando la
 * copia es inservible ni al solo abrir una pantalla; y cada vista muestra solo las de su
 * propio sujeto.
 */
class RegistroDescargasTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->seedCatalogosDte();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------------------ utilidades

    private function usuario(string $nombre): User
    {
        return User::factory()->create(['name' => $nombre])->assignRole(RolSistema::Administrador->value);
    }

    /**
     * Cada cliente con SU código de proveedor: la referencia de la solicitud es única en
     * toda la tabla y se arma con ese código, así que dos clientes con el mismo código
     * chocarían el mismo día.
     */
    private function cliente(string $nombre, string $codigoProveedor = '000123'): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => $nombre]);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => $codigoProveedor,
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    private function lote(Cliente $cliente): NcExportacion
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        $nc = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '05',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-05-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '10.00',
        ]);
        DteAlbaran::create([
            'dte_id' => $nc->id,
            'numero_canonico' => 'AC04/0033/00/'.(3000 + $this->n),
            'tipo_codigo' => 'AC04',
            'sala_codigo' => '0033',
            'numero' => (string) (3000 + $this->n),
            'fecha' => '2026-09-01',
            'total' => '10.00',
        ]);

        $lote = NcExportacion::create([
            'cliente_id' => $cliente->id,
            'referencia' => 'NC-000123-20260901-'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT),
            'formato' => 'albaran_nc_v1',
            'archivo_nombre' => '00012320260901093'.$this->n.'.xlsx',
        ]);
        NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nc->id, 'orden' => 1]);

        return $lote;
    }

    private function solicitud(Cliente $cliente, User $usuario): CobroSolicitud
    {
        $this->n++;
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $numero = 'DTE-03-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT);
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $cliente->id,
            'numero_control' => $numero,
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-18',
            'hora_emision' => '08:00:00',
            'total_pagar' => '123.74',
        ]);
        $albaran = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0017/00/'.(5100 + $this->n),
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sala_codigo' => '0017',
        ]);
        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $numero,
            'fecha_emision' => '2026-09-18',
            'monto' => '123.74',
        ]);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        return app(SolicitudCobroService::class)->crear($cliente, [$documento->id], $usuario);
    }

    /** Huella de los bytes que la respuesta va a servir. */
    private function huellaServida(TestResponse $respuesta): string
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $respuesta->baseResponse);

        return hash_file('sha256', $respuesta->baseResponse->getFile()->getPathname());
    }

    private function entradas(object $sujeto)
    {
        return Activity::where('log_name', RegistroDescargas::LOG)
            ->where('subject_type', $sujeto->getMorphClass())
            ->where('subject_id', $sujeto->getKey())
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------------ formato de NC

    public function test_primera_descarga_y_redescarga_de_nc_dejan_dos_entradas_con_actor_hora_y_huella(): void
    {
        $lote = $this->lote($this->cliente('Calleja, S.A. de C.V.'));
        $ana = $this->usuario('Usuario Primero');
        $beto = $this->usuario('Usuario Segundo');

        $h1 = $this->huellaServida($this->actingAs($ana)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk());
        $this->travel(5)->minutes();
        $h2 = $this->huellaServida($this->actingAs($beto)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk());

        $lote->refresh();
        $this->assertSame(2, $lote->descargas, 'El contador se conserva.');
        $this->assertSame($h1, $lote->archivo_hash);
        $this->assertSame($h1, $h2, 'La redescarga sirve la copia archivada.');

        $entradas = $this->entradas($lote);
        $this->assertCount(2, $entradas);
        $this->assertSame([$ana->id, $beto->id], $entradas->pluck('causer_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($h1, $entradas[0]->getExtraProperty('sha256'));
        $this->assertSame($h2, $entradas[1]->getExtraProperty('sha256'));
        $this->assertSame($lote->archivo_nombre, $entradas[1]->getExtraProperty('archivo'));
        $this->assertSame($lote->referencia, $entradas[1]->getExtraProperty('referencia'));
        $this->assertTrue($entradas[1]->created_at->gt($entradas[0]->created_at), 'Cada entrada lleva su hora.');
    }

    /**
     * Dos peticiones que cargaron el MISMO lote antes de registrar (lo que pasa en una
     * carrera): cada entrada debe sumar sobre el contador guardado, no sobre su copia vieja.
     */
    public function test_dos_modelos_desactualizados_del_mismo_lote_suman_dos_descargas(): void
    {
        $lote = $this->lote($this->cliente('Calleja, S.A. de C.V.'));
        $usuario = $this->usuario('Usuario Primero');
        $a = NcExportacion::findOrFail($lote->id);
        $b = NcExportacion::findOrFail($lote->id);

        $temporal = tempnam(sys_get_temp_dir(), 'registro_');
        file_put_contents($temporal, 'contenido servido');

        try {
            app(RegistroDescargas::class)->registrar($a, $temporal, 'x.xlsx', $usuario, (string) $lote->referencia);
            app(RegistroDescargas::class)->registrar($b, $temporal, 'x.xlsx', $usuario, (string) $lote->referencia);
        } finally {
            @unlink($temporal);
        }

        $this->assertSame(2, $lote->refresh()->descargas, 'Dos entradas, dos descargas.');
        $this->assertCount(2, $this->entradas($lote));
        $this->assertSame(2, $b->descargas, 'El llamador ve el contador guardado.');
        $this->assertSame(hash('sha256', 'contenido servido'), $this->entradas($lote)[1]->getExtraProperty('sha256'));
    }

    public function test_una_copia_nc_inservible_no_deja_entrada_ni_cuenta(): void
    {
        $lote = $this->lote($this->cliente('Calleja, S.A. de C.V.'));
        $usuario = $this->usuario('Usuario Primero');

        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk();
        $lote->refresh();
        Storage::disk((string) config('dte.storage.disk', 'local'))->delete($lote->archivo_path);

        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertRedirect(route('ppq.nc-exportaciones.show', $lote));

        $this->assertSame(1, $lote->refresh()->descargas);
        $this->assertCount(1, $this->entradas($lote), 'La descarga fallida no deja entrada.');
    }

    public function test_la_ficha_nc_muestra_solo_sus_descargas_y_abrirla_no_registra(): void
    {
        $propio = $this->lote($this->cliente('Cliente Propio'));
        $ajeno = $this->lote($this->cliente('Cliente Ajeno', '002200'));
        // Quien mira no es quien descargó: su nombre en la navegación no confunde la prueba.
        $lector = $this->usuario('Persona Lectora');
        $mio = $this->usuario('Descargador Propio');
        $otro = $this->usuario('Descargador Ajeno');

        $this->actingAs($mio)->get(route('ppq.nc-exportaciones.descargar', $propio))->assertOk();
        $this->actingAs($otro)->get(route('ppq.nc-exportaciones.descargar', $ajeno))->assertOk();
        $antes = Activity::count();

        $html = $this->actingAs($lector)->get(route('ppq.nc-exportaciones.show', $propio))->assertOk()->getContent();
        $this->actingAs($lector)->get(route('ppq.nc-exportaciones.show', $propio))->assertOk();

        $this->assertSame($antes, Activity::count(), 'Abrir la ficha no registra actividad.');
        $this->assertTrue(str_contains($html, 'Descargas preparadas'));
        $this->assertTrue(str_contains($html, 'No prueba que el navegador la haya terminado'));
        $this->assertTrue(str_contains($html, 'Descargador Propio'));
        $this->assertTrue(str_contains($html, (string) $propio->refresh()->archivo_hash));
        $this->assertFalse(str_contains($html, 'Descargador Ajeno'), 'Sin fuga de otro lote.');
        $this->assertFalse(str_contains($html, (string) $ajeno->refresh()->archivo_nombre));
    }

    public function test_las_descargas_anteriores_a_la_bitacora_no_se_inventan(): void
    {
        $lote = $this->lote($this->cliente('Calleja, S.A. de C.V.'));
        $lote->forceFill(['descargas' => 3, 'descargado_en' => now()->subMonth()])->save();

        $html = $this->actingAs($this->usuario('Usuario Primero'))
            ->get(route('ppq.nc-exportaciones.show', $lote))->assertOk()->getContent();

        $this->assertTrue(str_contains($html, 'Sin descargas registradas.'));
        $this->assertTrue(str_contains($html, '3 descarga(s) anterior(es) a este registro solo quedaron en el contador'));
        $this->assertCount(0, $this->entradas($lote));
    }

    // ------------------------------------------------------------------ solicitud de quedan

    public function test_primera_descarga_y_redescarga_de_quedan_dejan_dos_entradas_con_actor_y_huella(): void
    {
        $cliente = $this->cliente('Calleja, S.A. de C.V.');
        $ana = $this->usuario('Usuario Primero');
        $beto = $this->usuario('Usuario Segundo');
        $solicitud = $this->solicitud($cliente, $ana);

        $h1 = $this->huellaServida($this->actingAs($ana)->get(route('cobros.solicitudes.descargar', $solicitud))->assertOk());
        $h2 = $this->huellaServida($this->actingAs($beto)->get(route('cobros.solicitudes.descargar', $solicitud))->assertOk());

        $solicitud->refresh();
        $this->assertSame(2, $solicitud->descargas);
        $this->assertSame($h1, $solicitud->archivo_hash);
        $this->assertNull($solicitud->presentada_en, 'Descargar no presenta.');

        $entradas = $this->entradas($solicitud);
        $this->assertCount(2, $entradas);
        $this->assertSame([$ana->id, $beto->id], $entradas->pluck('causer_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$h1, $h2], $entradas->map(fn ($e) => $e->getExtraProperty('sha256'))->all());
        $this->assertSame($solicitud->archivo_nombre, $entradas[0]->getExtraProperty('archivo'));
    }

    public function test_una_copia_de_quedan_inservible_no_deja_entrada(): void
    {
        $cliente = $this->cliente('Calleja, S.A. de C.V.');
        $usuario = $this->usuario('Usuario Primero');
        $solicitud = $this->solicitud($cliente, $usuario);

        $this->actingAs($usuario)->get(route('cobros.solicitudes.descargar', $solicitud))->assertOk();
        $solicitud->refresh();
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($solicitud->archivo_path, 'otro contenido');

        $this->actingAs($usuario)->get(route('cobros.solicitudes.descargar', $solicitud))
            ->assertRedirect(route('cobros.index', ['cliente_id' => $cliente->id]));

        $this->assertSame(1, $solicitud->refresh()->descargas);
        $this->assertCount(1, $this->entradas($solicitud));
    }
}
