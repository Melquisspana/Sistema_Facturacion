<?php

namespace App\Services\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\BuzonFrenadoException;
use App\Services\DocumentosRecibidos\Buzon\EstadoBuzon;
use App\Services\DocumentosRecibidos\Buzon\PaginaMensajes;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Envuelve al lector del buzón para respetar el freno de Yahoo (#162).
 *
 * Tras «Too many login failures», nadie vuelve a entrar a ESE buzón hasta que pase la
 * espera: ni la sincronización programada ni el botón de Cobros, porque cada intento
 * alarga el freno. La marca va en caché por cuenta (la fuente lleva usuario y servidor),
 * así la comparten el scheduler y la web. Si Yahoo vuelve a frenar, la espera se duplica
 * (5, 10, 20, 40, hasta 60 minutos); al entrar bien, se olvida.
 */
class BuzonConFreno implements MailboxClient
{
    private const ESPERA_INICIAL = 5;

    private const ESPERA_MAXIMA = 60;

    public function __construct(private readonly MailboxClient $buzon) {}

    public function disponible(): bool
    {
        return $this->buzon->disponible();
    }

    public function fuente(): string
    {
        return $this->buzon->fuente();
    }

    public function estado(): EstadoBuzon
    {
        return $this->entrar(fn () => $this->buzon->estado());
    }

    public function mensajesDelDia(CarbonInterface $dia, int $limite, ?int $desdeUid = null): PaginaMensajes
    {
        return $this->entrar(fn () => $this->buzon->mensajesDelDia($dia, $limite, $desdeUid));
    }

    public function adjuntosDeRemitente(string $remitente, CarbonInterface $desde, array $extensiones, ?string $carpeta = null): array
    {
        return $this->entrar(fn () => $this->buzon->adjuntosDeRemitente($remitente, $desde, $extensiones, $carpeta));
    }

    private function entrar(Closure $lectura): mixed
    {
        $freno = Cache::get($this->clave());
        if (is_array($freno) && now()->getTimestamp() < (int) $freno['hasta']) {
            throw BuzonFrenadoException::esperar(max(1, (int) ceil(((int) $freno['hasta'] - now()->getTimestamp()) / 60)));
        }

        try {
            $resultado = $lectura();
        } catch (BuzonFrenadoException $e) {
            $nivel = is_array($freno) ? (int) $freno['nivel'] + 1 : 1;
            $minutos = min(self::ESPERA_MAXIMA, self::ESPERA_INICIAL * 2 ** ($nivel - 1));
            Cache::put($this->clave(), ['nivel' => $nivel, 'hasta' => now()->addMinutes($minutos)->getTimestamp()], now()->addDay());

            throw BuzonFrenadoException::esperar($minutos, $e);
        }

        if ($freno !== null) {
            Cache::forget($this->clave());
        }

        return $resultado;
    }

    private function clave(): string
    {
        return 'documentos_recibidos.buzon_frenado.'.sha1($this->buzon->fuente());
    }
}
