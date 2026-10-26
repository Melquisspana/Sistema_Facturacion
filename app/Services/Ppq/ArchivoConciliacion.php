<?php

namespace App\Services\Ppq;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El archivo de pagos del cliente, ya leído y guardado: su contenido, su nombre original,
 * su huella y dónde quedó la copia.
 *
 * Existe para que la CONCILIACIÓN no tenga que saber nada de peticiones HTTP ni de discos.
 * El controlador recibe la subida y arma esto; el conciliador recibe un objeto que ya sabe
 * todo lo que hace falta para dejar constancia.
 *
 * ─────────────────────── Por qué la copia se guarda por su HASH ───────────────────────
 *
 * La ruta es `{directorio}/{sha256}.txt`, o sea que el contenido decide dónde vive. Eso da
 * tres cosas de una sola vez:
 *
 *  - guardar dos veces el mismo archivo escribe el mismo lugar: no se duplica nada;
 *  - el nombre del archivo en disco no depende de cómo lo haya llamado el usuario, así que
 *    dos archivos distintos no pueden pisarse por llamarse igual;
 *  - la huella que se guarda en la bitácora es verificable: cualquiera puede recalcular el
 *    SHA-256 de la copia y comprobar que es el archivo que se procesó.
 *
 * Lo que se guarda es el TXT crudo, tal cual llegó. Nada más: ni tokens, ni credenciales,
 * ni la traza de la petición.
 *
 * ─────────────────────── Dónde vive, y por qué no se publica ───────────────────────
 *
 * En el disco `local`, cuya raíz es `storage/app/private` — NO `storage/app/public`, que es
 * a donde apunta el enlace `public/storage`. Además, la ruta `GET /storage/{path}` que
 * Laravel registra para los discos con `serve` exige URL FIRMADA cuando el disco no declara
 * `visibility: public`, que es el caso: sin firma responde 403 en desarrollo y 404 en
 * producción. Y nada en la aplicación genera esas firmas para estas rutas. Un archivo de
 * pagos del cliente no se sirve por web.
 *
 * ───────────────────────── Archivos huérfanos: se dejan, a propósito ─────────────────────────
 *
 * La copia se escribe ANTES de procesar. Si el parseo rechaza el archivo, si la conciliación
 * falla o si la transacción se deshace, la copia queda en disco sin ninguna fila de
 * `ppq_conciliaciones` que la referencie. Eso es DELIBERADO y no se limpia:
 *
 *  - la evidencia no debe depender de que el procesamiento salga bien. Si el archivo se
 *    rechazó por contradecirse, tenerlo guardado es justo lo que permite mostrárselo al
 *    cliente;
 *  - al reintentar, el mismo contenido produce la misma ruta y se reutiliza el archivo que
 *    ya está: un huérfano de hoy es la copia buena de mañana;
 *  - y sobre todo, NO se puede borrar por hash. La ruta depende solo del contenido, así que
 *    el mismo archivo aplicado a OTRO lote comparte la copia; borrarla al fallar en este
 *    lote dejaría sin respaldo una conciliación ajena que sí se aplicó.
 *
 * El costo es un archivo de unos pocos KB que nadie referencia. Si algún día conviene
 * recogerlos, la regla segura es borrar solo los que no aparezcan en NINGUNA fila de
 * `ppq_conciliaciones.archivo_path` y que además tengan cierta antigüedad.
 */
final class ArchivoConciliacion
{
    private function __construct(
        public readonly string $contenido,
        public readonly string $nombre,
        public readonly string $hash,
        public readonly string $ruta,
    ) {}

    /**
     * Lee la subida, calcula su huella y deja la copia en disco. No toca la base de datos:
     * si la conciliación falla después, lo único que queda es un archivo huérfano
     * direccionado por su contenido, que el siguiente intento reutiliza tal cual.
     */
    public static function desdeSubida(UploadedFile $archivo): self
    {
        $contenido = (string) file_get_contents($archivo->getRealPath());

        return self::guardarContenido($contenido, $archivo->getClientOriginalName());
    }

    /** Lo mismo con un contenido que no llegó como subida (un TXT recibido por correo). */
    public static function guardarContenido(string $contenido, string $nombre): self
    {
        $hash = hash('sha256', $contenido);
        $ruta = self::directorio().'/'.$hash.'.txt';

        if (! Storage::disk(self::disco())->put($ruta, $contenido)) {
            throw new \RuntimeException('No se pudo guardar la copia del TXT.');
        }

        return new self(
            contenido: $contenido,
            nombre: $nombre,
            hash: $hash,
            ruta: $ruta,
        );
    }

    /**
     * Recarga una copia ya guardada por su huella. Null si no existe o si su contenido ya no
     * da esa huella: lo que se aplica tiene que ser exactamente lo que se revisó.
     */
    public static function desdeCopia(string $hash, string $nombre): ?self
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }
        $ruta = self::directorio().'/'.$hash.'.txt';
        $disco = Storage::disk(self::disco());
        $contenido = $disco->exists($ruta) ? $disco->get($ruta) : null;

        return $contenido !== null && hash('sha256', $contenido) === $hash
            ? self::desdeContenido($contenido, $nombre, $ruta)
            : null;
    }

    /**
     * Construye la referencia SIN escribir en disco. Para pruebas y para el día que el
     * archivo llegue por otra vía (correo, portal) y ya esté guardado.
     */
    public static function desdeContenido(string $contenido, string $nombre, ?string $ruta = null): self
    {
        $hash = hash('sha256', $contenido);

        return new self(
            contenido: $contenido,
            nombre: $nombre,
            hash: $hash,
            ruta: $ruta ?? self::directorio().'/'.$hash.'.txt',
        );
    }

    /** ¿La copia sigue estando donde dice la bitácora? */
    public function existeCopia(): bool
    {
        return Storage::disk(self::disco())->exists($this->ruta);
    }

    private static function disco(): string
    {
        return (string) config('dte.storage.disk', 'local');
    }

    private static function directorio(): string
    {
        return trim((string) config('ppq.conciliacion.storage_dir', 'ppq/conciliaciones'), '/');
    }
}
