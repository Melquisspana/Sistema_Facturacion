<?php

namespace App\Ajustes\Integraciones;

/**
 * Segundo buzón IMAP de compras, OPCIONAL y TEMPORAL.
 *
 * Existe para los cambios de correo: durante un tiempo los proveedores mandan sus DTE
 * a dos direcciones (la vieja y la nueva) y hay que leer las dos sin perder ninguna.
 * Cuando la vieja se deja, se vacía el servidor y el sistema vuelve a leer uno.
 *
 * Se resuelve por el Centro de Configuración igual que el principal: lo guardado en
 * Configuración › Integraciones › Segundo buzón manda, y sin nada guardado sale del
 * .env (`DOCUMENTOS_RECIBIDOS_MAIL2_*`). La contraseña va cifrada y solo por la pantalla
 * de secretos. Sin servidor configurado está apagado.
 *
 * Hereda de la configuración principal para que el lector IMAP y el sincronizador lo
 * usen sin enterarse de que es otro buzón: mismas claves, mismo `paraLector()`. El
 * tamaño de página y la carpeta de adjuntos siguen siendo los del principal.
 */
class ConfiguracionBuzonAdicional extends ConfiguracionDocumentosRecibidos
{
    /** Clave del contenedor para el lector de este buzón (IMAP o nulo si está apagado). */
    public const LECTOR = 'documentos_recibidos.buzon_adicional';

    /** Servicio comprobado, para el historial de verificaciones. Aparte del principal. */
    public const CLAVE_VERIFICACION = 'imap_adicional';

    public function nombre(): string
    {
        return 'segundo buzón de compras';
    }

    public function driver(): string
    {
        return filled($this->host()) ? 'imap' : 'none';
    }

    public function host(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.buzon_adicional.host', '');
    }

    public function puerto(): int
    {
        return (int) $this->ajustes->entero('documentos_recibidos.buzon_adicional.port', 993);
    }

    public function cifrado(): string
    {
        return strtolower((string) $this->ajustes->texto('documentos_recibidos.buzon_adicional.encryption', 'ssl'));
    }

    public function usuario(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.buzon_adicional.username', '');
    }

    /** Contraseña del buzón. SOLO para abrir la conexión; nunca a una vista ni a un log. */
    public function password(): string
    {
        return (string) $this->ajustes->secretoParaRuntime('documentos_recibidos.buzon_adicional.password');
    }

    public function passwordConfigurada(): bool
    {
        return $this->ajustes->estaConfigurado('documentos_recibidos.buzon_adicional.password');
    }

    public function carpeta(): string
    {
        return (string) $this->ajustes->texto('documentos_recibidos.buzon_adicional.folder', 'INBOX');
    }

    /** Sin filtro propio: se revisa toda la carpeta, como hasta ahora. */
    public function busqueda(): string
    {
        return 'ALL';
    }

    public function timeout(): int
    {
        return 15;
    }

    /** Las filas viejas sin identidad salieron del principal: sus UID no son de acá. */
    public function reconoceFilasSinIdentidad(): bool
    {
        return false;
    }
}
