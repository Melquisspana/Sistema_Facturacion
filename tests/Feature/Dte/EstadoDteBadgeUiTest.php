<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Badge ÚNICO de estado DTE (<x-estado-dte-badge>): mismo texto y color en listado,
 * ficha y edición (CCF y NC), en vez de que cada vista arme su propio mapeo. Solo
 * presentación: no cambia lógica ni transiciones de estado (App\Enums\EstadoDte).
 */
class EstadoDteBadgeUiTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** estado->value => fragmento de clase Tailwind esperado (debe coincidir en todas las vistas). */
    private const CLASES_POR_ESTADO = [
        'borrador' => 'bg-gray-100 text-gray-700',
        'generado' => 'bg-blue-100 text-blue-700',
        'firmado' => 'bg-indigo-100 text-indigo-700',
        'enviado' => 'bg-amber-100 text-amber-700',
        'aceptado' => 'bg-green-100 text-green-700',
        'rechazado' => 'bg-red-100 text-red-700',
        'invalidado' => 'bg-rose-100 text-rose-700',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // El listado principal muestra SOLO producción (ambiente 01): estos CCF deben nacer
        // en producción para que su badge aparezca en el listado.
        config(['dte.ambiente' => '01']);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** CCF borrador mínimo (sin líneas; no hace falta generar JSON para ver estas pantallas). */
    private function ccfBorrador(): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
    }

    // --- El componente cubre TODOS los estados del enum (texto + color) ---

    public function test_el_componente_renderiza_texto_y_color_de_cada_estado(): void
    {
        foreach (EstadoDte::cases() as $estado) {
            $html = Blade::render('<x-estado-dte-badge :estado="$estado" />', ['estado' => $estado]);

            $this->assertStringContainsString($estado->label(), $html, "Falta el texto de {$estado->value}");
            $this->assertStringContainsString(
                self::CLASES_POR_ESTADO[$estado->value],
                $html,
                "Color incorrecto para {$estado->value}"
            );
        }
    }

    // --- Listado y ficha muestran el MISMO badge para el mismo documento ---

    public function test_listado_y_ficha_coinciden_para_generado(): void
    {
        $dte = $this->ccfBorrador();
        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Generado);
        $user = $this->usuario();

        $listado = $this->actingAs($user)->get(route('facturacion.index'))->assertOk()->getContent();
        $ficha = $this->actingAs($user)->get(route('facturacion.show', $dte))->assertOk()->getContent();

        foreach (['listado' => $listado, 'ficha' => $ficha] as $vista => $html) {
            $this->assertStringContainsString('Generado', $html, "'Generado' no aparece en $vista");
            $this->assertStringContainsString(self::CLASES_POR_ESTADO['generado'], $html, "color distinto en $vista");
        }
    }

    public function test_listado_y_ficha_coinciden_para_aceptado(): void
    {
        $dte = $this->aceptarCcf($this->ccfBorrador());
        $user = $this->usuario();

        $listado = $this->actingAs($user)->get(route('facturacion.index'))->assertOk()->getContent();
        $ficha = $this->actingAs($user)->get(route('facturacion.show', $dte))->assertOk()->getContent();

        foreach (['listado' => $listado, 'ficha' => $ficha] as $vista => $html) {
            $this->assertStringContainsString('Aceptado', $html, "'Aceptado' no aparece en $vista");
            $this->assertStringContainsString(self::CLASES_POR_ESTADO['aceptado'], $html, "color distinto en $vista");
        }
    }

    public function test_listado_y_ficha_coinciden_para_invalidado(): void
    {
        $dte = $this->aceptarCcf($this->ccfBorrador());
        app(DteStateMachine::class)->transicionar($dte, EstadoDte::Invalidado);
        $dte->refresh();
        $user = $this->usuario();

        $listado = $this->actingAs($user)->get(route('facturacion.index'))->assertOk()->getContent();
        $ficha = $this->actingAs($user)->get(route('facturacion.show', $dte))->assertOk()->getContent();

        foreach (['listado' => $listado, 'ficha' => $ficha] as $vista => $html) {
            $this->assertStringContainsString('Invalidado', $html, "'Invalidado' no aparece en $vista");
            $this->assertStringContainsString(self::CLASES_POR_ESTADO['invalidado'], $html, "color distinto en $vista");
        }
    }

    // --- Edición (CCF y NC) usa el mismo badge que listado/ficha ---

    public function test_edicion_de_borrador_ccf_usa_el_mismo_badge(): void
    {
        $dte = $this->ccfBorrador();

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $dte))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Borrador', $html);
        $this->assertStringContainsString(self::CLASES_POR_ESTADO['borrador'], $html);
    }

    public function test_edicion_de_nota_de_credito_usa_el_mismo_badge(): void
    {
        $ccf = $this->aceptarCcf($this->ccfBorrador());
        $nc = app(DteBorradorService::class)->crearNotaCredito($ccf);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.edit', $nc))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Borrador', $html);
        $this->assertStringContainsString(self::CLASES_POR_ESTADO['borrador'], $html);
    }
}
