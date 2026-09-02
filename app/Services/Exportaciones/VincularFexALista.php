<?php

namespace App\Services\Exportaciones;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\Exportacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vincula (y desvincula) facturas de exportación con una lista de empaque.
 *
 * Es el ÚNICO sitio que escribe la relación, y por eso es el único que tiene que
 * saber que hoy conviven dos representaciones del mismo vínculo:
 *
 *   - `exportacion_dte`, la relación real uno-a-muchos;
 *   - `exportaciones.dte_id`, la columna histórica, que se mantiene apuntando a
 *     la PRIMERA factura para que todo consumidor anterior siga funcionando
 *     (señaladamente `Dte::exportacionOrigen()`, que alimenta el enlace desde el
 *     editor del documento hacia su lista de origen).
 *
 * Mantener las dos sincronizadas desde un solo lugar es lo que permite no borrar
 * la columna todavía: mientras exista, no puede quedarse desfasada.
 *
 * REGLAS DE INTEGRIDAD, todas comprobadas acá y no en la vista, porque la vista
 * no protege de una petición armada a mano ni de un `?lista=` manipulado:
 *
 *   1. el documento es una FEX (tipo 11);
 *   2. su receptor es el MISMO cliente del directorio que tiene la lista;
 *   3. su estado fiscal admite el vínculo (ni rechazado ni invalidado);
 *   4. su ambiente coincide con el de las facturas que la lista ya tiene;
 *   5. no pertenece a otra lista;
 *   6. la lista se puede editar (ni finalizada ni pendiente de clasificar).
 *
 * La base refuerza las que se pueden expresar como índice: `exportacion_dte` es
 * único por (lista, dte) y único por dte.
 */
class VincularFexALista
{
    /**
     * Estados fiscales en los que una FEX puede vincularse a una lista.
     *
     * Rechazado e invalidado quedan fuera: un documento que Hacienda no aceptó, o
     * que se anuló después, no respalda el embarque. Vincularlo daría una lista
     * «facturada» cuyo respaldo no vale, que es peor que una lista sin facturar
     * porque parece resuelta.
     *
     * @var list<EstadoDte>
     */
    public const ESTADOS_VINCULABLES = [
        EstadoDte::Borrador,
        EstadoDte::Generado,
        EstadoDte::Firmado,
        EstadoDte::Enviado,
        EstadoDte::Aceptado,
    ];

    /**
     * Vincula una FEX a la lista. Idempotente: volver a vincular la misma factura
     * no duplica nada ni falla.
     *
     * @throws ValidationException si el documento no cumple alguna regla de integridad
     */
    public function vincular(Exportacion $exportacion, Dte $dte): Exportacion
    {
        return DB::transaction(function () use ($exportacion, $dte) {
            // Bloquea la fila: dos peticiones simultáneas sobre la misma lista se
            // serializan y la segunda ve el estado ya escrito por la primera.
            $lista = Exportacion::whereKey($exportacion->id)->lockForUpdate()->firstOrFail();

            $this->exigirListaEditable($lista);
            $this->exigirDocumentoVinculable($lista, $dte);

            $yaVinculado = DB::table('exportacion_dte')
                ->where('exportacion_id', $lista->id)
                ->where('dte_id', $dte->id)
                ->exists();

            if ($yaVinculado) {
                $this->sincronizarColumnaHistorica($lista);

                return $lista->fresh();
            }

            $enOtraLista = DB::table('exportacion_dte')
                ->where('dte_id', $dte->id)
                ->where('exportacion_id', '!=', $lista->id)
                ->value('exportacion_id');

            if ($enOtraLista !== null) {
                throw ValidationException::withMessages([
                    'dte_id' => 'Esa factura ya pertenece a la lista de empaque #'.$enOtraLista
                        .'. Una factura de exportación respalda un solo embarque: quitá primero ese vínculo si de verdad corresponde moverla.',
                ]);
            }

            // La columna histórica también es única, así que una FEX que ya figura
            // como `dte_id` de otra lista —sin fila en el pivote, caso de una
            // instalación a medio migrar— tiene que rechazarse igual.
            $enColumnaDeOtraLista = Exportacion::where('dte_id', $dte->id)
                ->where('id', '!=', $lista->id)
                ->value('id');

            if ($enColumnaDeOtraLista !== null) {
                throw ValidationException::withMessages([
                    'dte_id' => 'Esa factura ya figura como la factura de la lista de empaque #'.$enColumnaDeOtraLista.'.',
                ]);
            }

            // `principal` = la primera factura de la lista. Es la que ocupa la columna
            // histórica `dte_id`, así que solo puede haber una y no se reasigna al
            // vincular la segunda.
            $esPrimera = ! DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->exists();

            $lista->dtes()->attach($dte->id, ['principal' => $esPrimera]);

            $this->sincronizarColumnaHistorica($lista);

            return $lista->fresh();
        });
    }

    /**
     * Quita el vínculo con una factura. No borra el DTE ni lo modifica: la factura
     * es evidencia fiscal y sigue existiendo por su cuenta.
     *
     * @throws ValidationException si la lista no se puede editar
     */
    public function desvincular(Exportacion $exportacion, Dte $dte): Exportacion
    {
        return DB::transaction(function () use ($exportacion, $dte) {
            $lista = Exportacion::whereKey($exportacion->id)->lockForUpdate()->firstOrFail();

            $this->exigirListaEditable($lista);

            $lista->dtes()->detach($dte->id);

            // Si se quitó la principal, la columna histórica pasa a la factura que
            // quede más antigua; sin ninguna, vuelve a NULL. Nunca se queda apuntando
            // a un vínculo que ya no existe.
            if ($lista->dte_id === $dte->id) {
                $lista->forceFill(['dte_id' => null])->save();
                $lista->refresh();
            }

            $this->sincronizarColumnaHistorica($lista);

            return $lista->fresh();
        });
    }

    /**
     * Sincroniza la relación nueva a partir de la columna histórica. Se llama en el
     * camino de creación de FEX que ya existía y escribe `dte_id` directamente, para
     * que ese camino no tenga que conocer la tabla nueva.
     */
    public function sincronizarDesdeColumna(Exportacion $exportacion): void
    {
        if ($exportacion->dte_id === null) {
            return;
        }

        $yaVinculado = DB::table('exportacion_dte')
            ->where('exportacion_id', $exportacion->id)
            ->where('dte_id', $exportacion->dte_id)
            ->exists();

        if ($yaVinculado) {
            return;
        }

        $esPrimera = ! DB::table('exportacion_dte')->where('exportacion_id', $exportacion->id)->exists();

        $exportacion->dtes()->attach($exportacion->dte_id, ['principal' => $esPrimera]);
    }

    // -------------------------------------------------------------------- interno

    /**
     * INVARIANTE del módulo: `exportaciones.dte_id` siempre apunta a una factura que
     * está en el pivote de ESA lista, o a NULL.
     *
     * Se recalcula desde el pivote en cada escritura en vez de mantenerlo a mano en
     * cada rama: es la forma de que no exista ningún camino —vincular, desvincular,
     * vincular la segunda, quitar la principal— capaz de dejar la columna apuntando
     * a un vínculo inexistente.
     */
    private function sincronizarColumnaHistorica(Exportacion $lista): void
    {
        $principal = DB::table('exportacion_dte')
            ->where('exportacion_id', $lista->id)
            ->orderByDesc('principal')
            ->orderBy('dte_id')
            ->first();

        $nuevoId = $principal->dte_id ?? null;

        if ($nuevoId !== null) {
            // Reafirma cuál es la principal: si la anterior se desvinculó, la más
            // antigua que quede ocupa su lugar.
            DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->update(['principal' => false]);
            DB::table('exportacion_dte')
                ->where('exportacion_id', $lista->id)
                ->where('dte_id', $nuevoId)
                ->update(['principal' => true]);
        }

        if ($lista->dte_id !== $nuevoId) {
            $lista->forceFill(['dte_id' => $nuevoId])->save();
        }
    }

    /**
     * `ambiente` viene casteado a enum en unos sitios y como cadena en otros según de
     * dónde salga la fila; se normaliza acá para que el mensaje no dependa de eso.
     */
    private function etiquetaAmbiente(mixed $ambiente): string
    {
        return $ambiente instanceof \BackedEnum ? (string) $ambiente->value : (string) $ambiente;
    }

    /** @throws ValidationException */
    private function exigirListaEditable(Exportacion $lista): void
    {
        if ($lista->requiereRevision()) {
            throw ValidationException::withMessages([
                'dte_id' => 'La lista viene del flujo anterior y está pendiente de clasificar: un administrador tiene que resolverla antes de vincularle facturas.',
            ]);
        }

        if (! $lista->puedeEditarse()) {
            throw ValidationException::withMessages([
                'dte_id' => 'La lista está finalizada: reabrila para cambiar sus facturas.',
            ]);
        }
    }

    /** @throws ValidationException */
    private function exigirDocumentoVinculable(Exportacion $lista, Dte $dte): void
    {
        if ($dte->tipo_dte !== TipoDte::FacturaExportacion) {
            throw ValidationException::withMessages([
                'dte_id' => 'El documento #'.$dte->id.' no es una factura de exportación.',
            ]);
        }

        if (! in_array($dte->estado, self::ESTADOS_VINCULABLES, true)) {
            throw ValidationException::withMessages([
                'dte_id' => 'La factura #'.$dte->id.' está '.$dte->estado->label()
                    .' y no puede respaldar un embarque. Vinculá una factura vigente.',
            ]);
        }

        // Receptor: la FEX tiene que estar emitida al MISMO cliente del directorio
        // que tiene la lista. Sin esto, cambiar el cliente en el formulario de la FEX
        // —el que se abre con `?lista=`— dejaría la lista de un importador vinculada
        // a la factura de otro.
        $clienteLista = $lista->cliente?->cliente_id;

        if ($clienteLista === null) {
            throw ValidationException::withMessages([
                'dte_id' => 'La lista no tiene un cliente del directorio vinculado: habilitá al cliente para exportación antes de facturar.',
            ]);
        }

        if ((int) $dte->cliente_id !== (int) $clienteLista) {
            throw ValidationException::withMessages([
                'dte_id' => 'La factura #'.$dte->id.' está emitida a otro cliente ('.($dte->cliente?->nombre ?? 'id '.$dte->cliente_id)
                    .') y esta lista es de '.($lista->cliente?->nombreLegal() ?? 'otro').'. No se puede vincular.',
            ]);
        }

        // Ambiente: todas las facturas de una lista tienen que ser del mismo. Se
        // compara contra las que la lista YA tiene y no contra el ambiente activo de
        // la instalación, para no rechazar documentos históricos legítimos.
        $ambienteExistente = $lista->facturas()->first()?->ambiente;

        if ($ambienteExistente !== null && $dte->ambiente !== $ambienteExistente) {
            throw ValidationException::withMessages([
                'dte_id' => 'La factura #'.$dte->id.' es del ambiente «'.$this->etiquetaAmbiente($dte->ambiente)
                    .'» y la lista ya tiene facturas del ambiente «'.$this->etiquetaAmbiente($ambienteExistente)
                    .'». Mezclar documentos de prueba y de producción en un mismo embarque no está permitido.',
            ]);
        }
    }
}
