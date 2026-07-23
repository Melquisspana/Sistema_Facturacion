<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Models\Configuracion;
use App\Models\Dte;
use App\Services\Dte\Concerns\ChecksProduccionComunes;
use App\Support\Dinero;
use App\Support\Dte\CorrelativoSistemaNuevo;

/**
 * Preflight de READINESS (checklist, NO habilita nada) para Factura consumidor
 * final (tipo 01). Análogo a PreflightEmisionProduccion (CCF) pero en un archivo
 * separado: ese archivo NO se toca, sigue siendo el único gate real del botón
 * "Generar y transmitir producción" (CCF-only).
 *
 * Este preflight es SOLO DIAGNÓSTICO: no está conectado a ningún botón ni acción
 * de emisión real. Aunque devuelva `puede=true`, los guards que bloquean Factura
 * en producción (DteController::firmarTransmitir, dte:firmar, dte:transmitir)
 * siguen intactos y bloqueando — no leen nada de esta clase.
 */
class PreflightEmisionProduccionFactura
{
    use ChecksProduccionComunes;

    public function __construct(
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionService $transmision,
    ) {}

    /**
     * @return array{puede: bool, checks: array<int, array{clave: string, label: string, ok: bool, detalle: string}>, faltantes: array<int, string>}
     */
    public function evaluar(Dte $dte): array
    {
        $ambiente = $this->checkAmbiente();
        $checks = [
            $ambiente,
            $this->checkCorrelativo(),
            $this->checkWorker(),
            $this->checkBackup(),
            $this->checkFirmador($ambiente['ok']),
            $this->checkCandados(),
            $this->checkCredenciales(),
            $this->checkDocumentoCompleto($dte),
            $this->checkReceptorObligatorio($dte),
            $this->checkCorreoNoAutomatico(),
        ];

        $faltantes = array_values(array_map(
            fn ($c) => $c['label'],
            array_filter($checks, fn ($c) => ! $c['ok'])
        ));

        return [
            'puede' => $faltantes === [],
            'checks' => $checks,
            'faltantes' => $faltantes,
        ];
    }

    /** Correlativo de Factura en producción del SISTEMA NUEVO (P002): existe y está activo. */
    private function checkCorrelativo(): array
    {
        $corr = CorrelativoSistemaNuevo::correlativo('01', '01');
        $ok = $corr !== null;

        return $this->check('correlativo', 'Correlativo Factura producción (P002) existe', $ok,
            $ok ? "activo, último número {$corr->ultimo_numero}, próximo ".($corr->ultimo_numero + 1) : 'no hay correlativo de producción para tipo 01 en el punto de venta predeterminado (P002)');
    }

    /** Documento completo: líneas y total > 0 (el cliente es OPCIONAL: consumidor final). */
    private function checkDocumentoCompleto(Dte $dte): array
    {
        $ok = $dte->tipo_dte === TipoDte::Factura
            && $dte->lineas->isNotEmpty()
            && (float) $dte->total_pagar > 0;

        return $this->check('documento', 'Documento completo (productos, total > 0)', $ok,
            $ok ? 'listo' : 'faltan productos/total o no es Factura');
    }

    /**
     * Receptor obligatorio si el total SUPERA el umbral configurado (estricto: "mayor
     * que", no "mayor o igual"). Reutiliza la MISMA config que ya exige
     * ValidacionPreJsonService, no duplica el monto.
     */
    private function checkReceptorObligatorio(Dte $dte): array
    {
        $umbral = config('dte.factura_consumidor_final.receptor_obligatorio_desde');
        if ($umbral === null) {
            return $this->check('receptor_umbral', 'Receptor identificado si total > umbral', true, 'umbral no configurado (sin exigencia)');
        }

        $superaUmbral = Dinero::comparar((string) $dte->total_pagar, (string) $umbral) > 0;
        $ok = ! $superaUmbral || $dte->cliente_id !== null;

        return $this->check('receptor_umbral', 'Receptor identificado si total > $'.number_format((float) $umbral, 2), $ok,
            $ok
                ? ($superaUmbral ? 'receptor identificado' : 'total no supera el umbral')
                : 'total $'.number_format((float) $dte->total_pagar, 2).' supera el umbral y no hay receptor identificado');
    }

    /**
     * Resumen para el modal de confirmación (solo lectura). Consumidor final SIN
     * identificar es un caso válido (cliente null); análogo a resumen() de CCF pero
     * sin campos que no aplican a Factura (sala/OC/retención/correlativo externo).
     *
     * @return array<string, mixed>
     */
    public function resumen(Dte $dte): array
    {
        $documentoActual = $this->documentoActual($dte, '01');

        return array_merge($this->infoGeneral($dte), [
            'cliente' => $dte->cliente?->nombre ?? 'Consumidor final (sin identificar)',
            'total_pagar' => (float) $dte->total_pagar,
            'documento_actual' => $documentoActual,
            'proximo_futuro' => $documentoActual + 1,
        ]);
    }

    /**
     * El envío automático de correo al aceptar (correo.auto_envio) debe seguir siendo
     * una decisión manual explícita para Factura, no heredada en silencio del flujo
     * de CCF. Si está activo, el check queda en rojo con el detalle como advertencia
     * clara (no oculta el estado, solo marca que requiere revisión consciente).
     */
    private function checkCorreoNoAutomatico(): array
    {
        $autoEnvio = Configuracion::getBool('correo.auto_envio', false);

        return $this->check('correo_auto', 'Correo automático desactivado (o revisado a propósito)', ! $autoEnvio,
            $autoEnvio
                ? 'ADVERTENCIA: correo.auto_envio está ACTIVO — Factura aceptada dispararía correo automático sin haberlo probado específicamente para este tipo'
                : 'correo.auto_envio=false');
    }
}
