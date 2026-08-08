<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Models\Dte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Búsqueda de los CCF (03) que se pueden vincular como documento relacionado de una
 * Nota de Crédito. SOLO CONSULTA: no crea, no calcula, no toca la emisión.
 *
 * El universo es EXACTAMENTE el mismo que ya usaba el select del formulario —CCF tipo
 * 03 con {@see Dte::scopeAceptadoRealMh()}—, así que este buscador no amplía en nada
 * lo que se podía elegir antes. La validación dura sigue viviendo en
 * DteBorradorService::crearNotaCredito(); acá solo se decide qué se OFRECE.
 *
 * Diferencia deliberada con {@see \App\Services\Ppq\PpqBusquedaService}: aquel prioriza
 * P002 y esconde el P001 del mismo correlativo (para cobro, el vigente es el nuevo).
 * Acá NO: una nota de crédito puede tener que acreditar un CCF histórico de P001, y
 * ocultarlo dejaría documentos imposibles de seleccionar. La técnica de coincidencia
 * exacta del correlativo sí se reutiliza (ver correlativoExacto()).
 */
class BusquedaCcfParaNotaCredito
{
    /** Resultados devueltos al autocomplete (y precarga del select de respaldo). */
    public const LIMITE = 20;

    /**
     * Ancho máximo que se le supone a la secuencia final del número de control.
     * La norma son 15 dígitos, pero hay documentos históricos con 16, así que el
     * correlativo se busca probando anchos en vez de dar uno por sentado.
     */
    private const ANCHO_MAX_SECUENCIA = 20;

    /**
     * CCF que coinciden con el texto libre. Sin texto devuelve los más recientes.
     *
     * @return Collection<int, Dte>
     */
    public function buscar(string $texto = '', int $limite = self::LIMITE): Collection
    {
        $texto = trim($texto);

        return $this->base()
            ->when($texto !== '', fn (Builder $q) => $this->aplicarTexto($q, $texto))
            ->limit($limite)
            ->get();
    }

    /**
     * Precarga del formulario: los más recientes, más —si se indica— uno concreto que
     * debe estar presente aunque no sea reciente (CCF preseleccionado por `?ccf=` o
     * repintado con old() tras un error de validación). Sin esto, el select de respaldo
     * perdería la opción elegida y el POST viajaría vacío.
     *
     * @return Collection<int, Dte>
     */
    public function recientes(int $limite = self::LIMITE, ?int $incluirId = null): Collection
    {
        $ccfs = $this->buscar('', $limite);

        if ($incluirId === null || $ccfs->contains(fn (Dte $c) => (int) $c->id === $incluirId)) {
            return $ccfs;
        }

        $extra = $this->base()->whereKey($incluirId)->first();

        return $extra === null ? $ccfs : $ccfs->prepend($extra);
    }

    /**
     * Forma de cada CCF para la vista y para el JSON del autocomplete: UNA sola
     * definición, para que la tarjeta de resultado y la del CCF ya elegido no puedan
     * divergir. Se conservan las claves que el formulario ya consumía
     * (`onCcfChange()` hereda cliente/sala/emisor/OC de acá).
     *
     * @param  Collection<int, Dte>  $ccfs
     * @return array<int, array<string, mixed>>
     */
    public function opciones(Collection $ccfs): array
    {
        return $ccfs->map(fn (Dte $c) => [
            'id' => $c->id,
            'numero' => $c->numero_interno ?? ('#'.$c->id),
            'numero_control' => $c->numero_control,
            'numero_interno' => $c->numero_interno,
            'correlativo' => $this->correlativoVisible($c),
            'cliente_id' => $c->cliente_id,
            'cliente_sucursal_id' => $c->cliente_sucursal_id,
            'cliente_nombre' => $c->cliente?->nombre,
            'num_documento' => $c->cliente?->num_documento,
            'sala' => $c->clienteSucursal?->nombre,
            'orden_compra' => $c->numero_orden_compra,
            'fecha' => $c->fecha_emision?->format('d/m/Y'),
            'total' => number_format((float) $c->total_pagar, 2),
            'establecimiento_id' => $c->establecimiento_id,
            'punto_venta_id' => $c->punto_venta_id,
            // Serie del emisor: "M001/P001". Es lo que distingue dos CCF que comparten
            // correlativo entre puntos de venta.
            'serie' => trim(($c->establecimiento?->codigo ?? '').'/'.($c->puntoVenta?->codigo ?? ''), '/'),
            'punto_venta' => $c->puntoVenta?->codigo,
        ])->values()->all();
    }

    /**
     * Universo seleccionable: CCF (03) ACEPTADOS REALMENTE por Hacienda. Mismo scope
     * que usaba el select, sin agregar ni quitar condiciones. El eager loading cubre
     * todo lo que pinta la tarjeta de resultado, para no caer en N+1.
     */
    private function base(): Builder
    {
        return Dte::query()
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->aceptadoRealMh()
            ->with([
                'cliente:id,nombre,nombre_comercial,num_documento,nrc',
                'clienteSucursal:id,nombre,codigo',
                'establecimiento:id,codigo',
                'puntoVenta:id,codigo',
            ])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->select([
                'id', 'numero_interno', 'numero_control', 'cliente_id', 'cliente_sucursal_id',
                'numero_orden_compra', 'fecha_emision', 'total_pagar',
                'establecimiento_id', 'punto_venta_id',
            ]);
    }

    /**
     * Texto libre. Un término SOLO de dígitos es un correlativo («escribo los últimos
     * 4»); cualquier otra cosa —número de control completo, código de generación,
     * sello, cliente, sala, orden de compra con letras— se busca como subcadena.
     */
    private function aplicarTexto(Builder $q, string $texto): Builder
    {
        return $q->where(function (Builder $w) use ($texto) {
            if (ctype_digit($texto)) {
                $this->camposNumericos($w, $texto);

                return;
            }

            $w->where('numero_control', 'like', "%{$texto}%")
                ->orWhere('numero_interno', 'like', "%{$texto}%")
                ->orWhere('numero_orden_compra', 'like', "%{$texto}%")
                ->orWhere('codigo_generacion', 'like', "%{$texto}%")
                ->orWhere('sello_recepcion', 'like', "%{$texto}%")
                ->orWhereHas('cliente', fn (Builder $c) => $c
                    ->where('nombre', 'like', "%{$texto}%")
                    ->orWhere('nombre_comercial', 'like', "%{$texto}%"))
                ->orWhereHas('clienteSucursal', fn (Builder $s) => $s
                    ->where('nombre', 'like', "%{$texto}%")
                    ->orWhere('codigo', $texto));
        });
    }

    /**
     * Término de solo dígitos. Contra el número de control y el interno se exige
     * coincidencia EXACTA del correlativo —no subcadena—, para que escribir `0340` no
     * arrastre el `0003401` de otro documento. Contra el resto de campos se mantiene la
     * subcadena, porque una orden de compra, un NIT o un sello también pueden ser solo
     * dígitos.
     *
     * A diferencia de PPQ, acá NO se filtra por P002: si el mismo correlativo existe en
     * P001 y en P002 aparecen LOS DOS, y la serie mostrada en cada resultado es lo que
     * permite distinguirlos.
     */
    private function camposNumericos(Builder $w, string $digitos): void
    {
        $w->where(fn (Builder $c) => $this->correlativoExacto($c, 'numero_control', $digitos))
            ->orWhere(fn (Builder $c) => $this->correlativoExacto($c, 'numero_interno', $digitos))
            ->orWhere('numero_orden_compra', 'like', "%{$digitos}%")
            ->orWhere('codigo_generacion', 'like', "%{$digitos}%")
            ->orWhere('sello_recepcion', 'like', "%{$digitos}%")
            ->orWhereHas('cliente', fn (Builder $c) => $c
                ->where('num_documento', 'like', "%{$digitos}%")
                ->orWhere('nrc', 'like', "%{$digitos}%"))
            ->orWhereHas('clienteSucursal', fn (Builder $s) => $s->where('codigo', $digitos));
    }

    /**
     * La columna TERMINA en ese correlativo, con su relleno de ceros completo hasta el
     * separador. Se prueba un patrón por ancho posible en vez de fijar 15 dígitos: el
     * ancho real varía entre documentos. Anclar en el guion es lo que da la exactitud
     * —entre el separador y el final solo puede haber ceros y el correlativo—, de modo
     * que `986` no casa con `...100986`.
     *
     * Misma técnica que PpqBusquedaService::correlativoExacto(); se replica en vez de
     * reutilizarse porque aquel servicio arrastra la priorización de P002 y el universo
     * de PPQ (tipos 03+05, no archivados), que acá no corresponden.
     */
    private function correlativoExacto(Builder $q, string $columna, string $digitos): void
    {
        // `0986` y `986` son el mismo correlativo: el relleno lo pone el patrón.
        $valor = ltrim($digitos, '0');

        if ($valor === '') {
            $valor = '0';
        }

        // `max()` evita que un término más largo que el ancho máximo genere un rango
        // descendente: en ese caso solo cabe su propio ancho.
        foreach (range(strlen($valor), max(strlen($valor), self::ANCHO_MAX_SECUENCIA)) as $i => $ancho) {
            $patron = '%-'.str_pad($valor, $ancho, '0', STR_PAD_LEFT);

            $i === 0
                ? $q->where($columna, 'like', $patron)
                : $q->orWhere($columna, 'like', $patron);
        }
    }

    /**
     * Correlativo legible («1120») a partir de la secuencia final del número de control,
     * o del interno si el documento no tiene control. Solo presentación.
     */
    private function correlativoVisible(Dte $c): ?string
    {
        $fuente = $c->numero_control ?? $c->numero_interno;

        if ($fuente === null || ! str_contains($fuente, '-')) {
            return null;
        }

        $cola = ltrim((string) substr($fuente, strrpos($fuente, '-') + 1), '0');

        return $cola === '' ? '0' : $cola;
    }
}
