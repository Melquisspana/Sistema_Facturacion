<?php

namespace App\Services\Alertas\Detectores;

use App\Ajustes\Integraciones\ConfiguracionGmail;
use App\Models\GmailCuenta;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;

/**
 * La integración con Gmail está prendida pero no hay cuenta conectada. Pasa cuando
 * Google responde `invalid_grant` (permiso vencido o revocado): GmailClient borra el
 * token y desde ahí no entran albaranes ni respuestas de cobros hasta que alguien
 * reconecte la cuenta a mano. Sin este aviso solo quedaba escrito en el log.
 */
class GmailDesconectado implements Detector
{
    public function __construct(private readonly ConfiguracionGmail $gmail) {}

    public function tipo(): string
    {
        return 'gmail_desconectado';
    }

    public function nombre(): string
    {
        return 'Conexión con Gmail';
    }

    public function aplica(): bool
    {
        return $this->gmail->habilitado();
    }

    public function revisar(): ?Hallazgo
    {
        $cuenta = GmailCuenta::actual();

        if ($cuenta?->conectada()) {
            return null;
        }

        return new Hallazgo(
            titulo: 'Gmail está desconectado',
            detalle: 'El sistema no puede leer el correo: no entran los albaranes de Calleja ni las respuestas de cobros.',
            queHacer: 'Entrá a Configuración › Gmail / Pronto pago y reconectá la cuenta. Al reconectarla, el sistema lee solo lo que llegó mientras estuvo desconectado.',
            desde: $cuenta?->updated_at,
            enlace: 'configuracion.integraciones.gmail',
        );
    }
}
