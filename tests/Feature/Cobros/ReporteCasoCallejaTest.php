<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\EstadoPpq;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqLote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReporteCasoCallejaTest extends TestCase
{
    use RefreshDatabase;

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

    private function control(string $tipo, int $n): string
    {
        return "DTE-{$tipo}-M001P002-".str_pad((string) $n, 15, '0', STR_PAD_LEFT);
    }

    private function doc(Cliente $cliente, string $tipo, int $n): CobroDocumento
    {
        $doc = CobroDocumento::create([
            'cliente_id' => $cliente->id, 'origen' => 'externo', 'tipo_dte' => $tipo,
            'numero_control' => $this->control($tipo, $n), 'fecha_emision' => '2026-09-18', 'monto' => '100.00',
        ]);
        $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Presentada->value])->save();

        return $doc;
    }

    /** Un reporte como el del portal: «CASO n» en A1 y el DTE sin guiones en la columna I. */
    private function reporte(string $caso, array $controles): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('A1', "CASO {$caso} \nTitular de Ejemplo HERNANDEZ(000123)");
        $hoja->fromArray(['SALA', 'NUMERO ALBARAN', 'TIPO', 'AÑO', 'MES', 'FECHA', 'MONTO', 'CODIGO', 'DTE', 'MONTO DOC', 'ESTADO', 'PLAZO'], null, 'A2');
        foreach (array_values($controles) as $i => $c) {
            $hoja->fromArray(['0017', '5131', 'AC01', '26', '09', '17/09/2026', '1', 'X', str_replace('-', '', $c), '1', 'REGISTRADO', 'CREDITO'], null, 'A'.($i + 3));
        }
        $ruta = tempnam(sys_get_temp_dir(), 'caso').'.xls';
        (new Xls($libro))->save($ruta);

        return new UploadedFile($ruta, 'caso.xls', null, null, true);
    }

    public function test_lo_registrado_queda_recibido_y_lo_que_no_tomo_vuelve_a_por_presentar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create(['cliente_id' => $cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);

        $tomado = $this->doc($cliente, '03', 197);
        $rechazado = $this->doc($cliente, '03', 200);
        $ncVieja = $this->doc($cliente, '05', 18);

        $anterior = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ 4 sep', 'fecha' => '2026-09-04', 'estado' => 'listo']);
        $lote = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ 21 sep', 'fecha' => '2026-09-18', 'estado' => 'borrador']);
        $anterior->items()->create(['origen' => 'local', 'tipo_dte' => '05', 'numero_control' => $this->control('05', 18), 'monto_dte' => 1, 'sin_albaran' => true]);
        foreach ([[$this->control('03', 197), '03'], [$this->control('03', 200), '03'], [$this->control('05', 18), '05']] as [$c, $t]) {
            $lote->items()->create(['origen' => 'local', 'tipo_dte' => $t, 'numero_control' => $c, 'monto_dte' => 1, 'sin_albaran' => true]);
        }

        $usuario = User::factory()->create()->assignRole(RolSistema::Administrador->value);
        $this->actingAs($usuario)
            ->post(route('ppq.lotes.reporte-caso', $lote), ['reporte' => $this->reporte('12027', [$this->control('03', 197)])])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Caso 12027: 1 documento(s)') && str_contains($m, 'vuelven a por presentar: 200'));

        $this->assertSame(EstadoPresentacionCobro::Recibida, $tomado->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $rechazado->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $ncVieja->refresh()->presentacion_estado, 'Ya presentada en un lote anterior: no se toca.');
        $this->assertStringContainsString('caso 12027', (string) $rechazado->eventos()->latest('id')->value('detalle'));
        $this->assertSame(EstadoPpq::Enviado, $lote->refresh()->estado, 'El PPQ queda presentado.');
        $this->assertStringContainsString('Caso 12027', (string) $lote->observaciones);

        // El número del correo manda sobre el del archivo.
        $this->actingAs($usuario)
            ->post(route('ppq.lotes.reporte-caso', $lote), ['reporte' => $this->reporte('12027', [$this->control('03', 197)]), 'caso' => '12031'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Caso 12031'));
    }

    public function test_un_archivo_que_no_es_el_reporte_se_rechaza(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create(['cliente_id' => $cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setCellValue('A1', 'otra cosa');
        $ruta = tempnam(sys_get_temp_dir(), 'x').'.xls';
        (new Xls($libro))->save($ruta);

        $this->actingAs(User::factory()->create()->assignRole(RolSistema::Administrador->value))
            ->post(route('ppq.lotes.reporte-caso', PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ', 'fecha' => today(), 'estado' => 'borrador'])), ['reporte' => new UploadedFile($ruta, 'x.xls', null, null, true)])
            ->assertSessionHas('error');
    }
}
