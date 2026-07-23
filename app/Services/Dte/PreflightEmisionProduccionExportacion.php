<?php

namespace App\Services\Dte;

use App\Enums\TipoCliente;
use App\Enums\TipoItemExportacion;
use App\Models\CatalogoMh;
use App\Models\Dte;
use App\Services\Dte\Concerns\ChecksProduccionComunes;
use App\Support\Dte\CorrelativoSistemaNuevo;

/**
 * Preflight de READINESS (checklist, NO habilita nada) para Factura de
 * exportación (tipo 11). Igual que PreflightEmisionProduccionFactura: archivo
 * separado, NO toca PreflightEmisionProduccion.php (CCF), NO está conectado a
 * ningún botón ni acción de emisión real. Los guards que bloquean FEX en
 * producción (DteController::firmarTransmitir, dte:firmar, dte:transmitir)
 * siguen intactos y bloqueando, sin leer nada de esta clase.
 */
class PreflightEmisionProduccionExportacion
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
            $this->checkClienteExportacion($dte),
            $this->checkDocumentoNoProvisional($dte),
            $this->checkPaisActividad($dte),
            $this->checkTipoItemExpor($dte),
            $this->checkCatalogo('recinto_fiscal', 'Recinto fiscal (CAT-027)', $dte->recinto_fiscal, '027'),
            $this->checkCatalogo('tipo_regimen', 'Tipo de régimen (CAT-033)', $dte->tipo_regimen, '033'),
            $this->checkCatalogo('regimen', 'Régimen (CAT-028)', $dte->regimen, '028'),
            $this->checkCatalogo('incoterms', 'Incoterm (CAT-031)', $dte->cod_incoterms, '031'),
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

    /**
     * Resumen para el modal de confirmación (solo lectura). Análogo a resumen() de
     * CCF pero sin campos que no aplican a FEX (sala/retención/correlativo externo);
     * incluye flete/seguro por ser específicos de exportación.
     *
     * @return array<string, mixed>
     */
    public function resumen(Dte $dte): array
    {
        $documentoActual = $this->documentoActual($dte, '11');

        return array_merge($this->infoGeneral($dte), [
            'cliente' => $dte->cliente?->nombre,
            'total_pagar' => (float) $dte->total_pagar,
            'documento_actual' => $documentoActual,
            'proximo_futuro' => $documentoActual + 1,
            'flete' => (float) $dte->flete,
            'seguro' => (float) $dte->seguro,
        ]);
    }

    /** Correlativo de Exportación en producción del SISTEMA NUEVO (P002): existe y está activo. */
    private function checkCorrelativo(): array
    {
        $corr = CorrelativoSistemaNuevo::correlativo('11', '01');
        $ok = $corr !== null;

        return $this->check('correlativo', 'Correlativo Exportación producción (P002) existe', $ok,
            $ok ? "activo, último número {$corr->ultimo_numero}, próximo ".($corr->ultimo_numero + 1) : 'no hay correlativo de producción para tipo 11 en el punto de venta predeterminado (P002)');
    }

    /**
     * El Cliente DTE no puede tener el documento provisional (valor centinela) al
     * momento de emitir: evita que una FEX real salga con un documento inventado.
     */
    private function checkDocumentoNoProvisional(Dte $dte): array
    {
        $ok = ! ($dte->cliente?->tieneDocumentoProvisional() ?? false);

        return $this->check('documento_no_provisional', 'Cliente sin documento provisional', $ok,
            $ok ? 'documento real' : 'el Cliente DTE tiene un documento provisional. Ingrese el documento real antes de continuar.');
    }

    private function checkClienteExportacion(Dte $dte): array
    {
        $ok = $dte->cliente?->tipo_cliente === TipoCliente::Exportacion;

        return $this->check('cliente_exportacion', 'Cliente de tipo exportación', $ok,
            $ok ? 'cliente de exportación' : 'falta cliente o no es de tipo exportación');
    }

    private function checkPaisActividad(Dte $dte): array
    {
        $cliente = $dte->cliente;
        $ok = $cliente && filled($cliente->pais_id) && filled($cliente->actividad_economica_id);

        return $this->check('pais_actividad', 'País y actividad económica del receptor', $ok,
            $ok ? 'país y actividad presentes' : 'falta país (CAT-020) o actividad económica (CAT-019) del receptor');
    }

    private function checkTipoItemExpor(Dte $dte): array
    {
        $ok = TipoItemExportacion::tryFrom((int) $dte->tipo_item_expor) !== null;

        return $this->check('tipo_item_expor', 'Tipo de ítem exportación (bienes/servicios)', $ok,
            $ok ? TipoItemExportacion::from((int) $dte->tipo_item_expor)->label() : 'valor inválido o ausente');
    }

    /** Check genérico de "código guardado en el DTE existe en catalogos_mh". */
    private function checkCatalogo(string $clave, string $label, ?string $codigo, string $cat): array
    {
        if (blank($codigo)) {
            return $this->check($clave, $label, false, 'no capturado en el DTE');
        }

        $valor = CatalogoMh::where('cat', $cat)->where('codigo', $codigo)->value('valor');

        return $this->check($clave, $label, $valor !== null, $valor !== null ? "{$codigo} — {$valor}" : "código '{$codigo}' no existe en CAT-{$cat}");
    }
}
