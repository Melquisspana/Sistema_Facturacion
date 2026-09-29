<?php

namespace Tests\Feature\Ppq;

use App\Enums\EstadoNcExportacion;
use App\Enums\PermisoSistema;
use App\Enums\ProcedenciaArchivoNc;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\Establecimiento;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * COPIA ARCHIVADA del archivo de cada lote de NC.
 *
 * La primera descarga genera el archivo con el formato del lote, lo guarda con su SHA-256
 * y entrega esos bytes; las siguientes entregan la copia, verificada, sin volver a llamar
 * al exportador. Un lote que ya se había descargado sin copia se RECONSTRUYE una vez y se
 * dice así: la base no prueba qué bytes se entregaron entonces.
 */
class NcExportacionArchivoTest extends TestCase
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

    private function usuario(RolSistema $rol = RolSistema::Jefatura): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
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

    private function nc(Cliente $cliente, string $total): Dte
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
            'total_pagar' => $total,
        ]);

        DteAlbaran::create([
            'dte_id' => $nc->id,
            'numero_canonico' => 'AC04/0033/00/'.(3000 + $this->n),
            'tipo_codigo' => 'AC04',
            'sala_codigo' => '0033',
            'numero' => (string) (3000 + $this->n),
            'fecha' => '2026-09-01',
            'total' => $total,
        ]);

        return $nc;
    }

    /** @param  array<int, Dte>  $notas */
    private function lote(Cliente $cliente, array $notas): NcExportacion
    {
        $lote = NcExportacion::create([
            'cliente_id' => $cliente->id,
            'referencia' => 'NC-000123-20260901-'.str_pad((string) (++$this->n), 3, '0', STR_PAD_LEFT),
            'formato' => 'albaran_nc_v1',
            'archivo_nombre' => '000123202609010930.xlsx',
        ]);

        foreach (array_values($notas) as $i => $nota) {
            NcExportacionItem::create(['nc_exportacion_id' => $lote->id, 'dte_id' => $nota->id, 'orden' => $i + 1]);
        }

        return $lote;
    }

    private function descargar(NcExportacion $lote): TestResponse
    {
        return $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.descargar', $lote));
    }

    /** Bytes que la respuesta entregaría (el temporal todavía existe en la prueba). */
    private function bytes(TestResponse $respuesta): string
    {
        return (string) file_get_contents($respuesta->baseResponse->getFile()->getPathname());
    }

    /** A partir de acá, cualquier uso del exportador hace fallar la prueba. */
    private function sinExportador(): void
    {
        $this->mock(ExportadorNcFactory::class, function ($m) {
            $m->shouldNotReceive('porSlug');
            $m->shouldNotReceive('para');
        });
    }

    private function disco()
    {
        return Storage::disk((string) config('dte.storage.disk', 'local'));
    }

    // ------------------------------------------------------------------ primera descarga

    public function test_la_primera_descarga_archiva_y_las_siguientes_entregan_los_mismos_bytes(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00'), $this->nc($cliente, '20.50')]);

        $primera = $this->descargar($lote)->assertOk();
        $primera->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $primera->assertDownload($lote->archivo_nombre);
        $bytes = $this->bytes($primera);

        $lote->refresh();
        $this->assertSame(hash('sha256', $bytes), $lote->archivo_hash);
        $this->assertSame(ProcedenciaArchivoNc::PrimeraDescarga, $lote->archivo_origen);
        $this->assertNotNull($lote->archivado_en);
        $this->assertTrue($this->disco()->exists($lote->archivo_path));
        $this->assertSame(1, $lote->descargas);

        // Cambia el perfil (el código de proveedor va en la columna A de este formato): si
        // se regenerara, el archivo sería otro. Las NC emitidas no se tocan: son inmutables.
        ClientePerfilDocumento::where('cliente_id', $cliente->id)->update(['codigo_proveedor' => '999999']);
        app(PerfilDocumentoResolver::class)->olvidar();

        // Y el exportador NO se vuelve a usar: la segunda descarga solo puede venir de la copia.
        $this->sinExportador();

        $segunda = $this->descargar($lote)->assertOk();
        $segunda->assertDownload($lote->archivo_nombre);

        $this->assertSame($bytes, $this->bytes($segunda), 'Byte a byte el archivo archivado.');
        $this->assertSame(2, $lote->refresh()->descargas);
        $this->assertSame(2, $lote->items()->count(), 'Ninguna NC agregada.');
    }

    public function test_la_ficha_dice_que_la_copia_es_de_la_primera_descarga(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00')]);

        $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText('Todavía no se descargó');

        $this->descargar($lote)->assertOk();

        $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText(ProcedenciaArchivoNc::PrimeraDescarga->label())
            ->assertSee($lote->refresh()->archivo_hash)
            ->assertDontSeeText(ProcedenciaArchivoNc::Reconstruccion->label());
    }

    // ------------------------------------------------------------------ copia inservible

    /** @return array<string, array{0: string}> */
    public static function copiasInservibles(): array
    {
        return [
            'copia borrada' => ['borrar'],
            'copia alterada' => ['alterar'],
            'registro sin ruta' => ['sin_ruta'],
            'registro sin huella' => ['sin_huella'],
            'registro sin procedencia' => ['sin_origen'],
            'registro sin fecha de archivado' => ['sin_fecha'],
        ];
    }

    #[DataProvider('copiasInservibles')]
    public function test_sin_copia_valida_no_se_regenera_ni_se_cuenta_la_descarga(string $dano): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00')]);
        $this->descargar($lote)->assertOk();
        $lote->refresh();
        $path = $lote->archivo_path;

        match ($dano) {
            'borrar' => $this->disco()->delete($path),
            'alterar' => $this->disco()->put($path, 'otro contenido'),
            'sin_ruta' => $lote->forceFill(['archivo_path' => null])->save(),
            'sin_huella' => $lote->forceFill(['archivo_hash' => null])->save(),
            // Sin procedencia no se sabe si es la primera descarga o una reconstrucción: no
            // se infiere, se trata como registro incompleto.
            'sin_origen' => $lote->forceFill(['archivo_origen' => null])->save(),
            'sin_fecha' => $lote->forceFill(['archivado_en' => null])->save(),
        };
        $antes = $lote->refresh()->only(['archivo_hash', 'archivo_path', 'descargas']);

        $this->sinExportador();

        // Vuelve a la ficha del lote con un aviso seguro; no se sirve ningún archivo.
        $respuesta = $this->descargar($lote);
        $respuesta->assertRedirect(route('ppq.nc-exportaciones.show', $lote));
        $respuesta->assertSessionHas('error', fn ($m) => str_contains((string) $m, $lote->referencia)
            && str_contains((string) $m, 'ni se volvió a generar')
            // Sin rutas ni detalles internos.
            && ! str_contains((string) $m, 'ppq/nc-exportaciones')
            && ! str_contains((string) $m, 'SHA-256'));
        $this->assertNotInstanceOf(BinaryFileResponse::class, $respuesta->baseResponse);

        // La ficha muestra el aviso.
        $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText('No se pudo descargar '.$lote->referencia);

        $lote->refresh();
        $this->assertSame($antes, $lote->only(['archivo_hash', 'archivo_path', 'descargas']), 'Nada se reescribió ni se contó.');
        $this->assertSame(EstadoNcExportacion::Descargado, $lote->estado);

        // La ficha no inventa procedencia: dice que el registro está incompleto.
        if (in_array($dano, ['sin_origen', 'sin_fecha', 'sin_ruta', 'sin_huella'], true)) {
            $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
                ->assertOk()
                ->assertSeeText('Registro de la copia incompleto')
                ->assertDontSeeText(ProcedenciaArchivoNc::PrimeraDescarga->label())
                ->assertDontSeeText(ProcedenciaArchivoNc::Reconstruccion->label());
        }
    }

    // ------------------------------------------------------------------ lotes históricos

    public function test_un_lote_descargado_sin_copia_se_reconstruye_y_se_dice(): void
    {
        $cliente = $this->cliente();
        $lote = $this->lote($cliente, [$this->nc($cliente, '10.00'), $this->nc($cliente, '20.50')]);
        // Así quedaron los lotes bajados antes de existir el archivado.
        $lote->forceFill([
            'estado' => EstadoNcExportacion::Descargado->value,
            'descargas' => 3,
            'descargado_en' => now()->subMonth(),
        ])->save();
        $pendiente = $this->nc($cliente, '3.00');

        // Antes de bajarlo: la pantalla avisa que no hay copia y que se reconstruirá.
        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertSeeText('Sin copia: se reconstruirá');
        $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText('antes de que existiera la copia archivada');

        $primera = $this->descargar($lote)->assertOk();
        $bytes = $this->bytes($primera);

        $lote->refresh();
        $this->assertSame(ProcedenciaArchivoNc::Reconstruccion, $lote->archivo_origen, 'No se presenta como el archivo entregado.');
        $this->assertSame(hash('sha256', $bytes), $lote->archivo_hash);
        $this->assertSame(4, $lote->descargas);
        $this->assertSame(2, $lote->items()->count());
        $this->assertFalse(NcExportacionItem::where('dte_id', $pendiente->id)->exists());

        // La reconstrucción, una vez archivada, también se entrega byte a byte.
        $this->assertSame($bytes, $this->bytes($this->descargar($lote)->assertOk()));

        $this->actingAs($this->usuario())->get(route('ppq.nc-exportaciones.show', $lote))
            ->assertOk()
            ->assertSeeText(ProcedenciaArchivoNc::Reconstruccion->label())
            ->assertDontSeeText(ProcedenciaArchivoNc::PrimeraDescarga->label());
        $this->actingAs($this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertSeeText(ProcedenciaArchivoNc::Reconstruccion->label());
    }
}
