<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Cobros\ImportadorEnviados\ResultadoImportacionEnviados;
use App\Services\Cobros\ImportadorEnviadosService;
use App\Services\Ppq\GmailClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Incorpora al seguimiento de cobros los CCF/NC de contabilidad que salieron por la
 * cuenta Gmail conectada, desde el JSON adjunto a los correos ENVIADOS a un cliente.
 *
 * ═══════════════════════ Ensayo en seco por defecto ═══════════════════════
 *
 * Sin `--aplicar` recorre, clasifica y cuenta exactamente lo mismo, sin escribir una
 * fila. Con `--aplicar` da de alta los documentos nuevos, registra su procedencia y las
 * relaciones NC→CCF que declara cada nota. Es idempotente: repetirla no duplica nada.
 *
 * ═══════════════════════ Lo que no hace ═══════════════════════
 *
 * No modifica mensajes de Gmail (la conexión puede renovar su token OAuth), no pisa
 * estados de pago, presentación, observaciones ni albaranes, y no importa DTE de este
 * sistema ni JSON sin sello. No desbloquea la exportación de quedan: que el barrido no
 * encuentre NC para un CCF no demuestra que no las tenga.
 */
class CobrosImportarEnviadosCommand extends Command
{
    protected $signature = 'cobros:importar-enviados
        {--cliente= : ID del cliente}
        {--desde= : Fecha inicial YYYY-MM-DD}
        {--hasta= : Fecha final YYYY-MM-DD}
        {--limite=500 : Máximo de correos a revisar en esta corrida}
        {--aplicar : Escribe de verdad. Sin esto solo enumera lo que haría}';

    protected $description = 'Importa al seguimiento de cobros los CCF/NC enviados por Gmail con JSON (en seco por defecto)';

    public function handle(GmailClient $gmail, ImportadorEnviadosService $importador): int
    {
        $validador = Validator::make($this->options(), [
            'cliente' => ['required', 'integer', 'min:1'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'limite' => ['required', 'integer', 'min:1'],
        ]);

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
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
            $this->error('Gmail no está disponible: revisá la conexión en Configuración → Integraciones. No se importó nada.');

            return self::FAILURE;
        }

        $desde = Carbon::createFromFormat('Y-m-d', (string) $this->option('desde'))->startOfDay();
        $hasta = Carbon::createFromFormat('Y-m-d', (string) $this->option('hasta'))->startOfDay();
        $limite = (int) $this->option('limite');
        $aplicar = (bool) $this->option('aplicar');

        $this->line("Cliente: {$cliente->nombre} (id {$cliente->id})");
        $this->line("Rango: {$desde->format('Y-m-d')} a {$hasta->format('Y-m-d')} · límite {$limite} correo(s)");
        $this->line('Consulta Gmail: '.$importador->query($desde, $hasta));
        $aplicar
            ? $this->warn('APLICANDO: se incorporan documentos, procedencias y relaciones NC→CCF.')
            : $this->warn('ENSAYO EN SECO: no se escribe nada. Agregá --aplicar cuando el resultado sea el esperado.');
        $this->newLine();

        try {
            $resultado = $importador->importar($cliente, $desde, $hasta, $limite, $aplicar);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->mostrar($resultado);

        return self::SUCCESS;
    }

    private function mostrar(ResultadoImportacionEnviados $r): void
    {
        $verbo = $r->aplicado ? '' : ' (se haría)';

        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Correos revisados (únicos)', $r->correosRevisados],
                ['Correos repetidos en el barrido (descartados)', $r->correosRepetidos],
                ['Correos sin adjunto JSON', $r->correosSinJson],
                ['Adjuntos JSON ilegibles', $r->adjuntosIlegibles],
                ['DTE con receptor de otro cliente', $r->receptorAjeno],
                ['DTE con receptor no identificable (sin NIT)', $r->receptorNoIdentificable],
                ['DTE del cliente con tipo distinto de 03/05', $r->tipoDistinto],
                ['DTE del cliente incompletos (número o código inválido)', $r->incompletos],
                ['Documentos OMITIDOS por no traer sello de recepción', $r->omitidosSinSello],
                ['Copias repetidas (reenvíos)', $r->reenvios],
                ['DTE emitidos por este sistema (los da de alta cobros:sincronizar)', $r->propiosDelSistema],
                ['CCF nuevos'.$verbo, $r->ccfCreados],
                ['NC nuevas'.$verbo, $r->ncCreadas],
                ['De los nuevos, marcados para revisión histórica', $r->revisionHistorica],
                ['Ya estaban en el seguimiento', $r->yaExistian],
                ['Existentes con datos vacíos completados'.$verbo, $r->completados],
                ['Procedencias de correo registradas', $r->procedenciasNuevas],
                ['Relaciones NC→CCF nuevas'.$verbo, $r->relacionesNuevas],
                ['Relaciones NC→CCF resueltas'.$verbo, $r->relacionesResueltas],
                ['NC sin relación electrónica declarada', $r->ncSinRelacion],
                ['Excepciones (no se importaron)', count($r->excepciones)],
            ],
        );

        if ($r->excepciones !== []) {
            $this->warn('Excepciones para revisar a mano; ninguna sobreescribió datos:');
            foreach ($r->excepciones as $e) {
                $this->line('  - '.$e['referencia'].($e['mensaje'] ? " [correo {$e['mensaje']}]" : '').': '.$e['motivo']);
            }
        }

        if ($r->relacionesSinCcf !== []) {
            $this->warn(sprintf(
                '%d código(s) de CCF declarados por NC que NO están en el seguimiento (la relación queda guardada sin destino; '
                .'puede estar fuera del rango, del límite o no haberse enviado por correo):',
                count($r->relacionesSinCcf),
            ));
            foreach (array_keys($r->relacionesSinCcf) as $codigo) {
                $this->line('  - '.$codigo);
            }
        }

        $this->newLine();

        if ($r->truncado) {
            $this->warn('TRUNCADO: el barrido NO cubrió todo el rango pedido (se alcanzó --limite). '
                .'El resultado es PARCIAL: acotá el rango o subí el límite y volvé a correrlo.');
        } else {
            $this->info('El barrido recorrió todo lo que Gmail devolvió para esa consulta y ese rango.');
        }

        $this->comment('Esto no prueba que un CCF no tenga NC: solo registra las que se encontraron con relación declarada. '
            .'El sello del JSON es un indicio de aceptación, no una consulta en línea al MH.');

        if (! $r->aplicado) {
            $this->warn('ENSAYO EN SECO: no se escribió nada.');
        }
    }
}
