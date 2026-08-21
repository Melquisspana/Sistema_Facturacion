<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Integraciones\ResultadoPruebaIntegracion;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Services\Dte\DteFirmaService;

/**
 * «Probar firma» desde la pantalla de configuración.
 *
 * QUÉ HACE, EXACTAMENTE: pregunta al firmador si está vivo y le manda a firmar
 * un documento INVENTADO, con un NIT de relleno («00000000000000») y una
 * contraseña falsa. **No lee ningún DTE, no toca la base de datos, no usa el
 * certificado real ni la contraseña real y no envía nada a Hacienda.**
 *
 * QUÉ SE CONSIDERA ÉXITO, Y POR QUÉ ES RARO. Que el firmador RECHACE el
 * documento es el resultado bueno: significa que está levantado, que acepta la
 * petición y que la procesa hasta el punto de decir «no tengo ese certificado».
 * Si en cambio devolviera un documento firmado con un NIT de relleno, eso sí
 * sería una señal de alarma — y se dice con esas palabras en vez de pintarlo
 * verde. Un firmador que firma cualquier cosa no es un firmador que funciona.
 */
class PruebaFirmador
{
    public function __construct(
        private readonly DteFirmaService $firma,
        private readonly RegistroVerificaciones $verificaciones,
    ) {}

    public function ejecutar(): ResultadoPruebaIntegracion
    {
        if (blank(config('dte.firmador.url'))) {
            return ResultadoPruebaIntegracion::sinComprobar(
                'No se comprobó nada: no hay una dirección del firmador configurada en el servidor.'
            );
        }

        $salud = $this->firma->healthCheck();

        if (! $salud['disponible']) {
            return $this->registrar(ResultadoPruebaIntegracion::fallo(
                'El firmador no responde en '.$salud['url'].'. '.$salud['mensaje']
            ));
        }

        $post = $this->firma->postTest();

        if (! $post['procesa']) {
            return $this->registrar(ResultadoPruebaIntegracion::fallo(
                'El firmador responde pero no procesó la petición de firma de prueba. '.($post['mensaje'] ?? '')
            ));
        }

        if ($post['firmo']) {
            return $this->registrar(ResultadoPruebaIntegracion::fallo(
                'ATENCIÓN: el firmador firmó un documento de prueba con un NIT de relleno y una contraseña falsa. '
                .'Debería haberlo rechazado. Revisar qué certificado tiene cargado antes de firmar nada real.'
            ));
        }

        return $this->registrar(ResultadoPruebaIntegracion::exito(
            'El firmador está levantado y procesa peticiones: rechazó el documento de prueba, que es lo correcto. '
            .'Respondió: '.($post['mensaje'] ?? 'sin mensaje').'. No se firmó ningún documento real.'
        ));
    }

    private function registrar(ResultadoPruebaIntegracion $prueba): ResultadoPruebaIntegracion
    {
        $this->verificaciones->registrar(
            EstadoFirmador::CLAVE_VERIFICACION,
            $prueba->resultado(),
            $prueba->mensaje,
        );

        return $prueba;
    }
}
