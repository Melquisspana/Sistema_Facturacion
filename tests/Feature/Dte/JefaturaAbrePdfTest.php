<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Jefatura (12/11/2026): en Documentos fiscales ve solo lo que Hacienda ya procesó
 * (aceptados y anulados con sello real), con fecha, tipo, cliente y sala, total y el PDF.
 * Tocar un documento abre su PDF, no la ficha; si entra a la ficha por la URL, va al PDF o
 * al aviso de que todavía no está listo. El servidor aplica el alcance aunque cambie la URL.
 * Los demás roles, igual que antes.
 */
class JefaturaAbrePdfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        }
        $this->emisor = compact('estab', 'pv');
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /**
     * Un CCF en el estado pedido. `aceptado` e `invalidado` llevan sello real salvo que
     * `$sello` diga otra cosa (MOCK o null).
     */
    private function ccf(string $oc, EstadoDte $estado, ?string $sello = 'real'): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente '.$oc]),
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
            'numero_orden_compra' => $oc,
        ]);
        if ($estado === EstadoDte::Borrador) {
            return $dte->refresh();
        }
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        if ($estado !== EstadoDte::Generado) {
            $dte->forceFill([
                'estado' => $estado->value,
                'sello_recepcion' => match ($sello) {
                    'real' => Str::upper(Str::random(40)),
                    'mock' => 'MOCK-'.Str::random(10),
                    default => null,
                },
                'fecha_procesamiento_mh' => $sello === 'real' ? now() : null,
            ]);
            Dte::withoutEvents(fn () => $dte->saveQuietly());
        }

        return $dte->refresh();
    }

    public function test_jefatura_ve_solo_lo_que_hacienda_ya_proceso_aunque_lo_pida_por_url(): void
    {
        $aceptado = $this->ccf('OC-ACEPTADO', EstadoDte::Aceptado);
        $anulado = $this->ccf('OC-ANULADO', EstadoDte::Invalidado);
        $fuera = [
            $this->ccf('OC-BORRADOR', EstadoDte::Borrador),
            $this->ccf('OC-GENERADO', EstadoDte::Generado),
            $this->ccf('OC-RECHAZADO', EstadoDte::Rechazado, null),
            $this->ccf('OC-MOCK', EstadoDte::Aceptado, 'mock'),
            // Anulación interna de un generado: nunca pasó por Hacienda.
            $this->ccf('OC-ANULADO-LOCAL', EstadoDte::Invalidado, null),
        ];
        $jefatura = $this->usuario('jefatura');

        foreach ([[], ['estado' => 'borrador'], ['estado' => 'pendientes'], ['estado' => 'rechazados_invalidados'], ['estado' => 'rechazados_archivados']] as $params) {
            $respuesta = $this->actingAs($jefatura)->get(route('facturacion.index', $params))->assertOk()
                ->assertSee(route('facturacion.pdf', $aceptado), false)
                ->assertSee(route('facturacion.pdf', $anulado), false);
            foreach ($fuera as $dte) {
                $respuesta->assertDontSee(route('facturacion.pdf', $dte), false);
            }
        }
    }

    public function test_jefatura_ve_solo_fecha_tipo_cliente_total_y_el_pdf(): void
    {
        $aceptado = $this->ccf('OC-12345', EstadoDte::Aceptado);
        $anulado = $this->ccf('OC-67890', EstadoDte::Invalidado);

        $html = $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.index'))->assertOk()
            ->assertSee('Fecha')
            ->assertSee('Cliente OC-12345')
            ->assertSee('$'.number_format((float) $aceptado->total_pagar, 2))
            ->assertSee($aceptado->fecha_emision->format('d/m/Y'))
            ->assertSee('Ver PDF')
            ->assertSee('Anulado')
            // Nada técnico ni de la operación.
            ->assertDontSee('Orden compra')
            ->assertDontSee('>Correo<', false)
            ->assertDontSee('>Estado<', false)
            ->assertDontSee('OC-12345</td>', false)
            ->assertDontSee($aceptado->numero_control ?? 'sin-numero-de-control')
            ->assertDontSee('Mostrando documentos del ambiente')
            ->assertDontSee('Rechazados archivados')
            ->assertDontSee('name="estado"', false)
            ->assertDontSee('href="'.route('facturacion.show', $aceptado).'"', false)
            ->assertDontSee('href="'.route('facturacion.show', $anulado).'"', false)
            ->getContent();

        $this->assertStringContainsString(route('facturacion.pdf', $anulado), $html, 'El PDF del anulado abre igual.');
    }

    public function test_jefatura_que_entra_a_la_ficha_va_al_pdf(): void
    {
        $jefatura = $this->usuario('jefatura');

        foreach ([EstadoDte::Aceptado, EstadoDte::Invalidado] as $estado) {
            $dte = $this->ccf('OC-'.$estado->value, $estado);
            $this->actingAs($jefatura)->get(route('facturacion.show', $dte))
                ->assertRedirect(route('facturacion.pdf', $dte));
        }
    }

    public function test_jefatura_que_entra_a_la_ficha_de_lo_no_procesado_ve_el_aviso(): void
    {
        $jefatura = $this->usuario('jefatura');

        foreach ([EstadoDte::Borrador, EstadoDte::Generado] as $estado) {
            $dte = $this->ccf('OC-'.$estado->value, $estado);
            $this->actingAs($jefatura)->get(route('facturacion.show', $dte))
                ->assertRedirect(route('facturacion.index'))
                ->assertSessionHas('error');
        }

        $this->actingAs($jefatura)->followingRedirects()->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('todavía no está listo');
    }

    public function test_jefatura_abre_el_pdf_en_linea(): void
    {
        $dte = $this->ccf('OC-PDF', EstadoDte::Aceptado);

        $respuesta = $this->actingAs($this->usuario('jefatura'))->get(route('facturacion.pdf', $dte));

        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $respuesta->headers->get('Content-Disposition'));
    }

    /** El candado está en DtePolicy::view, no en la lista: por la URL tampoco abre lo no procesado. */
    public function test_jefatura_no_abre_por_url_el_pdf_de_lo_no_procesado(): void
    {
        $jefatura = $this->usuario('jefatura');
        $fuera = [
            $this->ccf('OC-BORRADOR', EstadoDte::Borrador),
            $this->ccf('OC-GENERADO', EstadoDte::Generado),
            $this->ccf('OC-RECHAZADO', EstadoDte::Rechazado, null),
            $this->ccf('OC-MOCK', EstadoDte::Aceptado, 'mock'),
        ];

        foreach ($fuera as $dte) {
            foreach (['facturacion.pdf', 'facturacion.pdf.descargar', 'facturacion.imprimir', 'facturacion.pdf-con-orden'] as $ruta) {
                $this->actingAs($jefatura)->get(route($ruta, $dte))->assertForbidden();
            }
        }
    }

    public function test_jefatura_abre_por_url_el_pdf_de_un_aceptado_y_de_un_anulado(): void
    {
        $jefatura = $this->usuario('jefatura');

        foreach ([EstadoDte::Aceptado, EstadoDte::Invalidado] as $estado) {
            $dte = $this->ccf('OC-'.$estado->value, $estado);
            foreach (['facturacion.pdf', 'facturacion.pdf.descargar', 'facturacion.imprimir'] as $ruta) {
                $this->actingAs($jefatura)->get(route($ruta, $dte))->assertOk();
            }
            // Pasa el candado; sin orden de compra recibida no hay nada que unir.
            $this->actingAs($jefatura)->get(route('facturacion.pdf-con-orden', $dte))->assertNotFound();
        }
    }

    public function test_los_demas_roles_siguen_igual(): void
    {
        $aceptado = $this->ccf('OC-ACEPTADO', EstadoDte::Aceptado);
        $borrador = $this->ccf('OC-BORRADOR', EstadoDte::Borrador);

        foreach (['administrador', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))->get(route('facturacion.show', $aceptado))->assertOk();
            $this->actingAs($this->usuario($rol))->get(route('facturacion.index'))
                ->assertOk()
                ->assertSee('href="'.route('facturacion.show', $aceptado).'"', false)
                ->assertSee('href="'.route('facturacion.show', $borrador).'"', false)
                ->assertSee('Orden compra')
                ->assertSee('Mostrando documentos del ambiente')
                ->assertSee('Rechazados archivados');
        }
    }
}
