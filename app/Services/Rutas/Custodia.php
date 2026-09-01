<?php

namespace App\Services\Rutas;

use App\Enums\EstadoCustodia;
use App\Enums\EstadoSalidaRuta;
use App\Enums\TipoEventoCustodia;
use App\Exceptions\Rutas\DocumentoYaRecibidoException;
use App\Models\CustodiaDocumentoEvento;
use App\Models\PersonalRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\SalidaRutaParticipante;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Punto ÚNICO por el que se registra qué le pasó al CCF FÍSICO.
 *
 * Todo pasa por acá para que la bitácora, el candado contra la doble recepción y la
 * sincronización de las columnas heredadas no dependan de que cada controlador se acuerde de
 * aplicarlas — el mismo criterio que sostiene {@see AsignadorDocumentos}.
 *
 * ═══════════════════ El estado se deriva, no se guarda ═══════════════════
 *
 * No existe ninguna columna «quién lo tiene». El tenedor actual y el estado
 * ({@see EstadoCustodia}) salen del último evento VIGENTE del documento. Una columna con el
 * responsable actual contestaría el presente borrando el pasado, y el pasado es justamente
 * lo que hace falta el día que el papel no aparece.
 *
 * ═══════════════════ Las dos columnas heredadas ═══════════════════
 *
 * `documentacion_fisica_recibida_at` y `_por` existían antes y NO cambian de significado:
 * pasan a ser una PROYECCIÓN del evento de recepción vigente. Se mantienen sincronizadas
 * dentro de la MISMA transacción que crea o anula ese evento, así que no pueden divergir.
 *
 * Se conservan por dos razones concretas, no por compatibilidad perezosa: media aplicación
 * ya las lee —{@see BandejaDocumentos} filtra por ellas en SQL, {@see SeguimientoDocumentos}
 * las cuenta, tres vistas las muestran— y un filtro sobre columna real es lo que permite
 * acotar en la base en vez de hidratar todas las filas para preguntarles una por una.
 *
 * La bitácora es la verdad. Esas dos columnas son la respuesta rápida a la pregunta más
 * frecuente.
 *
 * ═══════════════════ Quién puede registrar qué ═══════════════════
 *
 * Este servicio NO decide permisos —eso es del middleware— pero sí impone la regla de
 * dominio que los acompaña: la RECEPCIÓN es un acto de oficina y los demás eventos son de
 * campo. Quien llevaba el papel no puede declarar que la oficina ya lo recibió; son dos
 * actores y por eso son dos permisos.
 */
class Custodia
{
    /**
     * Bodega entrega el documento a quien sale. Primer eslabón de la cadena.
     *
     * Solo sale hacia alguien que VA en esa salida: entregarle el papel a quien no viajó no
     * es un caso raro, es un error de dedo, y dejarlo pasar rompe lo único que la bitácora
     * promete —poder preguntarle a alguien concreto por un documento—.
     *
     * @throws ValidationException
     */
    public function entregar(
        SalidaRutaDocumento $documento,
        PersonalRuta $destino,
        ?User $usuario,
        ?string $observacion = null,
        ?Carbon $ocurridoEn = null,
    ): CustodiaDocumentoEvento {
        $this->exigirPersonaActiva($destino);
        $this->exigirParticipanteDeLaSalida($documento, $destino);

        return $this->registrar(
            $documento,
            TipoEventoCustodia::EntregaAPersonal,
            $usuario,
            function (?CustodiaDocumentoEvento $ultimo) use ($destino, $observacion, $ocurridoEn) {
                $estado = self::estadoDesde($ultimo);
                $tenedor = $this->tenedorDe($ultimo);

                // Entregar dos veces no es idempotente: crearía un segundo eslabón que dice
                // que bodega lo tenía cuando ya lo tenía una persona.
                if ($tenedor !== null) {
                    throw ValidationException::withMessages([
                        'destino' => 'Este documento ya lo tiene '.$tenedor->nombre
                            .'. Si cambió de manos, usá «Transferir custodia».',
                    ]);
                }

                if ($estado === EstadoCustodia::Recibido) {
                    throw ValidationException::withMessages([
                        'destino' => 'Este documento ya volvió firmado a la oficina: no puede salir de bodega otra vez. '
                            .'Si la recepción se registró por error, anulala primero.',
                    ]);
                }

                return [
                    'origen_personal_id' => null, // sale de bodega, no de una persona
                    'destino_personal_id' => $destino->id,
                    'observacion' => $observacion,
                    'ocurrido_en' => $ocurridoEn,
                ];
            }
        );
    }

    /**
     * El papel cambia de manos sin volver a la empresa: típicamente un vendedor se lo pasa
     * al responsable de la salida para que lo entregue todo junto.
     *
     * `$custodioEsperadoId` es el candado contra la pantalla vieja. Quien pulsa «transferir»
     * vio una pantalla que decía quién lo tenía; si para cuando llega la petición ya lo tiene
     * otro —porque un compañero transfirió desde su propio teléfono— la operación se rechaza
     * en vez de encadenarse sobre un origen que el usuario nunca vio. Se comprueba con el
     * estado LEÍDO BAJO LLAVE, así que dos peticiones simultáneas no pueden pasar ambas.
     *
     * @throws ValidationException
     */
    public function transferir(
        SalidaRutaDocumento $documento,
        PersonalRuta $destino,
        ?User $usuario,
        ?string $observacion = null,
        ?Carbon $ocurridoEn = null,
        ?int $custodioEsperadoId = null,
    ): CustodiaDocumentoEvento {
        $this->exigirPersonaActiva($destino);
        $this->exigirParticipanteDeLaSalida($documento, $destino);

        return $this->registrar(
            $documento,
            TipoEventoCustodia::Transferencia,
            $usuario,
            function (?CustodiaDocumentoEvento $ultimo) use ($destino, $observacion, $ocurridoEn, $custodioEsperadoId) {
                $origen = $this->tenedorDe($ultimo);

                if ($origen === null) {
                    throw ValidationException::withMessages([
                        'destino' => 'Este documento no está en manos de nadie todavía: registrá primero la entrega desde bodega.',
                    ]);
                }

                if ($custodioEsperadoId !== null && $custodioEsperadoId !== $origen->id) {
                    throw ValidationException::withMessages([
                        'destino' => 'Mientras llenabas el formulario el documento cambió de manos: ahora lo tiene '
                            .$origen->nombre.'. Actualizá la pantalla y volvé a intentarlo.',
                    ]);
                }

                if ($origen->id === $destino->id) {
                    throw ValidationException::withMessages([
                        'destino' => 'El documento ya está en manos de '.$destino->nombre.'.',
                    ]);
                }

                return [
                    'origen_personal_id' => $origen->id,
                    'destino_personal_id' => $destino->id,
                    'observacion' => $observacion,
                    'ocurrido_en' => $ocurridoEn,
                ];
            }
        );
    }

    /**
     * Se reporta un problema con el papel: perdido, dañado, quedó en la sala. No dice dónde
     * está; dice que hay algo que resolver.
     *
     * NO da el papel por perdido ni por recibido: deja el documento señalado para que alguien
     * lo mire en la bandeja de excepciones. El papel puede aparecer al día siguiente, y
     * entonces se registra su entrega o su recepción encima, sin borrar nada.
     *
     * @throws ValidationException
     */
    public function reportarIncidencia(
        SalidaRutaDocumento $documento,
        ?User $usuario,
        string $observacion,
        ?Carbon $ocurridoEn = null,
    ): CustodiaDocumentoEvento {
        if (trim($observacion) === '') {
            throw ValidationException::withMessages([
                'observacion' => 'Contá qué pasó con el documento: una incidencia sin explicación no sirve para buscarlo.',
            ]);
        }

        return $this->registrar(
            $documento,
            TipoEventoCustodia::Incidencia,
            $usuario,
            function (?CustodiaDocumentoEvento $ultimo) use ($observacion, $ocurridoEn) {
                if (self::estadoDesde($ultimo) === EstadoCustodia::Recibido) {
                    throw ValidationException::withMessages([
                        'observacion' => 'Este documento ya volvió firmado a la oficina. Si la recepción se registró '
                            .'por error, anulala en vez de reportar una incidencia sobre ella.',
                    ]);
                }

                return [
                    'origen_personal_id' => $this->tenedorDe($ultimo)?->id,
                    'observacion' => trim($observacion),
                    'ocurrido_en' => $ocurridoEn,
                ];
            }
        );
    }

    /**
     * El documento firmado y sellado volvió a la oficina.
     *
     * Es el único evento que además escribe la proyección heredada, y el único protegido por
     * un índice único: dos personas confirmando a la vez no pueden producir dos recepciones
     * válidas.
     *
     * @throws DocumentoYaRecibidoException
     */
    public function recibir(
        SalidaRutaDocumento $documento,
        ?User $usuario,
        ?string $observacion = null,
        ?Carbon $ocurridoEn = null,
    ): CustodiaDocumentoEvento {
        $this->exigirSalidaNoCancelada($documento);

        $cuando = $ocurridoEn ?? now();

        try {
            return DB::transaction(function () use ($documento, $usuario, $observacion, $cuando) {
                // Capa 1: mirar si ya está recibido, para poder decir cuándo y quién. El
                // bloqueo evita que dos altas simultáneas lean ambas «libre».
                $anterior = CustodiaDocumentoEvento::query()
                    ->where('salida_ruta_documento_id', $documento->id)
                    ->recepcionVigente()
                    ->lockForUpdate()
                    ->first();

                if ($anterior !== null) {
                    throw new DocumentoYaRecibidoException($documento->numeroLegible(), $anterior->load('registradoPor'));
                }

                $evento = CustodiaDocumentoEvento::create([
                    'salida_ruta_documento_id' => $documento->id,
                    'salida_ruta_id' => $documento->salida_ruta_id,
                    'tipo' => TipoEventoCustodia::RecepcionOficina,
                    'origen_personal_id' => $this->tenedorActual($documento)?->id,
                    'destino_personal_id' => null, // vuelve a la empresa, no a una persona
                    'registrado_por' => $usuario?->id,
                    'ocurrido_en' => $cuando,
                    'observacion' => $observacion,
                    'recepcion_vigente' => 1,
                ]);

                // Proyección: en la MISMA transacción, así no pueden divergir.
                $this->proyectarRecepcion($documento, $cuando, $usuario?->id);

                $this->auditar($documento, $evento, 'registró la recepción del documento físico');

                return $evento;
            });
        } catch (QueryException $e) {
            // Capa 2: el índice único ganó la carrera contra otra pestaña. 23000 =
            // violación de integridad.
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new DocumentoYaRecibidoException(
                    $documento->numeroLegible(),
                    CustodiaDocumentoEvento::query()
                        ->where('salida_ruta_documento_id', $documento->id)
                        ->recepcionVigente()
                        ->with('registradoPor')
                        ->first(),
                );
            }

            throw $e;
        }
    }

    /**
     * Deja sin efecto un evento mal registrado. NO lo borra: crea una anulación que lo
     * compensa y exige motivo.
     *
     * Si lo anulado era la recepción vigente, el documento vuelve a estar pendiente y su
     * proyección se limpia — en la misma transacción, y liberando el índice único para que
     * se pueda volver a recibir.
     *
     * @throws ValidationException
     */
    public function anular(
        CustodiaDocumentoEvento $evento,
        string $motivo,
        ?User $usuario,
    ): CustodiaDocumentoEvento {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages([
                'motivo' => 'Explicá por qué se anula este registro (al menos 10 caracteres). '
                    .'Queda con tu nombre y es lo que va a explicar la diferencia más adelante.',
            ]);
        }

        return DB::transaction(function () use ($evento, $motivo, $usuario) {
            // Todo se decide sobre la fila LEÍDA BAJO LLAVE, no sobre el modelo que llegó por
            // parámetro: entre que el controlador lo cargó y esto se ejecuta, otra pestaña
            // pudo anularlo o registrar un hecho nuevo encima.
            $bloqueado = CustodiaDocumentoEvento::query()
                ->whereKey($evento->id)
                ->lockForUpdate()
                ->first();

            if ($bloqueado === null) {
                throw ValidationException::withMessages([
                    'motivo' => 'Ese registro ya no existe.',
                ]);
            }

            if ($bloqueado->anulado) {
                throw ValidationException::withMessages([
                    'motivo' => 'Ese registro ya estaba anulado.',
                ]);
            }

            if ($bloqueado->esAnulacion()) {
                throw ValidationException::withMessages([
                    'motivo' => 'Una anulación no se anula: registrá el hecho correcto como un evento nuevo.',
                ]);
            }

            $documento = $bloqueado->documento;

            // ───────────── Solo el ÚLTIMO vigente ─────────────
            //
            // Anular un evento del medio dejaría una cadena que no describe ninguna
            // realidad: si el papel pasó de bodega a Rene y de Rene a Lucía, borrar el
            // primer eslabón deja a Lucía recibiéndolo de nadie. La corrección de un hecho
            // viejo se hace deshaciendo desde el final, un evento a la vez.
            //
            // De paso, esta misma regla es el candado contra el formulario viejo: si la
            // pantalla se dibujó cuando este era el último y mientras tanto alguien registró
            // algo encima, el id que llega ya no es el último y se rechaza.
            $ultimo = $documento !== null ? $this->ultimoVigenteBloqueado($documento) : null;

            if ($ultimo === null || $ultimo->id !== $bloqueado->id) {
                throw ValidationException::withMessages([
                    'motivo' => 'Solo se puede anular el último registro del documento. '
                        .($ultimo !== null
                            ? 'El último ahora es «'.$ultimo->tipo->label().'» del '
                                .$ultimo->ocurrido_en?->translatedFormat('d M Y H:i')
                                .'; actualizá la pantalla y anulá ese primero.'
                            : 'Actualizá la pantalla y volvé a intentarlo.'),
                ]);
            }

            $eraRecepcionVigente = $bloqueado->recepcion_vigente === 1;

            $anulacion = CustodiaDocumentoEvento::create([
                'salida_ruta_documento_id' => $bloqueado->salida_ruta_documento_id,
                'salida_ruta_id' => $bloqueado->salida_ruta_id,
                'tipo' => TipoEventoCustodia::Anulacion,
                'registrado_por' => $usuario?->id,
                'ocurrido_en' => now(),
                'motivo' => $motivo,
                'anula_evento_id' => $bloqueado->id,
            ]);

            // Lo único que se toca del evento anulado: su marca. El contenido queda intacto,
            // con quién lo registró y cuándo, porque es lo que explica la diferencia después.
            $bloqueado->forceFill([
                'anulado' => true,
                // Libera el índice único: el documento puede volver a recibirse.
                'recepcion_vigente' => null,
            ])->save();

            // El custodio y el estado físico NO hace falta restaurarlos: se DERIVAN del
            // último evento vigente, y al marcar este como anulado el anterior vuelve a
            // serlo. Esa es toda la ventaja de no guardar el estado en una columna.
            //
            // La proyección heredada sí es una columna, así que se reescribe — y solo si lo
            // anulado era la recepción. Anular una transferencia no puede tocar la fecha de
            // recepción en oficina, ni el albarán AC01, ni nada de PPQ: son otros hechos con
            // otro dueño.
            if ($eraRecepcionVigente && $documento !== null) {
                $anterior = CustodiaDocumentoEvento::query()
                    ->where('salida_ruta_documento_id', $documento->id)
                    ->recepcionVigente()
                    ->first();

                $this->proyectarRecepcion($documento, $anterior?->ocurrido_en, $anterior?->registrado_por);
            }

            if ($documento !== null) {
                $this->auditar($documento, $anulacion, 'anuló un registro de custodia');
            }

            return $anulacion;
        });
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Los eventos de un documento, del más viejo al más nuevo. Es la línea de tiempo que se
     * muestra: incluye los anulados y las anulaciones, porque un intento fallido también es
     * historia.
     *
     * @return Collection<int, CustodiaDocumentoEvento>
     */
    public function historial(SalidaRutaDocumento $documento): Collection
    {
        return CustodiaDocumentoEvento::query()
            ->where('salida_ruta_documento_id', $documento->id)
            ->with(['origen:id,nombre', 'destino:id,nombre', 'registradoPor:id,name', 'anulado:id,tipo,ocurrido_en'])
            ->orderBy('id')
            ->get();
    }

    /**
     * El historial completo de MUCHOS documentos, en una sola consulta.
     *
     * El panel de custodia muestra la línea de tiempo de cada documento de la salida. Pedirla
     * documento por documento serían treinta consultas para pintar una pantalla; acá se
     * traen todos los eventos de la salida ordenados y se reparten en memoria.
     *
     * @param  array<int, int>  $documentoIds
     * @return array<int, Collection<int, CustodiaDocumentoEvento>> indexado por documento
     */
    public function historialesDe(array $documentoIds): array
    {
        $documentoIds = array_values(array_unique(array_filter($documentoIds)));

        if ($documentoIds === []) {
            return [];
        }

        return CustodiaDocumentoEvento::query()
            ->whereIn('salida_ruta_documento_id', $documentoIds)
            ->with(['origen:id,nombre', 'destino:id,nombre', 'registradoPor:id,name', 'anulado:id,tipo,ocurrido_en'])
            ->orderBy('id')
            ->get()
            ->groupBy('salida_ruta_documento_id')
            ->all();
    }

    /** El último evento que cuenta para el estado, o null si nunca pasó nada. */
    public function ultimoVigente(SalidaRutaDocumento $documento): ?CustodiaDocumentoEvento
    {
        return CustodiaDocumentoEvento::query()
            ->where('salida_ruta_documento_id', $documento->id)
            ->vigentes()
            ->with(['destino:id,nombre,activo', 'origen:id,nombre,activo'])
            ->orderByDesc('id')
            ->first();
    }

    /** Quién tiene el papel ahora mismo, o null si está en la empresa o nunca salió. */
    public function tenedorActual(SalidaRutaDocumento $documento): ?PersonalRuta
    {
        $ultimo = $this->ultimoVigente($documento);

        return $ultimo?->tipo->dejaEnPersonal() ? $ultimo->destino : null;
    }

    /** El estado de custodia, derivado del último evento vigente. */
    public function estado(SalidaRutaDocumento $documento): EstadoCustodia
    {
        return self::estadoDesde($this->ultimoVigente($documento));
    }

    /**
     * Qué hechos de CAMPO admite un documento en este estado.
     *
     * Es el mismo criterio que imponen los guardas de {@see entregar()}, {@see transferir()}
     * y {@see reportarIncidencia()}, expuesto para que la pantalla no ofrezca botones que el
     * servidor va a rechazar. La pantalla lo usa para PINTAR; el servidor lo vuelve a
     * comprobar bajo llave al escribir. Ninguna de las dos confía en la otra.
     *
     * La RECEPCIÓN nunca está en esta lista, en ningún estado: es un acto de oficina y vive
     * en su propia pantalla con su propio permiso.
     *
     * @return array<int, TipoEventoCustodia>
     */
    public static function accionesDeCampo(EstadoCustodia $estado): array
    {
        return match ($estado) {
            // Nadie lo tiene: solo puede salir de bodega, o reportarse que ya hay un problema.
            EstadoCustodia::EnBodega => [TipoEventoCustodia::EntregaAPersonal, TipoEventoCustodia::Incidencia],
            // Lo tiene alguien: solo puede cambiar de manos. Volver a «entregar» crearía un
            // eslabón que dice que bodega lo tenía cuando ya no.
            EstadoCustodia::ConPersonal => [TipoEventoCustodia::Transferencia, TipoEventoCustodia::Incidencia],
            // Hay un problema abierto. Si el papel aparece se registra su salida encima; la
            // incidencia anterior queda en el historial.
            EstadoCustodia::Incidencia => [TipoEventoCustodia::EntregaAPersonal, TipoEventoCustodia::Incidencia],
            // Ya volvió a la oficina. Desde campo no se toca: si el registro está mal, se
            // anula, que es otro permiso y otro acto.
            EstadoCustodia::Recibido => [],
        };
    }

    /**
     * La misma regla, aplicable a un evento ya cargado. Existe para que la precarga en
     * bloque no tenga que volver a consultar por cada fila.
     */
    public static function estadoDesde(?CustodiaDocumentoEvento $ultimo): EstadoCustodia
    {
        if ($ultimo === null) {
            return EstadoCustodia::EnBodega;
        }

        return match ($ultimo->tipo) {
            TipoEventoCustodia::EntregaAPersonal, TipoEventoCustodia::Transferencia => EstadoCustodia::ConPersonal,
            TipoEventoCustodia::RecepcionOficina => EstadoCustodia::Recibido,
            TipoEventoCustodia::Incidencia => EstadoCustodia::Incidencia,
            // Una anulación nunca es el último VIGENTE (el scope la excluye); si llegara acá
            // no describe ningún estado del papel.
            default => EstadoCustodia::EnBodega,
        };
    }

    /**
     * El último evento vigente de MUCHOS documentos, en una sola consulta.
     *
     * Sin esto, pintar una lista de cincuenta documentos costaría cincuenta consultas. Se
     * resuelve trayendo los eventos vigentes de todos ellos ordenados y quedándose con el
     * último de cada uno: es una tabla de bitácora acotada por documento, así que el volumen
     * por página es pequeño y previsible.
     *
     * @param  array<int, int>  $documentoIds
     * @return array<int, CustodiaDocumentoEvento> indexado por `salida_ruta_documento_id`
     */
    public function ultimosVigentesDe(array $documentoIds): array
    {
        $documentoIds = array_values(array_unique(array_filter($documentoIds)));

        if ($documentoIds === []) {
            return [];
        }

        $porDocumento = [];

        CustodiaDocumentoEvento::query()
            ->whereIn('salida_ruta_documento_id', $documentoIds)
            ->vigentes()
            ->with(['destino:id,nombre,activo', 'origen:id,nombre,activo'])
            ->orderBy('id')
            ->get()
            // Ascendente y sobrescribiendo: el último que pasa es el más reciente.
            ->each(function (CustodiaDocumentoEvento $evento) use (&$porDocumento) {
                $porDocumento[$evento->salida_ruta_documento_id] = $evento;
            });

        return $porDocumento;
    }

    // ------------------------------------------------------------------ interno

    /**
     * Escribe la proyección heredada con un UPDATE por clave, no sobre el modelo que llegó
     * por parámetro.
     *
     * No es un rodeo: quien llama puede traer una instancia con valores viejos en memoria
     * —muy fácil después de anular, donde el documento se tocó desde otra instancia— y
     * entonces `save()` no ve nada sucio y NO emite el UPDATE. El documento quedaría
     * recibido según la bitácora y pendiente según las columnas, que es exactamente la
     * divergencia que estas dos columnas no pueden permitirse.
     *
     * Además se sincroniza la instancia recibida, para que quien siga usándola vea lo mismo
     * que la base.
     */
    private function proyectarRecepcion(SalidaRutaDocumento $documento, ?Carbon $cuando, ?int $usuarioId): void
    {
        SalidaRutaDocumento::whereKey($documento->id)->update([
            'documentacion_fisica_recibida_at' => $cuando,
            'documentacion_fisica_recibida_por' => $usuarioId,
        ]);

        $documento->setAttribute('documentacion_fisica_recibida_at', $cuando);
        $documento->setAttribute('documentacion_fisica_recibida_por', $usuarioId);
        $documento->syncChanges();
        $documento->syncOriginalAttributes(['documentacion_fisica_recibida_at', 'documentacion_fisica_recibida_por']);
    }

    /**
     * Inserta el evento y deja la auditoría. Los eventos de campo no tocan la proyección
     * heredada: esa solo la mueve la recepción.
     *
     * `$atributos` es un CLOSURE y no un array por una razón concreta: recibe el último
     * evento vigente leído BAJO LLAVE dentro de la transacción, así que las reglas que
     * dependen del estado —no entregar dos veces, no transferir desde un custodio que ya
     * cambió— se comprueban contra lo que hay en la base en ese instante y no contra lo que
     * el controlador leyó hace medio segundo. Dos peticiones simultáneas se serializan: la
     * segunda ve el evento que escribió la primera y se rechaza.
     *
     * @param  Closure(?CustodiaDocumentoEvento): array<string, mixed>  $atributos
     *
     * @throws ValidationException
     */
    private function registrar(
        SalidaRutaDocumento $documento,
        TipoEventoCustodia $tipo,
        ?User $usuario,
        Closure $atributos,
    ): CustodiaDocumentoEvento {
        $this->exigirSalidaNoCancelada($documento);

        return DB::transaction(function () use ($documento, $tipo, $usuario, $atributos) {
            $valores = $atributos($this->ultimoVigenteBloqueado($documento));

            // `ocurrido_en` se resuelve ANTES de mezclar. Con `$valores + [...]` gana el
            // operando izquierdo, así que un `ocurrido_en => null` explícito —el valor por
            // defecto del parámetro— pisaría el `now()` y la columna es NOT NULL.
            $valores['ocurrido_en'] = $valores['ocurrido_en'] ?? now();

            $evento = CustodiaDocumentoEvento::create($valores + [
                'salida_ruta_documento_id' => $documento->id,
                'salida_ruta_id' => $documento->salida_ruta_id,
                'tipo' => $tipo,
                'registrado_por' => $usuario?->id,
            ]);

            $this->auditar($documento, $evento, 'registró un movimiento de custodia');

            return $evento;
        });
    }

    /**
     * El último evento vigente, leído con `lockForUpdate()` para que dos escrituras sobre el
     * mismo documento no puedan decidir a la vez sobre el mismo estado.
     *
     * Es el mismo recurso que usa {@see recibir()}, y por el mismo motivo: la comprobación
     * en PHP pierde carreras. Acá no hay además un índice único que la respalde —solo la
     * recepción lo tiene—, así que la llave es lo único que separa dos entregas simultáneas.
     */
    private function ultimoVigenteBloqueado(SalidaRutaDocumento $documento): ?CustodiaDocumentoEvento
    {
        return CustodiaDocumentoEvento::query()
            ->where('salida_ruta_documento_id', $documento->id)
            ->vigentes()
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first()
            ?->load('destino:id,nombre,activo');
    }

    /** Quién tiene el papel según un evento ya leído. La misma regla que {@see tenedorActual()}. */
    private function tenedorDe(?CustodiaDocumentoEvento $ultimo): ?PersonalRuta
    {
        return $ultimo?->tipo->dejaEnPersonal() ? $ultimo->destino : null;
    }

    /**
     * El papel solo se le entrega a quien VA en esa salida.
     *
     * No es burocracia: la bitácora existe para poder preguntarle a una persona concreta por
     * un documento, y una persona que no viajó no puede contestar. Además cierra el agujero
     * de mandar el `id` de cualquiera desde el formulario.
     *
     * @throws ValidationException
     */
    private function exigirParticipanteDeLaSalida(SalidaRutaDocumento $documento, PersonalRuta $destino): void
    {
        $va = SalidaRutaParticipante::query()
            ->where('salida_ruta_id', $documento->salida_ruta_id)
            ->where('rutas_personal_id', $destino->id)
            ->exists();

        if (! $va) {
            throw ValidationException::withMessages([
                'destino' => $destino->nombre.' no va en esta salida. Solo se le puede dar el documento a quien viajó; '
                    .'si tiene que ir, agregala primero a la salida.',
            ]);
        }
    }

    /**
     * Una persona inactiva no recibe documentos nuevos.
     *
     * Los que YA tiene no se le quitan solos —eso borraría el rastro de quién los tiene— y
     * aparecen como excepción para que alguien los transfiera a mano.
     *
     * @throws ValidationException
     */
    private function exigirPersonaActiva(PersonalRuta $personal): void
    {
        if (! $personal->activo) {
            throw ValidationException::withMessages([
                'destino' => $personal->nombre.' está inactivo: no se le pueden entregar documentos. '
                    .'Si volvió a la operación, activalo primero en Personal.',
            ]);
        }
    }

    /**
     * Una salida CANCELADA nunca ocurrió: no puede tener hechos operativos nuevos. Es la
     * misma regla que ya aplica {@see AsignadorDocumentos} a las marcas manuales, y por el
     * mismo motivo.
     *
     * Una salida FINALIZADA sí los admite: el papel casi siempre vuelve después de cerrarla.
     */
    private function exigirSalidaNoCancelada(SalidaRutaDocumento $documento): void
    {
        abort_if(
            $documento->salida?->estado === EstadoSalidaRuta::Cancelada,
            403,
            'La salida está cancelada: no se registran hechos sobre sus documentos.'
        );
    }

    private function auditar(SalidaRutaDocumento $documento, CustodiaDocumentoEvento $evento, string $descripcion): void
    {
        activity('custodia_documento')
            ->performedOn($documento->salida ?? $documento)
            ->causedBy($evento->registrado_por ? User::find($evento->registrado_por) : null)
            ->withProperties([
                'evento_id' => $evento->id,
                'documento_id' => $documento->id,
                'numero_control' => $documento->numeroLegible(),
                'tipo' => $evento->tipo->value,
                'origen' => $evento->origen_personal_id,
                'destino' => $evento->destino_personal_id,
                'motivo' => $evento->motivo,
            ])
            ->log($descripcion);
    }
}
