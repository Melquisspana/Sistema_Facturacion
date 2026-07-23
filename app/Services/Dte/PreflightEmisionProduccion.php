<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Models\Configuracion;
use App\Models\Dte;
use App\Models\RespaldoEjecucion;
use App\Support\Dte\CorrelativoSistemaNuevo;
use App\Support\WorkerHeartbeat;

/**
 * Preflight de EMISIÓN REAL A PRODUCCIÓN de un CCF. SOLO LECTURA: no emite, no
 * firma, no transmite, no toca correlativos ni .env. Reúne todas las precondiciones
 * de seguridad para habilitar la acción "Generar y transmitir producción".
 *
 * En modo NO producción (dte.ambiente != 01) devuelve `puede=false` de una y NO hace
 * las verificaciones caras (firmador/HTTP): así la ficha del CCF no le pega al
 * firmador mientras se opera en PARALELO SEGURO.
 */
class PreflightEmisionProduccion
{
    public function __construct(
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionService $transmision,
    ) {}

    /**
     * @return array{puede: bool, checks: array<int, array{clave: string, label: string, ok: bool, detalle: string}>, faltantes: array<int, string>}
     */
    public function evaluar(Dte $dte): array
    {
        $checks = [];

        $ambienteOk = (string) config('dte.ambiente') === '01';
        $checks[] = $this->check('ambiente', 'Ambiente producción (01) activo', $ambienteOk,
            $ambienteOk ? 'dte.ambiente=01' : 'dte.ambiente='.config('dte.ambiente').' (no es producción)');

        // Correlativo del SISTEMA NUEVO (punto de venta predeterminado, hoy P002).
        // Conta Portable (P001) es una contingencia INDEPENDIENTE: ya no se compara ni
        // se exige "alinear" contra ella para poder emitir.
        $corr = CorrelativoSistemaNuevo::correlativo('03', '01');
        $proximo = CorrelativoSistemaNuevo::proximoNumero('03', '01');
        $corrOk = $corr !== null;
        $checks[] = $this->check('correlativo', $corrOk ? "Próximo correlativo CCF producción (sistema nuevo) = {$proximo}" : 'Correlativo CCF producción (sistema nuevo)', $corrOk,
            $corrOk ? "P002 · último {$corr->ultimo_numero} · próximo {$proximo}"
                : 'no se encontró correlativo activo de producción para el punto de venta predeterminado (P002)');

        // Worker/cola: diagnóstico combinado (heartbeat + jobs pendientes/fallidos), no
        // solo "cola vacía sí/no". Ver WorkerHeartbeat::diagnostico().
        $worker = WorkerHeartbeat::diagnostico();
        $workerOk = $worker['nivel'] === 'correcto';
        $checks[] = $this->check('worker', 'Worker/cola activo', $workerOk, $worker['mensaje']);

        // Backup del día: registro real del backup diario verificado (respaldo_ejecuciones),
        // no un escaneo de archivos por fecha de modificación.
        $backupOk = RespaldoEjecucion::hayValidoHoy();
        $checks[] = $this->check('backup', 'Backup del día listo', $backupOk,
            $backupOk ? 'existe un backup automático/manual válido de hoy' : 'no hay un backup válido registrado hoy');

        // Firmador activo (health check EN VIVO) — solo en ambiente producción, para no
        // pegarle al firmador en modo paralelo/render normal.
        if ($ambienteOk) {
            $h = $this->firma->healthCheck();
            $firmadorOk = (bool) ($h['disponible'] ?? false);
            $firmadorDet = $firmadorOk ? 'firmador disponible' : 'firmador no responde';
        } else {
            $firmadorOk = false;
            $firmadorDet = 'no evaluado (requiere ambiente producción)';
        }
        $checks[] = $this->check('firmador', 'Firmador activo', $firmadorOk, $firmadorDet);

        // Candados de producción abiertos (transmisión real posible AHORA).
        $candadosOk = (bool) $this->transmision->estadoOperativo()['transmision_real_posible'];
        $checks[] = $this->check('candados', 'Candados de producción correctos', $candadosOk,
            $candadosOk ? 'transmisión real habilitada' : 'transmisión real bloqueada (paralelo/mock/candados)');

        // Credenciales de producción validadas (login-only OK; lo confirma el operador).
        $credOk = Configuracion::getBool('produccion.auth_prod_validada', false);
        $checks[] = $this->check('credenciales', 'Credenciales producción validadas', $credOk,
            $credOk ? 'validadas' : 'sin validar (correr dte:auth-test --prod y confirmar)');

        // Documento completo: CCF con cliente, productos y total > 0.
        $docOk = $dte->tipo_dte === TipoDte::CreditoFiscal
            && $dte->cliente_id !== null
            && $dte->lineas->isNotEmpty()
            && (float) $dte->total_pagar > 0;
        $checks[] = $this->check('documento', 'Documento completo (cliente, productos, total)', $docOk,
            $docOk ? 'listo' : 'faltan cliente/productos/total o no es CCF');

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
     * Resumen para el modal de confirmación (solo lectura). Cliente, sala, OC, número del
     * documento a emitir, totales, retención y correo destino si existe.
     *
     * @return array<string, mixed>
     */
    public function resumen(Dte $dte): array
    {
        // Correlativo del SISTEMA NUEVO (P002). Conta (P001) se muestra aparte, solo
        // informativo, y ya no participa en el cálculo del número de este documento.
        $corr = CorrelativoSistemaNuevo::correlativo('03', '01');
        $interno = (int) ($corr?->ultimo_numero ?? 0);
        $externo = (int) (Configuracion::get('produccion.ultimo_ccf_externo') ?? 1093);

        // Número del documento que ESTA acción va a emitir: si el CCF ya fue generado (ya
        // tiene numeroControl reservado), es el SUYO propio — no "interno + 1", porque
        // ese número ya fue consumido cuando se generó. Si todavía es borrador, sí es el
        // próximo que el correlativo de P002 asignará al generarlo ahora.
        $documentoActual = $dte->numero_control
            ? (int) preg_replace('/\D+/', '', substr($dte->numero_control, -15))
            : $interno + 1;

        return [
            'cliente' => $dte->cliente?->nombre,
            'sala' => $dte->clienteSucursal?->nombre,
            'oc' => $dte->numero_orden_compra,
            'operativo_ultimo' => $interno,
            // Informativo únicamente (Conta Portable, contingencia independiente):
            // ya NO se usa para calcular documento_actual/proximo_futuro.
            'externo_ultimo' => $externo,
            'documento_actual' => $documentoActual,
            'proximo_futuro' => $documentoActual + 1,
            // Compat: antes significaba siempre "operativo + 1"; ahora es el número real
            // de ESTE documento (coincide con documento_actual).
            'proximo_numero' => $documentoActual,
            'total_gravado' => (float) $dte->total_gravado,
            'iva' => (float) $dte->iva,
            'retencion' => (float) $dte->iva_retenido,
            'aplica_retencion' => (bool) $dte->aplica_retencion,
            'total_pagar' => (float) $dte->total_pagar,
            'correo_destino' => $dte->clienteSucursal?->correo ?: $dte->cliente?->correo,
            // Campos generales (compartidos con el resumen de Factura/FEX) para que el
            // modal de confirmación pueda mostrarlos igual sin importar el tipo de DTE.
            'tipo_dte' => $dte->tipo_dte->label(),
            'ambiente' => $dte->ambiente->value,
            'numero_control' => $dte->numero_control,
            'url_efectiva' => (string) config('dte.transmision.url_base'),
            'certificado_esperado' => (string) config('dte.transmision.ambiente') === 'produccion' ? 'Producción' : 'Pruebas',
        ];
    }

    /**
     * @return array{clave: string, label: string, ok: bool, detalle: string}
     */
    private function check(string $clave, string $label, bool $ok, string $detalle): array
    {
        return ['clave' => $clave, 'label' => $label, 'ok' => $ok, 'detalle' => $detalle];
    }
}
