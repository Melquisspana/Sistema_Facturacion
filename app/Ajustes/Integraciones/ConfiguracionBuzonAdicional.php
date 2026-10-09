<?php

namespace App\Ajustes\Integraciones;

/**
 * Segundo buzón IMAP de compras, OPCIONAL y TEMPORAL.
 *
 * Existe para los cambios de correo: durante un tiempo los proveedores mandan sus DTE
 * a dos direcciones (la vieja y la nueva) y hay que leer las dos sin perder ninguna.
 * Cuando la vieja se deja, se vacían estas variables y el sistema vuelve a leer uno.
 *
 * Vive SOLO en .env (`DOCUMENTOS_RECIBIDOS_MAIL2_*`), no en la pantalla: es un estado
 * de transición, no una opción permanente. Sin servidor configurado está apagado.
 *
 * Hereda de la configuración principal para que el lector IMAP y el sincronizador lo
 * usen sin enterarse de que es otro buzón: mismas claves, mismo `paraLector()`. El
 * tamaño de página y la carpeta de adjuntos siguen siendo los del principal.
 */
class ConfiguracionBuzonAdicional extends ConfiguracionDocumentosRecibidos
{
    /** Clave del contenedor para el lector de este buzón (IMAP o nulo si está apagado). */
    public const LECTOR = 'documentos_recibidos.buzon_adicional';

    public function driver(): string
    {
        return filled($this->host()) ? 'imap' : 'none';
    }

    public function host(): string
    {
        return (string) $this->valor('host', '');
    }

    public function puerto(): int
    {
        return (int) $this->valor('port', 993);
    }

    public function cifrado(): string
    {
        return strtolower((string) $this->valor('encryption', 'ssl'));
    }

    public function usuario(): string
    {
        return (string) $this->valor('username', '');
    }

    /** Contraseña del buzón. SOLO para abrir la conexión; nunca a una vista ni a un log. */
    public function password(): string
    {
        return (string) $this->valor('password', '');
    }

    public function passwordConfigurada(): bool
    {
        return filled($this->password());
    }

    public function carpeta(): string
    {
        return (string) $this->valor('folder', 'INBOX');
    }

    public function busqueda(): string
    {
        return (string) $this->valor('search', 'ALL');
    }

    public function timeout(): int
    {
        return (int) $this->valor('timeout', 15);
    }

    /** Las filas viejas sin identidad salieron del principal: sus UID no son de acá. */
    public function reconoceFilasSinIdentidad(): bool
    {
        return false;
    }

    private function valor(string $clave, mixed $defecto): mixed
    {
        $valor = config('documentos_recibidos.buzon_adicional.'.$clave);

        return $valor === null || $valor === '' ? $defecto : $valor;
    }
}
