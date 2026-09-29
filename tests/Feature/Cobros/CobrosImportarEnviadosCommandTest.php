<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EL COMANDO `cobros:importar-enviados`: en seco por defecto, escribe solo con
 * `--aplicar`, y dice cuándo el barrido quedó truncado. Gmail es el doble en memoria
 * {@see GmailClientDeImportacion} (ImportadorEnviadosServiceTest.php).
 */
class CobrosImportarEnviadosCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NIT = '0614-010101-101-1';

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['num_documento' => self::NIT]);
    }

    private function adjuntoCcf(int $n): array
    {
        $codigo = strtoupper(Str::uuid()->toString());

        return ['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode([
            'identificacion' => [
                'numeroControl' => 'DTE-03-M001P001-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
                'codigoGeneracion' => $codigo,
                'tipoDte' => '03',
                'fecEmi' => now()->toDateString(),
            ],
            'resumen' => ['totalPagar' => 50.0],
            'receptor' => ['nit' => self::NIT],
            'selloRecibido' => 'SELLO'.$codigo,
        ])];
    }

    private function opciones(Cliente $cliente, array $extra = []): array
    {
        return $extra + ['--cliente' => $cliente->id, '--desde' => '2026-09-01', '--hasta' => '2026-09-30'];
    }

    public function test_por_defecto_es_ensayo_en_seco(): void
    {
        $cliente = $this->cliente();
        $this->app->instance(GmailClient::class, new GmailClientDeImportacion([['m1']], ['m1' => [$this->adjuntoCcf(1)]]));

        $this->artisan('cobros:importar-enviados', $this->opciones($cliente))
            ->expectsOutputToContain('ENSAYO EN SECO')
            ->assertExitCode(0);

        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_con_aplicar_incorpora(): void
    {
        $cliente = $this->cliente();
        $this->app->instance(GmailClient::class, new GmailClientDeImportacion([['m1']], ['m1' => [$this->adjuntoCcf(1)]]));

        $this->artisan('cobros:importar-enviados', $this->opciones($cliente, ['--aplicar' => true]))
            ->expectsOutputToContain('APLICANDO')
            ->expectsOutputToContain('no prueba que un CCF no tenga NC')
            ->assertExitCode(0);

        $this->assertSame(1, CobroDocumento::count());
    }

    public function test_avisa_el_truncamiento(): void
    {
        $cliente = $this->cliente();
        $this->app->instance(GmailClient::class, new GmailClientDeImportacion(
            [['m1'], ['m2']],
            ['m1' => [$this->adjuntoCcf(1)], 'm2' => [$this->adjuntoCcf(2)]],
        ));

        $this->artisan('cobros:importar-enviados', $this->opciones($cliente, ['--limite' => 1]))
            ->expectsOutputToContain('TRUNCADO')
            ->assertExitCode(0);
    }

    public function test_rechaza_opciones_invalidas(): void
    {
        $cliente = $this->cliente();

        $this->artisan('cobros:importar-enviados', $this->opciones($cliente, ['--hasta' => '2026-08-01']))
            ->assertExitCode(1);
    }
}
