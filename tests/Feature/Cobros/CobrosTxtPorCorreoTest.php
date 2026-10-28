<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqConciliacion;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * Los TXT de pago que el cliente manda por correo: el sistema los busca en el buzón (solo
 * lectura), los muestra en «Solo revisar» y se aplican con un clic. Lo que se aplica es
 * exactamente lo que se revisó: el servidor recarga cada copia por su huella y la verifica.
 */
class CobrosTxtPorCorreoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const REMITENTE = 'pagos@cliente.example';

    private Cliente $cliente;

    private BuzonFalso $buzon;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['cobros.inicio_seguimiento' => '2026-07-01', 'cobros.txt_correo.remitente' => self::REMITENTE]);
        $this->travelTo('2026-10-25 10:00:00');

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();

        $this->buzon = new BuzonFalso;
        $this->app->instance(MailboxClient::class, $this->buzon);
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
    }

    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03', ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-10', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    private function txt(array $documentos, string $proveedor = '000123'): string
    {
        $lineas = array_map(fn (CobroDocumento $d) => $proveedor.';TITULAR DE EJEMPLO;CF;'.str_replace('-', '', $d->numero_control).';10-SEP-26;100.00'."\n", $documentos);

        return "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n".implode('', $lineas);
    }

    /** A y B en un PPQ, C en otro; un TXT por PPQ. */
    private function escenario(): array
    {
        [$a, $b, $c] = [$this->ccf(), $this->ccf(), $this->ccf()];
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]);
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$c->id]);

        return compact('a', 'b', 'c') + ['txt1' => $this->txt([$a, $b]), 'txt2' => $this->txt([$c])];
    }

    private function buscar(?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())->post(route('cobros.pagos.correo', $this->cliente));
    }

    private function aplicar(array $archivos, ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())->post(route('cobros.pagos.aplicar', $this->cliente), [
            'hashes' => array_values($archivos),
        ]);
    }

    private function encontrados($respuesta)
    {
        return collect($respuesta->viewData('correo'))->keyBy('nombre');
    }

    public function test_busca_en_el_correo_revisa_sin_aplicar_y_ofrece_aplicar_los_nuevos(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', 'Pagos Cliente <'.self::REMITENTE.'>', '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000123_20261022090000.txt', $e['txt2']);

        $respuesta = $this->buscar()->assertOk();

        $respuesta->assertSeeText('Revisión: todavía no se aplicó nada')
            ->assertSeeText('000123_20261020090000.txt')->assertSeeText('000123_20261022090000.txt');
        $encontrados = $this->encontrados($respuesta);
        $this->assertCount(2, $encontrados);
        $this->assertSame(['nuevo', 'nuevo'], $encontrados->pluck('estado')->values()->all());
        $this->assertSame(hash('sha256', $e['txt1']), $encontrados['000123_20261020090000.txt']['hash']);

        // Revisar no aplica nada…
        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertSame(0, PpqConciliacion::count());
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['a']->refresh()->pago_estado);
        // …pero sí guarda la copia (evidencia), y el formulario lleva solo las huellas.
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $this->assertTrue($disco->exists('ppq/conciliaciones/'.hash('sha256', $e['txt1']).'.txt'));
        $respuesta->assertSee(route('cobros.pagos.aplicar', $this->cliente), false)
            ->assertSee(hash('sha256', $e['txt2']), false)
            ->assertDontSee('TITULAR DE EJEMPLO;CF', false);
    }

    public function test_aplica_solo_las_huellas_revisadas(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000123_20261022090000.txt', $e['txt2']);
        $usuario = $this->usuario();
        $this->buscar($usuario)->assertOk();

        $respuesta = $this->aplicar(['000123_20261020090000.txt' => hash('sha256', $e['txt1'])], $usuario)->assertOk();

        $this->assertSame(EstadoPagoCobro::Pagado, $e['a']->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pagado, $e['b']->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['c']->refresh()->pago_estado, 'El TXT que no se eligió no se aplica.');
        $this->assertSame(1, PpqConciliacion::count());
        $respuesta->assertDontSeeText('Revisión: todavía no se aplicó nada')->assertSeeText('000123_20261020090000.txt');
        $this->assertSame('aplicado', collect($respuesta->viewData('archivos'))->first()['estado']);

        // Aplicar dos veces la misma huella no duplica pagos.
        $this->aplicar(['000123_20261020090000.txt' => hash('sha256', $e['txt1'])], $usuario)->assertOk()->assertSeeText('Este TXT no se revisó en esta sesión');
        $this->assertSame(1, PpqConciliacion::count());
        $this->assertSame(1, $e['a']->eventos()->where('tipo', TipoEventoCobro::Pago->value)->count());
    }

    public function test_busca_en_la_carpeta_de_los_txt_y_no_en_la_de_compras(): void
    {
        $e = $this->escenario();
        // Compras lee «CCF»; los TXT llegan a INBOX.
        $this->buzon = new BuzonFalso(carpeta: 'CCF');
        $this->app->instance(MailboxClient::class, $this->buzon);
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'], ['carpeta' => 'INBOX'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000123_20261022090000.txt', $e['txt2']);

        $respuesta = $this->buscar()->assertOk();

        $this->assertSame('INBOX', $this->buzon->carpetaPedida, 'Sin COBROS_TXT_CARPETA se busca en INBOX.');
        $this->assertSame(['000123_20261020090000.txt'], collect($respuesta->viewData('correo'))->pluck('nombre')->all());

        config(['cobros.txt_correo.carpeta' => 'Pagos Calleja']);
        $respuesta = $this->buscar()->assertOk();

        $this->assertSame('Pagos Calleja', $this->buzon->carpetaPedida);
        $this->assertCount(0, $respuesta->viewData('correo'));
    }

    public function test_un_txt_ya_aplicado_sale_como_ya_aplicado_y_no_se_vuelve_a_ofrecer(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000123_20261022090000.txt', $e['txt2']);
        $usuario = $this->usuario();
        $this->buscar($usuario)->assertOk();
        $this->aplicar(['000123_20261020090000.txt' => hash('sha256', $e['txt1'])], $usuario)->assertOk();

        $respuesta = $this->buscar($usuario)->assertOk();

        $encontrados = $this->encontrados($respuesta);
        $this->assertSame('ya_aplicado', $encontrados['000123_20261020090000.txt']['estado']);
        $this->assertSame('nuevo', $encontrados['000123_20261022090000.txt']['estado']);
        $this->assertSame(['000123_20261022090000.txt'], collect($respuesta->viewData('archivos'))->pluck('nombre')->all(),
            'Solo se revisa lo nuevo.');
    }

    public function test_el_mismo_txt_en_dos_correos_sale_una_sola_vez(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(12, '2026-10-21 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1']);

        $respuesta = $this->buscar()->assertOk();

        $this->assertCount(1, $respuesta->viewData('correo'));
        $this->assertCount(1, $respuesta->viewData('archivos'));
    }

    public function test_ignora_otros_remitentes_y_nombres_que_no_son_del_txt_de_pago(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', 'otro@cliente.example', '000123_20261020090000.txt', $e['txt1'])
            // Contiene la dirección configurada, pero no es ella.
            ->conTxt(11, '2026-10-20 10:00', 'x'.self::REMITENTE, '000123_20261020100000.txt', $e['txt1'])
            ->conTxt(12, '2026-10-20 11:00', self::REMITENTE, 'pagos.txt', $e['txt1'])
            ->conTxt(13, '2026-10-20 12:00', self::REMITENTE, '000123_2026102012.txt', $e['txt1'])
            // Fuera de la ventana de días.
            ->conTxt(14, '2026-06-01 09:00', self::REMITENTE, '000123_20260601090000.txt', $e['txt2']);

        $respuesta = $this->buscar()->assertOk();

        $this->assertCount(0, $respuesta->viewData('correo'));
        $respuesta->assertSeeText('No se encontraron TXT de pago nuevos en el correo');
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_un_txt_de_otro_proveedor_muestra_el_error_y_no_frena_a_los_demas(): void
    {
        $e = $this->escenario();
        $this->buzon
            ->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000999_20261022090000.txt', $this->txt([$e['c']], '999999'));

        $respuesta = $this->buscar()->assertOk();

        $archivos = collect($respuesta->viewData('archivos'))->keyBy('nombre');
        $this->assertSame('aplicado', $archivos['000123_20261020090000.txt']['estado']);
        $this->assertSame('error', $archivos['000999_20261022090000.txt']['estado']);
        $this->assertNotEmpty($archivos['000999_20261022090000.txt']['mensaje']);
        // El que falla no se ofrece para aplicar.
        $respuesta->assertSee(hash('sha256', $e['txt1']), false)
            ->assertDontSee(hash('sha256', $this->txt([$e['c']], '999999')), false);
    }

    public function test_aplicar_rechaza_una_copia_alterada_o_que_no_existe(): void
    {
        $e = $this->escenario();
        $hash = hash('sha256', $e['txt1']);
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $this->buzon->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1'])
            ->conTxt(11, '2026-10-22 09:00', self::REMITENTE, '000123_20261022090000.txt', $e['txt2']);
        $usuario = $this->usuario();
        $this->buscar($usuario)->assertOk();
        $disco->put('ppq/conciliaciones/'.$hash.'.txt', $e['txt2']);
        $disco->delete('ppq/conciliaciones/'.hash('sha256', $e['txt2']).'.txt');

        $respuesta = $this->aplicar([
            '000123_20261020090000.txt' => $hash,
            '000123_20261022090000.txt' => hash('sha256', $e['txt2']),
        ], $usuario)->assertOk();

        $archivos = collect($respuesta->viewData('archivos'))->keyBy('nombre');
        $this->assertSame('error', $archivos['000123_20261020090000.txt']['estado']);
        $this->assertSame('error', $archivos['000123_20261022090000.txt']['estado']);
        $this->assertSame(0, CobroEvento::count());
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['c']->refresh()->pago_estado);
    }

    public function test_aplicar_valida_las_huellas(): void
    {
        $this->escenario();
        $usuario = $this->usuario();

        $this->aplicar([], $usuario)->assertSessionHasErrors('hashes');
        $this->aplicar(['000123_20261020090000.txt' => '../../.env'], $usuario)->assertSessionHasErrors('hashes.0');
        $this->aplicar(['uno.txt' => str_repeat('a', 64), 'dos.txt' => str_repeat('a', 64)], $usuario)->assertSessionHasErrors('hashes.0');
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_sin_remitente_configurado_la_funcion_esta_apagada(): void
    {
        $this->escenario();
        config(['cobros.txt_correo.remitente' => '']);
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()
            ->assertDontSee(route('cobros.pagos.correo', $this->cliente), false);
        $this->buscar($usuario)->assertRedirect(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertSessionHas('error');
        $this->assertSame(0, $this->buzon->llamadas(), 'Apagada, ni siquiera consulta el buzón.');
    }

    public function test_con_remitente_configurado_el_boton_aparece(): void
    {
        $this->escenario();

        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()
            ->assertSee(route('cobros.pagos.correo', $this->cliente), false)
            ->assertSeeText('Buscar TXT en el correo')
            ->assertSeeText('Revisar TXT')->assertDontSeeText('Solo revisar')->assertDontSeeText('Cargar pagos')
            ->assertSee('name="archivos[]"', false)->assertSee('multiple', false);
        $html = $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->getContent();
        $this->assertSame(1, substr_count($html, 'enctype="multipart/form-data"'));
    }

    public function test_si_el_buzon_falla_se_informa_el_error_y_no_un_correo_vacio(): void
    {
        $this->escenario();
        $this->buzon->queFalla(new BuzonInaccesibleException('El servidor no respondió.'));

        $this->buscar()->assertRedirect(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'El servidor no respondió.'));
    }

    public function test_si_el_buzon_no_esta_configurado_se_informa(): void
    {
        $this->escenario();
        $this->app->instance(MailboxClient::class, new BuzonFalso(disponible: false));

        $this->buscar()->assertRedirect(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertSessionHas('error');
    }

    public function test_exige_el_permiso_de_gestionar(): void
    {
        $e = $this->escenario();
        $this->buzon->conTxt(10, '2026-10-20 09:00', self::REMITENTE, '000123_20261020090000.txt', $e['txt1']);
        $lector = User::factory()->create();
        $lector->givePermissionTo('ppq.ver');

        $this->buscar($lector)->assertForbidden();
        $this->aplicar(['000123_20261020090000.txt' => hash('sha256', $e['txt1'])], $lector)->assertForbidden();
        $this->assertSame(0, $this->buzon->llamadas());
        $this->assertSame(0, CobroEvento::count());
    }
}
