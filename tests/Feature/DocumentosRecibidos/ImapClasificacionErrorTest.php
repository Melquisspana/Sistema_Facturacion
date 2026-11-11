<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonFrenadoException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\ImapMailboxClient;
use Tests\TestCase;

/**
 * Qué excepción sale de cada motivo que deja IMAP (#162).
 *
 * Yahoo frena los logins seguidos con «Too many login failures». No son credenciales
 * malas: la corrida siguiente entra bien. Por eso se separa antes de que la señal
 * «login failure» lo tome por una contraseña rechazada.
 */
class ImapClasificacionErrorTest extends TestCase
{
    public function test_el_freno_de_yahoo_no_es_credencial_mala(): void
    {
        foreach (['[AUTHENTICATIONFAILED] Too many login failures', 'Too many login failures (Failure)'] as $motivo) {
            $e = ImapMailboxClient::excepcionPara($motivo, 'No se pudo abrir el buzón');

            $this->assertInstanceOf(BuzonFrenadoException::class, $e, $motivo);
            $this->assertStringNotContainsString('Revisá usuario y contraseña', $e->getMessage());
        }
    }

    public function test_las_credenciales_malas_siguen_siendo_autenticacion_fallida(): void
    {
        $e = ImapMailboxClient::excepcionPara('[AUTHENTICATIONFAILED] Invalid credentials', 'No se pudo abrir el buzón');

        $this->assertInstanceOf(AutenticacionBuzonException::class, $e);
        $this->assertStringContainsString('Revisá usuario y contraseña', $e->getMessage());
    }

    public function test_un_corte_de_red_es_buzon_inaccesible(): void
    {
        $e = ImapMailboxClient::excepcionPara('Connection timed out', 'No se pudo abrir el buzón');

        $this->assertInstanceOf(BuzonInaccesibleException::class, $e);
        $this->assertSame('No se pudo abrir el buzón: Connection timed out', $e->getMessage());
    }
}
