<?php

namespace App\Policies;

use App\Models\Dte;
use App\Models\User;

class DtePolicy
{
    /** Roles que pueden gestionar documentos. */
    private const GESTORES = ['administrador', 'facturacion'];

    /** Roles que pueden ver/listar. */
    private const LECTORES = ['administrador', 'facturacion', 'consulta', 'contador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::LECTORES);
    }

    public function view(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::LECTORES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }

    /** Solo se edita un borrador, y solo por un gestor. */
    public function update(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && $dte->esEditable();
    }

    /** Solo se elimina un borrador, y solo por un gestor. */
    public function delete(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && $dte->esEditable();
    }

    /** Anulación interna: solo un gestor y solo un documento GENERADO. */
    public function anular(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && $dte->estado === \App\Enums\EstadoDte::Generado;
    }

    /**
     * Ver / descargar el JSON oficial preliminar ya generado: solo gestores
     * (administrador/facturación) y solo si el documento tiene un JSON generado.
     * No firma ni transmite: es solo lectura del archivo local.
     */
    public function verJson(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && filled($dte->json_generado_path);
    }

    /**
     * Ver / descargar el JWS firmado localmente: solo gestores (administrador/
     * facturación) y solo si el documento tiene json_firmado_path. Es solo lectura
     * del archivo local; no transmite ni cambia nada.
     */
    public function verJsonFirmado(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && filled($dte->json_firmado_path);
    }

    /**
     * Ver el panel de ESTADO TÉCNICO / preflight y ejecutar el dry-run visual: solo
     * gestores (administrador/facturación). Es solo diagnóstico: no transmite, no
     * cambia estado, no guarda sello, no muestra secretos. Consulta/contador no lo ven.
     */
    public function verEstadoTecnico(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }

    /**
     * Generar el JSON oficial preliminar: solo gestores (administrador/facturación),
     * solo documentos GENERADOS y que aún NO tengan JSON (no se regenera desde la UI).
     * No firma ni transmite: solo produce el archivo local validado contra el schema.
     */
    public function generarJson(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES)
            && $dte->estado === \App\Enums\EstadoDte::Generado
            && blank($dte->json_generado_path);
    }

    /**
     * Enviar el documento por correo al cliente (manual): solo gestores y solo si el
     * documento NO es un borrador (ya tiene representación gráfica). Pensado para el
     * CCF aceptado, pero permitido desde generado en adelante. No transmite a Hacienda.
     */
    public function enviarCorreo(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES) && ! $dte->esEditable();
    }

    /**
     * Firmar y transmitir el DTE (acción MANUAL única): solo gestores y solo en un punto
     * válido de entrada al flujo: estado GENERADO (firma + transmisión) o FIRMADO (solo
     * transmisión / reintento), nunca con sello ya recibido, nunca aceptado/rechazado/
     * invalidado ni borrador. La idempotencia fina (saltar firma si ya hay JWS, no
     * retransmitir si hay sello) la refuerzan los servicios; aquí solo se gobierna el acceso.
     */
    public function firmarTransmitir(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES)
            && in_array($dte->estado, [\App\Enums\EstadoDte::Generado, \App\Enums\EstadoDte::Firmado], true)
            && blank($dte->sello_recepcion)
            && ! $dte->esAnulado();
    }

    /**
     * Ver el bloque de invalidación (evento anulardte) en la ficha: panel de candados,
     * dry-run visual y, si aplica, la evidencia del evento mock. Solo gestores y solo si
     * el DTE es candidato (aceptado realmente por el MH) o ya tiene un evento de
     * invalidación registrado (para mostrar la evidencia). Es solo lectura.
     */
    public function verInvalidacion(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES)
            && ($dte->aceptadoRealmentePorMh() || $dte->tieneEventoInvalidacion());
    }

    /**
     * Firmar el evento de invalidación en MODO MOCK (Fase C): persiste columnas dedicadas
     * SIN transmitir a Hacienda ni cambiar el estado del DTE. Solo gestores, solo un DTE
     * aceptado realmente por el MH y sin evento de invalidación previo (no se invalida dos
     * veces). La transmisión REAL a apitest queda fuera de la UI (solo por consola).
     */
    public function invalidarMock(User $user, Dte $dte): bool
    {
        return $user->hasAnyRole(self::GESTORES)
            && $dte->aceptadoRealmentePorMh()
            && ! $dte->tieneEventoInvalidacion();
    }
}
