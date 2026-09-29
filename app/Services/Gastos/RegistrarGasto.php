<?php

namespace App\Services\Gastos;

use App\Http\Requests\Gastos\RegistrarGastoRequest;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * «Registrar gasto», con o sin «Ya lo pagué», en UNA sola operación.
 *
 * Garantías que este servicio debe cumplir (acuerdos de fase 1):
 *
 *  1. Todo o nada. Gasto, cuotas, pago, aplicaciones, adjuntos y evento se
 *     guardan juntos. Si algo falla —validación, archivo o base— no queda un
 *     pago a medias ni un gasto sin sus comprobantes.
 *  2. Los archivos se escriben en disco privado ANTES del commit y se borran si
 *     hay rollback. Una caída dura del proceso puede dejar huérfanos privados;
 *     jamás un pago visible sin sus adjuntos registrados.
 *  3. Reintento y doble clic no duplican: la `clave` del formulario es única en
 *     base y la del pago se DERIVA de ella, así que ni siquiera un reintento que
 *     esquivara la primera comprobación podría crear un segundo pago.
 *  4. El reparto por cuotas es explícito. Este servicio no reparte solo: recibe
 *     lo que el formulario muestra y {@see RegistrarPago} lo verifica contra el
 *     pendiente real de cada cuota.
 */
final class RegistrarGasto
{
    /** Espacio de nombres propio para derivar la clave del pago desde la del gasto. */
    private const NS_PAGO = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    public function __construct(
        private AccesoGastos $acceso,
        private RegistrarPago $pagos,
        private AlmacenAdjuntos $almacen,
        private VincularCompra $compras,
    ) {}

    public function registrar(RegistrarGastoRequest $request): Gasto
    {
        $datos = $request->validated();
        $usuario = $request->user();

        // Un solo hash por archivo: sirve para la huella de idempotencia y para la
        // fila del adjunto. El archivo temporal sigue en su sitio (putFileAs COPIA,
        // no mueve), pero leerlo dos veces no aporta nada.
        $archivos = [];
        $identidadArchivos = [];
        foreach (['documentos', 'comprobantes'] as $grupo) {
            foreach ($datos[$grupo] ?? [] as $archivo) {
                $archivos[$grupo][] = $archivo;
                $identidadArchivos[$grupo][] = [
                    'nombre' => mb_substr(basename($archivo->getClientOriginalName()), 0, 240),
                    'sha256' => hash_file('sha256', $archivo->getRealPath()),
                ];
            }
        }

        // La huella incluye los archivos: el mismo formulario con OTRO comprobante no
        // es el mismo envío, y reintentarlo debe avisar en vez de devolver el anterior.
        $huella = hash('sha256', json_encode([
            Arr::except($datos, ['documentos', 'comprobantes']),
            $identidadArchivos,
        ], JSON_THROW_ON_ERROR));

        $rutas = [];

        try {
            return DB::transaction(function () use ($usuario, $request, $datos, $archivos, $huella, &$rutas) {
                // Serializa los reintentos del MISMO operador (doble clic, reenvío del
                // formulario). Entre operadores distintos el candado real es el índice
                // único de `clave` y, para el saldo, el bloqueo de gasto/cuota.
                User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

                if ($existente = Gasto::where('clave', $datos['clave'])->first()) {
                    abort_unless($this->acceso->ver($usuario, $existente), 403);

                    // Misma clave con otros datos NO es el mismo envío: es un formulario
                    // reutilizado. Se rechaza en vez de sobrescribir lo ya guardado.
                    if ($existente->registrado_por !== $usuario->id || $existente->huella_peticion !== $huella) {
                        throw ValidationException::withMessages([
                            'clave' => 'Este formulario ya se guardó con otros datos. Abrí un registro nuevo.',
                        ]);
                    }

                    return $existente;
                }

                $gasto = Gasto::create(Arr::only($datos, [
                    'clave', 'beneficiario', 'concepto', 'categoria', 'ambito', 'persona', 'naturaleza',
                    'moneda', 'periodo_desde', 'periodo_hasta', 'responsable_id', 'documentacion', 'observaciones',
                ]) + [
                    'huella_peticion' => $huella,
                    'registrado_por' => $usuario->id,
                    // Sin monto conocido NO se inventa cero: la deuda queda «por definir»
                    // y no suma a pendiente ni a vencido.
                    'importe' => $request->boolean('monto_pendiente')
                        ? null
                        : Dinero::decimal(Dinero::centavos((string) $datos['importe'])),
                ]);

                $pagado = $request->boolean('ya_pagado');
                $aplicaciones = [];

                foreach ($datos['cuotas'] ?? [] as $i => $fila) {
                    $cuota = $gasto->cuotas()->create([
                        'numero' => $i + 1,
                        'importe' => Dinero::decimal(Dinero::centavos((string) $fila['importe'])),
                        'vence' => $fila['vence'] ?? null,
                    ]);

                    // Una cuota sin reparto no entra: aplicación de importe cero no existe.
                    $aplicar = trim((string) ($fila['aplicar'] ?? ''));
                    if ($pagado && $aplicar !== '' && Dinero::centavos($aplicar) > 0) {
                        $aplicaciones[] = ['cuota_id' => $cuota->id, 'importe' => $aplicar];
                    }
                }

                $pago = $pagado ? $this->pagos->registrar($usuario, [
                    // Derivada de la clave del gasto: el mismo envío produce SIEMPRE la
                    // misma clave de pago, así que un reintento no puede duplicarlo.
                    'clave' => Uuid::uuid5(self::NS_PAGO, $datos['clave'])->toString(),
                    'importe' => $datos['pago_importe'],
                    'fecha' => $datos['pago_fecha'],
                    'metodo' => $datos['pago_metodo'],
                    'pagado_por' => $datos['pagado_por'],
                    'referencia' => $datos['pago_referencia'] ?? null,
                    'sin_comprobante' => $datos['sin_comprobante'] ?? null,
                ], $aplicaciones) : null;

                $this->guardarAdjuntos($usuario, $archivos, $gasto, $pago, $rutas);

                // El vínculo con el documento va DENTRO de la transacción a propósito.
                // Si este papel ya originó otra deuda, el índice único lo rechaza y se
                // deshace todo —gasto, cuotas, pago y archivos—, en vez de dejar una
                // segunda deuda creada y el vínculo a medias.
                $this->vincularDocumento($usuario, $request, $gasto);

                DB::table('gastos_eventos')->insert([
                    'gasto_id' => $gasto->id,
                    'usuario_id' => $usuario->id,
                    'accion' => 'gasto_registrado',
                    'datos' => json_encode([
                        'importe' => $gasto->importe,
                        'moneda' => $gasto->moneda,
                        'ambito' => $gasto->ambito,
                        'cuotas' => $gasto->cuotas()->count(),
                        'pago_id' => $pago?->id,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $gasto;
            });
        } catch (\Throwable $e) {
            // Rollback: lo que llegó al disco se retira. No se toca nada de envíos
            // anteriores porque $rutas solo contiene lo escrito en ESTE intento.
            $this->almacen->limpiar($rutas);

            throw $e;
        }
    }

    /**
     * Deja constancia de que ESTE documento de Compras originó ESTA deuda.
     *
     * Es la fila que faltaba. El módulo siempre tuvo el índice único que impide que un
     * documento origine dos deudas, pero ninguna pantalla creaba el vínculo `deuda`, así
     * que el candado nunca se armaba: el mismo recibo podía registrarse dos veces y
     * generar dos obligaciones por el mismo papel.
     */
    private function vincularDocumento(User $usuario, RegistrarGastoRequest $request, Gasto $gasto): void
    {
        if (! $request->filled('documento')) {
            return;
        }

        $documento = DocumentoRecibido::find((int) $request->input('documento'));

        if ($documento === null) {
            return;
        }

        abort_unless($usuario->can('documentos-recibidos.ver'), 403);

        $this->compras->vincular($usuario, $gasto, $documento, 'deuda');
    }

    /**
     * Documento del gasto y comprobante del pago son respaldos DISTINTOS: van a la
     * misma tabla pero nunca al mismo vínculo, y cada uno hereda el permiso de su
     * dueño (el gasto, o el pago COMPLETO).
     *
     * @param  array<string, array<int, UploadedFile>>  $archivos
     * @param  array<int, string>  $rutas
     */
    private function guardarAdjuntos(User $usuario, array $archivos, Gasto $gasto, ?Pago $pago, array &$rutas): void
    {
        foreach (['documentos' => $gasto, 'comprobantes' => $pago] as $grupo => $dueno) {
            if (empty($archivos[$grupo])) {
                continue;
            }

            if ($dueno === null) {
                // Comprobantes sin pago no tienen a qué colgarse: sería un archivo
                // huérfano y sin candado de autorización.
                throw ValidationException::withMessages([
                    $grupo => 'No hay un pago al que asociar estos comprobantes.',
                ]);
            }

            $this->almacen->guardar(
                $archivos[$grupo],
                $grupo === 'documentos' ? $gasto->id : null,
                $grupo === 'comprobantes' ? $pago->id : null,
                $usuario->id,
                $rutas,
                $grupo,
            );
        }
    }
}
