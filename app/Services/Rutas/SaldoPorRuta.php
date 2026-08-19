<?php

namespace App\Services\Rutas;

use App\Models\SalidaRutaDocumento;
use Illuminate\Support\Collection;

/**
 * El saldo repartido por ruta: qué ruta tiene plata trabada y desde cuándo.
 *
 * Responde la pregunta que el dashboard no podía contestar en ningún lado —«¿qué ruta
 * tiene que salir a cobrar esta semana?»— y la contesta sin abrir una sola consulta.
 *
 * ─────────────────────────── Esta clase SOLO AGRUPA ───────────────────────────
 *
 * No suma dinero, no decide qué es saldo, no sabe qué es una NC ni qué es estar en un
 * lote de PPQ. Recibe la colección YA HIDRATADA que devolvió
 * {@see BandejaDocumentos::consultar()} y, para cada grupo, le vuelve a preguntar a
 * {@see Cobranza} —el mismo servicio que calculó el total general de la pantalla—.
 *
 * Es deliberado y es el candado que sostiene el bloque: si acá se escribiera un
 * `reduce` propio para sumar saldos, existirían dos fórmulas del saldo y tarde o
 * temprano una de las dos se despegaría de la otra. Sumando por delegación, la suma de
 * las filas y el total de arriba no PUEDEN discrepar: salen de la misma función.
 *
 * Por lo mismo no hay `groupBy` en SQL. Agregar en la base obligaría a reescribir en
 * WHERE las reglas de PPQ y de NC que hoy viven en los localizadores, que es
 * exactamente la segunda versión de la verdad que este módulo evita. El grupo se arma
 * en memoria sobre objetos que ya saben responder todo.
 */
class SaldoPorRuta
{
    public function __construct(private readonly Cobranza $cobranza) {}

    /**
     * Una fila por ruta CON SALDO, de mayor a menor.
     *
     * Las rutas sin nada que cobrar no aparecen: la tabla es una lista de trabajo, y
     * una fila en cero solo ocupa lugar. Los documentos cuya salida no resuelve ruta
     * —no debería pasar, pero no se inventa un dueño— se agrupan aparte bajo `null`.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos  ya hidratados
     * @return Collection<int, array{ruta_id: int|null, ruta: string, saldo: float, documentos: int, fuera_ppq: float, en_ppq: float, tramo_viejo: string|null, sin_fecha: int}>
     */
    public function agrupar(Collection $documentos): Collection
    {
        return $documentos
            ->groupBy(fn (SalidaRutaDocumento $d) => $d->salida?->ruta_id)
            ->map(fn (Collection $grupo, $rutaId) => $this->fila($grupo, $rutaId === '' ? null : $rutaId))
            // Solo lo que hay que ir a cobrar.
            ->filter(fn (array $fila) => $fila['documentos'] > 0)
            ->sortByDesc(fn (array $fila) => $fila['saldo'])
            ->values();
    }

    /**
     * @param  Collection<int, SalidaRutaDocumento>  $grupo
     * @return array<string, mixed>
     */
    private function fila(Collection $grupo, int|string|null $rutaId): array
    {
        // Delegación, no cálculo propio: las cifras del grupo salen del MISMO servicio
        // que calculó las de la pantalla entera.
        $dinero = $this->cobranza->resumen($grupo);
        $antiguedad = $this->cobranza->antiguedad($grupo);

        return [
            'ruta_id' => $rutaId === null ? null : (int) $rutaId,
            'ruta' => $grupo->first()?->salida?->ruta?->nombre ?? 'Sin ruta',
            'saldo' => $dinero['saldo'],
            'documentos' => $dinero['documentos_con_saldo'],
            // El saldo de la fila también va partido: una ruta con todo trabado fuera
            // del PPQ tiene un problema nuestro, no de cobranza.
            'fuera_ppq' => $dinero['saldo_fuera_ppq'],
            'en_ppq' => $dinero['saldo_en_ppq'],
            'tramo_viejo' => $this->tramoMasViejo($antiguedad),
            'sin_fecha' => $antiguedad['sin_fecha']['documentos'],
        ];
    }

    /**
     * El tramo de antigüedad más viejo que todavía tiene documentos con saldo.
     *
     * Se recorre {@see Cobranza::TRAMOS} de nuevo a viejo y se devuelve el primero con
     * algo dentro; los tramos NO se escriben acá, se leen de donde ya están definidos.
     * `sin_fecha` queda fuera a propósito: no tener fecha no es ser viejo, es no saber,
     * y va en su propia columna para que nadie lo confunda con antigüedad.
     *
     * @param  array<string, array{documentos: int, monto: float}>  $antiguedad
     */
    private function tramoMasViejo(array $antiguedad): ?string
    {
        $viejo = null;

        foreach (array_keys(Cobranza::TRAMOS) as $tramo) {
            if (($antiguedad[$tramo]['documentos'] ?? 0) > 0) {
                $viejo = $tramo;
            }
        }

        return $viejo;
    }
}
