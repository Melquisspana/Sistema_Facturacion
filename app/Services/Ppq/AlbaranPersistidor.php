<?php

namespace App\Services\Ppq;

use App\Exceptions\Ppq\AlbaranDadoDeBajaException;
use App\Models\PpqAlbaran;
use App\Support\Albaran;
use App\Support\OrdenCompra;

/**
 * Punto ÚNICO de persistencia de albaranes en `ppq_albaranes`.
 *
 * Lo usan por igual el alta desde la pantalla de PPQ (PpqItemController) y la
 * sincronización automática desde Gmail (ppq:sincronizar-albaranes), para que ambas
 * compartan las mismas reglas de identidad y autocorrección de monto.
 *
 * IDENTIDAD (idempotencia): un albarán es único por `numero_albaran` + `numero_orden_compra`
 * (índice `ppq_albaran_oc_unico`). Registrar dos veces el mismo par devuelve la MISMA fila;
 * nunca duplica. Por eso la sincronización se puede correr cuantas veces se quiera.
 *
 * LA SALA NO ES COMPARTIDA: resolverla y escribir `cliente_sucursal_id` es EXCLUSIVO de la
 * sincronización automática, vía registrarConSala(). El alta desde la pantalla usa
 * registrar(), que deja la sala intacta. Ese vínculo es fiscal y solo se establece por la
 * vía automática, que además reporta las excepciones; desde la pantalla se captura un
 * albarán suelto (típicamente el de una NC) sin datos suficientes para decidirlo.
 *
 * Por eso registrar() es el método "seguro" por defecto: quien quiera tocar la sala tiene
 * que pedirlo explícitamente.
 *
 * NO decide nada de conciliación (no toca `dte_id` ni los items del lote): solo deja el
 * albarán guardado.
 */
class AlbaranPersistidor
{
    public function __construct(private readonly SalaSucursalResolver $salas) {}

    /**
     * Registra (o reusa) un albarán SIN tocar su sala. Es la vía del alta desde la pantalla
     * de PPQ: `sala_codigo` y `cliente_sucursal_id` quedan como estaban (vacíos en un alta
     * nueva). `$origen` = 'gmail' (parseado del correo) o 'manual' (capturado a mano para
     * una NC). La fecha se normaliza desde d/m/Y.
     *
     * Ojo: `$origen` describe de dónde salieron los DATOS, no por qué vía se guardan — la
     * pantalla también registra con origen 'gmail' cuando el albarán vino de un correo. Por
     * eso la sala se decide por el método que se llama y nunca por `$origen`.
     *
     * @param  array<string, mixed>  $datos  numero_albaran, numero_orden_compra, monto_albaran,
     *                                       fecha_albaran, gmail_message_id, archivo_path
     */
    public function registrar(array $datos, string $origen): PpqAlbaran
    {
        return $this->guardar($datos, $origen, resolverSala: false);
    }

    /**
     * Igual que registrar(), pero además resuelve la sala y completa `cliente_sucursal_id`.
     * EXCLUSIVO de la sincronización automática (`ppq:sincronizar-albaranes`), que es la
     * única que reporta las excepciones de sala para revisión manual.
     *
     * @param  array<string, mixed>  $datos  además acepta sala_codigo
     */
    public function registrarConSala(array $datos, string $origen): PpqAlbaran
    {
        return $this->guardar($datos, $origen, resolverSala: true);
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws AlbaranDadoDeBajaException si la identidad ya existe pero está dada de baja
     */
    private function guardar(array $datos, string $origen, bool $resolverSala): PpqAlbaran
    {
        $numero = Albaran::numeroLimpio($datos['numero_albaran'] ?? null);
        $oc = $datos['numero_orden_compra'] ?? null;

        $this->rechazarSiEstaDadoDeBaja($numero, $oc);

        $albaran = PpqAlbaran::firstOrCreate(
            [
                'numero_albaran' => $numero,
                'numero_orden_compra' => $oc,
            ],
            [
                'monto_albaran' => $datos['monto_albaran'] ?? null,
                'fecha_albaran' => Albaran::fecha($datos['fecha_albaran'] ?? null),
                'origen' => $origen,
                'gmail_message_id' => $datos['gmail_message_id'] ?? null,
                'archivo_path' => $datos['archivo_path'] ?? null,
            ],
        );

        // Autocorrección: el registro YA existía (mismo número+OC) pero quedó SIN
        // monto — típicamente porque una corrida anterior del parser no pudo
        // extraerlo (ej. un bug ya corregido). Si esta vez sí se resolvió un monto,
        // se completa. NUNCA pisa un monto ya guardado (evita sorprender con un
        // valor distinto si un reparseo posterior da otra cosa).
        if ($albaran->monto_albaran === null && filled($datos['monto_albaran'] ?? null)) {
            $albaran->update(['monto_albaran' => $datos['monto_albaran']]);
        }

        if ($resolverSala) {
            $this->completarSala($albaran, $datos);
        }

        return $albaran;
    }

    /**
     * Corta antes del `firstOrCreate` si esa identidad ya existe pero está dada de baja.
     *
     * Hace falta mirar con `withTrashed()` porque el índice único `ppq_albaran_oc_unico`
     * NO distingue borrados, mientras que el scope de SoftDeletes sí los esconde: sin este
     * guard, `firstOrCreate` no encontraría la fila, intentaría insertar y reventaría con
     * una violación de integridad — que en la sincronización abortaba la corrida entera.
     *
     * No se restaura nada por cuenta propia: una baja es una decisión de una persona.
     */
    private function rechazarSiEstaDadoDeBaja(?string $numero, ?string $oc): void
    {
        if (blank($numero)) {
            return;
        }

        $borrado = PpqAlbaran::onlyTrashed()
            ->where('numero_albaran', $numero)
            ->where('numero_orden_compra', $oc)
            ->first(['id']);

        if ($borrado !== null) {
            throw new AlbaranDadoDeBajaException($numero, $oc, (int) $borrado->id);
        }
    }

    /**
     * Resuelve la sala de unos datos de albarán SIN persistir nada. La usa el dry-run
     * de la sincronización para clasificar antes de escribir.
     *
     * @param  array<string, mixed>  $datos
     * @return array{sala_codigo: ?string, cliente_sucursal_id: ?int, nombre: ?string, fuente: string, excepcion: bool}
     */
    public function resolverSala(array $datos): array
    {
        return $this->salas->resolver($this->codigoSala($datos));
    }

    /**
     * Completa `sala_codigo` y `cliente_sucursal_id` si están vacíos. Solo RELLENA:
     * nunca pisa un vínculo ya establecido (puede haberlo corregido una persona).
     * Si el código no resuelve a ninguna sucursal, la fila queda con el código y sin
     * `cliente_sucursal_id` — esa es la marca de excepción, y es deliberado: NO se crea
     * la sucursal automáticamente.
     *
     * @param  array<string, mixed>  $datos
     */
    private function completarSala(PpqAlbaran $albaran, array $datos): void
    {
        $resolucion = $this->salas->resolver($this->codigoSala($datos));

        $cambios = [];

        if (blank($albaran->sala_codigo) && filled($resolucion['sala_codigo'])) {
            $cambios['sala_codigo'] = $resolucion['sala_codigo'];
        }

        if ($albaran->cliente_sucursal_id === null && $resolucion['cliente_sucursal_id'] !== null) {
            $cambios['cliente_sucursal_id'] = $resolucion['cliente_sucursal_id'];
        }

        if ($cambios !== []) {
            $albaran->update($cambios);
        }
    }

    /**
     * Código de sala de unos datos de albarán, en orden de confianza: el explícito, el
     * derivado de la OC (posición fija) y, como último recurso, el 2º segmento del número
     * de albarán de Calleja ("AC01/0236/00/6359" -> "0236") para cuando la OC no se parseó.
     *
     * @param  array<string, mixed>  $datos
     */
    private function codigoSala(array $datos): ?string
    {
        if (filled($datos['sala_codigo'] ?? null)) {
            return (string) $datos['sala_codigo'];
        }

        $desdeOc = OrdenCompra::salaDesde($datos['numero_orden_compra'] ?? null);
        if (filled($desdeOc)) {
            return $desdeOc;
        }

        return Albaran::salaDesdeNumero(Albaran::numeroLimpio($datos['numero_albaran'] ?? null));
    }
}
