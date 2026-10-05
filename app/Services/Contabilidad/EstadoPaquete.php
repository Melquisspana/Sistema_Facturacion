<?php

namespace App\Services\Contabilidad;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Estado de un paquete que se arma en segundo plano, guardado como JSON junto al ZIP
 * en `storage/app/private/paquetes/{usuario}/` (sin tabla nueva).
 *
 * Por qué existe: el ZIP de un mes completo regenera un PDF por cada venta y tarda más
 * de los 100 s que Cloudflare espera una respuesta (error 524). Ahora lo arma un job y
 * la pantalla consulta este estado hasta que el archivo está listo.
 *
 * Tipos: `zip` (descarga) y `envio` (envío a contabilidad). Cada usuario tiene su
 * propio estado por período y fuentes, que es también el candado: no se lanza otro
 * igual mientras uno siga en curso.
 */
class EstadoPaquete
{
    public const CARPETA = 'paquetes';

    /** Un paquete "en curso" más viejo que esto se da por muerto y se puede relanzar. */
    public const MINUTOS_EN_CURSO = 25;

    /** Los ZIP y estados más viejos que esto se borran al lanzar uno nuevo. */
    public const HORAS_LIMPIEZA = 24;

    private const EN_CURSO = ['generando', 'enviando'];

    public function __construct(
        public readonly int $usuarioId,
        public readonly string $tipo,
        public readonly string $clave,
    ) {}

    /** @param  array{etiqueta: string}  $rango */
    public static function para(int $usuarioId, string $tipo, array $rango, bool $compras, bool $ventas): self
    {
        $fuentes = ($compras ? 'c' : '').($ventas ? 'v' : '');

        return new self($usuarioId, $tipo, $tipo.'_'.$rango['etiqueta'].'_'.$fuentes);
    }

    public function rutaZip(): string
    {
        return $this->carpeta().'/'.$this->clave.'.zip';
    }

    /** @return array<string, mixed>|null */
    public function leer(): ?array
    {
        $json = $this->disco()->get($this->rutaJson());
        $datos = is_string($json) ? json_decode($json, true) : null;

        return is_array($datos) ? $datos : null;
    }

    /** @param  array<string, mixed>  $datos  se combinan con lo que ya había */
    public function escribir(array $datos): void
    {
        $this->disco()->put($this->rutaJson(), json_encode(
            array_merge($this->leer() ?? [], $datos),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ));
    }

    /**
     * Hay uno en curso y todavía no venció. Uno vencido es un job que murió sin avisar
     * (worker reiniciado, servidor apagado): no puede bloquear para siempre.
     */
    public function enCurso(): bool
    {
        $datos = $this->leer();
        if ($datos === null || ! in_array($datos['estado'] ?? null, self::EN_CURSO, true)) {
            return false;
        }

        $iniciado = (int) ($datos['iniciado_ts'] ?? 0);

        return $iniciado > now()->subMinutes(self::MINUTOS_EN_CURSO)->getTimestamp();
    }

    /** Marca el inicio: se escribe ANTES de despachar el job, así el candado vale ya. */
    public function iniciar(string $estado, string $mensaje): void
    {
        $this->disco()->delete($this->rutaZip());
        $this->disco()->put($this->rutaJson(), json_encode([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'iniciado_en' => now()->format('d/m/Y H:i:s'),
            'iniciado_ts' => now()->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function terminar(string $estado, ?string $mensaje, array $extra = []): void
    {
        $this->escribir(array_merge($extra, [
            'estado' => $estado,
            'mensaje' => $mensaje,
            'terminado_en' => now()->format('d/m/Y H:i:s'),
        ]));
    }

    /** Borra ZIP y estados de cualquier usuario con más de {@see HORAS_LIMPIEZA} horas. */
    public static function limpiarViejos(): void
    {
        $disco = Storage::disk('local');
        $limite = now()->subHours(self::HORAS_LIMPIEZA)->getTimestamp();

        foreach ($disco->allFiles(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
            }
        }
    }

    private function rutaJson(): string
    {
        return $this->carpeta().'/'.$this->clave.'.json';
    }

    private function carpeta(): string
    {
        return self::CARPETA.'/'.$this->usuarioId;
    }

    private function disco(): Filesystem
    {
        return Storage::disk('local');
    }
}
