<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Cobros\AuditorEnviados\ResultadoAuditoriaEnviados;
use App\Services\Cobros\AuditorEnviadosService;
use App\Services\Ppq\GmailClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Auditoría de SOLO LECTURA de los CCF/NC que salieron por Gmail (correos ENVIADOS)
 * para un cliente, en un rango de fechas. Es el diagnóstico previo a cualquier
 * importación masiva al seguimiento de cobros: cuenta qué apareció, qué no se pudo
 * leer y qué NC declaran un CCF que este barrido no vio.
 *
 * ═══════════════ No modifica documentos ni mensajes ═══════════════
 *
 * No crea, actualiza ni borra documentos de Cobros; no marca, etiqueta ni mueve
 * mensajes de Gmail. GmailClient puede renovar su token OAuth cifrado en la cuenta
 * conectada. No desbloquea CCF externos ni afirma que un CCF "no tiene NC": eso lo
 * decide la preparación de "quedan", que exige verificación explícita.
 *
 * ═══════════════════════════ Puede quedar truncado ═══════════════════════════
 *
 * Si el rango o el volumen supera `--limite`, el resultado es PARCIAL y se avisa
 * explícitamente. Nunca se informa cobertura completa cuando no se recorrió todo.
 */
class CobrosAuditarEnviadosCommand extends Command
{
    protected $signature = 'cobros:auditar-enviados
        {--cliente= : ID del cliente a auditar}
        {--desde= : Fecha inicial YYYY-MM-DD}
        {--hasta= : Fecha final YYYY-MM-DD}
        {--limite=500 : Máximo de correos a revisar en esta corrida}';

    protected $description = 'Audita (solo lectura) los CCF/NC enviados por Gmail a un cliente en un rango de fechas';

    public function handle(GmailClient $gmail, AuditorEnviadosService $auditor): int
    {
        $errores = $this->validarOpciones();
        if ($errores !== []) {
            foreach ($errores as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $cliente = Cliente::find((int) $this->option('cliente'));
        if ($cliente === null) {
            $this->error('No existe el cliente id '.$this->option('cliente').'.');

            return self::FAILURE;
        }

        if (! $gmail->disponible()) {
            $this->error('Gmail no está disponible: revisá la conexión en Configuración → Integraciones. No se auditó nada.');

            return self::FAILURE;
        }

        $desde = Carbon::createFromFormat('Y-m-d', (string) $this->option('desde'))->startOfDay();
        $hasta = Carbon::createFromFormat('Y-m-d', (string) $this->option('hasta'))->startOfDay();
        $limite = (int) $this->option('limite');

        $this->line("Cliente: {$cliente->nombre} (id {$cliente->id})");
        $this->line("Rango: {$desde->format('Y-m-d')} a {$hasta->format('Y-m-d')} · límite {$limite} correo(s)");
        $this->line('Consulta Gmail: '.$auditor->query($desde, $hasta));
        $this->warn('SOLO LECTURA DE DOCUMENTOS: no incorpora CCF/NC ni cambia mensajes; la conexión puede renovar su token OAuth.');
        $this->newLine();

        try {
            $resultado = $auditor->auditar($cliente, $desde, $hasta, $limite);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->mostrar($resultado);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function validarOpciones(): array
    {
        $validador = Validator::make($this->options(), [
            'cliente' => ['required', 'integer', 'min:1'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'limite' => ['required', 'integer', 'min:1'],
        ]);

        return $validador->fails() ? $validador->errors()->all() : [];
    }

    private function mostrar(ResultadoAuditoriaEnviados $r): void
    {
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Correos revisados (únicos)', $r->correosRevisados],
                ['Correos repetidos en el barrido (descartados)', $r->correosDuplicados],
                ['Correos sin JSON legible', $r->correosSinJsonLegible],
                ['Correos con receptor distinto de este cliente', $r->correosReceptorDistinto],
                ['Correos con receptor no identificable (sin NIT en el JSON)', $r->correosReceptorNoIdentificable],
                ['DTE del cliente con tipo distinto de 03/05', $r->dtesTipoDistinto],
                ['DTE del cliente incompletos (falta número/código)', $r->dtesIncompletos],
                ['CCF (03) del cliente, completos', $r->ccf],
                ['NC (05) del cliente, completas', $r->nc],
                ['NC sin CCF relacionado identificable en su JSON', $r->ncSinRelacion],
                ['DTE repetidos en más de un correo', $r->dtesRepetidosEnOtroCorreo],
            ],
        );

        if ($r->ncSinCcfEnBarrido !== []) {
            $this->warn(sprintf(
                '%d código(s) de CCF que alguna NC declara como relacionado y que este barrido NO vio '.
                '(no confirma que el CCF no exista: puede estar fuera del rango o del límite pedido):',
                count($r->ncSinCcfEnBarrido),
            ));
            foreach ($r->ncSinCcfEnBarrido as $codigo) {
                $this->line('  - '.$codigo);
            }
        }

        if ($r->codigosDuplicados !== []) {
            $this->line(sprintf('%d código(s) de generación visto(s) en más de un correo distinto (reenvíos).', count($r->codigosDuplicados)));
        }

        $this->newLine();

        if ($r->truncado) {
            $this->warn('TRUNCADO: el barrido NO cubrió todo el rango pedido (se alcanzó --limite u otro tope de Gmail). '.
                'Este resultado es PARCIAL; no lo tomes como cobertura completa del rango.');
        } else {
            $this->info('El barrido cubrió el rango completo pedido, dentro de lo que Gmail devolvió para esa consulta.');
        }

        $this->comment('El sello del JSON es un indicio, no una comprobación en línea de aceptación ante el MH.');
    }
}
