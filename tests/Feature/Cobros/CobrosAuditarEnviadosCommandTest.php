<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL COMANDO `cobros:auditar-enviados`, DE SOLO LECTURA.
 *
 * No se conecta a Gmail de verdad: en cada prueba se sustituye {@see GmailClient} por
 * un doble que vive solo en memoria (ver {@see GmailClientDeAuditoria} en
 * AuditorEnviadosServiceTest.php). Lo que se comprueba acá es la validación de
 * opciones, el aviso cuando Gmail no está disponible, y que el resumen final
 * distingue truncamiento de cobertura completa.
 */
class CobrosAuditarEnviadosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(string $nit = '0614-010101-101-1'): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['num_documento' => $nit]);
    }

    private function gmailNoDisponible(): void
    {
        $this->app->instance(GmailClient::class, new class extends GmailClient
        {
            public function __construct() {}

            public function disponible(): bool
            {
                return false;
            }
        });
    }

    /** @param array<string, array<int, string>> $paginas */
    private function gmailDisponibleCon(array $paginas, array $adjuntosPorId): void
    {
        $this->app->instance(GmailClient::class, new class(array_values($paginas), $adjuntosPorId) extends GmailClient
        {
            public function __construct(private readonly array $paginas, private readonly array $adjuntosPorId)
            {
                parent::__construct();
            }

            public function disponible(): bool
            {
                return true;
            }

            public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
            {
                $indice = (int) ($token ?? 0);
                $ids = $this->paginas[$indice] ?? [];
                $siguiente = isset($this->paginas[$indice + 1]) ? (string) ($indice + 1) : null;

                return ['ids' => $ids, 'siguiente' => $siguiente];
            }

            public function adjuntos(string $messageId): array
            {
                return $this->adjuntosPorId[$messageId] ?? [];
            }
        });
    }

    // ------------------------------------------------------------ validación

    public function test_exige_cliente(): void
    {
        $this->artisan('cobros:auditar-enviados', ['--desde' => '2026-09-01', '--hasta' => '2026-09-30'])
            ->assertExitCode(1);
    }

    public function test_rechaza_fechas_con_formato_invalido(): void
    {
        $cliente = $this->cliente();

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '01/09/2026',
            '--hasta' => '2026-09-30',
        ])->assertExitCode(1);
    }

    public function test_rechaza_un_rango_invertido(): void
    {
        $cliente = $this->cliente();

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '2026-09-30',
            '--hasta' => '2026-09-01',
        ])->assertExitCode(1);
    }

    public function test_rechaza_un_cliente_inexistente(): void
    {
        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => 999999,
            '--desde' => '2026-09-01',
            '--hasta' => '2026-09-30',
        ])->assertExitCode(1);
    }

    // ------------------------------------------------------------ gmail no disponible

    public function test_sin_gmail_conectado_no_hace_nada(): void
    {
        $this->gmailNoDisponible();
        $cliente = $this->cliente();

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '2026-09-01',
            '--hasta' => '2026-09-30',
        ])
            ->expectsOutputToContain('Gmail no está disponible')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------ éxito

    public function test_informa_el_resumen_y_que_cubrio_todo_el_rango(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $this->gmailDisponibleCon(
            paginas: [['m1']],
            adjuntosPorId: ['m1' => [[
                'filename' => 'dte.json',
                'mime' => 'application/json',
                'data' => json_encode([
                    'identificacion' => ['numeroControl' => 'DTE-03-M001P001-000000000000001', 'codigoGeneracion' => 'CCF-1', 'tipoDte' => '03', 'fecEmi' => '2026-09-01'],
                    'resumen' => ['totalPagar' => 50.0],
                    'receptor' => ['nit' => $nit],
                ]),
            ]]],
        );

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '2026-09-01',
            '--hasta' => '2026-09-30',
        ])
            ->expectsOutputToContain('SOLO LECTURA')
            ->expectsOutputToContain('cubrió el rango completo')
            ->assertExitCode(0);
    }

    public function test_informa_truncamiento_cuando_el_limite_corta_el_barrido(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $json = fn (string $codigo) => json_encode([
            'identificacion' => ['numeroControl' => 'DTE-03-M001P001-000000000000001', 'codigoGeneracion' => $codigo, 'tipoDte' => '03', 'fecEmi' => '2026-09-01'],
            'resumen' => ['totalPagar' => 50.0],
            'receptor' => ['nit' => $nit],
        ]);

        $this->gmailDisponibleCon(
            paginas: [['m1'], ['m2']],
            adjuntosPorId: [
                'm1' => [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => $json('CCF-1')]],
                'm2' => [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => $json('CCF-2')]],
            ],
        );

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '2026-09-01',
            '--hasta' => '2026-09-30',
            '--limite' => 1,
        ])
            ->expectsOutputToContain('TRUNCADO')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------------ cliente sin nit

    public function test_cliente_sin_numero_de_documento_da_error_claro(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['num_documento' => '']);
        $this->gmailDisponibleCon(paginas: [[]], adjuntosPorId: []);

        $this->artisan('cobros:auditar-enviados', [
            '--cliente' => $cliente->id,
            '--desde' => '2026-09-01',
            '--hasta' => '2026-09-30',
        ])
            ->expectsOutputToContain('no tiene número de documento fiscal')
            ->assertExitCode(1);
    }
}
