<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\ImapMailboxClient;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La búsqueda de TXT de Cobros abre su propia carpeta del buzón; Compras sigue con la suya.
 * Sin servidor IMAP: se mira la cadena de buzón que se le pasaría a `imap_open`.
 */
class ImapCarpetaDeTxtTest extends TestCase
{
    private function lector(): ImapMailboxClient
    {
        config([
            'documentos_recibidos.buzon_adicional.host' => 'imap.example.com',
            'documentos_recibidos.buzon_adicional.username' => 'compras@example.com',
            'documentos_recibidos.buzon_adicional.password' => 'secreto-de-prueba',
            'documentos_recibidos.buzon_adicional.folder' => 'CCF',
        ]);

        return new ImapMailboxClient(app(ConfiguracionBuzonAdicional::class));
    }

    private function buzon(ImapMailboxClient $lector, ?string $carpeta = null): string
    {
        return (new ReflectionMethod($lector, 'buzon'))->invoke($lector, $carpeta);
    }

    public function test_compras_sigue_con_su_carpeta_y_la_de_los_txt_es_aparte(): void
    {
        $lector = $this->lector();

        $this->assertStringEndsWith('/readonly}CCF', $this->buzon($lector));
        $this->assertStringEndsWith('/readonly}INBOX', $this->buzon($lector, 'INBOX'));
        // Pedir otra carpeta para los TXT no cambia la de Compras.
        $this->assertStringEndsWith('/readonly}CCF', $this->buzon($lector));
    }

    public function test_rechaza_una_carpeta_que_romperia_la_cadena_del_buzon_sin_conectar(): void
    {
        $lector = $this->lector();

        foreach (["INBOX}\r\n", '{otro:993}INBOX', ' '] as $carpeta) {
            try {
                $lector->adjuntosDeRemitente('pagos@cliente.example', Carbon::parse('2026-10-01'), ['txt'], $carpeta);
                $this->fail('Debió rechazar la carpeta: '.json_encode($carpeta));
            } catch (BuzonInaccesibleException $e) {
                $this->assertStringContainsString('carpeta configurada no es válida', $e->getMessage());
            }
        }
    }
}
