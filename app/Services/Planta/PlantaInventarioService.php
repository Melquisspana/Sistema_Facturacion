<?php

namespace App\Services\Planta;

use App\Enums\Planta\TipoMovimientoPlanta;
use App\Exceptions\Planta\BucketInvalidoException;
use App\Exceptions\Planta\EfectoDuplicadoException;
use App\Exceptions\Planta\InventarioFueraDeTransaccionException;
use App\Exceptions\Planta\MovimientoInvalidoException;
use App\Exceptions\Planta\SaldoInsuficienteException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaUbicacion;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use App\Support\Planta\EfectoUid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICO ESCRITOR LÓGICO del inventario de Planta.
 *
 * Todo lo que mueva saldo —recepciones, traslados, ajustes, cambios de
 * disponibilidad, cargas iniciales— pasa por {@see aplicarMovimiento()}. No hay
 * un segundo camino, y no lo hay por una razón concreta: escribir en el mayor y
 * proyectar el saldo son DOS escrituras que deben ocurrir juntas o no ocurrir.
 * En cuanto exista un segundo sitio que haga una sola de las dos, el inventario
 * empieza a mentir y nadie se entera hasta que alguien cuenta físicamente.
 *
 * Lo que garantiza cada llamada:
 *
 *   - TRANSACCIÓN OBLIGATORIA. Se comprueba antes de tocar nada; sin ella se
 *     lanza {@see InventarioFueraDeTransaccionException} (una LogicException:
 *     es código mal escrito, no un dato malo).
 *   - INVARIANTES DE BUCKET verificadas contra la base, no confiando en el
 *     llamador: lote del insumo, genérico donde corresponde, y la regla de
 *     tránsito que ninguna clave foránea puede expresar.
 *   - SALDO NUNCA NEGATIVO, comprobado sobre la fila BLOQUEADA, no sobre una
 *     lectura previa.
 *   - IDEMPOTENCIA por `efecto_uid`, resuelta por el UNIQUE del motor.
 *   - ARITMÉTICA DECIMAL EXACTA con bcmath, sobre cadenas. Nunca float: 0.1 + 0.2
 *     en coma flotante no es 0.3, y un inventario que arrastra ese error termina
 *     con saldos imposibles de cuadrar contra un conteo físico.
 *
 * ORDEN DE LAS OPERACIONES, que no es arbitrario:
 *
 *   1. `lockForUpdate` sobre la fila de existencia (serializa a los
 *      concurrentes AQUÍ), y solo si no existe, `insertOrIgnore` + relectura
 *      bloqueante. El porqué de ese orden —evitar una familia entera de
 *      deadlocks de InnoDB— está en {@see bloquearBucket()};
 *   2. cálculo del saldo resultante y validación de no-negativo;
 *   3. INSERT del movimiento en el mayor;
 *   4. UPDATE del saldo proyectado.
 *
 * Que el bloqueo (1) ocurra ANTES del INSERT del movimiento (3) es lo que
 * permite que la reconciliación pueda tomar un candado sobre `planta_existencias`
 * y quedar aislada de cualquier escritor a medias: no existe ningún instante en
 * que haya un movimiento escrito sin que el escritor tenga ya bloqueada la fila
 * de saldo correspondiente.
 *
 * LA UNIDAD NO ES NEGOCIABLE POR EL LLAMADOR. `unidad_base` se lee SIEMPRE del
 * insumo y se copia al movimiento como instantánea congelada. No hay parámetro,
 * ni campo en {@see ContextoMovimiento}, ni clave de `metadata` que la sustituya:
 * si existiera esa vía, tarde o temprano alguien pasaría la unidad de COMPRA
 * —saco, caja, kg— y el mayor acabaría sumando libras con unidades sin que nada
 * proteste. La conversión ocurre UNA sola vez, en la recepción, antes de llegar
 * aquí; `$cantidadFirmada` entra ya convertida a la unidad base del insumo.
 *
 * REINTENTOS: OBLIGACIÓN DEL LLAMADOR
 * -----------------------------------
 * Todo servicio documental que use este motor —recepciones, traslados, ajustes,
 * cambios de disponibilidad, cargas iniciales— DEBE envolver la operación en
 *
 *     DB::transaction($callback, 3)
 *
 * o un mecanismo equivalente de TRES intentos.
 *
 * No es una recomendación de estilo. Bajo contención real, InnoDB resuelve las
 * carreras matando a una de las transacciones con un deadlock; eso NO es un error
 * del inventario, es el mecanismo normal del motor, y la respuesta correcta es
 * reintentar. `DB::transaction($cb, $intentos)` ya distingue los errores de
 * concurrencia del resto: reintenta solo esos y propaga los demás sin tocarlos.
 *
 * Con una sola tentativa, una operación perfectamente válida falla de cara al
 * usuario porque otro proceso tocó el mismo bucket en el mismo milisegundo. El
 * servicio no puede imponerlo desde dentro —la transacción la abre el llamador,
 * y abrir una aquí impediría componer varios efectos en una sola operación
 * atómica—, así que queda escrito aquí y verificado en las pruebas.
 *
 * El diagnóstico contra MySQL midió por qué importa: con el orden de bloqueo
 * anterior, seis procesos simultáneos dejaban a uno fuera incluso reintentando.
 * Ver {@see bloquearBucket()}.
 *
 * Lo que este servicio NO hace, a propósito: no expone CRUD de existencias, no
 * actualiza movimientos, no borra nada y no conoce documentos concretos. Las
 * recepciones, traslados y ajustes llegan en pasos posteriores y serán CLIENTES
 * de esta clase, no reemplazos.
 */
class PlantaInventarioService
{
    /** Decimales del inventario. Coincide con decimal(14,4) en ambas tablas. */
    public const ESCALA = 4;

    public function __construct(private readonly LoteService $lotes) {}

    /**
     * Aplica UN efecto firmado sobre UN bucket: escribe el movimiento en el
     * mayor y proyecta el saldo, en la misma transacción.
     *
     * La UNIDAD no es un parámetro y no puede serlo: {@see $cantidadFirmada} llega
     * YA CONVERTIDA a la unidad base del insumo, y la unidad que se escribe se lee
     * del insumo. Ver la nota sobre la unidad en la cabecera de la clase.
     *
     * @param  string  $cantidadFirmada  Decimal como CADENA, en la UNIDAD BASE del
     *                                   insumo. Positiva suma, negativa resta. Nunca 0.
     *                                   Se acepta cadena y no float para que el importe
     *                                   llegue exacto.
     *
     * @throws InventarioFueraDeTransaccionException Sin transacción abierta.
     * @throws MovimientoInvalidoException Cantidad, escala o fracción inadmisibles.
     * @throws BucketInvalidoException El bucket viola una invariante del inventario.
     * @throws SaldoInsuficienteException El efecto dejaría el bucket en negativo.
     * @throws EfectoDuplicadoException Ese efecto exacto ya está aplicado.
     */
    public function aplicarMovimiento(
        BucketInventario $bucket,
        string $cantidadFirmada,
        ContextoMovimiento $contexto,
    ): PlantaMovimiento {
        $this->exigirTransaccion();

        $cantidad = $this->normalizarCantidad($cantidadFirmada);

        $insumo = $this->cargarInsumo($bucket);
        $this->validarLote($bucket, $insumo);
        $this->validarUbicacionYTraslado($bucket);
        $this->validarFraccion($insumo, $cantidad);
        $this->validarReversion($contexto);

        // 1 y 2. Bucket garantizado y bloqueado: de este punto al COMMIT, nadie más
        //        lo mueve.
        $saldoAntes = $this->bloquearBucket($bucket);

        // 3. Aritmética exacta y regla de no-negativo.
        $saldoDespues = bcadd($saldoAntes, $cantidad, self::ESCALA);

        if (bccomp($saldoDespues, '0', self::ESCALA) === -1) {
            throw SaldoInsuficienteException::crear(
                $saldoAntes,
                $cantidad,
                $saldoDespues,
                $bucket->descripcion(),
            );
        }

        // 4 y 5: el hecho y su proyección, sin nada en medio que pueda fallar a solas.
        $movimiento = $this->escribirMovimiento($bucket, $cantidad, $contexto, $insumo, $saldoAntes, $saldoDespues);

        $this->proyectarSaldo($bucket, $saldoDespues);

        return $movimiento;
    }

    /**
     * Saldo actual de un bucket, como cadena decimal. Lectura pura: si el bucket
     * nunca ha existido devuelve '0.0000' en vez de crear la fila.
     */
    public function saldo(BucketInventario $bucket): string
    {
        $fila = DB::table('planta_existencias')->where($bucket->aColumnas())->first();

        return $this->aEscala($fila === null ? '0' : (string) $fila->cantidad);
    }

    /**
     * Resuelve el lote que corresponde al insumo: el genérico si no controla
     * lotes. Atajo para los flujos que aún no conocen el lote; el genérico se
     * crea de forma concurrente-segura en {@see LoteService}.
     */
    public function resolverLoteGenerico(PlantaInsumo $insumo, string $fechaOperativa): PlantaLote
    {
        return $this->lotes->resolverGenerico($insumo, $fechaOperativa);
    }

    // --- Guardas ---

    /**
     * Sin transacción no se escribe. La comprobación es el nivel de anidamiento
     * de la conexión: `DB::transaction()` lo sube a 1 o más.
     */
    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() < 1) {
            throw InventarioFueraDeTransaccionException::crear();
        }
    }

    /**
     * Valida la cantidad y la normaliza a la escala del inventario.
     *
     * La escala se comprueba ANTES de normalizar: `bcadd('1.00005', '0', 4)`
     * devolvería '1.0000' tan tranquilo, y ese redondeo silencioso es
     * exactamente la clase de pérdida que hace irreconciliable un inventario.
     * Si el importe no cabe, se rechaza y que lo redondee quien sepa por qué.
     */
    private function normalizarCantidad(string $cantidadFirmada): string
    {
        $cantidad = trim($cantidadFirmada);

        if (preg_match('/^-?\d+(\.\d+)?$/', $cantidad) !== 1) {
            throw MovimientoInvalidoException::cantidadNoNumerica($cantidadFirmada);
        }

        $decimales = str_contains($cantidad, '.') ? strlen(explode('.', $cantidad)[1]) : 0;

        if ($decimales > self::ESCALA) {
            throw MovimientoInvalidoException::escalaExcedida($cantidadFirmada, self::ESCALA);
        }

        $normalizada = $this->aEscala($cantidad);

        if (bccomp($normalizada, '0', self::ESCALA) === 0) {
            throw MovimientoInvalidoException::cantidadCero();
        }

        return $normalizada;
    }

    private function cargarInsumo(BucketInventario $bucket): PlantaInsumo
    {
        return PlantaInsumo::findOrFail($bucket->insumoId);
    }

    /**
     * El lote debe ser del insumo, y debe ser del TIPO que ese insumo admite:
     * real si controla lotes, genérico si no. Las dos comprobaciones son
     * necesarias: la primera evita mezclar inventarios de insumos distintos, la
     * segunda evita que un insumo trazable acabe con su saldo en un cajón sin
     * trazabilidad (o al revés).
     */
    private function validarLote(BucketInventario $bucket, PlantaInsumo $insumo): void
    {
        $lote = PlantaLote::findOrFail($bucket->loteId);

        if ($lote->planta_insumo_id !== $insumo->id) {
            throw BucketInvalidoException::loteAjeno($lote->id, $insumo->id);
        }

        if ($insumo->controla_lotes && $lote->es_generico) {
            throw BucketInvalidoException::genericoEnInsumoConLotes($lote->id, $insumo->id);
        }

        if (! $insumo->controla_lotes && ! $lote->es_generico) {
            throw BucketInvalidoException::loteRealEnInsumoSinLotes($lote->id, $insumo->id);
        }
    }

    /**
     * La invariante que ninguna clave foránea puede expresar, porque es
     * CONDICIONAL al tipo de la ubicación:
     *
     *   - ubicación física  -> `planta_traslado_id` DEBE ser 0;
     *   - ubicación tránsito -> `planta_traslado_id` DEBE ser > 0.
     *
     * Sin esto, saldo real quedaría escondido en un bucket de tránsito que nadie
     * consulta, o saldo en viaje aparecería como disponible en una bodega donde
     * físicamente no está.
     */
    private function validarUbicacionYTraslado(BucketInventario $bucket): void
    {
        $ubicacion = PlantaUbicacion::findOrFail($bucket->ubicacionId);

        if ($ubicacion->tipo->esTransito() && $bucket->trasladoId === 0) {
            throw BucketInvalidoException::transitoSinTraslado($ubicacion->id);
        }

        if (! $ubicacion->tipo->esTransito() && $bucket->trasladoId > 0) {
            throw BucketInvalidoException::trasladoEnUbicacionFisica($ubicacion->id, $bucket->trasladoId);
        }
    }

    /**
     * Bolsas y viñetas se cuentan enteras. Media bolsa no existe, y admitirla
     * produce saldos que jamás cuadran con un conteo físico.
     */
    private function validarFraccion(PlantaInsumo $insumo, string $cantidad): void
    {
        if ($insumo->permite_fraccion) {
            return;
        }

        $parteEntera = explode('.', $cantidad)[0];

        if (bccomp($cantidad, $this->aEscala($parteEntera), self::ESCALA) !== 0) {
            throw MovimientoInvalidoException::fraccionNoPermitida($cantidad, $insumo->id);
        }
    }

    /**
     * Invariante declarada en {@see TipoMovimientoPlanta}: un
     * movimiento de compensación apunta SIEMPRE al original, y uno normal nunca
     * apunta a nada. Se verifica aquí porque este servicio es el único escritor
     * y por tanto el único sitio donde puede romperse.
     */
    private function validarReversion(ContextoMovimiento $contexto): void
    {
        $esReversion = $contexto->tipo->esReversion();
        $apunta = $contexto->movimientoRevertidoId !== null;

        if ($esReversion && ! $apunta) {
            throw new MovimientoInvalidoException(
                "El tipo '{$contexto->tipo->value}' es una compensación y exige "
                .'movimiento_revertido_id: sin él no se puede saber qué corrige.'
            );
        }

        if (! $esReversion && $apunta) {
            throw new MovimientoInvalidoException(
                "El tipo '{$contexto->tipo->value}' no es una compensación y no puede "
                .'apuntar a un movimiento revertido.'
            );
        }
    }

    // --- Escritura ---

    /**
     * Garantiza que la fila del bucket existe Y la deja BLOQUEADA, devolviendo su
     * saldo. Es el punto de serialización de todo el motor: dos movimientos sobre
     * el mismo bucket se ordenan aquí, y el segundo ve el saldo que dejó el
     * primero, no el que había al empezar.
     *
     * EL ORDEN —bloquear primero, crear solo si hace falta— NO ES INDIFERENTE, y
     * se eligió con un diagnóstico de concurrencia real delante:
     *
     * Hacerlo al revés (`insertOrIgnore` siempre y luego `lockForUpdate`) provoca
     * deadlocks bajo contención en InnoDB. Un `INSERT ... IGNORE` que choca con
     * una fila ya existente toma un lock COMPARTIDO sobre ella; si varias
     * sesiones lo toman a la vez y después todas intentan subirlo a EXCLUSIVO con
     * `SELECT ... FOR UPDATE`, se esperan mutuamente y el motor mata a una. Con
     * seis procesos simultáneos sobre el mismo bucket eso ocurría de verdad, y
     * agotaba los reintentos de alguno.
     *
     * Bloqueando primero, el camino frecuente —el bucket ya existe, que es TODO
     * movimiento salvo el primero de su vida— pide directamente el lock exclusivo
     * y no pasa nunca por el compartido: ese deadlock desaparece por completo. El
     * `insertOrIgnore` queda solo para el estreno del bucket, donde sigue siendo
     * la forma correcta de resolver la carrera (el UNIQUE decide, no un `if`) y
     * donde una colisión es un evento único e irrepetible por bucket.
     *
     * La relectura posterior al insert es obligatoria y bloqueante: bajo
     * REPEATABLE READ una lectura normal saldría del snapshot de la transacción y
     * no vería la fila que otra conexión acaba de confirmar.
     *
     * AUN ASÍ, EL LLAMADOR DEBE CONTAR CON REINTENTOS. Un deadlock sigue siendo
     * posible al estrenar un bucket, y es la forma NORMAL en que InnoDB resuelve
     * una carrera, no un error del inventario: envuelve la operación en
     * `DB::transaction($callback, 3)` y deja que se reintente.
     */
    private function bloquearBucket(BucketInventario $bucket): string
    {
        $fila = $this->leerBloqueado($bucket);

        if ($fila !== null) {
            return $this->aEscala((string) $fila->cantidad);
        }

        $ahora = now();

        // Estreno del bucket. `insertOrIgnore` en vez de «¿existe? -> no -> créala»:
        // entre la lectura y la escritura cabe otro proceso, y el UNIQUE de las cinco
        // dimensiones convertiría esa carrera en un error de duplicado. Aquí el
        // perdedor simplemente afecta 0 filas y sigue: la fila que necesitaba ya está.
        DB::table('planta_existencias')->insertOrIgnore(array_merge($bucket->aColumnas(), [
            'cantidad' => 0,
            'actualizado_en' => null,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]));

        $fila = $this->leerBloqueado($bucket);

        if ($fila === null) {
            // No debería ocurrir: el insertOrIgnore acaba de garantizarla.
            throw new \RuntimeException(
                'No se pudo bloquear la existencia de '.$bucket->descripcion().' tras asegurarla.'
            );
        }

        return $this->aEscala((string) $fila->cantidad);
    }

    /** Lectura de la fila del bucket con bloqueo exclusivo. Null si aún no existe. */
    private function leerBloqueado(BucketInventario $bucket): ?object
    {
        return DB::table('planta_existencias')
            ->where($bucket->aColumnas())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Inserta el hecho en el mayor. El duplicado lo detecta el UNIQUE de
     * `efecto_uid`, no una consulta previa: entre el SELECT y el INSERT cabe
     * otro proceso, y ese hueco es justo el que aprovecha un reintento.
     */
    private function escribirMovimiento(
        BucketInventario $bucket,
        string $cantidad,
        ContextoMovimiento $contexto,
        PlantaInsumo $insumo,
        string $saldoAntes,
        string $saldoDespues,
    ): PlantaMovimiento {
        $efectoUid = EfectoUid::calcular($bucket, $contexto);

        $movimiento = new PlantaMovimiento;
        $movimiento->fill(array_merge($bucket->aColumnas(), [
            'cantidad' => $cantidad,
            // La unidad sale del INSUMO, jamás del llamador: se congela aquí.
            'unidad_base' => $insumo->unidad_base->value,
            'tipo' => $contexto->tipo->value,
            'documento_type' => ltrim($contexto->documentoType, '\\'),
            'documento_id' => $contexto->documentoId,
            'documento_detalle_id' => $contexto->documentoDetalleId,
            'transicion' => $contexto->transicion,
            'grupo_uuid' => $contexto->grupoUuid,
            'movimiento_revertido_id' => $contexto->movimientoRevertidoId,
            'user_id' => $contexto->userId,
            'responsable_nombre' => $contexto->responsableNombre,
            'fecha_efectiva' => $contexto->fechaEfectiva,
            // El saldo va al final para que el contexto del llamador no pueda pisarlo.
            'metadata' => array_merge($contexto->metadata, [
                'saldo_antes' => $saldoAntes,
                'saldo_despues' => $saldoDespues,
            ]),
        ]));
        $movimiento->efecto_uid = $efectoUid;

        try {
            $movimiento->save();
        } catch (QueryException $e) {
            if ($this->esDuplicadoDeEfecto($e)) {
                throw EfectoDuplicadoException::crear($efectoUid, $contexto->descripcion());
            }

            throw $e;
        }

        return $movimiento;
    }

    /** Actualiza la proyección. La fila ya está bloqueada por {@see saldoBloqueado()}. */
    private function proyectarSaldo(BucketInventario $bucket, string $saldoDespues): void
    {
        $ahora = now();

        DB::table('planta_existencias')
            ->where($bucket->aColumnas())
            ->update([
                'cantidad' => $saldoDespues,
                'actualizado_en' => $ahora,
                'updated_at' => $ahora,
            ]);
    }

    // --- Auxiliares ---

    /**
     * ¿La violación de integridad es la del `efecto_uid` y no otra?
     *
     * Se comprueba el nombre del índice además del SQLSTATE porque en la misma
     * sentencia podrían saltar otras restricciones, y traducirlas todas a
     * «efecto duplicado» ocultaría el problema real.
     */
    private function esDuplicadoDeEfecto(QueryException $e): bool
    {
        if ((string) $e->getCode() !== '23000') {
            return false;
        }

        $mensaje = strtolower($e->getMessage());

        return str_contains($mensaje, 'planta_mov_efecto_uid_unico')
            || str_contains($mensaje, 'efecto_uid');
    }

    /** Lleva una cadena decimal a la escala del inventario sin pasar por float. */
    private function aEscala(string $valor): string
    {
        return bcadd($valor, '0', self::ESCALA);
    }
}
