<?php

namespace App\Services\Cobros;

use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Models\Cliente;
use App\Models\User;
use App\Services\Ppq\ActualizarPpqConTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use Illuminate\Support\Facades\DB;

/**
 * Puesta al día de Cobros: aplica de una vez varios TXT de pago, cada uno con el mismo flujo
 * que el TXT normal (idempotente por huella y línea), y arma el estado de cobro desde el
 * inicio del seguimiento. Un archivo que falla no frena a los demás.
 *
 * «Solo revisar» corre todo dentro de una transacción que siempre se deshace y no guarda la
 * copia del archivo: no queda nada en la base ni en disco. La cadena no despacha jobs ni
 * manda correos.
 */
class PuestaAlDiaPagos
{
    public function procesar(Cliente $cliente, array $subidas, ?User $usuario, bool $soloRevisar): array
    {
        $preparados = [];
        foreach ($subidas as $orden => $subida) {
            $contenido = $subida->isValid() && is_readable($subida->getPathname()) ? file_get_contents($subida->getPathname()) : false;
            $filas = $contenido === false ? [] : app(ConciliacionTxtParser::class)->parse($contenido);
            $preparados[] = compact('subida', 'contenido', 'filas', 'orden') + ['fecha' => collect($filas)->pluck('fecha')->filter()->max() ?? ''];
        }
        // Del más viejo al más nuevo: si un pago se repite en dos TXT, el primero aplica y el
        // segundo queda en revisión, como al subirlos uno por uno en su orden.
        usort($preparados, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: $a['orden'] <=> $b['orden']);
        $archivos = [];
        if ($soloRevisar) {
            DB::beginTransaction();
        }
        try {
            foreach ($preparados as $preparado) {
                $subida = $preparado['subida'];
                $resultado = ['nombre' => $subida->getClientOriginalName(), 'estado' => 'error', 'mensaje' => '', 'informe' => null, 'lotes' => []];
                if ($preparado['filas'] === []) {
                    $resultado['mensaje'] = 'El archivo está vacío o no se pudo leer como TXT de pagos.';
                } else {
                    try {
                        $resultado = DB::transaction(function () use ($cliente, $usuario, $soloRevisar, $preparado, $subida, $resultado) {
                            $archivo = $soloRevisar
                                ? ArchivoConciliacion::desdeContenido($preparado['contenido'], $resultado['nombre'])
                                : ArchivoConciliacion::desdeSubida($subida);
                            $informe = app(AplicadorPagosTxt::class)->aplicar($cliente, $preparado['filas'], $archivo, $usuario);
                            $lotes = app(ActualizarPpqConTxt::class)->aplicar($preparado['filas'], $archivo, $usuario);

                            return array_replace($resultado, ['estado' => 'aplicado', 'informe' => $informe, 'lotes' => $lotes]);
                        });
                    } catch (ArchivoConciliacionInconsistenteException|ArchivoProveedorInvalidoException $e) {
                        $resultado['mensaje'] = $e->getMessage();
                    }
                }
                $archivos[] = $resultado;
            }
            // El informe debe conservar el resultado simulado antes de deshacerlo.
            $reporte = app(EstadoCobroDesdeInicio::class)->generar($cliente);
            foreach ($archivos as &$archivo) {
                if ($archivo['estado'] === 'aplicado') {
                    $archivo['pendientes'] = app(PendientesDelTxt::class)->analizar($cliente, collect($archivo['lotes'])->pluck('lote'));
                }
            }
            unset($archivo);

            return compact('archivos', 'reporte');
        } finally {
            if ($soloRevisar) {
                DB::rollBack();
            }
        }
    }
}
