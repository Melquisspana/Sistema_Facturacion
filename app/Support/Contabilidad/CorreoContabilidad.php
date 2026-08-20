<?php

namespace App\Support\Contabilidad;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;

/**
 * FUENTE ÚNICA del correo de contabilidad y de la preferencia de copia oculta.
 *
 * Hasta ahora, "¿a qué dirección va esto?" se respondía en cuatro sitios con
 * cuatro copias del mismo bloque:
 *
 *   PaqueteContabilidadController::correoContabilidad()
 *   DocumentoRecibidoController::correoContabilidad()
 *   ReporteContadoraController::correoContabilidad()
 *   EnviarDteCorreo::correoContabilidad()
 *
 * Cuatro copias de tres líneas no parecen un problema hasta que se comprueba que
 * NO eran idénticas: tres normalizaban a minúsculas y la del paquete mensual no.
 * El mismo correo configurado podía viajar en mayúsculas al paquete de la
 * contadora y en minúsculas al resto, y una futura comparación entre esos valores
 * habría fallado sin motivo visible. Acá se normaliza UNA vez, igual para todos.
 *
 * La validación se mantiene tal cual estaba: una dirección inválida devuelve null
 * y cada consumidor decide qué hacer (avisar, no encolar, no adjuntar la copia).
 * Nunca se envía nada desde esta clase: solo resuelve el destinatario.
 */
class CorreoContabilidad
{
    /** Dirección configurada, normalizada y válida; null si no hay o no sirve. */
    public function direccion(): ?string
    {
        $correo = strtolower(trim((string) Ajustes::texto('contabilidad.correo')));

        return $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false
            ? $correo
            : null;
    }

    /** ¿El administrador pidió mandar copia oculta a contabilidad? */
    public function enviarCopia(): bool
    {
        return Ajustes::bool('contabilidad.enviar_copia', false);
    }

    /**
     * Destinatario de la copia OCULTA (BCC) de un DTE, o null si no aplica.
     * Exige las dos condiciones: preferencia activa Y dirección válida.
     */
    public function copiaOculta(): ?string
    {
        return $this->enviarCopia() ? $this->direccion() : null;
    }

    public function configurado(): bool
    {
        return $this->direccion() !== null;
    }

    /** De dónde se está resolviendo la dirección. Para diagnóstico y pantallas. */
    public function fuente(): FuenteAjuste
    {
        return Ajustes::fuente('contabilidad.correo');
    }
}
