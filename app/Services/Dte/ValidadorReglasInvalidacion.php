<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Support\Dte\DocumentoIdentidadMh;
use App\Support\Dte\PlazoInvalidacion;
use App\Support\Dte\PoliticaInvalidacion;
use App\Support\Dte\RequisitosInvalidacion;
use App\Support\HoraNegocio;
use Illuminate\Database\Eloquent\Collection;

/**
 * ÚNICO punto donde se aplican las reglas FISCALES de la invalidación oficial sobre un
 * documento concreto y un evento concreto. Lo consumen —sin reimplementarlo— el
 * formulario web, el Form Request, el preview/preflight de consola, el serializador, el
 * servicio mock y la transmisión real.
 *
 * Cuatro bloques, en orden:
 *
 *  1. MATRIZ documento × motivo ({@see PoliticaInvalidacion}): qué exige y qué prohíbe.
 *  2. SUSTITUTO ({@see VerificadorDocumentoReemplazo}): existe, es del mismo emisor y
 *     ambiente, del tipo correspondiente, aceptado realmente por el MH y no invalidado.
 *  3. DEPENDENCIAS FISCALES: un CCF con nota de crédito/débito VALIDADA VIGENTE en su
 *     contra no se invalida hasta que esas notas se invaliden primero. Qué tipos
 *     dependen de esas notas lo decide {@see PoliticaInvalidacion::dependeDeNotasVigentes()},
 *     no este validador y menos el modelo: listar relaciones y prohibir invalidar son
 *     responsabilidades distintas.
 *
 *  4. PLAZO de transmisión desde el día del sello, hasta el último segundo local
 *     ({@see PlazoInvalidacion}, decisión 0005). Tampoco admite override.
 *
 * El bloque 3 NO tiene override. No hay checkbox, parámetro HTTP ni bandera de consola
 * que lo salte: el antiguo `--confirmo-nc-relacionada` (y su casilla equivalente en la
 * web) trataba una regla fiscal como si fuera un riesgo asumible por quien factura. Se
 * conserva el parámetro solo para no romper scripts antiguos, y avisa de que ya no hace
 * nada (ver los comandos de consola y el Form Request).
 *
 * Esta clase NO evalúa los candados del ENTORNO (flags, firma, endpoint, producción):
 * esos siguen en {@see DteInvalidacionService::evaluarCandados()}, que llama a esta.
 */
class ValidadorReglasInvalidacion
{
    public function __construct(
        private readonly VerificadorDocumentoReemplazo $verificador,
    ) {}

    /** Requisitos de la combinación documento × motivo, sin tocar la base de datos. */
    public function requisitos(Dte $dte, EventoInvalidacionData $evento): RequisitosInvalidacion
    {
        return PoliticaInvalidacion::requisitos($dte->tipo_dte, $evento->tipoAnulacion);
    }

    /**
     * Todos los problemas fiscales del evento, en lenguaje de quien factura. Lista vacía
     * = el evento cumple la matriz, el sustituto, las dependencias y el plazo.
     *
     * @return array<int, string>
     */
    public function problemas(Dte $dte, EventoInvalidacionData $evento): array
    {
        $requisitos = $this->requisitos($dte, $evento);

        // Tipo sin regla conocida: se corta aquí. Seguir evaluando con la matriz de otro
        // documento sería exactamente lo que la política prohíbe.
        if (! $requisitos->soportado) {
            return [(string) $requisitos->razonNoSoportado];
        }

        return array_merge(
            $this->problemasDeMotivo($requisitos, $evento),
            $this->problemasDeReemplazo($dte, $evento, $requisitos),
            $this->problemasDeDependencias($dte),
            $this->problemasDePersonas($evento),
            $this->problemasDePlazo($dte),
        );
    }

    /**
     * Responsable y solicitante (Normativa 2.0, Anexo V, campos 112-117; issue #52). Solo
     * se revisa a quien trae algún dato: que falten por completo ya lo reportan los
     * candados del entorno, con la pista de configuración.
     *
     * @return array<int, string>
     */
    private function problemasDePersonas(EventoInvalidacionData $evento): array
    {
        $problemas = [];
        foreach ([
            'el responsable' => [$evento->nombreResponsable, $evento->tipoDocResponsable, $evento->numDocResponsable],
            'el solicitante' => [$evento->nombreSolicita, $evento->tipoDocSolicita, $evento->numDocSolicita],
        ] as $rol => [$nombre, $tipo, $numero]) {
            if (blank($nombre) && blank($tipo) && blank($numero)) {
                continue;
            }
            $problemas = array_merge($problemas, DocumentoIdentidadMh::problemas($rol, $nombre, $tipo, $numero));
        }

        return $problemas;
    }

    /** @return array<int, string> */
    public function problemasDePlazo(Dte $dte): array
    {
        if ($dte->fecha_procesamiento_mh === null) {
            return [];
        }

        // fhProcesamiento es hora LOCAL marcada como UTC: conservar el día tal cual.
        $fechaSello = $dte->fecha_procesamiento_mh->format('Y-m-d');
        $plazo = new PlazoInvalidacion;
        $limite = $plazo->limite($dte->tipo_dte, $fechaSello);
        if (! now()->greaterThan($limite)) {
            return [];
        }

        $mensaje = 'Fuera de plazo: el documento obtuvo el sello el '.$dte->fecha_procesamiento_mh->format('d/m/Y')
            .' y el plazo para invalidarlo venció el '.HoraNegocio::aLocal($limite)->format('d/m/Y')
            .' a las 23:59:59 (hora de El Salvador). Hacienda no da sello a una invalidación fuera de plazo.';
        if (! $plazo->calendarioCompleto($dte->tipo_dte, $fechaSello)) {
            $mensaje .= ' (calculado sin calendario de días inhábiles '.$plazo->anioCalendario($fechaSello).'; cargarlo en config/dte.php)';
        }

        return [$mensaje];
    }

    /**
     * Notas de crédito/débito vigentes en contra de `$dte`, EXISTAN O NO consecuencias.
     * Es una consulta descriptiva: dice qué hay, no qué se puede hacer.
     *
     * @return Collection<int, Dte>
     */
    public function notasVigentes(Dte $dte): Collection
    {
        if (! $dte->exists) {
            return new Collection;
        }

        return $dte->notasFiscalesVigentes()->get();
    }

    /**
     * Notas aceptadas realmente o en trámite que PROHÍBEN invalidar este documento; vacía cuando no hay
     * notas o cuando el tipo documental no depende de ellas según
     * {@see PoliticaInvalidacion::dependeDeNotasVigentes()}.
     *
     * Se expone aparte de {@see problemas()} porque la UI necesita distinguir este bloqueo
     * —que se resuelve invalidando esas notas, y que además se puede enlazar— de un
     * problema de datos del formulario. Y se expone aparte de {@see notasVigentes()}
     * porque tener una nota relacionada y estar bloqueado por ella no son lo mismo: hoy
     * solo el CCF queda bloqueado, aunque el modelo pueda relacionar notas con cualquier
     * documento.
     *
     * @return Collection<int, Dte>
     */
    public function notasQueBloquean(Dte $dte): Collection
    {
        if (! PoliticaInvalidacion::dependeDeNotasVigentes($dte->tipo_dte)) {
            return new Collection;
        }

        return Dte::query()->where('dte_relacionado_id', $dte->id)
            ->whereIn('tipo_dte', [TipoDte::NotaCredito->value, TipoDte::NotaDebito->value])
            // Sin filtro por sello_invalidacion: una invalidación real deja la nota en estado
            // Invalidado, y una MOCK es simulada (para Hacienda la nota sigue vigente).
            ->where(fn ($q) => $q->whereIn('estado', [EstadoDte::Generado->value, EstadoDte::Firmado->value, EstadoDte::Enviado->value])
                ->orWhere(fn ($aceptadas) => $aceptadas->aceptadoRealMh()))
            ->get();
    }

    /** @return array<int, string> */
    private function problemasDeMotivo(RequisitosInvalidacion $requisitos, EventoInvalidacionData $evento): array
    {
        if ($requisitos->requiereMotivoTexto && blank($evento->motivoAnulacion)) {
            return ['El motivo 3 (Otro) exige explicar la anulación en texto (motivo.motivoAnulacion).'];
        }

        return [];
    }

    /**
     * Sustituto: exigido y verificado, o prohibido y exigido en null. La matriz no deja
     * celdas «opcionales», así que un valor no vacío donde corresponde null se rechaza
     * con explicación en vez de ignorarse en silencio.
     *
     * @return array<int, string>
     */
    private function problemasDeReemplazo(Dte $dte, EventoInvalidacionData $evento, RequisitosInvalidacion $requisitos): array
    {
        if ($requisitos->requiereReemplazo) {
            [, $problemas] = $this->verificador->verificar($dte, $evento->codigoGeneracionReemplazo);

            return $problemas;
        }

        if (filled($evento->codigoGeneracionReemplazo)) {
            return [$this->razonReemplazoProhibido($requisitos)];
        }

        return [];
    }

    /**
     * Dependencia fiscal, SOLO para los tipos que la política dice que dependen. Un FE,
     * una FEX o una NC con una nota relacionada no heredan esta prohibición: el manual no
     * la enuncia para ellos, y que el modelo pueda representar la relación no la crea.
     *
     * @return array<int, string>
     */
    public function problemasDeDependencias(Dte $dte): array
    {
        $notas = $this->notasQueBloquean($dte);

        if ($notas->isEmpty()) {
            return [];
        }

        $mensajes = [];
        foreach ($notas->groupBy(fn (Dte $n) => $n->estado->value) as $estado => $grupo) {
            $tipos = $grupo->pluck('tipo_dte')->unique();
            $nombre = $tipos->count() > 1 ? 'notas de crédito/débito'
                : ($tipos->first() === TipoDte::NotaDebito ? 'una nota de débito' : 'una nota de crédito');
            $detalle = $grupo->map(fn (Dte $n) => ($n->tipo_dte?->label() ?? 'Nota').' '.($n->numero_control ?? $n->numero_interno ?? '#'.$n->id))->implode('; ');
            if ($estado === EstadoDte::Aceptado->value) {
                $vigencia = $grupo->count() > 1 ? ' vigentes. Primero invalidá esas notas' : ' vigente. Primero invalidá esa nota';
                $mensajes[] = 'Este comprobante tiene '.$nombre.$vigencia.' y luego volvé a intentar. Nota(s) que lo bloquean: '.$detalle.'.';
            } else {
                $anular = $estado === EstadoDte::Generado->value ? ' o anulala si no se va a enviar' : '';
                $mensajes[] = 'Este comprobante tiene '.$nombre.' que todavía no tiene respuesta de Hacienda (estado: '.EstadoDte::from($estado)->label().'). Terminá de enviarla o consultá su estado'.$anular.': si Hacienda la acepta, invalidala primero; si la rechaza, deja de bloquear. Nota(s) que lo bloquean: '.$detalle.'.';
            }
        }

        return $mensajes;
    }

    /** Explicación concreta de por qué ESTE documento y ESTE motivo no admiten sustituto. */
    private function razonReemplazoProhibido(RequisitosInvalidacion $requisitos): string
    {
        $documento = $requisitos->documento?->label() ?? 'documento';

        if ($requisitos->motivo === TipoAnulacionMh::RescindirOperacion) {
            return 'El motivo 2 (Rescindir la operación) no admite documento de reemplazo en ningún tipo de '
                .'documento: la operación no se sustituye, se deja sin efecto. Quitá el documento de reemplazo.';
        }

        return 'La invalidación de un '.$documento.' no admite documento de reemplazo: se invalida primero y, si '
            .'corresponde, la corrección se emite después. Quitá el documento de reemplazo.';
    }
}
