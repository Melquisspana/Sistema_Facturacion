<?php

namespace App\Ajustes\Integraciones;

use App\Ajustes\Ajustes;

/**
 * Configuración del buzón IMAP de compras, resuelta por el Centro de
 * Configuración (override en base de datos → config/.env → valor por defecto).
 *
 * EXISTE PARA NO TOCAR LA LÓGICA DE COMPRAS. `ImapMailboxClient` armaba su
 * configuración con `config('documentos_recibidos.mail')` en el constructor;
 * ahora la recibe de acá. El lector sigue siendo de SOLO LECTURA —no borra, no
 * mueve, no marca leído— y el parseo, la clasificación y las exclusiones no
 * cambian: solo cambia de dónde salen el servidor y las credenciales.
 *
 * `paraLector()` devuelve el array con las MISMAS claves que tenía
 * `config('documentos_recibidos.mail')`, para que el cliente no tenga que
 * reescribirse ni aprender una forma nueva.
 */
class ConfiguracionDocumentosRecibidos
{
    /** Servicio comprobado, para el historial de verificaciones. */
    public const CLAVE_VERIFICACION = 'imap';

    /** Valor de `encryption` que significa "sin cifrado". */
    public const SIN_CIFRADO = 'ninguna';

    public function __construct(private readonly Ajustes $ajustes) {}

    public function driver(): string
    {
        return strtolower((string) $this->ajustes->texto('documentos_recibidos.driver', 'none'));
    }

    public function lecturaActivada(): bool
    {
        return $this->driver() === 'imap';
    }

    public function host(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.host', '');
    }

    public function puerto(): int
    {
        return (int) $this->ajustes->entero('documentos_recibidos.port', 993);
    }

    public function cifrado(): string
    {
        return strtolower((string) $this->ajustes->texto('documentos_recibidos.encryption', 'ssl'));
    }

    public function usuario(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.username', '');
    }

    /** Contraseña del buzón. SOLO para abrir la conexión; nunca a una vista ni a un log. */
    public function password(): string
    {
        return (string) $this->ajustes->secretoParaRuntime('documentos_recibidos.password');
    }

    public function passwordConfigurada(): bool
    {
        return $this->ajustes->estaConfigurado('documentos_recibidos.password');
    }

    public function carpeta(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.folder', 'INBOX');
    }

    public function busqueda(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.search', 'ALL');
    }

    public function timeout(): int
    {
        return (int) $this->ajustes->entero('documentos_recibidos.timeout', 15);
    }

    public function limite(): int
    {
        return (int) $this->ajustes->entero('documentos_recibidos.limite', 30);
    }

    /** Ruta del servidor: se lee, no se edita desde la aplicación. */
    public function storageDir(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.storage_dir', 'documentos-recibidos');
    }

    /** ¿Hay todo lo necesario para abrir el buzón? Misma condición que usaba el lector. */
    public function completa(): bool
    {
        return $this->lecturaActivada()
            && filled($this->host())
            && filled($this->usuario())
            && $this->passwordConfigurada();
    }

    /**
     * Configuración con la FORMA que espera el lector IMAP: las mismas claves que
     * tenía `config('documentos_recibidos.mail')`.
     *
     * Lleva la contraseña, así que este array no sale de quien va a abrir la
     * conexión. Para pantalla está {@see paraPantalla()}.
     *
     * @return array<string, mixed>
     */
    public function paraLector(): array
    {
        return [
            'driver' => $this->driver(),
            'host' => $this->host(),
            'port' => $this->puerto(),
            // El lector compara contra 'ssl'/'tls' y trata cualquier otra cosa como
            // sin cifrado; se traduce acá para no tener que tocarlo.
            'encryption' => $this->cifrado() === self::SIN_CIFRADO ? '' : $this->cifrado(),
            'username' => $this->usuario(),
            'password' => $this->password(),
            'folder' => $this->carpeta(),
            'search' => $this->busqueda(),
            'timeout' => $this->timeout(),
        ];
    }

    /**
     * Lo mismo, pero publicable: sin contraseña y con el usuario parcialmente
     * tapado.
     *
     * @return array<string, mixed>
     */
    public function paraPantalla(): array
    {
        return [
            'driver' => $this->driver(),
            'host' => $this->host(),
            'port' => $this->puerto(),
            'encryption' => $this->cifrado(),
            'usuario' => $this->usuario(),
            'usuario_parcial' => self::taparCorreo($this->usuario()),
            'password_configurada' => $this->passwordConfigurada(),
            'folder' => $this->carpeta(),
            'search' => $this->busqueda(),
            'timeout' => $this->timeout(),
            'limite' => $this->limite(),
        ];
    }

    /**
     * Deja un correo reconocible sin publicarlo entero: `du••••••@yahoo.com`.
     *
     * Sirve para que quien administra confirme de un vistazo QUÉ buzón está
     * configurado sin que la pantalla reparta una dirección completa. El dominio
     * se conserva porque es lo que identifica el buzón y no es lo sensible.
     */
    public static function taparCorreo(string $correo): string
    {
        if (! str_contains($correo, '@')) {
            return $correo === '' ? '' : mb_substr($correo, 0, 2).'••••';
        }

        [$local, $dominio] = explode('@', $correo, 2);

        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.'••••@'.$dominio;
    }
}
