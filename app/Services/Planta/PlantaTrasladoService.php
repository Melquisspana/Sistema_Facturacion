<?php

namespace App\Services\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\TipoUbicacion;
use App\Exceptions\Planta\ReversionTrasladoImposibleException;
use App\Exceptions\Planta\TrasladoInvalidoException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaTrasladoDetalle;
use App\Models\Planta\PlantaUbicacion;
use App\Models\Secuencia;
use App\Models\User;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ÚNICO punto autorizado para crear, editar, enviar, recibir, cancelar y
 * reversar traslados entre ubicaciones.
 *
 * Es un CLIENTE de {@see PlantaInventarioService}: nunca escribe en
 * `planta_movimientos` ni en `planta_existencias`.
 *
 * DOS ACTOS, CUATRO MOVIMIENTOS. Un traslado completo escribe cuatro efectos por
 * línea, en dos momentos distintos:
 *
 *   enviar   origen/disponible/traslado 0   -X
 *            TRÁNSITO/disponible/traslado N +X
 *   recibir  TRÁNSITO/disponible/traslado N -X
 *            destino/disponible/traslado 0  +X
 *
 * La quinta dimensión del bucket —el traslado— es lo que hace que esto funcione.
 * Sin ella, dos envíos simultáneos del mismo insumo y lote compartirían un único
 * saldo de tránsito y recibir uno consumiría indistintamente lo del otro. Con
 * ella, cada viaje tiene su propio saldo y recibir consume EXACTAMENTE lo que
 * ese viaje mandó. Por eso {@see recibir()} filtra siempre por
 * `planta_traslado_id`, nunca por un tránsito agregado.
 *
 * SOLO VIAJA LO DISPONIBLE. El retenido está esperando una decisión de calidad y
 * el rechazado está fuera de la operación; moverlos de sitio no cambiaría eso y
 * sí escondería saldo no utilizable en otra bodega.
 *
 * SIN RECEPCIÓN PARCIAL. Se recibe exactamente lo enviado. Una diferencia real
 * —se rompió, faltó— es un hecho distinto que se registra con un ajuste con
 * motivo. Permitir «recibí menos» haría imposible distinguir un error de conteo
 * de una pérdida, y dejaría saldo huérfano en tránsito para siempre.
 *
 * ORDEN DE APLICACIÓN: por CLAVE CANÓNICA del bucket. Dos traslados simultáneos
 * que tocan los mismos buckets los bloquean en el mismo orden, así que InnoDB no
 * encuentra ciclos. La suficiencia del saldo se comprueba ANTES de escribir
 * nada, lo que hace irrelevante el orden de los signos.
 *
 * TODA operación va dentro de `DB::transaction($cb, 3)`.
 */
class PlantaTrasladoService
{
    /** Clave del contador propio. No es fiscal. */
    public const CLAVE_SECUENCIA = 'planta_traslado';

    /** Lados del par, para que el `efecto_uid` los distinga siempre. */
    private const LADO_SALIDA = 0;

    private const LADO_ENTRADA = 1;

    public function __construct(private readonly PlantaInventarioService $inventario) {}

    // --- Borrador ---

    /**
     * Crea el borrador con sus líneas. No toca inventario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearBorrador(array $datos, ?User $usuario = null): PlantaTraslado
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $traslado = new PlantaTraslado;
            $traslado->fill($this->soloCabecera($datos));
            $traslado->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $traslado->estado = EstadoTrasladoPlanta::Borrador;
            $traslado->creado_por = $usuario?->id;
            $traslado->save();

            $this->sincronizarDetalles($traslado, $datos['detalles'] ?? []);

            return $traslado->refresh();
        }, 3);
    }

    /**
     * Reemplaza cabecera y líneas de un borrador.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarBorrador(PlantaTraslado $traslado, array $datos): PlantaTraslado
    {
        return DB::transaction(function () use ($traslado, $datos) {
            $bloqueado = $this->bloquear($traslado);

            if (! $bloqueado->esEditable()) {
                throw TrasladoInvalidoException::estadoNoPermite($bloqueado->estado->value, 'edición');
            }

            $bloqueado->fill($this->soloCabecera($datos));
            $bloqueado->save();

            $this->sincronizarDetalles($bloqueado, $datos['detalles'] ?? []);

            return $bloqueado->refresh();
        }, 3);
    }

    /** Descarta un borrador. Es terminal y no mueve inventario. */
    public function cancelar(PlantaTraslado $traslado): PlantaTraslado
    {
        return DB::transaction(function () use ($traslado) {
            $bloqueado = $this->bloquear($traslado);

            if (! $bloqueado->puedeCancelarse()) {
                throw TrasladoInvalidoException::estadoNoPermite($bloqueado->estado->value, 'cancelación');
            }

            $bloqueado->estado = EstadoTrasladoPlanta::Cancelado;
            $bloqueado->save();

            activity('planta_traslado')
                ->performedOn($bloqueado)
                ->withProperties(['numero' => $bloqueado->numero])
                ->log('canceló el borrador de traslado');

            return $bloqueado;
        }, 3);
    }

    // --- Enviar ---

    /**
     * Saca la mercancía del origen y la deja en tránsito atada a este traslado.
     *
     * Todo lo que puede rechazar la operación se comprueba ANTES de escribir el
     * primer movimiento y con la fila bloqueada.
     */
    public function enviar(PlantaTraslado $traslado, User $usuario): PlantaTraslado
    {
        return DB::transaction(function () use ($traslado, $usuario) {
            // 1 y 2. Bloqueo y estado. Sin el bloqueo, dos envíos simultáneos
            //        leerían «borrador» los dos.
            $doc = $this->bloquear($traslado);

            if (! $doc->puedeEnviarse()) {
                throw TrasladoInvalidoException::estadoNoPermite($doc->estado->value, 'envío');
            }

            // 3. Al menos una línea.
            $detalles = $doc->detalles()->orderBy('id')->get();

            if ($detalles->isEmpty()) {
                throw TrasladoInvalidoException::sinDetalles($doc->numero);
            }

            // 4, 5 y 6. Contexto vigente HOY.
            [$origen, $destino] = $this->validarUbicaciones($doc);
            $transito = $this->resolverTransito();
            $insumos = $this->validarLineas($detalles);

            // 7. La unidad base se reafirma desde el insumo: el histórico no
            //    depende de lo que se guardó en el borrador.
            foreach ($detalles as $detalle) {
                $detalle->unidad_base = $insumos[$detalle->planta_insumo_id]->unidad_base->value;
                $detalle->save();
            }

            // 8, 9 y 10. Buckets, orden canónico y suficiencia ANTES de escribir.
            $efectos = $this->efectosDeEnvio($detalles, $transito);
            $this->exigirSaldoSuficiente($detalles, fn (PlantaTrasladoDetalle $d) => $d->bucketOrigen(), 'origen');

            $grupo = (string) Str::uuid();

            // 11. Los dos efectos por línea, a través del motor de inventario.
            foreach ($efectos as $efecto) {
                $this->aplicar($efecto, TipoMovimientoPlanta::TrasladoEnvio, 'enviar', $doc, $grupo, $usuario, [
                    'traslado_numero' => $doc->numero,
                    'origen' => $origen->codigo,
                    'destino' => $destino->codigo,
                ]);
            }

            // 12 y 13. El documento pasa a enviado con su firma.
            $doc->estado = EstadoTrasladoPlanta::Enviado;
            $doc->enviado_por = $usuario->id;
            $doc->enviado_en = now();
            $doc->save();

            // 14. Auditoría.
            activity('planta_traslado')
                ->performedOn($doc)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $doc->numero,
                    'grupo_uuid' => $grupo,
                    'lineas' => $detalles->count(),
                    'origen' => $origen->codigo,
                    'destino' => $destino->codigo,
                    'transito' => $transito->codigo,
                ])
                ->log('envió el traslado');

            return $doc->refresh();
        }, 3);
    }

    // --- Recibir ---

    /**
     * Saca la mercancía del tránsito de ESTE traslado y la deja en el destino.
     *
     * No hay nada que capturar: se recibe exactamente lo que se envió. Las
     * cantidades, los lotes y el destino son los del documento, y no se leen de
     * ninguna petición.
     */
    public function recibir(PlantaTraslado $traslado, User $usuario): PlantaTraslado
    {
        return DB::transaction(function () use ($traslado, $usuario) {
            $doc = $this->bloquear($traslado);

            if (! $doc->puedeRecibirse()) {
                throw TrasladoInvalidoException::estadoNoPermite($doc->estado->value, 'recepción');
            }

            $detalles = $doc->detalles()->orderBy('id')->get();

            if ($detalles->isEmpty()) {
                throw TrasladoInvalidoException::sinDetalles($doc->numero);
            }

            [$origen, $destino] = $this->validarUbicaciones($doc);
            $transito = $this->resolverTransito();

            // El saldo que se consume es el del bucket de tránsito ATADO A ESTE
            // traslado. Nunca un tránsito agregado: eso mezclaría viajes.
            $this->exigirSaldoSuficiente(
                $detalles,
                fn (PlantaTrasladoDetalle $d) => $d->bucketTransito($transito->id),
                'tránsito de este traslado',
            );

            $efectos = $this->efectosDeRecepcion($detalles, $transito);
            $grupo = (string) Str::uuid();

            foreach ($efectos as $efecto) {
                $this->aplicar($efecto, TipoMovimientoPlanta::TrasladoRecepcion, 'recibir', $doc, $grupo, $usuario, [
                    'traslado_numero' => $doc->numero,
                    'origen' => $origen->codigo,
                    'destino' => $destino->codigo,
                ]);
            }

            $doc->estado = EstadoTrasladoPlanta::Recibido;
            $doc->recibido_por = $usuario->id;
            $doc->recibido_en = now();
            $doc->save();

            activity('planta_traslado')
                ->performedOn($doc)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $doc->numero,
                    'grupo_uuid' => $grupo,
                    'lineas' => $detalles->count(),
                    'destino' => $destino->codigo,
                ])
                ->log('recibió el traslado');

            return $doc->refresh();
        }, 3);
    }

    // --- Reversar ---

    /**
     * Deshace un traslado, con dos formas distintas según dónde esté la
     * mercancía. La diferencia NO es cosmética y por eso lleva tipos distintos:
     *
     *   ENVIADO   la mercancía sigue en tránsito. Se deshace la salida:
     *             tránsito -X, origen +X. Tipo `reversion_traslado_envio`.
     *
     *   RECIBIDO  la mercancía ya llegó. Se compensa contablemente:
     *             destino -X, origen +X, SIN recrear tránsito. Tipo
     *             `reversion_traslado_recepcion`.
     *
     * El segundo caso se PARECE a un traslado normal en sentido inverso, y por
     * eso es tan importante que no lo sea: nadie condujo de vuelta. Si se tipara
     * como `traslado_*`, la pregunta «cuánta mercancía viajó de Casa a Fábrica»
     * quedaría contaminada por correcciones que no fueron viajes.
     */
    public function reversar(PlantaTraslado $traslado, string $motivo, User $usuario): PlantaTraslado
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw TrasladoInvalidoException::motivoRequerido();
        }

        return DB::transaction(function () use ($traslado, $motivo, $usuario) {
            $original = $this->bloquear($traslado);

            if ($original->esReversion()) {
                throw ReversionTrasladoImposibleException::esUnaReversion($original->numero);
            }

            if ($original->revertido_por_id !== null) {
                throw ReversionTrasladoImposibleException::yaReversado($original->numero);
            }

            $desdeEnviado = $original->estado === EstadoTrasladoPlanta::Enviado;
            $desdeRecibido = $original->estado === EstadoTrasladoPlanta::Recibido;

            if (! $desdeEnviado && ! $desdeRecibido) {
                throw TrasladoInvalidoException::estadoNoPermite($original->estado->value, 'reversión');
            }

            $detalles = $original->detalles()->orderBy('id')->get();
            $transito = $this->resolverTransito();

            // De dónde se retira, según dónde esté la mercancía ahora.
            $bucketRetiro = $desdeEnviado
                ? fn (PlantaTrasladoDetalle $d) => $d->bucketTransito($transito->id)
                : fn (PlantaTrasladoDetalle $d) => $d->bucketDestino();

            $this->exigirSaldoParaReversion($detalles, $bucketRetiro, $desdeEnviado ? 'tránsito' : 'destino');

            $tipo = $desdeEnviado
                ? TipoMovimientoPlanta::ReversionTrasladoEnvio
                : TipoMovimientoPlanta::ReversionTrasladoRecepcion;

            // Documento de compensación: un traslado más, con origen y destino
            // invertidos para que se lea «volvió de allí a aquí».
            $reversion = new PlantaTraslado;
            $reversion->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $reversion->estado = EstadoTrasladoPlanta::Recibido;
            $reversion->fecha = now()->toDateString();
            $reversion->planta_ubicacion_origen_id = $original->planta_ubicacion_destino_id;
            $reversion->planta_ubicacion_destino_id = $original->planta_ubicacion_origen_id;
            $reversion->creado_por = $usuario->id;
            $reversion->enviado_por = $usuario->id;
            $reversion->enviado_en = now();
            $reversion->recibido_por = $usuario->id;
            $reversion->recibido_en = now();
            $reversion->responsable_user_id = $original->responsable_user_id;
            $reversion->responsable_nombre = $original->responsable_nombre;
            $reversion->observaciones = $motivo;
            $reversion->reversion_de_id = $original->id;
            $reversion->save();

            $movimientos = $this->movimientosOriginales($original);
            $grupo = (string) Str::uuid();

            foreach ($detalles as $detalle) {
                $copia = $detalle->replicate(['created_at', 'updated_at']);
                $copia->planta_traslado_id = $reversion->id;
                $copia->save();

                $cantidad = $this->aEscala((string) $detalle->cantidad);
                $retiro = $bucketRetiro($detalle);
                $vuelta = $detalle->bucketOrigen();

                // Cada efecto compensa el movimiento del MISMO bucket en el
                // original: el retiro compensa la entrada, la vuelta compensa la
                // salida.
                $efectos = [
                    ['bucket' => $retiro, 'cantidad' => '-'.$cantidad, 'lado' => self::LADO_SALIDA],
                    ['bucket' => $vuelta, 'cantidad' => $cantidad, 'lado' => self::LADO_ENTRADA],
                ];

                usort($efectos, fn (array $a, array $b) => $a['bucket']->claveCanonica() <=> $b['bucket']->claveCanonica());

                foreach ($efectos as $efecto) {
                    $revertido = $movimientos[$efecto['bucket']->claveCanonica()] ?? null;

                    if ($revertido === null) {
                        throw ReversionTrasladoImposibleException::movimientoOriginalAusente(
                            $detalle->id,
                            $efecto['lado'] === self::LADO_SALIDA ? 'retiro' : 'vuelta',
                        );
                    }

                    $this->inventario->aplicarMovimiento(
                        $efecto['bucket'],
                        $efecto['cantidad'],
                        ContextoMovimiento::para(
                            tipo: $tipo,
                            documentoType: PlantaTraslado::class,
                            documentoId: $reversion->id,
                            transicion: 'reversar',
                            fechaEfectiva: $reversion->fecha,
                            documentoDetalleId: $copia->id,
                            grupoUuid: $grupo,
                            secuencia: $efecto['lado'],
                            userId: $usuario->id,
                            responsableNombre: $original->responsable_nombre,
                            movimientoRevertidoId: $revertido->id,
                            metadata: [
                                'reversion_de' => $original->numero,
                                'desde_estado' => $original->estado->value,
                                'motivo' => $motivo,
                            ],
                        ),
                    );
                }
            }

            $original->estado = EstadoTrasladoPlanta::Reversado;
            $original->revertido_por_id = $reversion->id;
            $original->motivo_reversion = $motivo;
            $original->save();

            activity('planta_traslado')
                ->performedOn($original)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $original->numero,
                    'reversion_numero' => $reversion->numero,
                    'grupo_uuid' => $grupo,
                    'desde_estado' => $desdeEnviado ? 'enviado' : 'recibido',
                    'tipo_movimiento' => $tipo->value,
                    'motivo' => $motivo,
                ])
                ->log('reversó el traslado');

            return $reversion->refresh();
        }, 3);
    }

    // --- Consultas de apoyo ---

    /**
     * Resuelve LA ubicación de tránsito del sistema.
     *
     * Exige exactamente una que cumpla las cuatro condiciones. No la crea: hacerlo
     * dentro de una operación sería inventar una ubicación de sistema en mitad de
     * un traslado, sin que nadie lo haya decidido. Y si hubiera varias, «dónde
     * está lo que salió» dejaría de tener una respuesta.
     */
    public function resolverTransito(): PlantaUbicacion
    {
        $candidatas = PlantaUbicacion::query()
            ->where('tipo', TipoUbicacion::Transito->value)
            ->where('es_sistema', true)
            ->where('activo', true)
            ->where('permite_operacion_manual', false)
            ->get();

        if ($candidatas->isEmpty()) {
            throw TrasladoInvalidoException::transitoAusente();
        }

        if ($candidatas->count() > 1) {
            throw TrasladoInvalidoException::transitoAmbiguo($candidatas->count());
        }

        return $candidatas->first();
    }

    /**
     * Buckets DISPONIBLES con saldo en una ubicación. Es lo que alimenta el
     * selector del formulario: ofrecer combinaciones sin saldo llevaría a
     * descubrir el error al enviar en vez de al escribir.
     *
     * @return Collection<int, object>
     */
    public function lotesDisponiblesEn(int $ubicacionId): Collection
    {
        return DB::table('planta_existencias as e')
            ->join('planta_insumos as i', 'i.id', '=', 'e.planta_insumo_id')
            ->join('planta_lotes as l', 'l.id', '=', 'e.planta_lote_id')
            ->where('e.planta_ubicacion_id', $ubicacionId)
            ->where('e.estado', EstadoDisponibilidad::Disponible->value)
            ->where('e.planta_traslado_id', 0)
            ->where('e.cantidad', '>', 0)
            ->where('i.activo', true)
            ->where('l.activo', true)
            ->orderBy('i.nombre')->orderBy('l.codigo_interno')
            ->get([
                'e.planta_insumo_id', 'e.planta_lote_id', 'e.cantidad',
                'i.codigo as insumo_codigo', 'i.nombre as insumo_nombre', 'i.unidad_base',
                'l.codigo_interno as lote_codigo',
            ]);
    }

    // --- Efectos ---

    /**
     * Efectos del ENVÍO: sale del origen, entra al tránsito de este traslado.
     *
     * @param  Collection<int, PlantaTrasladoDetalle>  $detalles
     * @return array<int, array{bucket: BucketInventario, cantidad: string, lado: int, detalle: PlantaTrasladoDetalle}>
     */
    private function efectosDeEnvio(Collection $detalles, PlantaUbicacion $transito): array
    {
        return $this->ordenar($detalles->flatMap(fn (PlantaTrasladoDetalle $d) => [
            ['bucket' => $d->bucketOrigen(), 'cantidad' => '-'.$this->aEscala((string) $d->cantidad),
                'lado' => self::LADO_SALIDA, 'detalle' => $d],
            ['bucket' => $d->bucketTransito($transito->id), 'cantidad' => $this->aEscala((string) $d->cantidad),
                'lado' => self::LADO_ENTRADA, 'detalle' => $d],
        ])->all());
    }

    /**
     * Efectos de la RECEPCIÓN: sale del tránsito de este traslado, entra al destino.
     *
     * @param  Collection<int, PlantaTrasladoDetalle>  $detalles
     * @return array<int, array{bucket: BucketInventario, cantidad: string, lado: int, detalle: PlantaTrasladoDetalle}>
     */
    private function efectosDeRecepcion(Collection $detalles, PlantaUbicacion $transito): array
    {
        return $this->ordenar($detalles->flatMap(fn (PlantaTrasladoDetalle $d) => [
            ['bucket' => $d->bucketTransito($transito->id), 'cantidad' => '-'.$this->aEscala((string) $d->cantidad),
                'lado' => self::LADO_SALIDA, 'detalle' => $d],
            ['bucket' => $d->bucketDestino(), 'cantidad' => $this->aEscala((string) $d->cantidad),
                'lado' => self::LADO_ENTRADA, 'detalle' => $d],
        ])->all());
    }

    /**
     * Orden canónico de bucket, y dentro de él por línea. Es lo que evita
     * deadlocks entre traslados simultáneos sobre los mismos buckets.
     *
     * @param  array<int, array<string, mixed>>  $efectos
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $efectos): array
    {
        usort($efectos, fn (array $a, array $b) => [$a['bucket']->claveCanonica(), $a['detalle']->id]
            <=> [$b['bucket']->claveCanonica(), $b['detalle']->id]);

        return $efectos;
    }

    /**
     * @param  array{bucket: BucketInventario, cantidad: string, lado: int, detalle: PlantaTrasladoDetalle}  $efecto
     * @param  array<string, mixed>  $metadata
     */
    private function aplicar(
        array $efecto,
        TipoMovimientoPlanta $tipo,
        string $transicion,
        PlantaTraslado $doc,
        string $grupo,
        User $usuario,
        array $metadata,
    ): void {
        $this->inventario->aplicarMovimiento(
            $efecto['bucket'],
            $efecto['cantidad'],
            ContextoMovimiento::para(
                tipo: $tipo,
                documentoType: PlantaTraslado::class,
                documentoId: $doc->id,
                transicion: $transicion,
                fechaEfectiva: $doc->fecha,
                documentoDetalleId: $efecto['detalle']->id,
                grupoUuid: $grupo,
                // El LADO distingue los dos efectos de la misma línea en la misma
                // transición. Sus buckets ya difieren, pero dejarlo explícito hace
                // el `efecto_uid` legible y a prueba de reordenaciones.
                secuencia: $efecto['lado'],
                userId: $usuario->id,
                responsableNombre: $doc->responsable_nombre,
                metadata: $metadata + ['linea' => $efecto['detalle']->id],
            ),
        );
    }

    // --- Validaciones ---

    private function bloquear(PlantaTraslado $traslado): PlantaTraslado
    {
        return PlantaTraslado::whereKey($traslado->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @return array{0: PlantaUbicacion, 1: PlantaUbicacion} */
    private function validarUbicaciones(PlantaTraslado $doc): array
    {
        $origen = PlantaUbicacion::findOrFail($doc->planta_ubicacion_origen_id);
        $destino = PlantaUbicacion::findOrFail($doc->planta_ubicacion_destino_id);

        if ($origen->id === $destino->id) {
            throw TrasladoInvalidoException::mismaUbicacion($origen->nombre);
        }

        foreach ([[$origen, 'origen'], [$destino, 'destino']] as [$ubicacion, $papel]) {
            if (! $ubicacion->activo) {
                throw TrasladoInvalidoException::ubicacionInactiva($ubicacion->nombre, $papel);
            }

            // Cubre TRÁNSITO sin nombrarlo: es la ubicación que no admite
            // operación manual. Si mañana hay otra igual, queda cubierta sola.
            if (! $ubicacion->permite_operacion_manual || $ubicacion->tipo->esTransito()) {
                throw TrasladoInvalidoException::ubicacionNoOperable($ubicacion->nombre, $papel);
            }
        }

        return [$origen, $destino];
    }

    /**
     * @param  Collection<int, PlantaTrasladoDetalle>  $detalles
     * @return array<int, PlantaInsumo>
     */
    private function validarLineas(Collection $detalles): array
    {
        $insumos = PlantaInsumo::whereIn('id', $detalles->pluck('planta_insumo_id')->unique())->get()->keyBy('id');
        $lotes = PlantaLote::whereIn('id', $detalles->pluck('planta_lote_id')->unique())->get()->keyBy('id');

        foreach ($detalles as $detalle) {
            if (bccomp((string) $detalle->cantidad, '0', 4) !== 1) {
                throw TrasladoInvalidoException::cantidadNoPositiva((string) $detalle->cantidad);
            }

            $insumo = $insumos->get($detalle->planta_insumo_id);

            if ($insumo === null || ! $insumo->activo) {
                throw TrasladoInvalidoException::insumoInactivo($insumo?->nombre ?? '#'.$detalle->planta_insumo_id);
            }

            $lote = $lotes->get($detalle->planta_lote_id);

            if ($lote === null || $lote->planta_insumo_id !== $insumo->id) {
                throw TrasladoInvalidoException::loteAjeno((int) $detalle->planta_lote_id, $insumo->nombre);
            }

            if (! $lote->activo) {
                throw TrasladoInvalidoException::loteInactivo($lote->codigo_interno);
            }
        }

        return $insumos->all();
    }

    /**
     * Suficiencia AGREGADA por bucket. Agregar importa: el unique de línea impide
     * duplicados dentro de un traslado, pero comprobar línea a línea seguiría
     * siendo la comprobación equivocada si algún día se admitieran.
     *
     * @param  Collection<int, PlantaTrasladoDetalle>  $detalles
     */
    private function exigirSaldoSuficiente(Collection $detalles, callable $bucketDe, string $donde): void
    {
        foreach ($this->requeridoPorBucket($detalles, $bucketDe) as ['bucket' => $bucket, 'cantidad' => $cantidad]) {
            $saldo = $this->inventario->saldo($bucket);

            if (bccomp($saldo, $cantidad, 4) === -1) {
                throw TrasladoInvalidoException::saldoInsuficienteEnOrigen(
                    $bucket->descripcion().' ('.$donde.')',
                    $cantidad,
                    $saldo,
                );
            }
        }
    }

    /** @param  Collection<int, PlantaTrasladoDetalle>  $detalles */
    private function exigirSaldoParaReversion(Collection $detalles, callable $bucketDe, string $donde): void
    {
        foreach ($this->requeridoPorBucket($detalles, $bucketDe) as ['bucket' => $bucket, 'cantidad' => $cantidad]) {
            $saldo = $this->inventario->saldo($bucket);

            if (bccomp($saldo, $cantidad, 4) === -1) {
                throw ReversionTrasladoImposibleException::saldoInsuficiente(
                    $bucket->descripcion(),
                    $cantidad,
                    $saldo,
                    $donde,
                );
            }
        }
    }

    /**
     * @param  Collection<int, PlantaTrasladoDetalle>  $detalles
     * @return array<string, array{bucket: BucketInventario, cantidad: string}>
     */
    private function requeridoPorBucket(Collection $detalles, callable $bucketDe): array
    {
        $requerido = [];

        foreach ($detalles as $detalle) {
            $bucket = $bucketDe($detalle);
            $clave = $bucket->claveCanonica();

            $requerido[$clave] = [
                'bucket' => $bucket,
                'cantidad' => bcadd($requerido[$clave]['cantidad'] ?? '0', (string) $detalle->cantidad, 4),
            ];
        }

        ksort($requerido);

        return $requerido;
    }

    /**
     * @return array<string, PlantaMovimiento> Movimientos del original, por bucket.
     */
    private function movimientosOriginales(PlantaTraslado $original): array
    {
        $indexados = [];

        foreach (PlantaMovimiento::query()
            ->delDocumento(PlantaTraslado::class, $original->id)
            ->orderBy('id')
            ->get() as $movimiento) {
            // El ÚLTIMO gana: si el traslado se envió y se recibió, el saldo que
            // se compensa está donde lo dejó el movimiento más reciente.
            $indexados[$movimiento->bucket()->claveCanonica()] = $movimiento;
        }

        return $indexados;
    }

    // --- Persistencia auxiliar ---

    /** @param  array<string, mixed>  $datos */
    private function soloCabecera(array $datos): array
    {
        return array_intersect_key($datos, array_flip([
            'fecha',
            'planta_ubicacion_origen_id',
            'planta_ubicacion_destino_id',
            'responsable_user_id',
            'responsable_nombre',
            'observaciones',
        ]));
    }

    /**
     * Sincroniza las líneas FUSIONANDO las que repiten insumo y lote.
     *
     * Dos líneas del mismo lote en el mismo traslado no son dos cosas distintas:
     * son la misma cantidad escrita dos veces. Se suman de forma determinista —el
     * orden de llegada no cambia el resultado— y el unique de la tabla garantiza
     * que ninguna otra vía las duplique.
     *
     * `unidad_base` se deriva del insumo y NO se toma del payload.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function sincronizarDetalles(PlantaTraslado $traslado, array $lineas): void
    {
        $fusionadas = [];

        foreach ($lineas as $linea) {
            $insumoId = (int) ($linea['planta_insumo_id'] ?? 0);
            $loteId = (int) ($linea['planta_lote_id'] ?? 0);

            if ($insumoId === 0 || $loteId === 0) {
                continue;
            }

            $clave = $insumoId.'|'.$loteId;

            $fusionadas[$clave] = [
                'planta_insumo_id' => $insumoId,
                'planta_lote_id' => $loteId,
                'cantidad' => bcadd(
                    $fusionadas[$clave]['cantidad'] ?? '0',
                    (string) ($linea['cantidad'] ?? '0'),
                    4
                ),
                'observaciones' => $linea['observaciones'] ?? ($fusionadas[$clave]['observaciones'] ?? null),
            ];
        }

        ksort($fusionadas);

        $conservados = [];

        foreach ($fusionadas as $datos) {
            $insumo = PlantaInsumo::find($datos['planta_insumo_id']);

            if ($insumo === null) {
                continue;
            }

            $detalle = $traslado->detalles()
                ->where('planta_insumo_id', $datos['planta_insumo_id'])
                ->where('planta_lote_id', $datos['planta_lote_id'])
                ->first() ?? new PlantaTrasladoDetalle;

            $detalle->planta_traslado_id = $traslado->id;
            $detalle->fill($datos);
            $detalle->unidad_base = $insumo->unidad_base->value;
            $detalle->save();

            $conservados[] = $detalle->id;
        }

        $traslado->detalles()->whereNotIn('id', $conservados ?: [0])->delete();
    }

    private function aEscala(string $valor): string
    {
        return bcadd($valor, '0', 4);
    }
}
