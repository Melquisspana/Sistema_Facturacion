<?php

namespace App\Console\Commands;

use App\Ajustes\Integraciones\ConfiguracionGmail;
use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Exceptions\Ppq\GmailNoDisponibleException;
use App\Models\OrdenCompraRecibida;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\OrdenCompraParser;
use App\Services\Ppq\SalaSucursalResolver;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Trae las órdenes de compra de Calleja desde la etiqueta de Gmail («Ordenes» por
 * defecto) a `ordenes_compra_recibidas`, con su PDF en el disco privado.
 *
 * SOLO LECTURA de Gmail: no marca, no mueve y no borra correos. Idempotente por
 * `gmail_message_id`: un correo ya guardado no se vuelve a bajar.
 *
 * Una orden que no se puede leer se guarda igual, como `sin_leer`, para revisarla a
 * mano. Lo único que no se guarda es un correo sin PDF (no hay qué guardar): se avisa
 * por salida.
 */
class OrdenesSincronizarCommand extends Command
{
    protected $signature = 'ordenes:sincronizar
        {--dias=3 : Días hacia atrás a revisar (para traer lo anterior, un número mayor)}';

    protected $description = 'Trae las órdenes de compra de Calleja desde Gmail (solo lectura, idempotente)';

    public function handle(GmailClient $gmail, ConfiguracionGmail $configuracion, OrdenCompraParser $parser, SalaSucursalResolver $salas): int
    {
        if (! $gmail->disponible()) {
            $this->error('Gmail no está disponible (integración deshabilitada o cuenta desconectada): no se trajo ninguna orden.');

            return self::FAILURE;
        }

        $dias = max(1, (int) $this->option('dias'));
        $desde = Carbon::today()->subDays($dias - 1);
        $consulta = 'label:'.$configuracion->labelOrdenes().' after:'.$desde->format('Y/m/d');
        $conteo = ['nuevas' => 0, 'sin_leer' => 0, 'ya_estaban' => 0, 'sin_pdf' => 0];

        try {
            $token = null;
            do {
                $pagina = $gmail->idsDeCobros($consulta, $token, 100);
                $token = $pagina['siguiente'];

                $conocidos = OrdenCompraRecibida::whereIn('gmail_message_id', $pagina['ids'])->pluck('gmail_message_id')->all();
                foreach (array_diff($pagina['ids'], $conocidos) as $id) {
                    $this->guardar($id, $gmail, $parser, $salas, $conteo);
                }
                $conteo['ya_estaban'] += count($conocidos);
            } while ($token !== null);
        } catch (GmailDesconectadoException $e) {
            $this->error(($e instanceof GmailNoDisponibleException ? 'Gmail no respondió: ' : 'Gmail se desconectó: ').$e->getMessage());
            $this->warn('Lo guardado hasta acá queda. La próxima corrida sigue donde quedó.');

            return self::FAILURE;
        }

        $this->info(sprintf('Desde %s: %d orden(es) nuevas (%d sin leer) · %d ya estaban · %d correo(s) sin PDF.',
            $desde->format('d/m/Y'), $conteo['nuevas'], $conteo['sin_leer'], $conteo['ya_estaban'], $conteo['sin_pdf']));

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $conteo */
    private function guardar(string $id, GmailClient $gmail, OrdenCompraParser $parser, SalaSucursalResolver $salas, array &$conteo): void
    {
        $pdfs = array_values(array_filter($gmail->adjuntos($id), fn (array $a) => str_contains(strtolower($a['mime']), 'pdf')
            || str_ends_with(strtolower($a['filename']), '.pdf')));

        if ($pdfs === []) {
            $conteo['sin_pdf']++;
            $this->warn("Correo {$id}: no trae PDF, no se guardó. Revisalo en Gmail.");

            return;
        }
        if (count($pdfs) > 1) {
            $this->warn("Correo {$id}: trae ".count($pdfs).' PDF; se guarda el primero que se pudo leer.');
        }

        // El primer PDF con número; si ninguno lo tiene, el primero, como «sin leer».
        $elegido = null;
        foreach ($pdfs as $pdf) {
            $datos = $parser->desdePdf($pdf['data']);
            if ($datos['numero'] !== null) {
                $elegido = [$pdf, $datos];
                break;
            }
            $elegido ??= [$pdf, $datos];
        }
        [$pdf, $datos] = $elegido;

        // Otro correo ya trajo este número (un reenvío o una orden corregida): se guarda
        // como «sin leer» para que alguien decida, sin pisar la que ya estaba.
        if ($datos['numero'] !== null && ($previa = OrdenCompraRecibida::where('numero', $datos['numero'])->first()) !== null) {
            $this->warn("Correo {$id}: la orden {$datos['numero']} ya había llegado en otro correo (#{$previa->id}); se guarda como «sin leer» para revisarla.");
            $datos['numero'] = null;
            $datos['estado'] = OrdenCompraRecibida::SIN_LEER;
        }

        $hash = hash('sha256', $pdf['data']);
        $ruta = OrdenCompraRecibida::CARPETA_PDF.'/'.$hash.'.pdf';
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $pdf['data']);

        try {
            OrdenCompraRecibida::create([
                'numero' => $datos['numero'],
                'sala_codigo' => $datos['sala_codigo'],
                'cliente_sucursal_id' => $salas->resolver($datos['sala_codigo'])['cliente_sucursal_id'],
                'fecha_creacion' => $datos['fecha_creacion'],
                'fecha_despacho' => $datos['fecha_despacho'],
                'total' => $datos['total'],
                'lineas' => $datos['lineas'],
                'pdf_path' => $ruta,
                'pdf_hash' => $hash,
                'gmail_message_id' => $id,
                'estado' => $datos['estado'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra corrida lo guardó en el mismo instante: ya está.
            $conteo['ya_estaban']++;

            return;
        }

        $conteo['nuevas']++;
        if ($datos['estado'] === OrdenCompraRecibida::SIN_LEER) {
            $conteo['sin_leer']++;
            $this->warn("Correo {$id}: no se pudo leer el número de la orden; quedó «sin leer» para revisarla a mano.");
        }
    }
}
