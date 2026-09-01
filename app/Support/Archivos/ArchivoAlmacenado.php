<?php

namespace App\Support\Archivos;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un archivo del almacenamiento, con la distinción que faltaba: **ausente** no es lo
 * mismo que **no se pudo leer**.
 *
 * POR QUÉ EXISTE. El patrón que usaban el ZIP del paquete mensual y el correo de DTE
 * era este:
 *
 *     if (filled($ruta) && Storage::disk($disco)->exists($ruta)) { adjuntar }
 *
 * Los discos locales del proyecto están declarados con `'throw' => false` y
 * `'report' => false` (config/filesystems.php), así que un disco mal configurado, un
 * permiso denegado o un nombre de disco inexistente hacen que `exists()` devuelva
 * `false` sin decir nada. El JSON simplemente no viaja: el ZIP sale sin él y el correo
 * sale sin él, los dos sin un solo error. Un archivo que el proveedor nunca mandó y un
 * disco roto se ven idénticos, y el que recibe el paquete no tiene forma de notarlo.
 *
 * Acá los tres desenlaces se separan y quien llama decide qué hacer con cada uno:
 * `presente` adjunta, `ausente` avisa, `error` avisa CON el motivo.
 */
class ArchivoAlmacenado
{
    public const PRESENTE = 'presente';

    public const AUSENTE = 'ausente';

    public const ERROR = 'error';

    private function __construct(
        public readonly string $estado,
        public readonly string $disco,
        public readonly ?string $ruta,
        public readonly ?string $contenido = null,
        public readonly ?string $motivo = null,
    ) {}

    /**
     * Revisa una ruta y, si está, la lee.
     *
     * Se lee de una vez en lugar de comprobar y leer por separado: entre las dos
     * llamadas el archivo puede desaparecer, y además duplicaría el manejo de errores.
     */
    public static function leer(string $disco, ?string $ruta): self
    {
        if (blank($ruta)) {
            return new self(self::AUSENTE, $disco, $ruta, motivo: 'No hay ruta registrada para este archivo.');
        }

        try {
            $almacen = Storage::disk($disco);

            if (! $almacen->exists($ruta)) {
                return new self(self::AUSENTE, $disco, $ruta,
                    motivo: 'El archivo no existe en el disco «'.$disco.'».');
            }

            $contenido = $almacen->get($ruta);

            // `get()` devuelve null cuando falla con `throw => false`. Un archivo que
            // existe pero no se puede leer es un problema de disco, no una ausencia.
            if ($contenido === null) {
                return new self(self::ERROR, $disco, $ruta,
                    motivo: 'El archivo existe pero no se pudo leer del disco «'.$disco.'» (permisos o almacenamiento).');
            }

            return new self(self::PRESENTE, $disco, $ruta, contenido: (string) $contenido);
        } catch (Throwable $e) {
            // Disco inexistente, credenciales de un disco remoto, ruta inválida: todo
            // esto llegaba antes como "el archivo no está".
            return new self(self::ERROR, $disco, $ruta,
                motivo: 'Error de almacenamiento en el disco «'.$disco.'»: '.$e->getMessage());
        }
    }

    public function presente(): bool
    {
        return $this->estado === self::PRESENTE;
    }

    public function ausente(): bool
    {
        return $this->estado === self::AUSENTE;
    }

    public function fallo(): bool
    {
        return $this->estado === self::ERROR;
    }

    /** Frase corta con contexto suficiente para actuar: qué archivo, qué disco, qué pasó. */
    public function explicacion(): string
    {
        return match ($this->estado) {
            self::PRESENTE => 'Archivo disponible ('.$this->ruta.').',
            self::AUSENTE => 'Falta el archivo '.($this->ruta ?? '(sin ruta)').': '.($this->motivo ?? 'no está en el disco.'),
            default => 'No se pudo leer '.($this->ruta ?? '(sin ruta)').': '.($this->motivo ?? 'error de almacenamiento.'),
        };
    }
}
