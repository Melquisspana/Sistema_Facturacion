<?php

namespace Tests\Feature\Contabilidad;

use App\Jobs\EnviarPaqueteContabilidad;
use App\Jobs\GenerarPaqueteContabilidad;
use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\Reportes\ReporteContadoraExcel;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El paquete se arma en segundo plano: un mes completo pasaba de los 100 s que
 * Cloudflare espera (error 524). Acá se prueba el despacho, el candado, el estado,
 * la descarga y la limpieza; el contenido del ZIP ya lo prueban las otras clases.
 */
class PaqueteEnSegundoPlanTest extends TestCase
{
    use RefreshDatabase;

    private const RANGO = ['mes' => 7, 'anio' => 2026, 'incluir_compras' => 1, 'incluir_ventas' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);

        $progreso = app(ProgresoSincronizacionCompras::class);
        for ($d = Carbon::create(2026, 7, 1); $d->month === 7; $d->addDay()) {
            $progreso->marcarCompleto($d, 'INBOX', 5001, null, []);
        }

        DocumentoRecibido::create([
            'gmail_message_id' => 'c1', 'emisor_nombre' => 'PROVEEDOR 1', 'tipo_documento' => '03',
            'numero_control' => 'DTE-03-PROV-1', 'estado' => 'pendiente', 'total' => 100,
            'tiene_pdf' => false, 'tiene_json' => false,
            'fecha_correo' => Carbon::parse('2026-07-05'), 'fecha_dte' => Carbon::parse('2026-07-05'),
        ]);
    }

    private function usuario(string $rol = 'contabilidad'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function estado(User $u): EstadoPaquete
    {
        return EstadoPaquete::para($u->id, 'zip', ['etiqueta' => '2026-07'], true, false);
    }

    private function rango(): array
    {
        return ['desde' => '2026-07-01', 'hasta' => '2026-07-31', 'etiqueta' => '2026-07', 'mes' => 7, 'anio' => 2026];
    }

    public function test_generar_despacha_el_job_y_deja_el_estado_generando(): void
    {
        Queue::fake();
        $u = $this->usuario();

        $this->actingAs($u)->post(route('contabilidad.paquete.generar'), self::RANGO)
            ->assertRedirect(route('contabilidad.paquete', self::RANGO))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Generando'));

        Queue::assertPushed(GenerarPaqueteContabilidad::class, fn ($job) => $job->usuarioId === $u->id
            && $job->rango['etiqueta'] === '2026-07' && $job->incluirCompras && ! $job->incluirVentas);
        $this->assertSame('generando', $this->estado($u)->leer()['estado']);

        $this->actingAs($u)->getJson(route('contabilidad.paquete.estado', self::RANGO))
            ->assertOk()->assertJson(['zip' => 'generando', 'envio' => null]);
        $this->actingAs($u)->get(route('contabilidad.paquete', self::RANGO))
            ->assertOk()->assertSee('Generando el ZIP');
    }

    public function test_no_despacha_dos_veces_el_mismo_paquete_en_curso(): void
    {
        Queue::fake();
        $u = $this->usuario();

        $this->actingAs($u)->post(route('contabilidad.paquete.generar'), self::RANGO);
        $this->actingAs($u)->post(route('contabilidad.paquete.generar'), self::RANGO)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ya se está generando'));

        Queue::assertPushed(GenerarPaqueteContabilidad::class, 1);
    }

    public function test_uno_en_curso_vencido_se_puede_relanzar(): void
    {
        Queue::fake();
        $u = $this->usuario();

        $this->actingAs($u)->post(route('contabilidad.paquete.generar'), self::RANGO);
        $this->travel(EstadoPaquete::MINUTOS_EN_CURSO + 1)->minutes();
        $this->actingAs($u)->post(route('contabilidad.paquete.generar'), self::RANGO)
            ->assertSessionMissing('error');

        Queue::assertPushed(GenerarPaqueteContabilidad::class, 2);
    }

    public function test_el_job_deja_el_zip_listo_para_el_usuario(): void
    {
        $u = $this->usuario();
        $this->estado($u)->iniciar('generando', 'x');

        app()->call([new GenerarPaqueteContabilidad($u->id, $this->rango(), true, false), 'handle']);

        $datos = $this->estado($u)->leer();
        $this->assertSame('listo', $datos['estado']);
        $this->assertSame('documentos_contabilidad_2026-07.zip', $datos['nombre_descarga']);
        $this->assertSame(1, $datos['compras']);
        Storage::disk('local')->assertExists('paquetes/'.$u->id.'/zip_2026-07_c.zip');

        $this->actingAs($u)->get(route('contabilidad.paquete.descargar', self::RANGO))
            ->assertOk()->assertDownload('documentos_contabilidad_2026-07.zip');
    }

    public function test_un_fallo_del_job_queda_visible_y_no_sigue_generando(): void
    {
        $u = $this->usuario();
        $this->estado($u)->iniciar('generando', 'x');

        (new GenerarPaqueteContabilidad($u->id, $this->rango(), true, false))->failed(new RuntimeException('disco lleno'));

        $datos = $this->estado($u)->leer();
        $this->assertSame('error', $datos['estado']);
        $this->assertStringContainsString('disco lleno', $datos['mensaje']);
        $this->actingAs($u)->get(route('contabilidad.paquete', self::RANGO))
            ->assertOk()->assertSee('disco lleno')->assertDontSee('Generando el ZIP');
    }

    public function test_la_descarga_es_solo_del_dueno_con_permiso_y_si_esta_lista(): void
    {
        $u = $this->usuario();

        // No listo todavía.
        $this->estado($u)->iniciar('generando', 'x');
        $this->actingAs($u)->get(route('contabilidad.paquete.descargar', self::RANGO))->assertNotFound();

        app()->call([new GenerarPaqueteContabilidad($u->id, $this->rango(), true, false), 'handle']);

        // Otro usuario con permiso: su propio paquete no existe.
        $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete.descargar', self::RANGO))->assertNotFound();

        // Sin permiso de reportes: ni siquiera llega.
        $this->actingAs(User::factory()->create())
            ->get(route('contabilidad.paquete.descargar', self::RANGO))->assertForbidden();

        $this->actingAs($u)->get(route('contabilidad.paquete.descargar', self::RANGO))->assertOk();
    }

    public function test_al_lanzar_uno_nuevo_se_limpian_los_de_mas_de_un_dia(): void
    {
        Queue::fake();
        $disco = Storage::disk('local');
        $disco->put('paquetes/99/zip_2026-06_c.zip', 'viejo');
        $disco->put('paquetes/99/zip_2026-06_c.json', '{}');
        $disco->put('paquetes/99/zip_2026-05_c.zip', 'reciente');
        $viejo = now()->subHours(EstadoPaquete::HORAS_LIMPIEZA + 1)->getTimestamp();
        touch($disco->path('paquetes/99/zip_2026-06_c.zip'), $viejo);
        touch($disco->path('paquetes/99/zip_2026-06_c.json'), $viejo);

        $this->actingAs($this->usuario())->post(route('contabilidad.paquete.generar'), self::RANGO);

        $disco->assertMissing('paquetes/99/zip_2026-06_c.zip');
        $disco->assertMissing('paquetes/99/zip_2026-06_c.json');
        $disco->assertExists('paquetes/99/zip_2026-05_c.zip');
    }

    public function test_un_envio_que_no_pasa_las_guardas_no_llega_a_la_cola(): void
    {
        Queue::fake();
        $u = $this->usuario();

        $this->actingAs($u)->post(route('contabilidad.paquete.enviar'), self::RANGO + ['frase' => 'enviar'])
            ->assertSessionHas('error');

        Queue::assertNotPushed(EnviarPaqueteContabilidad::class);
    }

    public function test_los_excel_no_dejan_la_semilla_de_tempnam(): void
    {
        foreach ([app(DocumentosRecibidosExcel::class)->generar(collect()), app(ReporteContadoraExcel::class)->generar(collect())] as $ruta) {
            $this->assertFileExists($ruta);
            $this->assertFileDoesNotExist(substr($ruta, 0, -strlen('.xlsx')));
            @unlink($ruta);
        }
    }
}
