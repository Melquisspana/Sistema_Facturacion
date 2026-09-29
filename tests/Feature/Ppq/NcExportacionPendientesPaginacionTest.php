<?php

namespace Tests\Feature\Ppq;

use App\Enums\PermisoSistema;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * NC PENDIENTES paginadas en el formato de notas de crédito: sin pérdidas ni duplicados
 * entre páginas, con filtros, cliente y la página del historial conservados, y con la
 * selección limitada a lo que se ve. Nada viene marcado de antemano.
 */
class NcExportacionPendientesPaginacionTest extends TestCase
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

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function cliente(string $formato = 'albaran_nc_v1'): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => $formato,
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** NC pendiente aceptada, con albarán; la n-ésima es la n-ésima más antigua. */
    private function nc(Cliente $cliente, ?string $fechaAlbaran = '2026-09-01'): Dte
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
            'fecha_emision' => Carbon::parse('2026-01-01')->addDays($this->n)->toDateString(),
            'hora_emision' => '08:00:00',
            'total_pagar' => '10.00',
        ]);

        DteAlbaran::create([
            'dte_id' => $nc->id,
            'numero_canonico' => 'AC04/0033/00/'.(3000 + $this->n),
            'tipo_codigo' => 'AC04',
            'sala_codigo' => '0033',
            'numero' => (string) (3000 + $this->n),
            'fecha' => $fechaAlbaran,
            'total' => '10.00',
        ]);

        return $nc;
    }

    private function bandeja(Cliente $cliente, array $params = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id] + $params));
    }

    /** ¿La casilla de esa NC viene marcada en el HTML? */
    private function marcada(string $html, int $id): bool
    {
        return preg_match('/<input[^>]*name="dtes\[\]"[^>]*value="'.$id.'"[^>]*\schecked(?![:\w-])/s', $html) === 1;
    }

    // ------------------------------------------------------------------ páginas

    public function test_las_paginas_de_pendientes_cubren_todo_sin_perder_ni_repetir(): void
    {
        $cliente = $this->cliente();
        $notas = collect(range(1, 30))->map(fn () => $this->nc($cliente));

        $pagina1 = $this->bandeja($cliente)->assertOk();
        $pagina2 = $this->bandeja($cliente, ['pendientes_page' => 2])->assertOk();

        $ids1 = $pagina1->viewData('pendientes')->pluck('id');
        $ids2 = $pagina2->viewData('pendientes')->pluck('id');
        $this->assertCount(25, $ids1);
        $this->assertCount(5, $ids2);
        $this->assertSame([], $ids1->intersect($ids2)->all(), 'Ninguna NC en dos páginas.');
        $this->assertSame($notas->pluck('id')->all(), $ids1->concat($ids2)->all(), 'Todas, en orden de antigüedad.');

        $pagina1->assertSeeText('Mostrando 1–25 de 30 nota(s)');
        $pagina2->assertSeeText('Mostrando 26–30 de 30 nota(s)');
    }

    public function test_filtros_cliente_y_pagina_del_historial_se_conservan(): void
    {
        $cliente = $this->cliente();
        foreach (range(1, 30) as $_) {
            $this->nc($cliente);
        }
        foreach (range(1, 25) as $i) {
            NcExportacion::create([
                'cliente_id' => $cliente->id,
                'referencia' => 'NC-000123-20260901-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'formato' => 'albaran_nc_v1',
                'archivo_nombre' => '000123'.str_pad((string) $i, 12, '0', STR_PAD_LEFT).'.xlsx',
            ]);
        }
        // Las dos primeras quedan fuera del filtro.
        $desde = Carbon::parse('2026-01-01')->addDays(3)->toDateString();

        $respuesta = $this->bandeja($cliente, ['desde' => $desde, 'pendientes_page' => 2, 'lotes_page' => 2])->assertOk();

        $pendientes = $respuesta->viewData('pendientes');
        $lotes = $respuesta->viewData('lotes');
        $this->assertSame(28, $pendientes->total(), 'Total FILTRADO.');
        $this->assertSame(2, $pendientes->currentPage());
        $this->assertSame(2, $lotes->currentPage(), 'Elegir página de pendientes no mueve el historial.');
        $this->assertSame(25, $lotes->total(), 'Los filtros de pendientes no cambian los lotes.');

        $enlacePendientes = $pendientes->url(1);
        foreach (['cliente_id='.$cliente->id, 'desde='.$desde, 'lotes_page=2'] as $parte) {
            $this->assertStringContainsString($parte, $enlacePendientes);
        }
        $this->assertStringContainsString('pendientes_page=2', $lotes->url(1));
    }

    public function test_una_pagina_fuera_de_rango_guia_a_la_primera_o_a_quitar_filtros(): void
    {
        $cliente = $this->cliente();
        $this->nc($cliente);

        $respuesta = $this->bandeja($cliente, ['pendientes_page' => 7, 'tipo' => 'AC04'])->assertOk();

        $respuesta->assertSeeText('Esta página de pendientes no existe');
        $respuesta->assertSee(e($respuesta->viewData('pendientes')->url(1)), false);
        $respuesta->assertSeeText('Quitar filtros');
    }

    // ------------------------------------------------------------------ selección

    public function test_nada_viene_marcado_y_solo_se_ofrece_la_pagina_visible(): void
    {
        $cliente = $this->cliente();
        $notas = collect(range(1, 27))->map(fn () => $this->nc($cliente));

        $respuesta = $this->bandeja($cliente, ['pendientes_page' => 2])->assertOk();
        $html = $respuesta->getContent();

        // El alcance de la casilla general se ve (etiqueta visible asociada, no solo lectores
        // de pantalla) y el aviso de que la selección es de esta página está completo.
        // (assertTrue con str_contains: una falla no vuelca la página entera.)
        $this->assertTrue(str_contains($html, '<label for="marcar_pagina"'), 'Falta la etiqueta visible de la casilla general.');
        $this->assertTrue(str_contains($html, 'Marcar las de esta página'), 'Falta el alcance de la casilla general.');
        $this->assertTrue(str_contains($html, 'cambiar de página no guarda la selección'), 'Falta el aviso de selección por página.');
        $this->assertFalse(str_contains($html, 'Marcar todas'), 'No debe ofrecerse «Marcar todas».');
        // Cada casilla individual avisa a la general al cambiar, para que no aparente «todas».
        $this->assertSame(
            substr_count($html, 'name="dtes[]"'),
            substr_count($html, '@change="sincronizar()"'),
            'Cada casilla individual sincroniza la casilla general.',
        );

        foreach ($notas as $i => $nota) {
            $enPagina = $i >= 25;
            $this->assertSame($enPagina, str_contains($html, 'name="dtes[]" value="'.$nota->id.'"'), "NC {$i}: solo las de esta página tienen casilla.");
            $this->assertFalse($this->marcada($html, $nota->id), "NC {$i} no debe venir marcada.");
        }
    }

    public function test_las_bloqueadas_de_la_pagina_vienen_deshabilitadas_y_los_faltantes_son_de_esa_pagina(): void
    {
        $cliente = $this->cliente('carga_masiva_nc_v1');
        foreach (range(1, 25) as $_) {
            $this->nc($cliente);
        }
        $sinFecha = $this->nc($cliente, null); // queda en la página 2

        $pagina1 = $this->bandeja($cliente)->assertOk();
        $this->assertSame([], $pagina1->viewData('faltantes'), 'Los faltantes son solo de la página visible.');

        $pagina2 = $this->bandeja($cliente, ['pendientes_page' => 2])->assertOk();
        $this->assertArrayHasKey($sinFecha->id, $pagina2->viewData('faltantes'));
        $pagina2->assertSeeText('de esta página no se pueden incluir todavía');
        $this->assertMatchesRegularExpression(
            // El atributo `disabled`, no la clase `disabled:opacity-50`.
            '/<input[^>]*name="dtes\[\]"[^>]*value="'.$sinFecha->id.'"[^>]*\sdisabled(?![:\w-])/s',
            $pagina2->getContent(),
        );
    }

    public function test_solo_lectura_ve_las_pendientes_sin_casillas(): void
    {
        $cliente = $this->cliente();
        $nota = $this->nc($cliente);

        $this->bandeja($cliente, [], $this->usuario(RolSistema::Jefatura))
            ->assertOk()
            ->assertSee($nota->numero_control)
            ->assertDontSee('name="dtes[]"', false)
            ->assertDontSeeText('Marcar las de esta página')
            ->assertSeeText('Solo lectura');
    }

    // ------------------------------------------------------------------ servidor

    public function test_el_servidor_crea_con_lo_enviado_y_no_deja_reexportar(): void
    {
        $cliente = $this->cliente();
        $notas = collect(range(1, 27))->map(fn () => $this->nc($cliente));
        $pagina1 = $this->bandeja($cliente)->viewData('pendientes')->pluck('id')->all();
        $usuario = $this->usuario();

        $this->actingAs($usuario)
            ->post(route('ppq.nc-exportaciones.store'), ['cliente_id' => $cliente->id, 'dtes' => array_slice($pagina1, 0, 3)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, NcExportacion::count());
        $this->assertSame(3, NcExportacionItem::count(), 'Solo las enviadas, no toda la lista.');

        // Reenviar una ya exportada: se rechaza entero, sin duplicar.
        $this->actingAs($usuario)
            ->post(route('ppq.nc-exportaciones.store'), ['cliente_id' => $cliente->id, 'dtes' => [$pagina1[0], $pagina1[5]]])
            ->assertSessionHasErrors('dtes');
        $this->assertSame(3, NcExportacionItem::count());
        $this->assertSame(1, NcExportacionItem::where('dte_id', $pagina1[0])->count());

        // Las demás siguen pendientes y la lista se reacomoda: 24 en total.
        $this->assertSame(24, $this->bandeja($cliente)->viewData('pendientes')->total());
        $this->assertFalse(NcExportacionItem::whereIn('dte_id', $notas->slice(25)->pluck('id'))->exists());
    }
}
