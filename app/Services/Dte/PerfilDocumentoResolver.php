<?php

namespace App\Services\Dte;

use App\Enums\TipoDte;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Models\Dte;

/**
 * Punto ÚNICO donde se pregunta «¿este cliente tiene exigencias documentales propias?».
 *
 * Todo lo que hay debajo depende de que la respuesta por defecto sea NO: un cliente sin
 * perfil —que son casi todos— tiene que salir de acá con `null` y seguir el camino
 * histórico sin enterarse de que este código existe. Por eso el resolutor no inventa
 * valores por omisión ni «perfiles vacíos»: o hay fila activa, o no hay nada.
 *
 * Memoiza por cliente dentro del request porque `recalcular()` puede preguntar varias
 * veces por el mismo documento mientras cuadra los totales, y eso no debe convertirse en
 * una consulta por línea.
 *
 * SOLO LECTURA: no crea perfiles, no los modifica y no decide nada fiscal. Devuelve la
 * regla declarada; quién la aplica es {@see DteBorradorService}.
 */
class PerfilDocumentoResolver
{
    /** @var array<int, ClientePerfilDocumento|null> */
    private array $memo = [];

    /** Perfil ACTIVO del cliente, o null si no tiene o está desactivado. */
    public function paraCliente(?int $clienteId): ?ClientePerfilDocumento
    {
        if ($clienteId === null) {
            return null;
        }

        if (! array_key_exists($clienteId, $this->memo)) {
            $perfil = ClientePerfilDocumento::with('tiposNc')
                ->where('cliente_id', $clienteId)
                ->where('activo', true)
                ->first();

            $this->memo[$clienteId] = $perfil;
        }

        return $this->memo[$clienteId];
    }

    /** Perfil activo del cliente de un documento. */
    public function para(Dte $dte): ?ClientePerfilDocumento
    {
        return $this->paraCliente($dte->cliente_id);
    }

    /**
     * Regla declarada para la modalidad de ESTA nota de crédito, o null si el documento
     * no es una NC, el cliente no tiene perfil, o esa modalidad no está mapeada. Los tres
     * casos significan lo mismo para quien llama: seguí con el criterio de siempre.
     */
    public function reglaNotaCredito(Dte $dte): ?ClientePerfilTipoNc
    {
        if ($dte->tipo_dte !== TipoDte::NotaCredito) {
            return null;
        }

        return $this->para($dte)?->reglaPara($dte->tipo_nota_credito);
    }

    /** Olvida lo memoizado. Necesario cuando un test cambia el perfil en caliente. */
    public function olvidar(): void
    {
        $this->memo = [];
    }
}
