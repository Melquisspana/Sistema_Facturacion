<?php

namespace App\Console\Commands;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteNoMapeableException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\DteSchemaValidator;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorMhFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * VISTA PREVIA (solo lectura) del JSON oficial de un DTE (CCF 03, Factura 01,
 * Exportación 11, Nota de crédito 05): mapea → serializa → valida contra el schema.
 * NO firma, NO transmite, NO genera sello/PDF, NO cambia estado, NO toca numeración
 * oficial ni json_generado_path. Con --guardar escribe en storage/app/dte/previews/.
 */
class DteJsonPreviewCommand extends Command
{
    private const TIPOS_SOPORTADOS = [
        TipoDte::CreditoFiscal, TipoDte::Factura, TipoDte::FacturaExportacion, TipoDte::NotaCredito,
    ];

    protected $signature = 'dte:json-preview {dte : ID del DTE}
        {--guardar : Guarda la vista previa en storage/app/dte/previews}
        {--fake-identificacion : Rellena numeroControl/codigoGeneracion temporales SOLO en memoria para validar (no se persiste nada)}';

    protected $description = 'Vista previa del JSON oficial de un DTE (mapea, serializa y valida contra el schema). No envía nada.';

    public function handle(
        MapeadorDteSalida $mapeador,
        SerializadorMhFactory $serializadores,
        DteSchemaValidator $validador,
    ): int {
        $dte = Dte::find($this->argument('dte'));
        if (! $dte) {
            $this->error('No existe el DTE con id '.$this->argument('dte').'.');

            return self::FAILURE;
        }

        if (! in_array($dte->tipo_dte, self::TIPOS_SOPORTADOS, true)) {
            $this->error('Tipo no soportado para vista previa oficial: '.$dte->tipo_dte->label().'.');

            return self::FAILURE;
        }
        if ($dte->estado !== EstadoDte::Generado) {
            $this->error('El documento debe estar GENERADO. Estado actual: '.$dte->estado->label().'.');

            return self::FAILURE;
        }

        // 1) Mapear a DteSalidaData (interno).
        try {
            $salida = $mapeador->mapear($dte);
        } catch (DteNoMapeableException $e) {
            $this->error('No se puede mapear el DTE:');
            foreach ($e->problemas as $p) {
                $this->line('  - '.$p);
            }

            return self::FAILURE;
        }

        // 2) Serializar al array oficial del tipo.
        try {
            $oficial = $serializadores->para($dte->tipo_dte)->serializar($salida);
        } catch (DteNoSerializableException $e) {
            $this->error('No se puede serializar a JSON oficial:');
            foreach ($e->problemas as $p) {
                $this->line('  - '.$p);
            }

            return self::FAILURE;
        }

        // Modo PREVIEW con numeración temporal: rellena numeroControl/codigoGeneracion
        // SOLO en el array en memoria, nunca en la BD. Sirve para confirmar que el
        // resto del documento valida cuando esos campos están presentes.
        if ($this->option('fake-identificacion')) {
            $marcado = false;
            if (blank($oficial['identificacion']['numeroControl'])) {
                $oficial['identificacion']['numeroControl'] = $this->numeroControlTemporal($oficial, $dte->tipo_dte->value, (int) $dte->id);
                $marcado = true;
            }
            if (blank($oficial['identificacion']['codigoGeneracion'])) {
                $oficial['identificacion']['codigoGeneracion'] = strtoupper((string) Str::uuid());
                $marcado = true;
            }
            if ($marcado) {
                $this->newLine();
                $this->warn('*** SOLO PREVIEW — NO OFICIAL — NO GUARDADO (numeración temporal en memoria) ***');
            }
        }

        // 3) Faltantes de numeración oficial (NO se inventan).
        $this->newLine();
        $faltantes = [];
        if (blank($oficial['identificacion']['numeroControl'])) {
            $faltantes[] = 'numeroControl (numeración oficial pendiente; no se inventa)';
        }
        if (blank($oficial['identificacion']['codigoGeneracion'])) {
            $faltantes[] = 'codigoGeneracion (UUID oficial pendiente; no se inventa)';
        }
        if ($faltantes !== []) {
            $this->warn('Faltantes para emisión oficial (esperado en preview):');
            foreach ($faltantes as $f) {
                $this->line('  - '.$f);
            }
        }

        // 4) Validar contra el schema oficial del tipo (si hay librería).
        $this->newLine();
        $res = $validador->validar($oficial, $dte->tipo_dte);
        switch ($res['estado']) {
            case 'valido':
                $this->info('✔ '.$res['mensaje']);
                break;
            case 'invalido':
                $this->warn('✘ '.$res['mensaje']);
                foreach (array_slice($res['errores'], 0, 40) as $err) {
                    $this->line('  - '.$err);
                }
                break;
            case 'sin_libreria':
                $this->warn($res['mensaje']);
                break;
            default:
                $this->warn($res['mensaje']);
        }

        // 5) Mostrar / guardar (solo preview; nunca json_generado_path).
        $json = json_encode($oficial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->option('guardar')) {
            $ruta = 'dte/previews/ccf-'.$dte->id.'-preview.json';
            Storage::disk('local')->put($ruta, $json);
            $this->newLine();
            $this->info('Vista previa guardada en storage/app/'.$ruta.' (no es un DTE oficial).');
        } else {
            $this->newLine();
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /**
     * Número de control TEMPORAL con el formato que exige el schema CCF
     * (DTE-03-(M|B|S|P)NNN P NNN-15dígitos), construido desde los códigos del emisor.
     * No es oficial ni se persiste; es solo para validar el preview.
     *
     * @param  array<string, mixed>  $oficial
     */
    private function numeroControlTemporal(array $oficial, string $tipoCode, int $dteId): string
    {
        $estable = (string) ($oficial['emisor']['codEstable'] ?? '');
        $puntoVenta = (string) ($oficial['emisor']['codPuntoVenta'] ?? '');

        if (! preg_match('/^[MBSP][0-9]{3}$/', $estable)) {
            $estable = 'M001';
        }
        if (! preg_match('/^P[0-9]{3}$/', $puntoVenta)) {
            $puntoVenta = 'P001';
        }

        return 'DTE-'.$tipoCode.'-'.$estable.$puntoVenta.'-'.str_pad((string) $dteId, 15, '0', STR_PAD_LEFT);
    }
}

