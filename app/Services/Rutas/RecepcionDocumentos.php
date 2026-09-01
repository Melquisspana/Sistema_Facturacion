<?php

namespace App\Services\Rutas;

use App\Models\Dte;
use App\Models\SalidaRutaDocumento;
use App\Support\IdentidadPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Encontrar el documento que alguien acaba de escanear o teclear en la pantalla de
 * recepción.
 *
 * ─────────────────────── Por qué hay varias formas de buscarlo ───────────────────────
 *
 * Quien recibe tiene el papel en la mano y necesita identificarlo en un segundo. Según qué
 * mire del impreso, va a escribir cosas distintas:
 *
 *  · el NÚMERO DE CONTROL completo, que es lo que lee un escáner de código de barras;
 *  · el CÓDIGO DE GENERACIÓN, el UUID que también viene impreso;
 *  · el NÚMERO DEL SISTEMA («N.º 7»), que es como se nombra el documento en la oficina;
 *  · los ÚLTIMOS DÍGITOS, que es como se lo nombra en voz alta («el 986»).
 *
 * Se prueban en ese orden, del más específico al más ambiguo. Los tres primeros identifican
 * un documento y nada más; los últimos dígitos pueden dar varios, y entonces NO se elige:
 * se devuelven todos para que una persona mire cuál es. Adivinar acá sería registrar la
 * devolución del papel equivocado.
 *
 * ─────────────────────── El escáner es un teclado ───────────────────────
 *
 * Un lector de código de barras teclea el contenido y manda Enter. No hace falta ninguna
 * integración: la pantalla es un campo de texto con foco y este servicio tolera lo que el
 * lector escriba —con o sin guiones, con espacios de más—, porque la normalización es la
 * misma que ya usa todo el módulo ({@see IdentidadPpq}).
 *
 * SOLO LECTURA. Quien registra la recepción es {@see Custodia}; acá solo se busca.
 */
class RecepcionDocumentos
{
    /** Cuántos resultados tiene sentido mostrar cuando la búsqueda es ambigua. */
    private const MAXIMO = 15;

    /**
     * Documentos que corresponden a lo que se escribió. Vacío si no hay ninguno; con un
     * solo elemento en el caso normal; con varios cuando el texto es ambiguo.
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    public function buscar(string $texto): Collection
    {
        $texto = trim($texto);

        if ($texto === '') {
            return collect();
        }

        foreach (['porNumeroControl', 'porCodigoGeneracion', 'porNumeroSistema', 'porUltimosDigitos'] as $estrategia) {
            $encontrados = $this->{$estrategia}($texto);

            if ($encontrados->isNotEmpty()) {
                return $encontrados;
            }
        }

        return collect();
    }

    /**
     * ¿Qué se buscó y con qué resultado? Para que la pantalla explique el caso ambiguo en
     * vez de mostrar una lista sin decir por qué.
     *
     * @return array{documentos: Collection<int, SalidaRutaDocumento>, estado: string}
     */
    public function resolver(string $texto): array
    {
        $documentos = $this->buscar($texto);

        return [
            'documentos' => $documentos,
            'estado' => match (true) {
                $documentos->isEmpty() => 'sin_resultados',
                $documentos->count() === 1 => 'unico',
                default => 'ambiguo',
            },
        ];
    }

    // ------------------------------------------------------------- estrategias

    /**
     * Número de control completo, normalizado en los dos lados. Es lo que manda un escáner
     * y lo único que identifica a un documento en los dos caminos (P002 y P001 histórico).
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    private function porNumeroControl(string $texto): Collection
    {
        $clave = IdentidadPpq::normalizar($texto);

        if ($clave === null) {
            return collect();
        }

        return $this->base()
            ->where(IdentidadPpq::columnaNormalizada('salida_ruta_documentos.numero_control'), $clave)
            ->get();
    }

    /**
     * Código de generación (UUID). No vive en `salida_ruta_documentos` —esa tabla guarda lo
     * mínimo para identificar— así que se resuelve contra `dtes` y se entra por `dte_id`.
     * Los históricos P001 no tienen DTE local, y por eso esta vía no los encuentra: para
     * ellos está el número de control.
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    private function porCodigoGeneracion(string $texto): Collection
    {
        if (! preg_match('/^[0-9A-Fa-f-]{30,40}$/', $texto)) {
            return collect();
        }

        $dteIds = Dte::query()->where('codigo_generacion', strtoupper($texto))->pluck('id');

        return $dteIds->isEmpty()
            ? collect()
            : $this->base()->whereIn('dte_id', $dteIds)->get();
    }

    /**
     * Número visible del sistema («N.º 7»). Es como se nombra el documento en la oficina, y
     * quien recibe suele tenerlo a mano antes que el número de control.
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    private function porNumeroSistema(string $texto): Collection
    {
        $numero = preg_replace('/\D/', '', $texto);

        if ($numero === '' || strlen($numero) > 9) {
            return collect();
        }

        $dteIds = Dte::query()->where('numero_sistema', (int) $numero)->pluck('id');

        return $dteIds->isEmpty()
            ? collect()
            : $this->base()->whereIn('dte_id', $dteIds)->get();
    }

    /**
     * Últimos dígitos del número de control. Es la vía AMBIGUA a propósito: puede devolver
     * varios y quien llama debe mostrarlos todos, nunca quedarse con el primero.
     *
     * @return Collection<int, SalidaRutaDocumento>
     */
    private function porUltimosDigitos(string $texto): Collection
    {
        $digitos = preg_replace('/\D/', '', $texto);

        if (strlen($digitos) < 3) {
            return collect(); // menos de tres dígitos devolvería medio archivo
        }

        return $this->base()
            ->where('salida_ruta_documentos.numero_control', 'like', '%'.$digitos)
            ->limit(self::MAXIMO)
            ->get();
    }

    /**
     * La consulta base, con todo lo que la ficha necesita mostrar para que quien recibe
     * reconozca el papel sin dudar: cliente, sala, fecha, monto, salida y responsable.
     *
     * Se ordena por lo más reciente: un documento que salió esta semana es casi siempre el
     * que alguien tiene en la mano.
     */
    private function base(): Builder
    {
        return SalidaRutaDocumento::query()
            ->with([
                'dte:id,tipo_dte,estado,numero_control,numero_sistema,codigo_generacion,numero_orden_compra,fecha_emision,total_pagar,cliente_id,cliente_sucursal_id',
                'dte.cliente:id,nombre',
                'dte.clienteSucursal:id,nombre,codigo',
                'clienteSucursal:id,nombre,codigo',
                'salida:id,ruta_id,fecha_inicio,fecha_fin_real,fecha_fin_estimada,estado',
                'salida.ruta:id,nombre',
                'salida.participantes.personal:id,nombre,activo',
                'documentacionRecibidaPor:id,name',
            ])
            ->orderByDesc('id');
    }
}
