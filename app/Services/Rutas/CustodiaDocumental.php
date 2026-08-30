<?php

namespace App\Services\Rutas;

use App\Enums\ModoPapelFisico;
use App\Models\Dte;
use App\Models\SalidaRutaDocumento;
use App\Services\Dte\PerfilDocumentoResolver;

/**
 * ¿Volvió el CCF FÍSICO firmado y sellado de este documento, y qué exige el cliente al
 * respecto antes de dejarlo cobrar?
 *
 * ─────────────────────────── Dos hechos, no uno ───────────────────────────
 *
 * Esta clase existe para que el sistema deje de tener una sola respuesta a dos preguntas
 * distintas:
 *
 *   · ENTREGA   — el cliente recibió la mercadería. Lo prueba el albarán, que llega solo
 *                 al correo ({@see AlbaranLocalizador}).
 *   · CUSTODIA  — nosotros tenemos de vuelta el papel firmado. Lo prueba una persona que
 *                 lo recibió en oficina, y no hay forma de derivarlo de ningún dato.
 *
 * Entre las dos pasan días, a veces meses, y a veces el papel no vuelve nunca. Tratarlas
 * como el mismo estado hace invisible exactamente el hueco que hay que ver. Por eso acá
 * NUNCA se consulta el albarán: si el albarán decidiera algo de esto, volverían a ser lo
 * mismo con otro nombre.
 *
 * ──────────────────────── Por qué el vínculo es solo `dte_id` ────────────────────────
 *
 * La recepción del papel se busca por el vínculo EXPLÍCITO al DTE y por nada más. Ni por
 * número de control, ni por orden de compra. El número de control no es único entre
 * ambientes —producción y pruebas llevan correlativos independientes— y una fila de un
 * documento de pruebas no puede autorizar el cobro de uno real. La orden de compra ampara
 * varios documentos, así que daría por recuperado un papel que nadie vio.
 *
 * La consecuencia se asume a propósito: un documento HISTÓRICO cargado a mano (sin
 * `dte_id`) no acredita custodia. Es preferible decir «no consta» a afirmar que tenemos un
 * papel que quizá no tenemos.
 *
 * ─────────────────────────── Sin perfil, nada cambia ───────────────────────────
 *
 * El modo por defecto es {@see ModoPapelFisico::NoRequerir}. Un cliente sin perfil
 * documental —que son casi todos— no se entera de que esta clase existe, y el
 * comportamiento del cobro es exactamente el de antes.
 *
 * SOLO LECTURA: no marca papeles, no crea filas y no decide nada fiscal.
 */
class CustodiaDocumental
{
    public function __construct(private readonly PerfilDocumentoResolver $perfiles) {}

    /** Modo declarado por el cliente del documento. Sin perfil activo: no requerir. */
    public function modo(Dte $dte): ModoPapelFisico
    {
        return $this->perfiles->para($dte)?->modoPapelFisico() ?? ModoPapelFisico::NoRequerir;
    }

    /**
     * ¿Consta que el documento físico de este DTE regresó?
     *
     * Basta con UNA fila de seguimiento que lo registre. Se aceptan también las salidas
     * ya finalizadas —el papel casi siempre vuelve después de cerrar la salida— y no se
     * exige que la fila sea la asignación vigente: el papel volvió aunque el documento se
     * haya movido de salida después.
     */
    public function papelRecibido(Dte $dte): bool
    {
        return SalidaRutaDocumento::query()
            ->where('dte_id', $dte->id)
            ->conDocumentacionFisica()
            ->exists();
    }

    /** La fila que registra la recepción, para poder decir quién y cuándo. */
    public function registroDeRecepcion(Dte $dte): ?SalidaRutaDocumento
    {
        return SalidaRutaDocumento::query()
            ->with(['documentacionRecibidaPor:id,name', 'salida:id,ruta_id,fecha_inicio'])
            ->where('dte_id', $dte->id)
            ->conDocumentacionFisica()
            ->orderBy('documentacion_fisica_recibida_at')
            ->first();
    }

    /**
     * POR QUÉ este documento no se puede cobrar todavía por falta del papel, o `null` si
     * no hay impedimento —sea porque el papel volvió, sea porque el cliente no lo exige—.
     *
     * El mensaje dice exactamente qué falta y qué hay que hacer: un bloqueo que solo dice
     * «no se puede» obliga a adivinar, y quien lo lee es la persona que está intentando
     * cobrar, no quien programó la regla.
     */
    public function motivoBloqueo(Dte $dte): ?string
    {
        if (! $this->modo($dte)->bloquea() || $this->papelRecibido($dte)) {
            return null;
        }

        return 'Falta el documento físico: no consta que el CCF impreso, firmado y sellado, haya regresado. '
            .'Registrá su recepción en la salida de ruta correspondiente y volvé a intentarlo.';
    }

    /**
     * Aviso —no bloqueo— cuando el cliente pidió que solo se advierta. Devuelve `null` si
     * el modo no es advertir o si el papel ya volvió.
     */
    public function advertencia(Dte $dte): ?string
    {
        if (! $this->modo($dte)->advierte() || $this->papelRecibido($dte)) {
            return null;
        }

        return 'No consta el regreso del documento físico firmado y sellado. '
            .'Se puede cobrar igual, pero conviene buscarlo antes de cerrar el lote.';
    }

    /**
     * Estado de custodia en una etiqueta corta, para pantallas.
     *
     * @return array{recibido: bool, modo: ModoPapelFisico, label: string}
     */
    public function estado(Dte $dte): array
    {
        $recibido = $this->papelRecibido($dte);

        return [
            'recibido' => $recibido,
            'modo' => $this->modo($dte),
            'label' => $recibido ? 'Documento físico recibido' : 'Documento físico sin registrar',
        ];
    }
}
