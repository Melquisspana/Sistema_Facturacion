<?php

namespace App\Services\Dte;

use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use App\Support\Dte\CodigoGeneracion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Busca los documentos que se pueden OFRECER como «documento de reemplazo» en la
 * invalidación tipo 1 de CAT-024 (`documento.codigoGeneracionR`). SOLO CONSULTA: no
 * crea, no valida fiscalmente, no toca la emisión ni el evento.
 *
 * ── Por qué este universo y no otro ────────────────────────────────────────────
 * Las ÚNICAS reglas fiscales que hoy existen sobre el reemplazo viven en
 * {@see SerializadorInvalidacionMh::candados()}:
 *
 *   1. el tipo 1 exige `codigoGeneracionR`;
 *   2. debe tener formato oficial (UUID v4 en mayúsculas);
 *   3. no puede ser el MISMO DTE que se invalida.
 *
 * No hay ninguna regla que ate el reemplazo a un tipo de DTE, a un cliente o a una
 * fecha concreta, así que este buscador NO inventa una. Lo que sí hace —y por eso es
 * un filtro de CONVENIENCIA, no una regla— es ofrecer solo documentos cuyo código de
 * generación EXISTE realmente en Hacienda: aceptados realmente por el MH
 * ({@see Dte::scopeAceptadoRealMh()}) y del MISMO ambiente que el documento a
 * invalidar. Un documento de apitest jamás reemplaza a uno de producción, y un
 * aceptado MOCK no tiene código conocido por el MH: ofrecerlos sería ofrecer un
 * rechazo seguro.
 *
 * Como ese filtro podría, en algún caso no previsto, dejar fuera un documento que el
 * MH sí aceptaría, la UI conserva un MODO AVANZADO donde el código se escribe a mano.
 * Lo que aquí se decide es qué se OFRECE; la validación dura sigue siendo la del
 * serializador y la del Form Request, sin cambios.
 *
 * La técnica de coincidencia exacta del correlativo se reutiliza de
 * {@see BusquedaCcfParaNotaCredito} (misma forma del número de control).
 */
class BusquedaDocumentoReemplazo
{
    /**
     * Resultados devueltos al autocomplete y a la precarga del panel. Es un TOPE DURO:
     * el controlador no expone ningún parámetro de request que lo altere, así que nadie
     * puede pedir «todos los documentos» por esta vía. El autocomplete refina escribiendo,
     * no paginando: si el documento buscado no entra en los 20 primeros, se teclea un dato
     * más concreto (número de control, código de generación) o se usa el modo avanzado.
     */
    public const LIMITE = 20;

    /**
     * Tope de longitud del término de búsqueda. Un texto arbitrariamente largo solo
     * serviría para forzar `LIKE %…%` costosos sin devolver nada útil; por encima de
     * esto se recorta (no se rechaza: recortar da un resultado, rechazar da un error
     * que la UI no necesita mostrar).
     */
    private const LONGITUD_MAX_TEXTO = 100;

    /** Mismo ancho máximo de secuencia que {@see BusquedaCcfParaNotaCredito}. */
    private const ANCHO_MAX_SECUENCIA = 20;

    /**
     * Candidatos a reemplazo del DTE indicado que coinciden con el texto libre.
     * Sin texto devuelve los más recientes (los del mismo cliente primero).
     *
     * El límite se acota SIEMPRE a {@see self::LIMITE}, aunque quien llame pida más:
     * es un buscador de autocomplete, no un exportador de documentos.
     *
     * @return Collection<int, Dte>
     */
    public function buscar(Dte $invalidado, string $texto = '', int $limite = self::LIMITE): Collection
    {
        $texto = mb_substr(trim($texto), 0, self::LONGITUD_MAX_TEXTO);
        $limite = max(1, min($limite, self::LIMITE));

        return $this->base($invalidado)
            ->when($texto !== '', fn (Builder $q) => $this->aplicarTexto($q, $texto))
            ->limit($limite)
            ->get();
    }

    /**
     * Forma de cada candidato para la vista y para el JSON del autocomplete: UNA sola
     * definición, para que la fila del resultado y la del documento ya elegido no
     * puedan divergir. `codigo_generacion` es el único valor que viaja al POST.
     *
     * @param  Collection<int, Dte>  $documentos
     * @return array<int, array<string, mixed>>
     */
    public function opciones(Collection $documentos): array
    {
        return $documentos->map(fn (Dte $d) => [
            'id' => $d->id,
            'codigo_generacion' => $d->codigo_generacion,
            'numero_control' => $d->numero_control,
            'tipo' => $d->tipo_dte?->value,
            'tipo_label' => $d->tipo_dte?->label(),
            'cliente' => $d->cliente?->nombre,
            'fecha' => $d->fecha_emision?->format('d/m/Y'),
            'total' => number_format((float) $d->total_pagar, 2),
            'estado' => $d->estado?->label(),
        ])->values()->all();
    }

    /**
     * Universo ofrecible: aceptados REALMENTE por el MH, mismo ambiente que el
     * documento a invalidar, con código de generación presente y distinto al propio
     * (regla 3 del serializador, aplicada aquí solo para no ofrecer lo que el
     * serializador rechazaría). Los del mismo cliente salen primero: es el caso
     * normal de un reemplazo, sin excluir al resto.
     */
    private function base(Dte $invalidado): Builder
    {
        $consulta = Dte::query()
            ->aceptadoRealMh()
            ->whereKeyNot($invalidado->id)
            ->where('ambiente', $invalidado->ambiente->value)
            ->whereNotNull('codigo_generacion')
            ->with(['cliente:id,nombre,nombre_comercial,num_documento,nrc'])
            ->select([
                'id', 'tipo_dte', 'estado', 'ambiente', 'numero_control', 'numero_interno',
                'codigo_generacion', 'cliente_id', 'fecha_emision', 'total_pagar',
            ]);

        if ($invalidado->cliente_id !== null) {
            $consulta->orderByRaw('CASE WHEN cliente_id = ? THEN 0 ELSE 1 END', [$invalidado->cliente_id]);
        }

        return $consulta->orderByDesc('fecha_emision')->orderByDesc('id');
    }

    /**
     * Texto libre: número de control, código de generación, cliente o fecha. Un
     * término de solo dígitos se trata como correlativo (coincidencia EXACTA contra el
     * número de control, para que `0340` no arrastre el `0003401` de otro documento).
     */
    private function aplicarTexto(Builder $q, string $texto): Builder
    {
        return $q->where(function (Builder $w) use ($texto) {
            if (ctype_digit($texto)) {
                $w->where(fn (Builder $c) => $this->correlativoExacto($c, 'numero_control', $texto))
                    ->orWhere(fn (Builder $c) => $this->correlativoExacto($c, 'numero_interno', $texto))
                    ->orWhere('codigo_generacion', 'like', '%'.$texto.'%')
                    ->orWhereHas('cliente', fn (Builder $c) => $c
                        ->where('num_documento', 'like', '%'.$texto.'%')
                        ->orWhere('nrc', 'like', '%'.$texto.'%'));

                return;
            }

            $w->where('numero_control', 'like', '%'.$texto.'%')
                ->orWhere('numero_interno', 'like', '%'.$texto.'%')
                ->orWhere('codigo_generacion', 'like', '%'.strtoupper($texto).'%')
                ->orWhereHas('cliente', fn (Builder $c) => $c
                    ->where('nombre', 'like', '%'.$texto.'%')
                    ->orWhere('nombre_comercial', 'like', '%'.$texto.'%'));

            // Fecha escrita como d/m/Y o Y-m-d: se compara contra la fecha de emisión.
            $fecha = $this->fechaDelTexto($texto);
            if ($fecha !== null) {
                $w->orWhereDate('fecha_emision', $fecha);
            }
        });
    }

    /**
     * La columna TERMINA en ese correlativo con su relleno de ceros completo hasta el
     * separador. Misma técnica que {@see BusquedaCcfParaNotaCredito::correlativoExacto()}:
     * el ancho de la secuencia varía entre documentos, así que se prueba por anchos en
     * vez de fijar 15 dígitos.
     */
    private function correlativoExacto(Builder $q, string $columna, string $digitos): void
    {
        $valor = ltrim($digitos, '0');

        if ($valor === '') {
            $valor = '0';
        }

        foreach (range(strlen($valor), max(strlen($valor), self::ANCHO_MAX_SECUENCIA)) as $i => $ancho) {
            $patron = '%-'.str_pad($valor, $ancho, '0', STR_PAD_LEFT);

            $i === 0
                ? $q->where($columna, 'like', $patron)
                : $q->orWhere($columna, 'like', $patron);
        }
    }

    /** `d/m/Y` o `Y-m-d` → `Y-m-d`; cualquier otra cosa, null (no se busca por fecha). */
    private function fechaDelTexto(string $texto): ?string
    {
        foreach (['d/m/Y', 'Y-m-d'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, $texto);

            if ($fecha !== false && $fecha->format($formato) === $texto) {
                return $fecha->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * ¿El código escrito a mano en el modo avanzado tiene el formato oficial? Espejo de
     * presentación de la regla que ya aplica el serializador: sirve para avisar en la
     * UI, nunca para autorizar. Un código con formato válido igual pasa por el
     * serializador y por el MH.
     */
    public function formatoValido(?string $codigo): bool
    {
        return filled($codigo) && CodigoGeneracion::esValido(strtoupper(trim($codigo)));
    }
}
