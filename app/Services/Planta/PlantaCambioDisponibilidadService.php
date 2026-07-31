<?php

namespace App\Services\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\CambioDisponibilidadInvalidoException;
use App\Exceptions\Planta\ReversionCambioDisponibilidadImposibleException;
use App\Models\Planta\PlantaCambioDisponibilidad;
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
 * ÚNICO punto autorizado para liberar o rechazar saldo retenido.
 *
 * Es un CLIENTE de {@see PlantaInventarioService}: nunca escribe en
 * `planta_movimientos` ni en `planta_existencias`, y nunca «edita el estado» de
 * un lote o de una fila de existencias. Cambiar de disponibilidad es, por
 * construcción, MOVER cantidad de un bucket a otro con un par de movimientos que
 * suma cero —el estado es una dimensión del bucket, no una columna editable—.
 *
 * EL PAR SIEMPRE SUMA CERO. Liberar 30 lb no crea ni destruye nada: saca 30 del
 * bucket `retenido` y mete 30 en el bucket `disponible`, del mismo insumo, mismo
 * lote, misma ubicación y `planta_traslado_id = 0`. Si alguna vez las dos
 * cantidades dejaran de coincidir, esto habría dejado de ser un cambio de
 * disponibilidad para convertirse en un ajuste encubierto.
 *
 * ORDEN DE APLICACIÓN: por CLAVE CANÓNICA del bucket, no por signo.
 *
 * Dos documentos simultáneos sobre el mismo insumo/lote/ubicación bloquean los
 * mismos buckets; si cada uno los tomara en el orden que le conviene, InnoDB
 * detectaría un ciclo y mataría a uno. Ordenando siempre por la clave canónica,
 * dos transacciones nunca piden los mismos dos candados en orden opuesto. En la
 * práctica esto pone el movimiento POSITIVO primero (`disponible` y `rechazado`
 * ordenan antes que `retenido`), lo cual es inocuo porque la suficiencia del
 * saldo retenido se comprueba ANTES de aplicar nada: si no alcanza, no se
 * escribe ni el primero.
 *
 * TODA operación va dentro de `DB::transaction($cb, 3)`. Los tres intentos no
 * son adorno: bajo contención InnoDB mata una transacción con un deadlock, y esa
 * es la forma NORMAL en que el motor resuelve una carrera.
 *
 * NUMERACIÓN propia con {@see Secuencia} y la clave {@see CLAVE_SECUENCIA},
 * tomada al crear el borrador. No es fiscal: no es `numero_sistema` ni un
 * correlativo del MH, y anular deja un hueco sin ninguna consecuencia.
 */
class PlantaCambioDisponibilidadService
{
    /** Clave del contador propio. No es fiscal. */
    public const CLAVE_SECUENCIA = 'planta_cambio_disponibilidad';

    /** Permiso que exige TODA escritura de este documento. */
    public const PERMISO = 'planta.calidad.gestionar';

    /** Único origen admitido: este documento solo mueve saldo retenido. */
    private const ORIGEN = EstadoDisponibilidad::Retenido;

    /** Destinos admitidos desde `retenido`. */
    private const DESTINOS = [
        EstadoDisponibilidad::Disponible,
        EstadoDisponibilidad::Rechazado,
    ];

    /** Lados del par compensado, para que el `efecto_uid` los distinga siempre. */
    private const LADO_SALIDA = 0;

    private const LADO_ENTRADA = 1;

    public function __construct(private readonly PlantaInventarioService $inventario) {}

    // --- Borrador ---

    /**
     * Crea el borrador. No toca inventario.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearBorrador(array $datos, ?User $usuario = null): PlantaCambioDisponibilidad
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $this->validarDestino($datos['estado_destino'] ?? null);

            $documento = new PlantaCambioDisponibilidad;
            $documento->fill($this->soloCampos($datos));
            $documento->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $documento->estado = EstadoCambioDisponibilidad::Borrador;
            // El origen no lo elige nadie: es el sentido entero del documento.
            $documento->estado_origen = self::ORIGEN;
            $documento->creado_por = $usuario?->id;
            $documento->save();

            return $documento->refresh();
        }, 3);
    }

    /**
     * Reemplaza los datos de un borrador.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizarBorrador(
        PlantaCambioDisponibilidad $documento,
        array $datos,
    ): PlantaCambioDisponibilidad {
        return DB::transaction(function () use ($documento, $datos) {
            $bloqueado = $this->bloquear($documento);

            if (! $bloqueado->esEditable()) {
                throw CambioDisponibilidadInvalidoException::estadoNoPermite($bloqueado->estado->value, 'edición');
            }

            $this->validarDestino($datos['estado_destino'] ?? null);

            $bloqueado->fill($this->soloCampos($datos));
            // Se reafirma en cada guardado: ni una petición manual puede cambiarlo.
            $bloqueado->estado_origen = self::ORIGEN;
            $bloqueado->save();

            return $bloqueado->refresh();
        }, 3);
    }

    /** Descarta un borrador. Es terminal. */
    public function anular(PlantaCambioDisponibilidad $documento): PlantaCambioDisponibilidad
    {
        return DB::transaction(function () use ($documento) {
            $bloqueado = $this->bloquear($documento);

            if (! $bloqueado->puedeAnularse()) {
                throw CambioDisponibilidadInvalidoException::estadoNoPermite($bloqueado->estado->value, 'anulación');
            }

            $bloqueado->estado = EstadoCambioDisponibilidad::Anulado;
            $bloqueado->save();

            activity('planta_cambio_disponibilidad')
                ->performedOn($bloqueado)
                ->withProperties(['numero' => $bloqueado->numero])
                ->log('anuló el borrador de cambio de disponibilidad');

            return $bloqueado;
        }, 3);
    }

    // --- Confirmación ---

    /**
     * Emite el par compensado y deja el documento inmutable.
     *
     * Todo lo que puede rechazar la operación se comprueba ANTES de escribir el
     * primer movimiento y con la fila bloqueada, de modo que un rechazo no deje
     * medio par aplicado.
     */
    public function confirmar(PlantaCambioDisponibilidad $documento, User $usuario): PlantaCambioDisponibilidad
    {
        return DB::transaction(function () use ($documento, $usuario) {
            // 1 y 2. Bloqueo y estado. El bloqueo va primero: sin él, dos
            //        confirmaciones simultáneas leerían «borrador» las dos.
            $doc = $this->bloquear($documento);

            if (! $doc->puedeConfirmarse()) {
                throw CambioDisponibilidadInvalidoException::estadoNoPermite($doc->estado->value, 'confirmación');
            }

            // 3 a 6. Cantidad, contexto vigente HOY y transición.
            $cantidad = $this->validarCantidad((string) $doc->cantidad);
            $this->validarMotivo($doc->motivo);
            $this->validarUbicacion($doc);
            $insumo = $this->validarInsumoYLote($doc);
            $this->validarTransicion($doc);

            // 7 y 8. Los dos buckets, ordenados por clave canónica.
            $efectos = $this->efectosOrdenados($doc, $cantidad);

            // 9. Suficiencia del saldo RETENIDO, antes de escribir nada. Es lo que
            //    hace irrelevante el orden de los signos en el paso siguiente.
            $origen = $doc->bucketOrigen();
            $saldo = $this->inventario->saldo($origen);

            if (bccomp($saldo, $cantidad, 4) === -1) {
                throw CambioDisponibilidadInvalidoException::saldoRetenidoInsuficiente(
                    $origen->descripcion(),
                    $cantidad,
                    $saldo,
                );
            }

            $grupo = (string) Str::uuid();

            // 10 y 11. El par, a través del motor de inventario.
            foreach ($efectos as $efecto) {
                $this->inventario->aplicarMovimiento(
                    $efecto['bucket'],
                    $efecto['cantidad'],
                    ContextoMovimiento::para(
                        tipo: TipoMovimientoPlanta::CambioDisponibilidad,
                        documentoType: PlantaCambioDisponibilidad::class,
                        documentoId: $doc->id,
                        transicion: 'confirmar',
                        fechaEfectiva: $doc->fecha,
                        // Sin líneas: el documento ES la línea.
                        documentoDetalleId: null,
                        grupoUuid: $grupo,
                        // El LADO distingue los dos efectos del mismo documento.
                        // Hoy sus buckets ya difieren por el estado, pero la
                        // secuencia lo deja explícito y determinista.
                        secuencia: $efecto['lado'],
                        userId: $usuario->id,
                        responsableNombre: $doc->responsable_nombre,
                        metadata: [
                            'cambio_numero' => $doc->numero,
                            'accion' => $doc->esLiberacion() ? 'liberacion' : 'rechazo',
                            'estado_origen' => $doc->estado_origen->value,
                            'estado_destino' => $doc->estado_destino->value,
                            'motivo' => $doc->motivo,
                        ],
                    ),
                );
            }

            // 12 y 13. El documento pasa a confirmado con su firma.
            $doc->estado = EstadoCambioDisponibilidad::Confirmado;
            $doc->confirmado_por = $usuario->id;
            $doc->confirmado_en = now();
            $doc->save();

            // 14. Auditoría.
            activity('planta_cambio_disponibilidad')
                ->performedOn($doc)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $doc->numero,
                    'grupo_uuid' => $grupo,
                    'accion' => $doc->esLiberacion() ? 'liberacion' : 'rechazo',
                    'cantidad' => $cantidad,
                    'insumo_id' => $doc->planta_insumo_id,
                    'lote_id' => $doc->planta_lote_id,
                    'ubicacion_id' => $doc->planta_ubicacion_id,
                    'motivo' => $doc->motivo,
                ])
                ->log('confirmó el cambio de disponibilidad');

            return $doc->refresh();
        }, 3);
    }

    // --- Reversión ---

    /**
     * Deshace un cambio confirmado con el par espejo: devuelve la cantidad del
     * destino al origen.
     *
     * NO edita ni borra los movimientos originales: crea un documento nuevo cuyos
     * dos efectos apuntan, cada uno, al movimiento que compensan.
     */
    public function reversar(
        PlantaCambioDisponibilidad $documento,
        string $motivo,
        User $usuario,
    ): PlantaCambioDisponibilidad {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw CambioDisponibilidadInvalidoException::motivoRequerido();
        }

        return DB::transaction(function () use ($documento, $motivo, $usuario) {
            $original = $this->bloquear($documento);

            if ($original->esReversion()) {
                throw ReversionCambioDisponibilidadImposibleException::esUnaReversion($original->numero);
            }

            if ($original->revertido_por_id !== null) {
                throw ReversionCambioDisponibilidadImposibleException::yaReversado($original->numero);
            }

            if ($original->estado !== EstadoCambioDisponibilidad::Confirmado) {
                throw CambioDisponibilidadInvalidoException::estadoNoPermite($original->estado->value, 'reversión');
            }

            $cantidad = $this->aEscala((string) $original->cantidad);
            $destino = $original->bucketDestino();

            // El saldo tiene que seguir EXACTAMENTE donde lo dejó el original.
            $saldoDestino = $this->inventario->saldo($destino);

            if (bccomp($saldoDestino, $cantidad, 4) === -1) {
                throw ReversionCambioDisponibilidadImposibleException::saldoDestinoInsuficiente(
                    $destino->descripcion(),
                    $cantidad,
                    $saldoDestino,
                );
            }

            // Documento de compensación: un cambio más, con origen y destino
            // intercambiados. Se escribe con `saveQuietly`-equivalente: el origen
            // NO es `retenido` aquí, sino el destino del original, así que se
            // asignan las columnas a mano en vez de pasar por crearBorrador().
            $reversion = new PlantaCambioDisponibilidad;
            $reversion->numero = Secuencia::siguiente(self::CLAVE_SECUENCIA);
            $reversion->estado = EstadoCambioDisponibilidad::Confirmado;
            $reversion->planta_insumo_id = $original->planta_insumo_id;
            $reversion->planta_lote_id = $original->planta_lote_id;
            $reversion->planta_ubicacion_id = $original->planta_ubicacion_id;
            $reversion->estado_origen = $original->estado_destino;
            $reversion->estado_destino = $original->estado_origen;
            $reversion->cantidad = $cantidad;
            $reversion->fecha = now()->toDateString();
            $reversion->motivo = $motivo;
            $reversion->creado_por = $usuario->id;
            $reversion->confirmado_por = $usuario->id;
            $reversion->confirmado_en = now();
            $reversion->responsable_user_id = $original->responsable_user_id;
            $reversion->responsable_nombre = $original->responsable_nombre;
            $reversion->reversion_de_id = $original->id;
            $reversion->save();

            $movimientos = $this->movimientosOriginales($original);
            $grupo = (string) Str::uuid();

            foreach ($this->efectosOrdenados($reversion, $cantidad) as $efecto) {
                // El movimiento que se compensa es el del MISMO bucket en el
                // documento original: la salida de la reversión compensa la
                // entrada del original, y viceversa.
                $revertido = $movimientos[$efecto['bucket']->claveCanonica()] ?? null;

                if ($revertido === null) {
                    throw ReversionCambioDisponibilidadImposibleException::movimientoOriginalAusente(
                        $original->numero,
                        $efecto['lado'] === self::LADO_SALIDA ? 'salida' : 'entrada',
                    );
                }

                $this->inventario->aplicarMovimiento(
                    $efecto['bucket'],
                    $efecto['cantidad'],
                    ContextoMovimiento::para(
                        tipo: TipoMovimientoPlanta::ReversionCambioDisponibilidad,
                        documentoType: PlantaCambioDisponibilidad::class,
                        documentoId: $reversion->id,
                        transicion: 'reversar',
                        fechaEfectiva: $reversion->fecha,
                        documentoDetalleId: null,
                        grupoUuid: $grupo,
                        secuencia: $efecto['lado'],
                        userId: $usuario->id,
                        responsableNombre: $original->responsable_nombre,
                        movimientoRevertidoId: $revertido->id,
                        metadata: [
                            'reversion_de' => $original->numero,
                            'motivo' => $motivo,
                        ],
                    ),
                );
            }

            $original->estado = EstadoCambioDisponibilidad::Reversado;
            $original->revertido_por_id = $reversion->id;
            $original->save();

            activity('planta_cambio_disponibilidad')
                ->performedOn($original)
                ->causedBy($usuario)
                ->withProperties([
                    'numero' => $original->numero,
                    'reversion_numero' => $reversion->numero,
                    'grupo_uuid' => $grupo,
                    'motivo' => $motivo,
                ])
                ->log('reversó el cambio de disponibilidad');

            return $reversion->refresh();
        }, 3);
    }

    // --- Consultas de apoyo ---

    /**
     * Buckets RETENIDOS con saldo, que son los únicos que este documento puede
     * mover. Es lo que alimenta el selector del formulario: capturar insumo,
     * lote y ubicación como combinación libre permitiría pedir un cambio sobre
     * saldo que no existe.
     *
     * @return Collection<int, object>
     */
    public function bucketsRetenidosConSaldo()
    {
        return DB::table('planta_existencias as e')
            ->join('planta_insumos as i', 'i.id', '=', 'e.planta_insumo_id')
            ->join('planta_lotes as l', 'l.id', '=', 'e.planta_lote_id')
            ->join('planta_ubicaciones as u', 'u.id', '=', 'e.planta_ubicacion_id')
            ->where('e.estado', self::ORIGEN->value)
            ->where('e.planta_traslado_id', 0)
            ->where('e.cantidad', '>', 0)
            ->orderBy('i.nombre')->orderBy('l.codigo_interno')
            ->get([
                'e.planta_insumo_id', 'e.planta_lote_id', 'e.planta_ubicacion_id', 'e.cantidad',
                'i.codigo as insumo_codigo', 'i.nombre as insumo_nombre', 'i.unidad_base',
                'l.codigo_interno as lote_codigo', 'u.codigo as ubicacion_codigo', 'u.nombre as ubicacion_nombre',
            ]);
    }

    /** Saldo retenido del bucket que describe el documento. */
    public function saldoRetenido(PlantaCambioDisponibilidad $documento): string
    {
        return $this->inventario->saldo($documento->bucketOrigen());
    }

    // --- Validaciones ---

    /** Relee la fila con bloqueo exclusivo: el estado que vale es el de ahora. */
    private function bloquear(PlantaCambioDisponibilidad $documento): PlantaCambioDisponibilidad
    {
        return PlantaCambioDisponibilidad::whereKey($documento->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Los dos efectos del par, ORDENADOS POR CLAVE CANÓNICA del bucket.
     *
     * @return array<int, array{bucket: BucketInventario, cantidad: string, lado: int}>
     */
    private function efectosOrdenados(PlantaCambioDisponibilidad $doc, string $cantidad): array
    {
        $efectos = [
            ['bucket' => $doc->bucketOrigen(), 'cantidad' => '-'.$cantidad, 'lado' => self::LADO_SALIDA],
            ['bucket' => $doc->bucketDestino(), 'cantidad' => $cantidad, 'lado' => self::LADO_ENTRADA],
        ];

        usort($efectos, fn (array $a, array $b) => $a['bucket']->claveCanonica() <=> $b['bucket']->claveCanonica());

        return $efectos;
    }

    /**
     * Movimientos del documento original, indexados por la clave de su bucket.
     *
     * @return array<string, PlantaMovimiento>
     */
    private function movimientosOriginales(PlantaCambioDisponibilidad $original): array
    {
        $indexados = [];

        foreach (PlantaMovimiento::query()
            ->delDocumento(PlantaCambioDisponibilidad::class, $original->id)
            ->orderBy('id')
            ->get() as $movimiento) {
            $indexados[$movimiento->bucket()->claveCanonica()] = $movimiento;
        }

        return $indexados;
    }

    private function validarCantidad(string $cantidad): string
    {
        $normalizada = $this->aEscala($cantidad);

        if (bccomp($normalizada, '0', 4) !== 1) {
            throw CambioDisponibilidadInvalidoException::cantidadNoPositiva($cantidad);
        }

        return $normalizada;
    }

    private function validarMotivo(?string $motivo): void
    {
        if (trim((string) $motivo) === '') {
            throw CambioDisponibilidadInvalidoException::motivoRequerido();
        }
    }

    /**
     * El destino solo puede ser `disponible` o `rechazado`.
     *
     * Acepta el enum o su valor porque llega de los dos sitios: del payload del
     * formulario (cadena) y del documento ya casteado (enum). El mensaje se
     * construye con {@see etiquetaDe()} y no con un `(string)` directo: castear
     * un enum respaldado a string es un error fatal de PHP, no una conversión.
     */
    private function validarDestino(mixed $destino): void
    {
        $enum = $destino instanceof EstadoDisponibilidad
            ? $destino
            : EstadoDisponibilidad::tryFrom((string) $destino);

        if ($enum === null || ! in_array($enum, self::DESTINOS, true)) {
            throw CambioDisponibilidadInvalidoException::destinoNoPermitido($this->etiquetaDe($destino));
        }
    }

    /** Representación textual segura de un destino, venga como enum o como valor. */
    private function etiquetaDe(mixed $destino): string
    {
        return $destino instanceof EstadoDisponibilidad
            ? $destino->value
            : (is_scalar($destino) ? (string) $destino : get_debug_type($destino));
    }

    /**
     * La transición completa: origen retenido, destino admitido, y coherencia con
     * la tabla que declara el propio enum de disponibilidad.
     */
    private function validarTransicion(PlantaCambioDisponibilidad $doc): void
    {
        if ($doc->estado_origen !== self::ORIGEN) {
            throw CambioDisponibilidadInvalidoException::origenNoRetenido($doc->estado_origen->value);
        }

        $this->validarDestino($doc->estado_destino);

        // Segunda barrera: el enum es quien decide qué pares son válidos, y así
        // ampliar las transiciones no obliga a tocar este servicio.
        if (! $doc->estado_origen->puedeTransicionarA($doc->estado_destino)) {
            throw CambioDisponibilidadInvalidoException::destinoNoPermitido($doc->estado_destino->value);
        }
    }

    private function validarUbicacion(PlantaCambioDisponibilidad $doc): PlantaUbicacion
    {
        $ubicacion = PlantaUbicacion::findOrFail($doc->planta_ubicacion_id);

        if (! $ubicacion->activo) {
            throw CambioDisponibilidadInvalidoException::ubicacionInactiva($ubicacion->nombre);
        }

        // Cubre TRÁNSITO sin nombrarlo: es la ubicación que no admite operación
        // manual. El saldo en viaje no cambia de disponibilidad.
        if (! $ubicacion->permite_operacion_manual || $ubicacion->tipo->esTransito()) {
            throw CambioDisponibilidadInvalidoException::ubicacionNoAdmiteOperacion($ubicacion->nombre);
        }

        return $ubicacion;
    }

    private function validarInsumoYLote(PlantaCambioDisponibilidad $doc): PlantaInsumo
    {
        $insumo = PlantaInsumo::findOrFail($doc->planta_insumo_id);

        if (! $insumo->activo) {
            throw CambioDisponibilidadInvalidoException::insumoInactivo($insumo->nombre);
        }

        $lote = PlantaLote::findOrFail($doc->planta_lote_id);

        if ($lote->planta_insumo_id !== $insumo->id) {
            throw CambioDisponibilidadInvalidoException::loteAjeno($lote->id, $insumo->nombre);
        }

        return $insumo;
    }

    /** @param  array<string, mixed>  $datos */
    private function soloCampos(array $datos): array
    {
        return array_intersect_key($datos, array_flip([
            'planta_insumo_id',
            'planta_lote_id',
            'planta_ubicacion_id',
            'estado_destino',
            'cantidad',
            'fecha',
            'motivo',
            'responsable_user_id',
            'responsable_nombre',
        ]));
    }

    private function aEscala(string $valor): string
    {
        return bcadd($valor, '0', 4);
    }
}
