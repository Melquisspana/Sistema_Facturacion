<?php

namespace App\Services\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\AjusteInvalidoException;
use App\Exceptions\Planta\ReversionAjusteImposibleException;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaAjusteDetalle;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaUbicacion;
use App\Models\Secuencia;
use App\Models\User;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ÚNICO punto autorizado para crear, editar, confirmar, anular y reversar
 * ajustes de inventario.
 *
 * Es un CLIENTE de {@see PlantaInventarioService}: nunca escribe en
 * `planta_movimientos` ni en `planta_existencias`.
 *
 * QUÉ HACE ESPECIAL A ESTE DOCUMENTO. Todos los anteriores tienen algo externo
 * que los respalda: una recepción tiene proveedor, un traslado tiene camión, un
 * cambio de disponibilidad suma cero. Un ajuste no: dice «aquí hay más» o «aquí
 * hay menos» porque alguien lo constató. Por eso el motivo es obligatorio
 * siempre, por eso es admin-only, y por eso cada regla de tipo se valida en el
 * servidor aunque el formulario ya la haya mirado.
 *
 * EL SIGNO LO PONE EL SERVICIO, NUNCA EL FORMULARIO. `cantidad` es una magnitud
 * positiva; el tipo decide si suma o resta. La corrección de conteo es la única
 * excepción, y ahí el signo sale de la diferencia entre lo contado y lo que
 * decía el sistema — leído BAJO BLOQUEO al confirmar, jamás del payload.
 *
 * POR QUÉ `cantidad_sistema` SE LEE AL CONFIRMAR. Entre que se escribe el
 * borrador y se confirma pueden pasar horas y el saldo puede haber cambiado. Si
 * la diferencia se calculara con el valor viejo, la corrección escribiría un
 * movimiento que descuadra justo lo que pretendía cuadrar. Se recalcula con la
 * fila bloqueada, y por eso dos correcciones simultáneas del mismo bucket no
 * pueden partir ambas del mismo saldo.
 *
 * ORDEN DE APLICACIÓN: por CLAVE CANÓNICA del bucket. Dos ajustes simultáneos
 * que tocan los mismos buckets los bloquean en el mismo orden, así que InnoDB no
 * encuentra ciclos.
 *
 * TODA operación va dentro de `DB::transaction($cb, 3)`.
 */
class PlantaAjusteService
{
    /** Clave del contador propio. No es fiscal. */
    public const CLAVE_SECUENCIA = 'planta_ajuste';

    /** Estados de disponibilidad que un ajuste puede alterar: los tres. */
    private const ESTADOS_VALIDOS = [
        EstadoDisponibilidad::Disponible,
        EstadoDisponibilidad::Retenido,
        EstadoDisponibilidad::Rechazado,
    ];

    public function __construct(
        private readonly PlantaInventarioService $inventario,
        private readonly LoteService $lotes,
    ) {}

    // --- Borrador ---

    /**
     * Crea el borrador con sus líneas. No toca inventario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearBorrador(array $datos, ?User $usuario = null): PlantaAjuste
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $this->validarMotivo($datos['motivo'] ?? null);

            $ajuste = new PlantaAjuste;
            $ajuste->fill($this->soloCabecera($datos));
            $ajuste->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $ajuste->estado = EstadoAjustePlanta::Borrador;
            $ajuste->creado_por = $usuario?->id;
            $ajuste->save();

            $this->sincronizarDetalles($ajuste, $datos['detalles'] ?? []);

            return $ajuste->refresh();
        }, 3);
    }

    /**
     * Reemplaza cabecera y líneas de un borrador.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarBorrador(PlantaAjuste $ajuste, array $datos): PlantaAjuste
    {
        return DB::transaction(function () use ($ajuste, $datos) {
            $bloqueado = $this->bloquear($ajuste);

            if (! $bloqueado->esEditable()) {
                throw AjusteInvalidoException::estadoNoPermite($bloqueado->estado->value, 'edición');
            }

            $this->validarMotivo($datos['motivo'] ?? $bloqueado->motivo);

            $bloqueado->fill($this->soloCabecera($datos));
            $bloqueado->save();

            $this->sincronizarDetalles($bloqueado, $datos['detalles'] ?? []);

            return $bloqueado->refresh();
        }, 3);
    }

    /** Descarta un borrador. Es terminal y no mueve inventario. */
    public function anular(PlantaAjuste $ajuste): PlantaAjuste
    {
        return DB::transaction(function () use ($ajuste) {
            $bloqueado = $this->bloquear($ajuste);

            if (! $bloqueado->puedeAnularse()) {
                throw AjusteInvalidoException::estadoNoPermite($bloqueado->estado->value, 'anulación');
            }

            $bloqueado->estado = EstadoAjustePlanta::Anulado;
            $bloqueado->save();

            activity('planta_ajuste')
                ->performedOn($bloqueado)
                ->withProperties(['numero' => $bloqueado->numero, 'tipo' => $bloqueado->tipo->value])
                ->log('anuló el borrador de ajuste');

            return $bloqueado;
        }, 3);
    }

    // --- Confirmación ---

    /**
     * Aplica el ajuste al inventario y deja el documento inmutable.
     *
     * Todo lo que puede rechazar la operación se comprueba ANTES de escribir el
     * primer movimiento y con la fila bloqueada, de modo que un rechazo no deje
     * medio documento aplicado.
     */
    public function confirmar(PlantaAjuste $ajuste, User $usuario): PlantaAjuste
    {
        return DB::transaction(function () use ($ajuste, $usuario) {
            // 1 y 2. Bloqueo y estado. Sin el bloqueo, dos confirmaciones
            //        simultáneas leerían «borrador» las dos.
            $doc = $this->bloquear($ajuste);

            if (! $doc->puedeConfirmarse()) {
                throw AjusteInvalidoException::estadoNoPermite($doc->estado->value, 'confirmación');
            }

            // 3 a 5. Líneas, motivo y tipo.
            $detalles = $doc->detalles()->orderBy('id')->get();

            if ($detalles->isEmpty()) {
                throw AjusteInvalidoException::sinDetalles($doc->numero);
            }

            $this->validarMotivo($doc->motivo);

            // 6 a 9. Contexto vigente HOY, no cuando se escribió el borrador.
            $insumos = $this->validarLineas($doc, $detalles);

            // 10. Orden canónico: evita deadlocks entre ajustes simultáneos.
            $ordenados = $detalles->sortBy(fn (PlantaAjusteDetalle $d) => $d->bucket()->claveCanonica())->values();

            // 11 a 13. Saldos bajo bloqueo, diferencias recalculadas y reglas de
            //          tipo. Se resuelve TODO antes de escribir nada.
            $efectos = $this->resolverEfectos($doc, $ordenados, $insumos);

            $grupo = (string) Str::uuid();

            // 14. Los movimientos, a través del motor de inventario.
            foreach ($efectos as $efecto) {
                $this->inventario->aplicarMovimiento(
                    $efecto['bucket'],
                    $efecto['cantidadFirmada'],
                    ContextoMovimiento::para(
                        tipo: $doc->tipo->tipoMovimiento(),
                        documentoType: PlantaAjuste::class,
                        documentoId: $doc->id,
                        transicion: 'confirmar',
                        fechaEfectiva: $doc->fecha,
                        documentoDetalleId: $efecto['detalle']->id,
                        grupoUuid: $grupo,
                        userId: $usuario->id,
                        responsableNombre: $doc->responsable_nombre,
                        metadata: [
                            'ajuste_numero' => $doc->numero,
                            // El tipo CONCRETO va en la metadata: el mayor solo
                            // distingue `carga_inicial` de `ajuste`, y aquí es
                            // donde se puede responder «cuánto se mermó».
                            'tipo_ajuste' => $doc->tipo->value,
                            'motivo' => $doc->motivo,
                        ] + $efecto['metadata'],
                    ),
                );
            }

            // 15 y 16. El documento pasa a confirmado con su firma.
            $doc->estado = EstadoAjustePlanta::Confirmado;
            $doc->confirmado_por = $usuario->id;
            $doc->confirmado_en = now();
            $doc->save();

            // 17. Auditoría.
            activity('planta_ajuste')
                ->performedOn($doc)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $doc->numero,
                    'tipo' => $doc->tipo->value,
                    'grupo_uuid' => $grupo,
                    'lineas' => $detalles->count(),
                    'movimientos' => count($efectos),
                    'motivo' => $doc->motivo,
                ])
                ->log('confirmó el ajuste de inventario');

            return $doc->refresh();
        }, 3);
    }

    // --- Reversión ---

    /**
     * Deshace un ajuste confirmado aplicando su efecto al revés sobre el MISMO
     * bucket.
     *
     * NO edita ni borra los movimientos originales: crea un documento nuevo cuyos
     * efectos apuntan, uno a uno, al movimiento que compensan.
     */
    public function reversar(PlantaAjuste $ajuste, string $motivo, User $usuario): PlantaAjuste
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw AjusteInvalidoException::motivoRequerido();
        }

        return DB::transaction(function () use ($ajuste, $motivo, $usuario) {
            $original = $this->bloquear($ajuste);

            if ($original->esReversion()) {
                throw ReversionAjusteImposibleException::esUnaReversion($original->numero);
            }

            if ($original->revertido_por_id !== null) {
                throw ReversionAjusteImposibleException::yaReversado($original->numero);
            }

            if ($original->estado !== EstadoAjustePlanta::Confirmado) {
                throw AjusteInvalidoException::estadoNoPermite($original->estado->value, 'reversión');
            }

            // Se compensa MOVIMIENTO a MOVIMIENTO, no línea a línea: una
            // corrección de conteo con diferencia cero no generó movimiento y por
            // tanto no hay nada que compensar en esa línea.
            $movimientos = PlantaMovimiento::query()
                ->delDocumento(PlantaAjuste::class, $original->id)
                ->orderBy('id')
                ->get();

            // Lo que hay que RETIRAR, agregado por bucket: solo los movimientos
            // que sumaron. Agregar importa porque un mismo bucket podría recibir
            // más de un movimiento en documentos futuros.
            $retiros = [];

            foreach ($movimientos as $movimiento) {
                if (bccomp((string) $movimiento->cantidad, '0', 4) !== 1) {
                    continue;
                }

                $clave = $movimiento->bucket()->claveCanonica();
                $retiros[$clave] = [
                    'bucket' => $movimiento->bucket(),
                    'cantidad' => bcadd($retiros[$clave]['cantidad'] ?? '0', (string) $movimiento->cantidad, 4),
                ];
            }

            ksort($retiros);

            foreach ($retiros as ['bucket' => $bucket, 'cantidad' => $cantidad]) {
                $saldo = $this->inventario->saldo($bucket);

                if (bccomp($saldo, $cantidad, 4) === -1) {
                    throw ReversionAjusteImposibleException::saldoInsuficiente(
                        $bucket->descripcion(),
                        $cantidad,
                        $saldo,
                    );
                }
            }

            // Documento de compensación: un ajuste más, del mismo tipo, con el
            // motivo de la reversión.
            $reversion = new PlantaAjuste;
            $reversion->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $reversion->estado = EstadoAjustePlanta::Confirmado;
            $reversion->tipo = $original->tipo;
            $reversion->fecha = now()->toDateString();
            $reversion->motivo = $motivo;
            $reversion->creado_por = $usuario->id;
            $reversion->confirmado_por = $usuario->id;
            $reversion->confirmado_en = now();
            $reversion->responsable_user_id = $original->responsable_user_id;
            $reversion->responsable_nombre = $original->responsable_nombre;
            $reversion->observaciones = $original->observaciones;
            $reversion->reversion_de_id = $original->id;
            $reversion->save();

            $grupo = (string) Str::uuid();

            // Las líneas de la reversión copian las del original que SÍ movieron.
            $detallesPorId = $original->detalles()->get()->keyBy('id');
            $copias = [];

            foreach ($movimientos->sortBy(fn ($m) => $m->bucket()->claveCanonica()) as $movimiento) {
                $detalleOriginal = $detallesPorId->get($movimiento->documento_detalle_id);

                if ($detalleOriginal === null) {
                    throw ReversionAjusteImposibleException::movimientoOriginalAusente(
                        (int) $movimiento->documento_detalle_id
                    );
                }

                $clave = $detalleOriginal->id;

                if (! isset($copias[$clave])) {
                    $copia = $detalleOriginal->replicate(['created_at', 'updated_at']);
                    $copia->planta_ajuste_id = $reversion->id;
                    $copia->save();
                    $copias[$clave] = $copia;
                }

                $this->inventario->aplicarMovimiento(
                    $movimiento->bucket(),
                    // El efecto contrario, exacto.
                    bcmul((string) $movimiento->cantidad, '-1', 4),
                    ContextoMovimiento::para(
                        tipo: TipoMovimientoPlanta::ReversionAjuste,
                        documentoType: PlantaAjuste::class,
                        documentoId: $reversion->id,
                        transicion: 'reversar',
                        fechaEfectiva: $reversion->fecha,
                        documentoDetalleId: $copias[$clave]->id,
                        grupoUuid: $grupo,
                        userId: $usuario->id,
                        responsableNombre: $original->responsable_nombre,
                        movimientoRevertidoId: $movimiento->id,
                        metadata: [
                            'reversion_de' => $original->numero,
                            'tipo_ajuste' => $original->tipo->value,
                            'motivo' => $motivo,
                        ],
                    ),
                );
            }

            $original->estado = EstadoAjustePlanta::Reversado;
            $original->revertido_por_id = $reversion->id;
            $original->save();

            activity('planta_ajuste')
                ->performedOn($original)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $original->numero,
                    'reversion_numero' => $reversion->numero,
                    'tipo' => $original->tipo->value,
                    'grupo_uuid' => $grupo,
                    'motivo' => $motivo,
                ])
                ->log('reversó el ajuste de inventario');

            return $reversion->refresh();
        }, 3);
    }

    // --- Resolución de efectos ---

    /**
     * Convierte las líneas en efectos firmados, con los saldos leídos BAJO
     * BLOQUEO y las reglas de cada tipo aplicadas.
     *
     * Devuelve solo las líneas que de verdad mueven algo: una corrección de
     * conteo cuya diferencia es cero no produce movimiento, porque no hay nada
     * que registrar.
     *
     * @param  Collection<int, PlantaAjusteDetalle>  $detalles
     * @param  array<int, PlantaInsumo>  $insumos
     * @return array<int, array{bucket: BucketInventario, cantidadFirmada: string, detalle: PlantaAjusteDetalle, metadata: array<string, mixed>}>
     */
    private function resolverEfectos(PlantaAjuste $doc, Collection $detalles, array $insumos): array
    {
        $efectos = [];

        foreach ($detalles as $detalle) {
            $bucket = $detalle->bucket();

            // El saldo se lee BLOQUEANDO la fila: de aquí al COMMIT nadie más
            // mueve este bucket, así que la diferencia que se calcule sigue
            // siendo cierta cuando se escriba.
            $saldo = $this->inventario->saldoBloqueado($bucket);

            [$cantidadFirmada, $metadata] = $doc->esCorreccionDeConteo()
                ? $this->efectoDeCorreccion($detalle, $saldo)
                : $this->efectoDeTipoFijo($doc, $detalle, $saldo, $bucket);

            if ($cantidadFirmada === null) {
                // Diferencia cero: la línea queda registrada con su conteo, pero
                // no escribe en el mayor. Un movimiento de cero no es un hecho.
                continue;
            }

            $this->validarReglaDeTipo($doc, $detalle, $insumos[$detalle->planta_insumo_id], $bucket);

            $efectos[] = [
                'bucket' => $bucket,
                'cantidadFirmada' => $cantidadFirmada,
                'detalle' => $detalle,
                'metadata' => $metadata,
            ];
        }

        // Una corrección entera sin diferencias no es un ajuste: es ruido.
        if ($doc->esCorreccionDeConteo() && $efectos === []) {
            throw AjusteInvalidoException::correccionSinDiferencias($doc->numero);
        }

        if ($efectos === []) {
            throw AjusteInvalidoException::sinDetalles($doc->numero);
        }

        return $efectos;
    }

    /**
     * Corrección de conteo: la diferencia se calcula AQUÍ, con el saldo ya
     * bloqueado, y se persiste en la línea.
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function efectoDeCorreccion(PlantaAjusteDetalle $detalle, string $saldo): array
    {
        if ($detalle->cantidad_conteo === null) {
            throw AjusteInvalidoException::correccionSinConteo();
        }

        $conteo = $this->aEscala((string) $detalle->cantidad_conteo);

        if (bccomp($conteo, '0', 4) === -1) {
            throw AjusteInvalidoException::conteoNegativo($conteo);
        }

        $diferencia = bcsub($conteo, $saldo, 4);

        // Lo que el formulario dijera sobre `cantidad_sistema` se descarta: el
        // valor que vale es el que acaba de leerse bajo bloqueo.
        $detalle->cantidad_sistema = $saldo;
        $detalle->diferencia = $diferencia;
        $detalle->cantidad = ltrim($diferencia, '-');
        $detalle->save();

        if (bccomp($diferencia, '0', 4) === 0) {
            return [null, []];
        }

        return [$diferencia, [
            'cantidad_sistema' => $saldo,
            'cantidad_conteo' => $conteo,
            'diferencia' => $diferencia,
        ]];
    }

    /**
     * Tipos de signo fijo: el tipo decide si suma o resta, y los que restan
     * exigen saldo suficiente.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function efectoDeTipoFijo(
        PlantaAjuste $doc,
        PlantaAjusteDetalle $detalle,
        string $saldo,
        BucketInventario $bucket,
    ): array {
        $cantidad = $this->aEscala((string) $detalle->cantidad);

        if (bccomp($cantidad, '0', 4) !== 1) {
            throw AjusteInvalidoException::cantidadNoPositiva($cantidad);
        }

        if ($doc->sumaSaldo() === true) {
            return [$cantidad, ['saldo_previo' => $saldo]];
        }

        if (bccomp($saldo, $cantidad, 4) === -1) {
            throw AjusteInvalidoException::saldoInsuficiente($bucket->descripcion(), $cantidad, $saldo);
        }

        return ['-'.$cantidad, ['saldo_previo' => $saldo]];
    }

    // --- Validaciones ---

    private function bloquear(PlantaAjuste $ajuste): PlantaAjuste
    {
        return PlantaAjuste::whereKey($ajuste->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validarMotivo(?string $motivo): void
    {
        if (trim((string) $motivo) === '') {
            throw AjusteInvalidoException::motivoRequerido();
        }
    }

    /**
     * Ubicación, insumo, lote y estado de cada línea, con el contexto vigente
     * HOY y no el del borrador.
     *
     * @param  Collection<int, PlantaAjusteDetalle>  $detalles
     * @return array<int, PlantaInsumo>
     */
    private function validarLineas(PlantaAjuste $doc, Collection $detalles): array
    {
        $insumos = PlantaInsumo::whereIn('id', $detalles->pluck('planta_insumo_id')->unique())->get()->keyBy('id');
        $lotes = PlantaLote::whereIn('id', $detalles->pluck('planta_lote_id')->unique())->get()->keyBy('id');
        $ubicaciones = PlantaUbicacion::whereIn('id', $detalles->pluck('planta_ubicacion_id')->unique())->get()->keyBy('id');

        foreach ($detalles as $detalle) {
            $ubicacion = $ubicaciones->get($detalle->planta_ubicacion_id);

            if ($ubicacion === null || ! $ubicacion->activo) {
                throw AjusteInvalidoException::ubicacionInactiva($ubicacion?->nombre ?? '#'.$detalle->planta_ubicacion_id);
            }

            // Cubre TRÁNSITO sin nombrarlo: es la ubicación que no admite
            // operación manual. El saldo en viaje pertenece a un traslado.
            if (! $ubicacion->permite_operacion_manual || $ubicacion->tipo->esTransito()) {
                throw AjusteInvalidoException::ubicacionNoOperable($ubicacion->nombre);
            }

            $insumo = $insumos->get($detalle->planta_insumo_id);

            if ($insumo === null || ! $insumo->activo) {
                throw AjusteInvalidoException::insumoInactivo($insumo?->nombre ?? '#'.$detalle->planta_insumo_id);
            }

            $lote = $lotes->get($detalle->planta_lote_id);

            if ($lote === null || $lote->planta_insumo_id !== $insumo->id) {
                throw AjusteInvalidoException::loteAjeno((int) $detalle->planta_lote_id, $insumo->nombre);
            }

            // Un insumo trazable no ajusta contra el genérico: perdería la
            // trazabilidad que ese insumo existe para tener.
            if ($insumo->controla_lotes && $lote->es_generico) {
                throw AjusteInvalidoException::loteGenericoNoSeleccionable($insumo->nombre);
            }

            if (! in_array($detalle->estado_disponibilidad, self::ESTADOS_VALIDOS, true)) {
                throw AjusteInvalidoException::estadoNoPermite(
                    $detalle->estado_disponibilidad->value,
                    'ajuste'
                );
            }

            // La unidad base se reafirma desde el insumo en cada confirmación.
            $detalle->unidad_base = $insumo->unidad_base->value;
            $detalle->save();
        }

        return $insumos->all();
    }

    /** Reglas propias de `carga_inicial` y `vencimiento`. */
    private function validarReglaDeTipo(
        PlantaAjuste $doc,
        PlantaAjusteDetalle $detalle,
        PlantaInsumo $insumo,
        BucketInventario $bucket,
    ): void {
        if ($doc->esCargaInicial()) {
            // La regla que da sentido a la carga inicial: es el ARRANQUE de un
            // bucket. Basta un movimiento histórico —aunque el saldo hoy sea
            // cero— para que ya no lo sea.
            $movimientos = PlantaMovimiento::query()->delBucket($bucket)->count();

            if ($movimientos > 0) {
                throw AjusteInvalidoException::cargaInicialSobreBucketConHistorial(
                    $bucket->descripcion(),
                    $movimientos,
                );
            }
        }

        if ($doc->tipo === TipoAjuste::Vencimiento) {
            $lote = $detalle->lote()->first();

            if ($lote?->fecha_vencimiento === null) {
                throw AjusteInvalidoException::vencimientoSinFecha($lote?->codigo_interno ?? '—');
            }

            // Por defecto NO se admite la baja anticipada: dar de baja por
            // vencimiento algo que aún no venció haría que el histórico de
            // mermas por caducidad dejara de significar nada.
            if ($lote->fecha_vencimiento->gt($doc->fecha)) {
                throw AjusteInvalidoException::vencimientoAnticipado(
                    $lote->codigo_interno,
                    $lote->fecha_vencimiento->toDateString(),
                    $doc->fecha->toDateString(),
                );
            }
        }
    }

    // --- Persistencia auxiliar ---

    /** @param  array<string, mixed>  $datos */
    private function soloCabecera(array $datos): array
    {
        return array_intersect_key($datos, array_flip([
            'tipo',
            'fecha',
            'motivo',
            'responsable_user_id',
            'responsable_nombre',
            'observaciones',
        ]));
    }

    /**
     * Sincroniza las líneas por bucket. Dos líneas del mismo bucket en el mismo
     * ajuste no son dos hechos: son el mismo hecho escrito dos veces, así que la
     * última gana y el unique de la tabla lo garantiza.
     *
     * `unidad_base` se deriva del insumo, y el lote se resuelve al genérico
     * cuando el insumo no controla lotes: elegirlo a mano no es una opción.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    private function sincronizarDetalles(PlantaAjuste $ajuste, array $lineas): void
    {
        $conservados = [];

        foreach ($lineas as $linea) {
            $insumo = PlantaInsumo::find($linea['planta_insumo_id'] ?? null);

            if ($insumo === null) {
                continue;
            }

            $loteId = $insumo->controla_lotes
                ? ($linea['planta_lote_id'] ?? null)
                // Sin control de lotes, el genérico manda: lo que venga del
                // formulario se ignora.
                : $this->lotes->resolverGenerico($insumo, (string) ($linea['fecha'] ?? now()->toDateString()))->id;

            if ($loteId === null || blank($linea['planta_ubicacion_id'] ?? null)) {
                continue;
            }

            $detalle = $ajuste->detalles()
                ->where('planta_insumo_id', $insumo->id)
                ->where('planta_lote_id', $loteId)
                ->where('planta_ubicacion_id', $linea['planta_ubicacion_id'])
                ->where('estado_disponibilidad', $linea['estado_disponibilidad'] ?? EstadoDisponibilidad::Disponible->value)
                ->first() ?? new PlantaAjusteDetalle;

            $detalle->planta_ajuste_id = $ajuste->id;
            $detalle->planta_insumo_id = $insumo->id;
            $detalle->planta_lote_id = $loteId;
            $detalle->planta_ubicacion_id = $linea['planta_ubicacion_id'];
            $detalle->estado_disponibilidad = $linea['estado_disponibilidad'] ?? EstadoDisponibilidad::Disponible->value;
            $detalle->observaciones = $linea['observaciones'] ?? null;
            $detalle->unidad_base = $insumo->unidad_base->value;

            if ($ajuste->esCorreccionDeConteo()) {
                // La magnitud sale de la diferencia al confirmar; aquí solo se
                // guarda lo contado.
                $detalle->cantidad_conteo = $linea['cantidad_conteo'] ?? null;
                $detalle->cantidad = '0';
                $detalle->cantidad_sistema = null;
                $detalle->diferencia = null;
            } else {
                $detalle->cantidad = $linea['cantidad'] ?? '0';
                $detalle->cantidad_conteo = null;
            }

            $detalle->save();

            $conservados[] = $detalle->id;
        }

        $ajuste->detalles()->whereNotIn('id', $conservados ?: [0])->delete();
    }

    private function aEscala(string $valor): string
    {
        return bcadd($valor, '0', 4);
    }
}
