<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CobrosMotivoTextMigracionTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): object
    {
        return require database_path('migrations/2026_09_21_131000_ampliar_motivo_cobro_correos_a_text.php');
    }

    public function test_ampliacion_y_reversion_conservan_nulos_y_acentos(): void
    {
        $cliente = Cliente::factory()->create();
        foreach ([null, 'Revisión: información pendiente, año 2026.'] as $i => $motivo) {
            CobroCorreo::create(['cliente_id' => $cliente->id, 'gmail_message_id' => 'm-'.$i, 'motivo' => $motivo]);
        }
        $antes = CobroCorreo::orderBy('id')->get()->toArray();
        $this->migracion()->down();
        $this->assertSame($antes, CobroCorreo::orderBy('id')->get()->toArray());
        $this->migracion()->up();
        $this->assertSame('text', Schema::getColumnType('cobro_correos', 'motivo'));
        $this->assertSame($antes, CobroCorreo::orderBy('id')->get()->toArray());
    }

    public function test_no_se_puede_revertir_truncando_un_motivo_largo(): void
    {
        $motivo = str_repeat('Observación pendiente. ', 30);
        $correo = CobroCorreo::create([
            'cliente_id' => Cliente::factory()->create()->id,
            'gmail_message_id' => 'largo', 'motivo' => $motivo,
        ]);
        try {
            $this->migracion()->down();
            $this->fail('Debía impedirse la reducción destructiva.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sin perder datos', $e->getMessage());
        }
        $this->assertSame($motivo, $correo->refresh()->motivo);
        $this->assertSame('text', Schema::getColumnType('cobro_correos', 'motivo'));
    }
}
