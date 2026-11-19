<?php

namespace App\Services\Alertas\Detectores;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Documentos de producción que no cuadran con Hacienda, de los últimos 30 días (lo más
 * viejo ya se conoce y no tiene que dejar el aviso abierto para siempre):
 *
 *  - aceptados sin sello real (vacío, MOCK o sin fecha de procesamiento);
 *  - invalidados con sello real pero sin sello de invalidación;
 *  - notas de crédito o débito aceptadas sobre un documento que se invalidó.
 *
 * El resultado (solo correlativos) se guarda una hora.
 */
class DocumentosFiscalesFueraDeRegla implements Detector
{
    public const CLAVE = 'alertas.vigilancia.dte';

    private const MINUTOS_GUARDADO = 60;

    private const DIAS = 30;

    private const NOMBRADOS = 3;

    private const SELLO_HACIENDA = 'Buscá el documento en la consulta pública de Hacienda con su código de generación. Si Hacienda lo tiene, pedí ayuda para cargar el sello; si no, no lo entregués como válido.';

    private const REGLAS = [
        'sin_sello' => [
            'titulo' => ['1 documento aceptado no tiene el sello de Hacienda', ':n documentos aceptados no tienen el sello de Hacienda'],
            'detalle' => 'Sin sello', 'que_hacer' => self::SELLO_HACIENDA,
        ],
        'sin_sello_invalidacion' => [
            'titulo' => ['1 documento invalidado no tiene el sello de invalidación', ':n documentos invalidados no tienen el sello de invalidación'],
            'detalle' => 'Sin sello de invalidación', 'que_hacer' => self::SELLO_HACIENDA,
        ],
        'nota_de_invalidado' => [
            'titulo' => ['1 nota sigue sobre un CCF invalidado', ':n notas siguen sobre un CCF invalidado'],
            'detalle' => 'Notas sobre un CCF invalidado',
            'que_hacer' => 'Revisá la nota en Facturación: hay que invalidarla o rehacerla sobre el CCF que reemplazó al invalidado.',
        ],
    ];

    public function tipo(): string
    {
        return 'dte_fuera_de_regla';
    }

    public function nombre(): string
    {
        return 'Documentos sin el sello de Hacienda';
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

        $total = array_sum(array_column($resultado, 'n'));
        $regla = array_key_first($resultado);

        return new Hallazgo(
            titulo: count($resultado) === 1
                ? str_replace(':n', (string) $total, self::REGLAS[$regla]['titulo'][$total === 1 ? 0 : 1])
                : $total.' documentos no cuadran con Hacienda',
            detalle: implode(' ', array_map(fn (string $r) => self::REGLAS[$r]['detalle'].': '.implode(', ', $resultado[$r]['nombres'])
                .($resultado[$r]['n'] > count($resultado[$r]['nombres']) ? ' y '.($resultado[$r]['n'] - count($resultado[$r]['nombres'])).' más' : '').'.',
                array_keys($resultado))),
            queHacer: implode(' ', array_values(array_unique(array_map(fn (string $r) => self::REGLAS[$r]['que_hacer'], array_keys($resultado))))),
            conteo: $total,
            enlace: 'facturacion.index',
        );
    }

    /** @return array<string, array{n: int, nombres: list<string>}> */
    private function contar(): array
    {
        $desde = now()->subDays(self::DIAS);

        return [
            'sin_sello' => $this->resumen(Dte::produccion()->where('estado', EstadoDte::Aceptado->value)
                ->where('fecha_emision', '>=', $desde->toDateString())
                ->where(fn (Builder $q) => $this->selloVacio($q, 'sello_recepcion')->orWhereNull('fecha_procesamiento_mh'))),
            'sin_sello_invalidacion' => $this->resumen(Dte::produccion()->where('estado', EstadoDte::Invalidado->value)
                ->where('fecha_invalidacion', '>=', $desde)
                ->whereNot(fn (Builder $q) => $this->selloVacio($q, 'sello_recepcion'))
                ->where(fn (Builder $q) => $this->selloVacio($q, 'sello_invalidacion'))),
            'nota_de_invalidado' => $this->resumen(Dte::produccion()->aceptadoRealMh()
                ->whereIn('tipo_dte', [TipoDte::NotaCredito->value, TipoDte::NotaDebito->value])
                ->whereHas('dteRelacionado', fn (Builder $q) => $q->where('estado', EstadoDte::Invalidado->value)
                    ->where('fecha_invalidacion', '>=', $desde))),
        ];
    }

    /** Vacío, nulo o de simulación (MOCK): lo contrario de un sello real. */
    private function selloVacio(Builder $q, string $columna): Builder
    {
        return $q->whereNull($columna)->orWhere($columna, '')->orWhereRaw('UPPER('.$columna.') LIKE ?', ['MOCK%']);
    }

    /** @return array{n: int, nombres: list<string>} */
    private function resumen(Builder $consulta): array
    {
        return [
            'n' => (clone $consulta)->count(),
            'nombres' => $consulta->orderBy('id')->limit(self::NOMBRADOS)->get()->map(fn (Dte $d) => $d->correlativoCorto())->all(),
        ];
    }
}
