<?php

namespace Tests\Feature\Ppq;

use App\Services\Ppq\JsonAdjuntoDecoder;
use Tests\TestCase;

class JsonAdjuntoDecoderTest extends TestCase
{
    private function decoder(): JsonAdjuntoDecoder
    {
        return new JsonAdjuntoDecoder();
    }

    public function test_utf8_directo(): void
    {
        $raw = json_encode(['emisor' => ['nombre' => 'Elsa']], JSON_UNESCAPED_UNICODE);
        $dec = $this->decoder()->decodificar($raw, 'application/json', 'dte.json');

        $this->assertTrue($dec['ok']);
        $this->assertSame('UTF-8', $dec['encoding_usado']);
        $this->assertSame('Elsa', $dec['data']['emisor']['nombre']);
    }

    public function test_iso_8859_1_con_acentos(): void
    {
        $utf8 = json_encode(['emisor' => ['nombre' => 'Elsa Fidelina Hernández Cañas']], JSON_UNESCAPED_UNICODE);
        $iso = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');

        // json_decode crudo falla con "Malformed UTF-8".
        json_decode($iso, true);
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error());

        $dec = $this->decoder()->decodificar($iso, 'application/json', 'dte.json');
        $this->assertTrue($dec['ok'], 'Debe decodificar el JSON ISO-8859-1');
        $this->assertContains($dec['encoding_usado'], ['ISO-8859-1', 'Windows-1252']);
        $this->assertSame('Elsa Fidelina Hernández Cañas', $dec['data']['emisor']['nombre']);
    }

    public function test_utf8_con_bom(): void
    {
        $raw = "\xEF\xBB\xBF".json_encode(['numeroControl' => 'DTE-03-X']);
        $dec = $this->decoder()->decodificar($raw, 'application/json', 'dte.json');

        $this->assertTrue($dec['ok']);
        $this->assertTrue($dec['info']['bom']);
        $this->assertSame('DTE-03-X', $dec['data']['numeroControl']);
    }

    public function test_invalido_reporta_error_y_primeros_500(): void
    {
        $dec = $this->decoder()->decodificar('esto no es json {', 'text/plain', 'x.json');

        $this->assertFalse($dec['ok']);
        $this->assertNotNull($dec['error']);
        $this->assertArrayHasKey('primeros_500', $dec['info']);
        $this->assertArrayHasKey('UTF-8', $dec['intentos']);
    }
}
