<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Exceptions\Dte\DteJsonException;
use App\Exceptions\Dte\DteJsonInvalidoException;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorMhFactory;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\NumeroControlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el JSON oficial PRELIMINAR de un DTE (CCF 03, Factura 01, Exportación 11,
 * Nota de crédito 05): asigna numeración oficial, serializa con el serializador del
 * tipo, valida contra el schema oficial y guarda el archivo + json_generado_path.
 *
 * NO firma, NO transmite, NO guarda sello, NO cambia el estado, NO contacta a
 * Hacienda. Todo ocurre en una transacción: si la validación falla, no queda
 * numeración ni path a medias.
 */
class DteJsonService
{
    public function __construct(
        private readonly MapeadorDteSalida $mapeador,
        private readonly SerializadorMhFactory $serializadores,
        private readonly DteSchemaValidator $validador,
    ) {}

    /**
     * @return array{ruta: string, numeroControl: string, codigoGeneracion: string, json: array<string, mixed>}
     *
     * @throws DteJsonException
     * @throws DteJsonInvalidoException
     */
    public function generar(Dte $dte, bool $force = false): array
    {
        // Tipos soportados (CCF, Factura, Exportación, Nota de crédito).
        if (! $this->soporta($dte->tipo_dte)) {
            throw new DteJsonException('Tipo no soportado para JSON oficial: '.$dte->tipo_dte->label().'.');
        }
        if ($dte->estado !== EstadoDte::Generado) {
            throw new DteJsonException('El CCF debe estar en estado generado (actual: '.$dte->estado->label().').');
        }
        // 3. No regenerar si ya tiene JSON, salvo --force explícito.
        if (! $force && filled($dte->json_generado_path)) {
            throw new DteJsonException('El CCF ya tiene JSON generado en '.$dte->json_generado_path.'. Use --force solo para regenerar.');
        }

        // 5. Todo en transacción: si algo falla, no deja numeración ni path a medias.
        return DB::transaction(function () use ($dte) {
            // 4. Numeración oficial asignada UNA SOLA VEZ (congelada).
            if (blank($dte->numero_control)) {
                $dte->numero_control = $this->numeroControl($dte);
            }
            if (blank($dte->codigo_generacion)) {
                $dte->codigo_generacion = CodigoGeneracion::generar();
            }
            $dte->save(); // el observer permite numero_control/codigo_generacion fuera de borrador

            // Mapear (usa la numeración recién asignada) y serializar al array oficial del tipo.
            $salida = $this->mapeador->mapear($dte);
            $oficial = $this->serializadores->para($dte->tipo_dte)->serializar($salida);

            // 6. Validar contra el schema oficial del tipo; si falla, ROLLBACK (sin numeración ni path).
            $res = $this->validador->validar($oficial, $dte->tipo_dte);
            if (! $res['valido']) {
                throw new DteJsonInvalidoException($res['errores']);
            }

            // 8. JSON legible para revisión.
            $json = json_encode($oficial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $disco = (string) config('dte.storage.disk', 'local');
            $carpeta = trim((string) config('dte.storage.json', 'dte/json'), '/');
            $ruta = $carpeta.'/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.json';
            Storage::disk($disco)->put($ruta, $json);

            $dte->json_generado_path = $ruta;
            $dte->save();

            // 9. Auditoría (sin firma/transmisión).
            activity('dte_json')
                ->performedOn($dte)
                ->withProperties(['numero_control' => $dte->numero_control, 'codigo_generacion' => $dte->codigo_generacion, 'ruta' => $ruta])
                ->log('generó el JSON oficial preliminar del CCF (sin firma ni transmisión)');

            return [
                'ruta' => $ruta,
                'numeroControl' => $dte->numero_control,
                'codigoGeneracion' => $dte->codigo_generacion,
                'json' => $oficial,
            ];
        });
    }

    /** ¿El tipo de documento tiene serializador/JSON oficial (CCF, Factura, Exportación, NC)? */
    public function soporta(TipoDte $tipo): bool
    {
        return in_array($tipo, [TipoDte::CreditoFiscal, TipoDte::Factura, TipoDte::FacturaExportacion, TipoDte::NotaCredito], true);
    }

    /** Número de control oficial: usa los códigos del emisor y el correlativo del documento. */
    private function numeroControl(Dte $dte): string
    {
        $dte->loadMissing(['establecimiento', 'puntoVenta']);

        return NumeroControlBuilder::construir(
            $dte->tipo_dte->value,
            (string) ($dte->establecimiento?->codigo ?? 'M001'),
            (string) ($dte->puntoVenta?->codigo ?? 'P001'),
            $this->correlativo($dte),
        );
    }

    /** Correlativo tomado del número interno ya consumido (mismo número de la emisión). */
    private function correlativo(Dte $dte): int
    {
        if (filled($dte->numero_interno) && preg_match('/(\d{1,15})$/', (string) $dte->numero_interno, $m)) {
            return max(1, (int) $m[1]);
        }

        return (int) $dte->id;
    }
}
