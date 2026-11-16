<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\Ajustes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ConfiguracionTiendaWeb
{
    public function __construct(private readonly Ajustes $ajustes) {}

    public function url(): string
    {
        return rtrim((string) $this->ajustes->texto('tienda.url', ''), '/');
    }

    /** Solo para el cliente HTTP; nunca se entrega a la vista ni a los registros. */
    public function secreto(): string
    {
        return (string) $this->ajustes->secretoParaRuntime('tienda.secreto');
    }

    /**
     * El cliente HTTP de la tienda, el mismo para traer pedidos y para avisarle: token,
     * JSON, 10 s y sin seguir redirecciones. El secreto no sale de esta clase.
     */
    public function cliente(): PendingRequest
    {
        return Http::withToken($this->secreto())->acceptJson()->timeout(10)
            ->withOptions(['allow_redirects' => false]);
    }

    public function completo(): bool
    {
        return self::urlValida($this->url()) && $this->ajustes->estaConfigurado('tienda.secreto');
    }

    public static function urlValida(string $url): bool
    {
        $partes = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($partes['scheme'] ?? '') === 'https'
            && ! isset($partes['user']) && ! isset($partes['pass'])
            && ! isset($partes['query']) && ! isset($partes['fragment']);
    }
}
