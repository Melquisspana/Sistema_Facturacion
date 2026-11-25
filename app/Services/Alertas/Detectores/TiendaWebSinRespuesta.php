<?php

namespace App\Services\Alertas\Detectores;

use App\Facades\Ajustes;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * La tienda no contesta y los pedidos pagados no entran. Mira lo que dejó la última
 * pasada de `tienda:traer-pedidos` ({@see ImportarPedidosWeb::CLAVE_LECTURA}): cuánto
 * hace de la última lectura buena y, si después falló, por qué, de una lista fija.
 * También avisa si la tarea dejó de correr sin anotar ninguna falla.
 *
 * La tarea corre en segundo plano y Laravel no dispara ScheduledTaskFailed para ella:
 * esta es su vigilancia.
 */
class TiendaWebSinRespuesta implements Detector
{
    public const MINUTOS = 15;

    /** Un recordatorio («Sigue la falla») cada tanto como mucho. */
    private const MINUTOS_RECORDATORIO = 240;

    private const MOTIVOS = [
        'sin_conexion' => ['El sistema no logra conectarse con la página.',
            'Abrí la tienda en el celular; si abre, es el internet del servidor; si no, la página está caída.'],
        'clave' => ['La página no acepta la clave del sistema.',
            'La clave de Configuración › Tienda web tiene que ser igual a SECRETO_SISTEMA en Cloudflare.'],
        'apagada' => ['La página contesta que está apagada.',
            'A la página le falta su base o su clave; avisá a soporte.'],
        'direccion' => ['La dirección guardada no lleva a la tienda.',
            'Revisá la dirección en Configuración › Tienda web.'],
        'respuesta' => ['La página contestó algo que el sistema no entiende.', 'Avisá a soporte.'],
        'error' => ['La página contestó con un error.', 'Avisá a soporte.'],
        'sistema' => ['Falló el propio sistema al guardar los pedidos.', 'Avisá a soporte.'],
        'configuracion' => ['Falta la dirección o la clave de la tienda.', 'Completá Configuración › Tienda web.'],
    ];

    private const SIN_FALLO = ['No quedó anotada ninguna falla.',
        'La tarea que trae los pedidos no está corriendo; avisá a soporte.'];

    public function tipo(): string
    {
        return 'tienda_web_sin_respuesta';
    }

    public function nombre(): string
    {
        return 'Pedidos de la tienda sin traer';
    }

    /** Igual que el programador: sin la traída automática no hay nada que vigilar. */
    public function aplica(): bool
    {
        return Ajustes::boolSinBase('tienda.pedidos.automatica');
    }

    public function revisar(): ?Hallazgo
    {
        $lectura = self::lectura();
        $base = $lectura['ok'] ?? $lectura['fallo_desde'] ?? self::vigilando();
        $minutos = intdiv(now()->getTimestamp() - $base, 60);

        if ($minutos < self::MINUTOS) {
            return null;
        }

        [$motivo, $queHacer] = self::MOTIVOS[$lectura['fallo'] ?? ''] ?? self::SIN_FALLO;
        $ultima = isset($lectura['ok'])
            ? HoraNegocio::aLocal(Carbon::createFromTimestamp($lectura['ok']))->format('d/m H:i')
            : 'nunca';

        return new Hallazgo(
            titulo: 'Los pedidos de la tienda no están entrando',
            detalle: 'Última lectura buena: '.$ultima.'. '.$motivo,
            queHacer: $queHacer.' Los pedidos pagados entran solos cuando vuelva; si pasa de 2 días, hay que revisar a mano en Wompi los pagos de esos días.',
            conteo: 1 + intdiv($minutos, self::MINUTOS_RECORDATORIO),
            desde: Carbon::createFromTimestamp($base),
            enlace: 'configuracion.integraciones.tienda',
        );
    }

    /** @return array{ok?: ?int, fallo?: ?string, fallo_desde?: ?int, invalidos?: int, invalidos_ids?: list<int>} */
    public static function lectura(): array
    {
        $lectura = Cache::get(ImportarPedidosWeb::CLAVE_LECTURA);

        return is_array($lectura) ? $lectura : [];
    }

    /**
     * Desde cuándo se vigila. Se fija una sola vez: después de vaciar la caché (el
     * despliegue lo hace) hay 15 minutos de gracia antes de opinar.
     */
    public static function vigilando(): int
    {
        Cache::add(ImportarPedidosWeb::CLAVE_VIGILANDO, now()->getTimestamp());

        return (int) Cache::get(ImportarPedidosWeb::CLAVE_VIGILANDO, now()->getTimestamp());
    }
}
