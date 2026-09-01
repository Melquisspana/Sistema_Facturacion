<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\TipoEventoCustodia;
use App\Models\CustodiaDocumentoEvento;
use App\Models\SalidaRutaDocumento;
use Illuminate\Support\Collection;

/**
 * Lo que no cuadra y alguien tiene que mirar.
 *
 * ─────────────────────── Qué es una excepción y qué no ───────────────────────
 *
 * Una excepción es una situación que NO se resuelve sola con el paso del tiempo. Un
 * documento que salió ayer y todavía no tiene albarán no lo es: es la espera normal. Un
 * documento entregado hace tres semanas cuyo papel nadie encuentra, sí.
 *
 * La distinción importa porque una bandeja llena de ruido se deja de mirar, y entonces deja
 * de servir para lo único que existe: que el hueco se vea el día que aparece.
 *
 * ─────────────────────── Sobre el mismo universo que la bandeja ───────────────────────
 *
 * Trabaja sobre la colección YA HIDRATADA que devuelve {@see BandejaDocumentos::consultar()},
 * con sus mismos filtros y su misma ventana. No abre consultas propias ni reescribe reglas:
 * si preguntara por su cuenta qué es «entregado» o «recibido», existirían dos versiones de
 * la verdad y tarde o temprano una contradiría a la pantalla que la abre.
 *
 * Los umbrales de días son configurables (`config/rutas.php`). Empiezan conservadores: es
 * preferible que la bandeja avise tarde a que avise de todo.
 *
 * NO envía nada. Esta fase solo muestra; correos y notificaciones llegan cuando alguien
 * confirme que los umbrales son los correctos.
 */
class BandejaExcepciones
{
    public const ENTREGADO_SIN_PAPEL = 'entregado_sin_papel';

    public const SALIDA_CERRADA_PENDIENTE = 'salida_cerrada_pendiente';

    public const RECIBIDO_MAS_DE_UNA_VEZ = 'recibido_mas_de_una_vez';

    public const ALBARAN_AMBIGUO = 'albaran_ambiguo';

    public const CUSTODIA_PERSONA_INACTIVA = 'custodia_persona_inactiva';

    public const SIN_RESPONSABLE_CONOCIDO = 'sin_responsable_conocido';

    public const CUSTODIA_CON_INCIDENCIA = 'custodia_con_incidencia';

    /** Título y explicación de cada caso, para que la pantalla no tenga que inventarlos. */
    public const CATALOGO = [
        self::ENTREGADO_SIN_PAPEL => [
            'titulo' => 'Entregado, pero el CCF físico no volvió',
            'detalle' => 'El albarán prueba que el cliente recibió la mercadería y el papel firmado sigue sin registrarse en oficina.',
        ],
        self::SALIDA_CERRADA_PENDIENTE => [
            'titulo' => 'Salida finalizada con documentos pendientes',
            'detalle' => 'El viaje ya se cerró y todavía hay papeles sin recibir.',
        ],
        self::RECIBIDO_MAS_DE_UNA_VEZ => [
            'titulo' => 'Recepción registrada más de una vez',
            'detalle' => 'Hubo una recepción anulada y otra posterior. Conviene revisar cuál es la buena.',
        ],
        self::ALBARAN_AMBIGUO => [
            'titulo' => 'Vínculo de albarán ambiguo',
            'detalle' => 'Hay varios albaranes posibles, o el que llegó no consta como de entrega.',
        ],
        self::CUSTODIA_PERSONA_INACTIVA => [
            'titulo' => 'En manos de una persona inactiva',
            'detalle' => 'El papel figura con alguien que ya no está en la operación. Hay que transferirlo o registrar su recepción.',
        ],
        self::SIN_RESPONSABLE_CONOCIDO => [
            'titulo' => 'Salió sin responsable conocido',
            'detalle' => 'El documento viajó en una salida que ya arrancó y nadie registró quién se llevó el papel.',
        ],
        self::CUSTODIA_CON_INCIDENCIA => [
            'titulo' => 'Con incidencia reportada',
            'detalle' => 'Alguien reportó un problema con el documento físico.',
        ],
    ];

    /**
     * Clasifica la colección. Un documento puede aparecer en más de un grupo: son problemas
     * distintos y esconder uno porque ya salió en otro haría que se resuelva a medias.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos  ya hidratados
     * @return array<string, Collection<int, SalidaRutaDocumento>>
     */
    public function clasificar(Collection $documentos): array
    {
        $recepcionesPorDocumento = $this->recepcionesPorDocumento($documentos);

        return [
            self::ENTREGADO_SIN_PAPEL => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $d->entregado()
                    && ! $d->documentacionFisicaRecibida()
                    && $this->diasDesdeEntrega($d) >= $this->umbral('dias_sin_papel')
            )->values(),

            self::SALIDA_CERRADA_PENDIENTE => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $d->salida?->estado === EstadoSalidaRuta::Finalizada
                    && ! $d->documentacionFisicaRecibida()
            )->values(),

            self::RECIBIDO_MAS_DE_UNA_VEZ => $documentos->filter(
                fn (SalidaRutaDocumento $d) => ($recepcionesPorDocumento[$d->id] ?? 0) > 1
            )->values(),

            self::ALBARAN_AMBIGUO => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $d->entregaExcepcion() !== null
            )->values(),

            self::CUSTODIA_PERSONA_INACTIVA => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $d->custodiaEnPersonaInactiva()
            )->values(),

            self::SIN_RESPONSABLE_CONOCIDO => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $this->salioSinResponsable($d)
            )->values(),

            self::CUSTODIA_CON_INCIDENCIA => $documentos->filter(
                fn (SalidaRutaDocumento $d) => $d->estadoCustodia()->esExcepcion()
            )->values(),
        ];
    }

    /**
     * Cuántos documentos hay en cada grupo. Para las tarjetas del resumen, que no necesitan
     * la lista entera.
     *
     * @param  array<string, Collection<int, SalidaRutaDocumento>>  $grupos
     * @return array<string, int>
     */
    public function contar(array $grupos): array
    {
        return array_map(fn (Collection $c) => $c->count(), $grupos);
    }

    // ------------------------------------------------------------------ interno

    /**
     * El documento viajó en una salida que ya arrancó y nadie registró que alguien se
     * llevara el papel.
     *
     * Solo cuenta desde que la salida está EN CURSO: mientras está planificada, que el papel
     * siga en bodega es exactamente lo correcto.
     */
    private function salioSinResponsable(SalidaRutaDocumento $documento): bool
    {
        $estado = $documento->salida?->estado;

        if ($estado !== EstadoSalidaRuta::EnCurso && $estado !== EstadoSalidaRuta::Finalizada) {
            return false;
        }

        // Si ya volvió, el hueco quedó cerrado aunque nadie hubiera anotado la salida.
        if ($documento->documentacionFisicaRecibida()) {
            return false;
        }

        return $documento->ultimoEventoCustodia() === null;
    }

    /** Días desde que el albarán prueba la entrega. Null-safe: sin fecha, no cuenta. */
    private function diasDesdeEntrega(SalidaRutaDocumento $documento): int
    {
        $fecha = $documento->fechaEntrega();

        return $fecha === null ? 0 : (int) $fecha->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    /**
     * Cuántas recepciones hay en el historial de cada documento, ANULADAS INCLUIDAS.
     *
     * Que haya más de una no significa que el sistema haya fallado —el índice único impide
     * que dos estén vigentes a la vez— sino que alguien anuló una y registró otra. Eso es
     * una corrección legítima y vale la pena mirarla.
     *
     * @param  Collection<int, SalidaRutaDocumento>  $documentos
     * @return array<int, int>
     */
    private function recepcionesPorDocumento(Collection $documentos): array
    {
        if ($documentos->isEmpty()) {
            return [];
        }

        return CustodiaDocumentoEvento::query()
            ->whereIn('salida_ruta_documento_id', $documentos->pluck('id')->all())
            ->where('tipo', TipoEventoCustodia::RecepcionOficina->value)
            ->selectRaw('salida_ruta_documento_id, COUNT(*) as total')
            ->groupBy('salida_ruta_documento_id')
            ->pluck('total', 'salida_ruta_documento_id')
            ->all();
    }

    private function umbral(string $clave): int
    {
        return (int) config('rutas.excepciones.'.$clave, 5);
    }
}
