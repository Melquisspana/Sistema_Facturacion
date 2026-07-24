<?php

namespace App\Policies;

use App\Models\Dte;
use App\Models\User;

class DtePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('dte.ver');
    }

    public function view(User $user, Dte $dte): bool
    {
        return $user->can('dte.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('dte.gestionar');
    }

    /**
     * Revertir un CCF con una Nota de Crédito por devolución total (crea un borrador con
     * todas las líneas). Requiere el MISMO permiso operativo que crear un DTE
     * (`dte.gestionar`) —no disponible a perfiles de solo lectura— y que el documento sea
     * un CCF ACEPTADO REALMENTE por Hacienda (no basta el estado local: sello real, no mock).
     * La reversión nunca emite: deja un borrador para revisión manual.
     */
    public function revertirConNotaCredito(User $user, Dte $dte): bool
    {
        return $user->can('dte.gestionar')
            && $dte->tipo_dte === \App\Enums\TipoDte::CreditoFiscal
            && $dte->estado === \App\Enums\EstadoDte::Aceptado
            && $dte->aceptadoRealmentePorMh();
    }

    /** Solo se edita un borrador, y solo con permiso de gestión. */
    public function update(User $user, Dte $dte): bool
    {
        return $user->can('dte.gestionar') && $dte->esEditable();
    }

    /** Solo se elimina un borrador, y solo con permiso de gestión. */
    public function delete(User $user, Dte $dte): bool
    {
        return $user->can('dte.gestionar') && $dte->esEditable();
    }

    /** Anulación interna: solo con permiso de gestión y solo un documento GENERADO. */
    public function anular(User $user, Dte $dte): bool
    {
        return $user->can('dte.gestionar') && $dte->estado === \App\Enums\EstadoDte::Generado;
    }

    /**
     * Ver / descargar el JSON oficial preliminar ya generado: solo gestores
     * (administrador/facturación) y solo si el documento tiene un JSON generado.
     * No firma ni transmite: es solo lectura del archivo local.
     */
    public function verJson(User $user, Dte $dte): bool
    {
        return $user->can('dte.emitir') && filled($dte->json_generado_path);
    }

    /**
     * Ver / descargar el JWS firmado localmente: solo gestores (administrador/
     * facturación) y solo si el documento tiene json_firmado_path. Es solo lectura
     * del archivo local; no transmite ni cambia nada.
     */
    public function verJsonFirmado(User $user, Dte $dte): bool
    {
        return $user->can('dte.emitir') && filled($dte->json_firmado_path);
    }

    /**
     * Ver el panel de ESTADO TÉCNICO / preflight y ejecutar el dry-run visual: solo
     * gestores (administrador/facturación). Es solo diagnóstico: no transmite, no
     * cambia estado, no guarda sello, no muestra secretos. Consulta/contador no lo ven.
     */
    public function verEstadoTecnico(User $user, Dte $dte): bool
    {
        return $user->can('dte.emitir');
    }

    /**
     * Generar el JSON oficial preliminar: solo gestores (administrador/facturación),
     * solo documentos GENERADOS y que aún NO tengan JSON (no se regenera desde la UI).
     * No firma ni transmite: solo produce el archivo local validado contra el schema.
     */
    public function generarJson(User $user, Dte $dte): bool
    {
        return $user->can('dte.emitir')
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
        return $user->can('dte.enviar-correo') && ! $dte->esEditable();
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
        return $user->can('dte.emitir')
            && in_array($dte->estado, [\App\Enums\EstadoDte::Generado, \App\Enums\EstadoDte::Firmado], true)
            && blank($dte->sello_recepcion)
            && ! $dte->esAnulado();
    }

    /** Tipos habilitados para la acción REAL "Generar y transmitir producción". */
    private const TIPOS_EMISION_PRODUCCION = [
        \App\Enums\TipoDte::CreditoFiscal,
        \App\Enums\TipoDte::Factura,
        \App\Enums\TipoDte::FacturaExportacion,
    ];

    /**
     * Acción REAL "Generar y transmitir producción". A diferencia de firmarTransmitir,
     * admite arrancar desde BORRADOR (genera primero). Gestores, tipo habilitado
     * (CCF/Factura/FEX), no aceptado/anulado, y el DOCUMENTO debe ser ambiente
     * producción (01): un documento de pruebas (00) nunca es candidato a esta acción
     * real, sin importar el ambiente configurado del sistema (defensa adicional; el
     * preflight específico del tipo solo mira el ambiente del SISTEMA, no el del
     * documento). La emisión real la blindan además el preflight específico del tipo,
     * la barrera de confirmación y la frase EMITIR PRODUCCION (en el controlador).
     */
    public function generarTransmitirProduccion(User $user, Dte $dte): bool
    {
        return $user->can('dte.emitir')
            && in_array($dte->tipo_dte, self::TIPOS_EMISION_PRODUCCION, true)
            && in_array($dte->estado, [\App\Enums\EstadoDte::Borrador, \App\Enums\EstadoDte::Generado, \App\Enums\EstadoDte::Firmado], true)
            && blank($dte->sello_recepcion)
            && ! $dte->esAnulado()
            && $dte->ambiente === \App\Enums\AmbienteHacienda::Produccion;
    }

    /**
     * Ver el bloque de invalidación (evento anulardte) en la ficha: panel de candados,
     * dry-run visual y, si aplica, la evidencia del evento mock. Solo gestores (permiso
     * `dte.invalidar`) y para cualquier documento en estado ACEPTADO (real o mock) o que ya
     * tenga un evento registrado. Es SOLO LECTURA: mostrar la tarjeta no habilita transmitir.
     * La transmisión real la sigue candando {@see transmitirInvalidacion()} + el servicio
     * (que exige aceptación REAL por el MH): en un documento mock la tarjeta aparece pero
     * con el formulario deshabilitado y las razones del bloqueo, en vez de ocultarse.
     */
    public function verInvalidacion(User $user, Dte $dte): bool
    {
        return $user->can('dte.invalidar')
            && ($dte->estado === \App\Enums\EstadoDte::Aceptado || $dte->tieneEventoInvalidacion());
    }

    /**
     * Firmar el evento de invalidación en MODO MOCK (Fase C): persiste columnas dedicadas
     * SIN transmitir a Hacienda ni cambiar el estado del DTE. Solo gestores, solo un DTE
     * aceptado realmente por el MH y sin evento de invalidación previo (no se invalida dos
     * veces). La transmisión REAL a apitest queda fuera de la UI (solo por consola).
     */
    public function invalidarMock(User $user, Dte $dte): bool
    {
        return $user->can('dte.invalidar')
            && $dte->aceptadoRealmentePorMh()
            && ! $dte->tieneEventoInvalidacion()
            && ! $dte->estaProtegidoComoEvidencia();
    }

    /**
     * Transmitir el evento de invalidación REAL a Hacienda desde la web (evento anulardte).
     * Mismas guardas de CANDIDATURA que el mock: permiso `dte.invalidar`, DTE aceptado
     * realmente por el MH, sin evento previo y no protegido como evidencia. Los candados
     * DUROS de la transmisión real (flags de entorno, firma real, endpoint/ambiente, frase
     * exacta, NC relacionada, doble invalidación) los RE-valida en cada intento el servicio
     * {@see \App\Services\Dte\DteInvalidacionService::evaluarCandados()} y la frase la valida
     * el Form Request en servidor: esta ability solo decide si el bloque es aplicable.
     */
    public function transmitirInvalidacion(User $user, Dte $dte): bool
    {
        return $user->can('dte.invalidar')
            && $dte->aceptadoRealmentePorMh()
            && ! $dte->tieneEventoInvalidacion()
            && ! $dte->estaProtegidoComoEvidencia();
    }
}
