<?php

namespace App\Services\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\RecepcionInvalidaException;
use App\Exceptions\Planta\ReversionRecepcionImposibleException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaRecepcionDetalle;
use App\Models\Planta\PlantaUbicacion;
use App\Models\Secuencia;
use App\Models\User;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ÚNICO punto autorizado para crear, editar, confirmar, anular y reversar
 * recepciones de insumos.
 *
 * Es un CLIENTE de {@see PlantaInventarioService}, no un sustituto: no escribe
 * jamás en `planta_movimientos` ni en `planta_existencias`. Su trabajo es
 * decidir QUÉ efectos corresponden y dejar que el motor los aplique con sus
 * propias garantías (transacción, bloqueo, saldo no negativo, idempotencia).
 *
 * TODA operación que mueva inventario va dentro de `DB::transaction($cb, 3)`.
 * Los tres intentos no son adorno: bajo contención InnoDB mata una de las
 * transacciones con un deadlock, y esa es la forma NORMAL en que el motor
 * resuelve una carrera. Sin reintentos, una confirmación perfectamente válida
 * fallaría de cara al usuario porque otro proceso tocó el mismo bucket en el
 * mismo milisegundo.
 *
 * NUMERACIÓN. `numero` se toma con {@see Secuencia::siguiente()} y la clave
 * {@see CLAVE_SECUENCIA} al CREAR el borrador, no al confirmarlo. La columna es
 * NOT NULL y la operación se refiere a «la recepción 47» desde que existe, así
 * que no hay alternativa coherente. Anular un borrador deja un hueco en la
 * serie, y es aceptable porque esta numeración NO es fiscal: no es
 * `numero_sistema`, no es un correlativo del MH y no tiene obligación de
 * continuidad.
 *
 * REGLA DE LOTES REALES, para insumos con `controla_lotes = true`:
 *
 *   1. por DEFECTO, cada recepción crea un lote interno NUEVO
 *      `INT-AAAAMMDD-####`;
 *   2. solo se REUTILIZA un lote existente si el usuario lo selecciona de forma
 *      explícita, y únicamente si es del mismo insumo, está activo y no es
 *      genérico;
 *   3. NUNCA se deduce reutilización porque coincida `lote_codigo_proveedor`.
 *
 * El punto 3 es el que importa: dos entregas del mismo proveedor con el mismo
 * texto impreso siguen siendo dos llegadas distintas, con fechas de recepción y
 * de vencimiento potencialmente distintas. Fusionarlas por el texto haría
 * imposible saber de qué entrega salió un saldo, que es justo lo que un lote
 * existe para responder.
 *
 * Para `controla_lotes = false` se usa siempre el genérico
 * ({@see LoteService::resolverGenerico()}) y se IGNORA cualquier lote que llegue
 * del formulario.
 */
class PlantaRecepcionService
{
    /** Clave del contador propio del módulo. No es fiscal. */
    public const CLAVE_SECUENCIA = 'planta_recepcion';

    /** Permiso que exige recibir mercancía como retenida. */
    public const PERMISO_CALIDAD = 'planta.calidad.gestionar';

    /** Destinos admisibles al recibir. `rechazado` no es una forma de recibir. */
    private const DESTINOS_VALIDOS = [
        EstadoDisponibilidad::Disponible,
        EstadoDisponibilidad::Retenido,
    ];

    public function __construct(
        private readonly PlantaInventarioService $inventario,
        private readonly LoteService $lotes,
    ) {}

    // --- Borradores ---

    /**
     * Crea un borrador con sus líneas. No toca inventario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearBorrador(array $datos, ?User $usuario = null): PlantaRecepcion
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $this->exigirPermisoDeDestino($datos['detalles'] ?? [], $usuario);

            $recepcion = new PlantaRecepcion;
            $recepcion->fill($this->soloCabecera($datos));
            $recepcion->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $recepcion->estado = EstadoRecepcionPlanta::Borrador;
            $recepcion->creado_por = $usuario?->id;
            $recepcion->save();

            $this->sincronizarDetalles($recepcion, $datos['detalles'] ?? []);

            return $recepcion->refresh();
        }, 3);
    }

    /**
     * Reemplaza cabecera y líneas de un borrador.
     *
     * Las líneas se SINCRONIZAN por id en vez de borrarse y recrearse: así el
     * registro de actividad refleja lo que de verdad cambió —esta línea subió de
     * cantidad, aquella se quitó— en vez de un borrado y alta masivos en cada
     * guardado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarBorrador(PlantaRecepcion $recepcion, array $datos, ?User $usuario = null): PlantaRecepcion
    {
        return DB::transaction(function () use ($recepcion, $datos, $usuario) {
            $bloqueada = $this->bloquear($recepcion);

            if (! $bloqueada->esEditable()) {
                throw RecepcionInvalidaException::estadoNoPermite($bloqueada->estado->value, 'edición');
            }

            $this->exigirPermisoDeDestino($datos['detalles'] ?? [], $usuario);

            $bloqueada->fill($this->soloCabecera($datos));
            $bloqueada->save();

            $this->sincronizarDetalles($bloqueada, $datos['detalles'] ?? []);

            return $bloqueada->refresh();
        }, 3);
    }

    /** Descarta un borrador. Es terminal: un anulado no se reabre. */
    public function anular(PlantaRecepcion $recepcion): PlantaRecepcion
    {
        return DB::transaction(function () use ($recepcion) {
            $bloqueada = $this->bloquear($recepcion);

            if (! $bloqueada->puedeAnularse()) {
                throw RecepcionInvalidaException::estadoNoPermite($bloqueada->estado->value, 'anulación');
            }

            $bloqueada->estado = EstadoRecepcionPlanta::Anulada;
            $bloqueada->save();

            activity('planta_recepcion')
                ->performedOn($bloqueada)
                ->withProperties(['numero' => $bloqueada->numero])
                ->log('anuló el borrador de recepción');

            return $bloqueada;
        }, 3);
    }

    // --- Confirmación ---

    /**
     * Convierte el borrador en inventario real.
     *
     * El orden de los pasos no es decorativo: todo lo que puede rechazar la
     * operación se comprueba ANTES de escribir el primer movimiento, con la fila
     * ya bloqueada, de modo que un rechazo no deje medio documento aplicado.
     */
    public function confirmar(PlantaRecepcion $recepcion, User $usuario): PlantaRecepcion
    {
        return DB::transaction(function () use ($recepcion, $usuario) {
            // 1 y 2. Bloqueo y estado. El bloqueo va primero: sin él, dos
            //        confirmaciones simultáneas leerían «borrador» las dos.
            $doc = $this->bloquear($recepcion);

            if (! $doc->puedeConfirmarse()) {
                throw RecepcionInvalidaException::estadoNoPermite($doc->estado->value, 'confirmación');
            }

            // 3. Al menos una línea.
            $detalles = $doc->detalles()->orderBy('id')->get();

            if ($detalles->isEmpty()) {
                throw RecepcionInvalidaException::sinDetalles($doc->numero);
            }

            // 4, 5 y 6. Contexto vigente HOY, no cuando se escribió el borrador.
            $ubicacion = $this->validarUbicacion($doc);
            $this->validarProveedor($doc);
            $insumos = $this->validarInsumos($detalles);

            // Destino retenido: decisión de calidad, no de recepción.
            $this->exigirPermisoDeDestinoEnDetalles($detalles, $usuario);

            $grupo = (string) Str::uuid();

            // 7, 8 y 9. Recalcular, resolver lote y construir bucket por línea.
            $efectos = [];

            foreach ($detalles as $detalle) {
                $insumo = $insumos[$detalle->planta_insumo_id];

                $cantidadBase = $this->recalcularCantidadBase($detalle, $insumo);
                $lote = $this->resolverLote($detalle, $doc, $insumo);

                $detalle->planta_lote_id = $lote->id;
                $detalle->cantidad_base = $cantidadBase;
                $detalle->unidad_base = $insumo->unidad_base->value;
                $detalle->save();

                $bucket = new BucketInventario(
                    insumoId: $insumo->id,
                    loteId: $lote->id,
                    ubicacionId: $ubicacion->id,
                    estado: $detalle->estado_destino,
                    trasladoId: 0,
                );

                $efectos[] = ['bucket' => $bucket, 'detalle' => $detalle, 'cantidad' => $cantidadBase];
            }

            // 10. Ordenar por bucket y agrupar. El ORDEN es lo que evita deadlocks
            //     entre dos confirmaciones que tocan los mismos buckets en distinto
            //     orden; agrupar deja las líneas del mismo bucket contiguas, que es
            //     como se toma un solo bloqueo por bucket en vez de saltar entre
            //     ellos. Las líneas NO se fusionan: cada una conserva su movimiento
            //     y su trazabilidad hasta el detalle que la originó.
            usort($efectos, fn (array $a, array $b) => [$a['bucket']->claveCanonica(), $a['detalle']->id]
                <=> [$b['bucket']->claveCanonica(), $b['detalle']->id]);

            // 11. Los efectos, uno por línea, a través del motor de inventario.
            foreach ($efectos as $efecto) {
                $this->inventario->aplicarMovimiento(
                    $efecto['bucket'],
                    $efecto['cantidad'],
                    ContextoMovimiento::para(
                        tipo: TipoMovimientoPlanta::Recepcion,
                        documentoType: PlantaRecepcion::class,
                        documentoId: $doc->id,
                        transicion: 'confirmar',
                        fechaEfectiva: $doc->fecha,
                        // La estabilidad del efecto_uid la da el DETALLE: es único,
                        // no cambia y sobrevive a cualquier reordenación. Por eso la
                        // secuencia se queda en 0: no hay dos efectos del mismo
                        // detalle sobre el mismo bucket.
                        documentoDetalleId: $efecto['detalle']->id,
                        grupoUuid: $grupo,
                        userId: $usuario->id,
                        responsableNombre: $doc->responsable_nombre,
                        metadata: [
                            'recepcion_numero' => $doc->numero,
                            'unidad_recibida' => $efecto['detalle']->unidad_recibida,
                            'cantidad_recibida' => (string) $efecto['detalle']->cantidad_recibida,
                        ],
                    ),
                );
            }

            // 12 y 13. El documento pasa a confirmada con su firma.
            $doc->estado = EstadoRecepcionPlanta::Confirmada;
            $doc->confirmado_por = $usuario->id;
            $doc->confirmado_en = now();
            $doc->save();

            // 14. Auditoría del hecho, con lo justo para reconstruirlo.
            activity('planta_recepcion')
                ->performedOn($doc)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $doc->numero,
                    'grupo_uuid' => $grupo,
                    'lineas' => $detalles->count(),
                    'ubicacion' => $ubicacion->codigo,
                    'destinos' => $detalles->groupBy(fn ($d) => $d->estado_destino->value)
                        ->map->count()->all(),
                ])
                ->log('confirmó la recepción de insumos');

            return $doc->refresh();
        }, 3);
    }

    // --- Reversión ---

    /**
     * Deshace contablemente una recepción confirmada con movimientos espejo.
     *
     * NO edita ni borra los movimientos originales: crea un documento nuevo cuyos
     * efectos son negativos y apuntan, uno a uno, al movimiento que compensan.
     *
     * Falla ENTERA si el saldo ya no está donde entró. La comprobación se hace
     * AGREGANDO por bucket, no línea a línea: dos líneas del mismo bucket podrían
     * pasar cada una por separado y ser imposibles juntas.
     */
    public function reversar(PlantaRecepcion $recepcion, string $motivo, User $usuario): PlantaRecepcion
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw RecepcionInvalidaException::motivoRequerido();
        }

        return DB::transaction(function () use ($recepcion, $motivo, $usuario) {
            $original = $this->bloquear($recepcion);

            if ($original->esReversion()) {
                throw ReversionRecepcionImposibleException::esUnaReversion($original->numero);
            }

            if ($original->revertido_por_id !== null) {
                throw ReversionRecepcionImposibleException::yaReversada($original->numero);
            }

            if ($original->estado !== EstadoRecepcionPlanta::Confirmada) {
                throw RecepcionInvalidaException::estadoNoPermite($original->estado->value, 'reversión');
            }

            $detalles = $original->detalles()->with('insumo', 'lote')->orderBy('id')->get();

            // Requerido por bucket, agregado. Ver docblock.
            $requerido = [];
            $buckets = [];

            foreach ($detalles as $detalle) {
                $bucket = $detalle->bucket();
                $clave = $bucket->claveCanonica();

                $buckets[$clave] = ['bucket' => $bucket, 'detalle' => $detalle];
                $requerido[$clave] = bcadd($requerido[$clave] ?? '0', (string) $detalle->cantidad_base, 4);
            }

            ksort($requerido);

            foreach ($requerido as $clave => $cantidad) {
                $bucket = $buckets[$clave]['bucket'];
                $saldo = $this->inventario->saldo($bucket);

                if (bccomp($saldo, $cantidad, 4) === -1) {
                    throw ReversionRecepcionImposibleException::saldoInsuficiente(
                        $bucket->descripcion(),
                        (string) $buckets[$clave]['detalle']->lote?->codigo_interno,
                        $cantidad,
                        $saldo,
                    );
                }
            }

            // Documento de compensación: una recepción más, con sus líneas copiadas.
            $reversion = new PlantaRecepcion;
            $reversion->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $reversion->estado = EstadoRecepcionPlanta::Confirmada;
            $reversion->fecha = now()->toDateString();
            $reversion->planta_proveedor_id = $original->planta_proveedor_id;
            $reversion->planta_ubicacion_id = $original->planta_ubicacion_id;
            $reversion->documento_referencia = $original->documento_referencia;
            $reversion->creado_por = $usuario->id;
            $reversion->confirmado_por = $usuario->id;
            $reversion->confirmado_en = now();
            $reversion->responsable_user_id = $original->responsable_user_id;
            $reversion->responsable_nombre = $original->responsable_nombre;
            $reversion->observaciones = $motivo;
            $reversion->reversion_de_id = $original->id;
            $reversion->save();

            $grupo = (string) Str::uuid();

            foreach ($detalles as $detalle) {
                $copia = $detalle->replicate(['created_at', 'updated_at']);
                $copia->planta_recepcion_id = $reversion->id;
                $copia->save();

                $movimientoOriginal = PlantaMovimiento::query()
                    ->delDocumento(PlantaRecepcion::class, $original->id)
                    ->where('documento_detalle_id', $detalle->id)
                    ->orderBy('id')
                    ->first();

                if ($movimientoOriginal === null) {
                    throw ReversionRecepcionImposibleException::movimientoOriginalAusente($detalle->id);
                }

                $this->inventario->aplicarMovimiento(
                    $detalle->bucket(),
                    '-'.$detalle->cantidad_base,
                    ContextoMovimiento::para(
                        tipo: TipoMovimientoPlanta::ReversionRecepcion,
                        documentoType: PlantaRecepcion::class,
                        documentoId: $reversion->id,
                        transicion: 'reversar',
                        fechaEfectiva: $reversion->fecha,
                        documentoDetalleId: $copia->id,
                        grupoUuid: $grupo,
                        userId: $usuario->id,
                        responsableNombre: $original->responsable_nombre,
                        movimientoRevertidoId: $movimientoOriginal->id,
                        metadata: [
                            'reversion_de' => $original->numero,
                            'motivo' => $motivo,
                        ],
                    ),
                );
            }

            $original->estado = EstadoRecepcionPlanta::Reversada;
            $original->revertido_por_id = $reversion->id;
            $original->save();

            activity('planta_recepcion')
                ->performedOn($original)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $original->numero,
                    'reversion_numero' => $reversion->numero,
                    'grupo_uuid' => $grupo,
                    'motivo' => $motivo,
                ])
                ->log('reversó la recepción confirmada');

            return $reversion->refresh();
        }, 3);
    }

    // --- Validaciones de contexto ---

    /** Relee la fila con bloqueo exclusivo: el estado que vale es el de ahora. */
    private function bloquear(PlantaRecepcion $recepcion): PlantaRecepcion
    {
        return PlantaRecepcion::whereKey($recepcion->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validarUbicacion(PlantaRecepcion $doc): PlantaUbicacion
    {
        $ubicacion = PlantaUbicacion::findOrFail($doc->planta_ubicacion_id);

        if (! $ubicacion->activo) {
            throw RecepcionInvalidaException::ubicacionInactiva($ubicacion->nombre);
        }

        // Cubre TRÁNSITO sin nombrarlo: es la ubicación que no admite operación
        // manual. Si mañana hay otra igual, queda cubierta sola.
        if (! $ubicacion->permite_operacion_manual || $ubicacion->tipo->esTransito()) {
            throw RecepcionInvalidaException::ubicacionNoAdmiteOperacion($ubicacion->nombre);
        }

        return $ubicacion;
    }

    private function validarProveedor(PlantaRecepcion $doc): void
    {
        if ($doc->planta_proveedor_id === null) {
            return;
        }

        $proveedor = $doc->proveedor()->first();

        if ($proveedor !== null && ! $proveedor->activo) {
            throw RecepcionInvalidaException::proveedorInactivo($proveedor->nombre);
        }
    }

    /**
     * @param  Collection<int, PlantaRecepcionDetalle>  $detalles
     * @return array<int, PlantaInsumo>
     */
    private function validarInsumos($detalles): array
    {
        $insumos = PlantaInsumo::whereIn('id', $detalles->pluck('planta_insumo_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($detalles as $detalle) {
            $insumo = $insumos->get($detalle->planta_insumo_id);

            if ($insumo === null) {
                throw RecepcionInvalidaException::insumoInactivo('#'.$detalle->planta_insumo_id);
            }

            if (! $insumo->activo) {
                throw RecepcionInvalidaException::insumoInactivo($insumo->nombre);
            }
        }

        return $insumos->all();
    }

    /**
     * Recalcula la cantidad base en el servidor. Lo que venga en el campo se
     * ignora: es un valor derivado, no un dato de entrada.
     */
    private function recalcularCantidadBase(PlantaRecepcionDetalle $detalle, PlantaInsumo $insumo): string
    {
        foreach ([
            'cantidad recibida' => (string) $detalle->cantidad_recibida,
            'contenido por unidad' => (string) $detalle->contenido_por_unidad,
            'factor de conversión' => (string) $detalle->factor_conversion,
        ] as $campo => $valor) {
            if (bccomp($valor, '0', 8) !== 1) {
                throw RecepcionInvalidaException::cantidadNoPositiva($campo, $valor);
            }
        }

        $base = PlantaRecepcionDetalle::convertir(
            (string) $detalle->cantidad_recibida,
            (string) $detalle->contenido_por_unidad,
            (string) $detalle->factor_conversion,
        );

        if (bccomp($base, '0', 4) !== 1) {
            throw RecepcionInvalidaException::cantidadNoPositiva('cantidad base', $base);
        }

        // La fracción de un insumo indivisible la rechaza el motor de inventario
        // al aplicar el movimiento; aquí no se duplica esa regla.
        return $base;
    }

    /** Aplica la regla de lotes documentada en la cabecera de la clase. */
    private function resolverLote(PlantaRecepcionDetalle $detalle, PlantaRecepcion $doc, PlantaInsumo $insumo): PlantaLote
    {
        if (! $insumo->controla_lotes) {
            // El genérico manda: lo que venga del formulario se ignora.
            return $this->lotes->resolverGenerico($insumo, $doc->fecha->toDateString());
        }

        if ($detalle->planta_lote_id !== null) {
            return $this->validarReutilizacion($detalle->planta_lote_id, $insumo);
        }

        return $this->crearLoteInterno($detalle, $doc, $insumo);
    }

    /** Reutilización EXPLÍCITA: mismo insumo, activo y no genérico. */
    private function validarReutilizacion(int $loteId, PlantaInsumo $insumo): PlantaLote
    {
        $lote = PlantaLote::find($loteId);

        if ($lote === null) {
            throw RecepcionInvalidaException::loteNoReutilizable($loteId, 'no existe');
        }

        if ($lote->planta_insumo_id !== $insumo->id) {
            throw RecepcionInvalidaException::loteAjeno($loteId, $insumo->nombre);
        }

        if ($lote->es_generico) {
            throw RecepcionInvalidaException::loteNoReutilizable(
                $loteId,
                'es el lote genérico del sistema y no se elige a mano'
            );
        }

        if (! $lote->activo) {
            throw RecepcionInvalidaException::loteNoReutilizable($loteId, 'está inactivo');
        }

        return $lote;
    }

    /**
     * Crea el lote interno `INT-AAAAMMDD-####` de esta entrada.
     *
     * El correlativo `####` es DIARIO y se sirve con {@see Secuencia}, con una
     * clave por día: el contador queda bajo bloqueo de fila y dos recepciones
     * simultáneas no pueden recibir el mismo número. Un `MAX()+1` sobre
     * `planta_lotes` sí podría dárselo a las dos.
     */
    private function crearLoteInterno(PlantaRecepcionDetalle $detalle, PlantaRecepcion $doc, PlantaInsumo $insumo): PlantaLote
    {
        $fecha = Carbon::parse($doc->fecha)->format('Ymd');
        $correlativo = Secuencia::siguiente('planta_lote_interno_'.$fecha);

        return PlantaLote::create([
            'planta_insumo_id' => $insumo->id,
            'planta_proveedor_id' => $doc->planta_proveedor_id,
            'codigo_interno' => sprintf('INT-%s-%04d', $fecha, $correlativo),
            'codigo_proveedor' => $detalle->lote_codigo_proveedor,
            'es_generico' => false,
            // La fecha del LOTE es la de la recepción, no la de captura.
            'fecha_recepcion' => $doc->fecha->toDateString(),
            'fecha_elaboracion' => $detalle->fecha_elaboracion?->toDateString(),
            'fecha_vencimiento' => $detalle->fecha_vencimiento?->toDateString(),
            'activo' => true,
        ]);
    }

    // --- Permiso de calidad ---

    /**
     * Recibir como RETENIDA es una decisión de calidad. Se comprueba en el
     * servicio y no solo en el Form Request porque un formulario no es una
     * barrera: una petición construida a mano llegaría igual.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     */
    private function exigirPermisoDeDestino(array $detalles, ?User $usuario): void
    {
        foreach ($detalles as $linea) {
            $destino = $linea['estado_destino'] ?? null;

            if ($destino === EstadoDisponibilidad::Retenido->value && ! $this->puedeGestionarCalidad($usuario)) {
                throw RecepcionInvalidaException::retenidoSinPermisoDeCalidad();
            }

            if ($destino !== null && ! in_array(
                EstadoDisponibilidad::tryFrom((string) $destino),
                self::DESTINOS_VALIDOS,
                true
            )) {
                throw RecepcionInvalidaException::destinoNoPermitido((string) $destino);
            }
        }
    }

    /** @param  Collection<int, PlantaRecepcionDetalle>  $detalles */
    private function exigirPermisoDeDestinoEnDetalles($detalles, ?User $usuario): void
    {
        foreach ($detalles as $detalle) {
            if (! in_array($detalle->estado_destino, self::DESTINOS_VALIDOS, true)) {
                throw RecepcionInvalidaException::destinoNoPermitido($detalle->estado_destino->value);
            }

            if ($detalle->estado_destino === EstadoDisponibilidad::Retenido && ! $this->puedeGestionarCalidad($usuario)) {
                throw RecepcionInvalidaException::retenidoSinPermisoDeCalidad();
            }
        }
    }

    private function puedeGestionarCalidad(?User $usuario): bool
    {
        return $usuario !== null && $usuario->can(self::PERMISO_CALIDAD);
    }

    // --- Persistencia auxiliar ---

    /** @param  array<string, mixed>  $datos */
    private function soloCabecera(array $datos): array
    {
        return array_intersect_key($datos, array_flip([
            'fecha',
            'planta_proveedor_id',
            'planta_ubicacion_id',
            'documento_referencia',
            'responsable_user_id',
            'responsable_nombre',
            'observaciones',
        ]));
    }

    /**
     * Sincroniza las líneas por id: actualiza las que llegan con id, crea las que
     * no lo traen y elimina las que ya no vienen.
     *
     * `cantidad_base` y `unidad_base` se derivan aquí y NO se toman del payload,
     * ni siquiera en el borrador: si el formulario pudiera fijarlas, una petición
     * manual dejaría un borrador que dice 500 y vale 5.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function sincronizarDetalles(PlantaRecepcion $recepcion, array $lineas): void
    {
        $conservados = [];

        foreach ($lineas as $linea) {
            $insumo = PlantaInsumo::find($linea['planta_insumo_id'] ?? null);

            if ($insumo === null) {
                continue;
            }

            $detalle = isset($linea['id'])
                ? $recepcion->detalles()->whereKey($linea['id'])->first() ?? new PlantaRecepcionDetalle
                : new PlantaRecepcionDetalle;

            $detalle->planta_recepcion_id = $recepcion->id;
            $detalle->fill(array_intersect_key($linea, array_flip([
                'planta_insumo_id',
                'cantidad_recibida',
                'unidad_recibida',
                'contenido_por_unidad',
                'factor_conversion',
                'estado_destino',
                'lote_codigo_proveedor',
                'fecha_elaboracion',
                'fecha_vencimiento',
                'observaciones',
            ])));

            // Reutilización explícita de lote real: solo se guarda la intención;
            // se valida al confirmar, que es cuando el lote pasa a ser inventario.
            $detalle->planta_lote_id = $insumo->controla_lotes
                ? ($linea['planta_lote_id'] ?? null)
                : null;

            $detalle->unidad_base = $insumo->unidad_base->value;
            $detalle->cantidad_base = PlantaRecepcionDetalle::convertir(
                (string) ($linea['cantidad_recibida'] ?? '0'),
                (string) ($linea['contenido_por_unidad'] ?? '0'),
                (string) ($linea['factor_conversion'] ?? '0'),
            );

            $detalle->save();

            $conservados[] = $detalle->id;
        }

        $recepcion->detalles()->whereNotIn('id', $conservados ?: [0])->delete();
    }
}
