<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\Cobros\BarridoCorreosCobro;
use App\Services\Cobros\CorreoCobroParser;
use App\Services\Cobros\LectorCorreosCobro;
use App\Services\Ppq\GmailClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lee del buzón los correos con que el cliente responde a una solicitud (acuses de recibo y
 * observaciones) y los deja registrados y asociados.
 *
 * ══════════════════════ Dos llaves, igual que los albaranes ══════════════════════
 *
 * `ppq.gmail.enabled` decide si el sistema PUEDE hablar con Gmail; `cobros.correo.enabled`
 * decide si además lee este buzón. Son dos cosas distintas y por eso son dos llaves: con
 * una sola no habría forma de probar la conexión sin encender a la vez la lectura.
 *
 * El DRY-RUN (sin `--aplicar`) es el paso previo obligatorio: enumera lo que haría —qué
 * correo casaría con qué envío y cuál quedaría sin asociar— sin escribir una fila. Es donde
 * se comprueba, sobre los correos reales, que la interpretación es la correcta ANTES de
 * dejar que registre acuses.
 *
 * ══════════════════════════ Nunca escribe en el buzón ══════════════════════════
 *
 * No envía, no responde, no marca como leído y no mueve etiquetas. Lo único que hace con
 * Gmail es leer.
 */
class CobrosLeerCorreosCommand extends Command
{
    protected $signature = 'cobros:leer-correos
        {--cliente= : ID del cliente (por defecto, el único con perfil documental activo)}
        {--limite= : Máximo de mensajes a leer}
        {--query= : Consulta de Gmail a usar en vez de la configurada}
        {--aplicar : Registra de verdad. Sin esto solo enumera lo que haría}';

    protected $description = 'Lee (sin enviar ni modificar nada) los acuses y observaciones del cliente y los asocia a sus solicitudes';

    public function handle(
        GmailClient $gmail,
        LectorCorreosCobro $lector,
        CorreoCobroParser $parser,
        BarridoCorreosCobro $barrido,
    ): int {
        $aplicar = (bool) $this->option('aplicar');

        if ($aplicar && ! config('cobros.correo.enabled')) {
            $this->error('La lectura de correo de cobros está apagada (COBROS_CORREO_ENABLED=false). '
                .'No se consultó el buzón. Ver config/cobros.php.');

            return self::FAILURE;
        }

        if (! $gmail->disponible()) {
            $this->error('Gmail no está disponible: revisá la conexión en Configuración → Integraciones.');

            return self::FAILURE;
        }

        $cliente = $this->cliente();

        if ($cliente === null) {
            $this->error('No se pudo determinar el cliente. Pasá --cliente=ID.');

            return self::FAILURE;
        }

        $query = (string) ($this->option('query') ?: config('cobros.correo.query'));
        $limite = (int) ($this->option('limite') ?: config('cobros.correo.limite', 50));

        $this->line("Cliente: {$cliente->nombre} (id {$cliente->id})");
        $this->line("Consulta: {$query} · límite {$limite}");
        $this->newLine();

        // PREPARAR no escribe nada. Lo nuevo primero y una tanda del backlog después,
        // siguiendo por donde se quedó la corrida anterior.
        $tanda = $barrido->prepararTanda($cliente, $query, $limite);
        $mensajes = $tanda['mensajes'];

        $this->avanceDelBarrido($tanda);

        if ($mensajes === []) {
            $this->info('No hay mensajes nuevos que procesar con esa consulta.');

            // Aun sin mensajes hay algo que confirmar: la cola pudo agotarse, y eso es lo
            // que cierra el barrido. En seco, no.
            if ($aplicar) {
                $barrido->confirmarAvance($cliente, $query, $tanda);
            }

            return self::SUCCESS;
        }

        if (! $aplicar) {
            // ENSAYO EN SECO: se preparó y se muestra, pero nunca se confirma. La marca del
            // barrido real se queda intacta.
            return $this->enSeco($mensajes, $parser);
        }

        $resumen = $lector->procesar($cliente, $mensajes);

        // CONFIRMAR va DESPUÉS de procesar, y solo llega acá si el procesamiento no lanzó.
        // Aunque hubiera fallado a mitad, la marca se movería únicamente sobre lo que
        // quedó registrado de verdad.
        $progreso = $barrido->confirmarAvance($cliente, $query, $tanda);

        $this->table(
            ['Fecha', 'Tipo', 'Asunto', 'Ref.', 'Estado', 'Motivo'],
            $resumen['correos']->map(fn ($c) => [
                $c->fecha_mensaje?->format('d/m/Y H:i') ?? '—',
                $c->tipo,
                Str::limit((string) $c->asunto, 40),
                $c->referencia_calleja ?? '—',
                $c->estado,
                Str::limit((string) $c->motivo, 50),
            ])->all(),
        );

        $this->info(sprintf(
            '%d nuevo(s), %d ya estaban, %d asociado(s), %d sin asociar (quedan a la vista para revisión manual).',
            $resumen['nuevos'],
            $resumen['repetidos'],
            $resumen['asociados'],
            $resumen['sin_asociar'],
        ));

        $this->line('  '.$progreso->resumen());

        return self::SUCCESS;
    }

    /**
     * Dónde va el barrido y, sobre todo, si QUEDA buzón por recorrer.
     *
     * Es lo que el operador necesita para no leer mal el resultado: que una corrida no
     * traiga nada puede significar «no hay más» o «todavía no he llegado ahí», y esas dos
     * cosas no pueden mostrarse igual.
     *
     * @param  array<string, mixed>  $tanda
     */
    private function avanceDelBarrido(array $tanda): void
    {
        $this->line(sprintf(
            'Barrido: %d nuevo(s) de la cabeza · %d del backlog · %d ya estaban registrados (no gastan cupo).',
            $tanda['cabeza'],
            $tanda['cola'],
            $tanda['ya_conocidos'],
        ));

        // El estado DEFINITIVO se imprime después de confirmar: el de acá es el de antes de
        // esta tanda, y darlo como resultado final diría que el barrido no avanzó.

        if ($tanda['tope_alcanzado']) {
            $this->warn('  Se alcanzó el límite de esta corrida y QUEDAN mensajes por procesar. '
                .'La siguiente sigue donde esta se quedó; no se vuelve a empezar desde el principio.');
        }

        $this->newLine();
    }

    /**
     * Enumera lo que haría, sin escribir nada.
     *
     * @param  array<int, array<string, mixed>>  $mensajes
     */
    private function enSeco(array $mensajes, CorreoCobroParser $parser): int
    {
        $filas = [];
        foreach ($mensajes as $mensaje) {
            $leido = $parser->interpretar($mensaje['asunto'] ?? null, $mensaje['cuerpo_actual'] ?? $mensaje['cuerpo'] ?? null);
            $filas[] = [
                Str::limit((string) ($mensaje['asunto'] ?? ''), 45),
                $leido['tipo'],
                $leido['archivo_referido'] ?? '—',
                $leido['referencia_calleja'] ?? '—',
                $leido['fecha_programada_pago'] ?? '—',
                count($leido['documentos']),
            ];
        }

        $this->table(['Asunto', 'Tipo', 'Archivo', 'Ref.', 'Pago programado', 'Docs. citados'], $filas);
        $this->warn('ENSAYO EN SECO: no se escribió nada, ni siquiera el avance del barrido. '
            .'La marca sigue donde estaba y la corrida de verdad traerá esta misma tanda. '
            .'Agregá --aplicar cuando la lectura sea la esperada.');

        return self::SUCCESS;
    }

    private function cliente(): ?Cliente
    {
        if ($this->option('cliente')) {
            return Cliente::find((int) $this->option('cliente'));
        }

        $candidatos = Cliente::query()
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))
            ->get(['id', 'nombre']);

        // Con más de uno no se elige por el agente: se pide el id. Leer el buzón del
        // cliente equivocado asociaría acuses a envíos ajenos.
        return $candidatos->count() === 1 ? $candidatos->first() : null;
    }
}
