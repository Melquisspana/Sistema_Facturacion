<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\EstadoDte;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqItem;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Support\EjercicioControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CCF que quedaron fuera de la regla de PPQ ({@see ElegibilidadPpqSeguimiento}):
 *
 *  - preparados que no están en ningún PPQ vivo (el lote se borró sin devolverlos);
 *  - el mismo CCF en dos PPQ vivos, con algún renglón sin conciliar;
 *  - invalidados en Hacienda en los últimos 30 días que siguen en cobro.
 *
 * Recorre todos los renglones de PPQ: el resultado (solo números de CCF y de PPQ) se
 * guarda una hora. Lo que ya estaba mal se avisa una vez y después solo si sube.
 */
class CobrosFueraDeRegla implements Detector
{
    public const CLAVE = 'alertas.vigilancia.cobros';

    private const MINUTOS_GUARDADO = 60;

    /** Lo recién tocado puede estar entrando a un PPQ en este momento. */
    private const MINUTOS_RECIEN = 10;

    private const DIAS_INVALIDADOS = 30;

    private const NOMBRADOS = 3;

    private const REGLAS = [
        'sin_lote' => [
            'titulo' => ['1 CCF preparado no está en ningún PPQ', ':n CCF preparados no están en ningún PPQ'],
            'detalle' => 'Preparados sin PPQ',
            'que_hacer' => 'Pasale los números a quien te ayuda con el sistema para devolverlos a «Entregados, por presentar»; no los metás a mano en otro PPQ.',
        ],
        'doble' => [
            'titulo' => ['1 CCF está en dos PPQ a la vez', ':n CCF están en dos PPQ a la vez'],
            'detalle' => 'En dos PPQ',
            'que_hacer' => 'Abrí uno de los dos PPQ y quitá ese CCF; si ya se presentaron los dos, avisale al cliente que lo pague una sola vez.',
        ],
        'invalidados' => [
            'titulo' => ['1 CCF invalidado sigue en cobro', ':n CCF invalidados siguen en cobro'],
            'detalle' => 'Invalidados en cobro',
            'que_hacer' => 'Si no se presentó, quitalo del PPQ; si ya se presentó, avisale al cliente que no lo pague.',
        ],
    ];

    public function __construct(private readonly ElegibilidadPpqSeguimiento $elegibilidad) {}

    public function tipo(): string
    {
        return 'cobros_fuera_de_regla';
    }

    public function nombre(): string
    {
        return 'CCF fuera de regla en Cobros';
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $resultado = array_filter(
            Cache::remember(self::CLAVE, self::MINUTOS_GUARDADO * 60, fn () => $this->contar()),
            fn (array $regla) => $regla['n'] > 0,
        );
        if ($resultado === []) {
            return null;
        }

        $titulo = fn (string $regla) => str_replace(':n', (string) $resultado[$regla]['n'],
            self::REGLAS[$regla]['titulo'][$resultado[$regla]['n'] === 1 ? 0 : 1]);
        $total = array_sum(array_column($resultado, 'n'));

        return new Hallazgo(
            titulo: count($resultado) === 1 ? $titulo(array_key_first($resultado)) : $total.' CCF están fuera de regla en Cobros',
            detalle: implode(' ', array_map(fn (string $regla) => self::REGLAS[$regla]['detalle'].': '
                .implode(', ', $resultado[$regla]['nombres'])
                .($resultado[$regla]['n'] > count($resultado[$regla]['nombres']) ? ' y '.($resultado[$regla]['n'] - count($resultado[$regla]['nombres'])).' más' : '').'.',
                array_keys($resultado))),
            queHacer: implode(' ', array_map(fn (string $regla) => self::REGLAS[$regla]['que_hacer'], array_keys($resultado))),
            conteo: $total,
            enlace: 'cobros.index',
        );
    }

    /** @return array<string, array{n: int, nombres: list<string>}> */
    private function contar(): array
    {
        $vigentes = $this->elegibilidad->itemsVigentes();

        return [
            'sin_lote' => $this->resumen($this->preparadosSinLote($vigentes)->map(fn (CobroDocumento $d) => $d->correlativoCorto())),
            'doble' => $this->resumen($this->enDosLotes($vigentes)),
            'invalidados' => $this->resumen($this->invalidadosEnCobro()->map(fn (CobroDocumento $d) => $d->correlativoCorto())),
        ];
    }

    /** @return array{n: int, nombres: list<string>} */
    private function resumen(Collection $nombres): array
    {
        return ['n' => $nombres->count(), 'nombres' => $nombres->take(self::NOMBRADOS)->values()->all()];
    }

    /**
     * El ejemplo de Melqui: «preparada» sin solicitud y sin un lote vivo que la tenga.
     * Incluye los pagos de menos que el TXT devolvió para el próximo PPQ.
     *
     * @param  Collection<int, PpqItem>  $vigentes
     * @return Collection<int, CobroDocumento>
     */
    private function preparadosSinLote(Collection $vigentes): Collection
    {
        $claves = collect();
        foreach ($vigentes as $item) {
            $claves->put(EjercicioControl::llave($item->numero_control, $item->ejercicio_control) ?? '', true);
            if ($item->dte_id !== null) {
                $claves->put('dte:'.$item->dte_id, true);
            }
        }

        return CobroDocumento::query()->where('tipo_dte', '03')
            ->where('presentacion_estado', EstadoPresentacionCobro::Preparada->value)
            ->whereNull('cobro_solicitud_id')
            ->whereDoesntHave('dte', fn ($d) => $d->where('estado', EstadoDte::Invalidado->value))
            ->where('updated_at', '<', now()->subMinutes(self::MINUTOS_RECIEN))
            ->orderBy('id')->get()
            ->filter(fn (CobroDocumento $doc) => $this->elegibilidad->pagoAdmitePpq($doc)
                && ! $claves->has(EjercicioControl::llave($doc->numero_control, $doc->ejercicio_control) ?? '')
                && ! ($doc->dte_id !== null && $claves->has('dte:'.$doc->dte_id)))
            ->values();
    }

    /**
     * La misma clave en dos o más lotes vivos, con al menos un renglón sin conciliar.
     *
     * @param  Collection<int, PpqItem>  $vigentes
     * @return Collection<int, string> «CCF 214: PPQ #12 y #15»
     */
    private function enDosLotes(Collection $vigentes): Collection
    {
        return $vigentes
            ->groupBy(fn (PpqItem $item) => EjercicioControl::llave($item->numero_control, $item->ejercicio_control)
                ?? ($item->dte_id !== null ? 'dte:'.$item->dte_id : 'item:'.$item->id))
            ->filter(fn (Collection $items) => $items->pluck('ppq_lote_id')->unique()->count() > 1
                && $items->contains(fn (PpqItem $item) => ! $item->estaConciliado()))
            ->map(function (Collection $items) {
                $lotes = $items->pluck('ppq_lote_id')->unique()->sort()->map(fn ($id) => '#'.$id)->values();
                $control = (string) $items->first()->numero_control;
                $corto = preg_match('/(\d+)$/', $control, $m) ? (ltrim($m[1], '0') ?: '0') : $control;

                return 'CCF '.$corto.': PPQ '.$lotes->slice(0, -1)->implode(', ').' y '.$lotes->last();
            })
            ->values();
    }

    /**
     * Invalidados hace poco que siguen preparados, presentados, recibidos o con solicitud.
     * No se mira «está en un lote vivo»: un lote enviado no se edita y el aviso no se cerraría.
     *
     * @return Collection<int, CobroDocumento>
     */
    private function invalidadosEnCobro(): Collection
    {
        return CobroDocumento::invalidados()
            ->whereHas('dte', fn ($d) => $d->where('fecha_invalidacion', '>=', now()->subDays(self::DIAS_INVALIDADOS)))
            ->where('pago_estado', EstadoPagoCobro::Pendiente->value)
            ->where(fn ($q) => $q->whereIn('presentacion_estado', [
                EstadoPresentacionCobro::Preparada->value,
                EstadoPresentacionCobro::Presentada->value,
                EstadoPresentacionCobro::Recibida->value,
            ])->orWhereNotNull('cobro_solicitud_id'))
            ->orderBy('id')->get();
    }
}
