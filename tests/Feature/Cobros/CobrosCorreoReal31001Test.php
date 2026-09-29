<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Services\Cobros\CorreoCobroParser;
use App\Services\Cobros\LectorCorreosCobro;
use App\Support\Correo\MensajeActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosCorreoReal31001Test extends TestCase
{
    use RefreshDatabase;

    private function actual(): string
    {
        return MensajeActual::desdePartes(
            file_get_contents(base_path('tests/Fixtures/cobros/ref31001-real/mensaje.txt')),
            file_get_contents(base_path('tests/Fixtures/cobros/ref31001-real/mensaje.html')),
        );
    }

    public function test_html_original_empareja_dos_filas_con_sus_motivos_sin_heredar_el_acuse(): void
    {
        $leido = app(CorreoCobroParser::class)->interpretar('OBSERVACIONES REF 31001', $this->actual());
        $this->assertSame('observaciones', $leido['tipo']);
        $this->assertNull($leido['archivo_referido']);
        $this->assertNull($leido['fecha_programada_pago']);
        $this->assertCount(2, $leido['documentos']);
        $this->assertSame('00000000-0000-4000-8000-000000000102', $leido['documentos'][0]['codigo_generacion']);
        $this->assertSame('DTE03M001P002000000000000119', $leido['documentos'][0]['numero_control']);
        $this->assertStringContainsString('FALTA NOTA DE CREDITO', $leido['documentos'][0]['detalle']);
        $this->assertStringNotContainsString('NO APARECE EN REPORTERIA', $leido['documentos'][0]['detalle']);
        $this->assertSame('00000000-0000-4000-8000-000000000103', $leido['documentos'][1]['codigo_generacion']);
        $this->assertSame('DTE03M001P001000000000001186', $leido['documentos'][1]['numero_control']);
        $this->assertStringContainsString('NO APARECE EN REPORTERIA', $leido['documentos'][1]['detalle']);
        $this->assertStringNotContainsString('FALTA NOTA DE CREDITO', $leido['documentos'][1]['detalle']);
    }

    public function test_lector_conserva_motivo_del_documento_local_y_el_otro_pendiente_sin_duplicar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $doc = CobroDocumento::create([
            'cliente_id' => $cliente->id, 'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000119',
            'codigo_generacion' => '00000000-0000-4000-8000-000000000102', 'monto' => '77.74',
        ]);
        $mensaje = ['id' => 'fixture-31001', 'asunto' => 'OBSERVACIONES REF 31001', 'cuerpo_actual' => $this->actual()];
        $lector = app(LectorCorreosCobro::class);
        $resultado = $lector->procesar($cliente, [$mensaje]);
        $lector->procesar($cliente, [$mensaje]);
        $this->assertSame('sin_asociar', $resultado['correos']->first()->estado);
        $this->assertStringContainsString('1186', $resultado['correos']->first()->motivo);
        $this->assertSame(1, $doc->eventos()->count());
        $this->assertStringContainsString('FALTA NOTA DE CREDITO', $doc->eventos()->first()->detalle);
        $this->assertStringNotContainsString('NO APARECE EN REPORTERIA', $doc->eventos()->first()->detalle);
        $this->assertSame('sin_presentar', $doc->refresh()->presentacion_estado->value);
        $this->assertSame('0.00', $doc->monto_pagado);
    }
}
