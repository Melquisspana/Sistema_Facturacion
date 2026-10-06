<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoPpq;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\IdentidadPpq;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * El reporte que devuelve el portal de Calleja al registrar un caso de quedan
 * («CASO 12027 …», una fila por documento con su DTE y ESTADO).
 *
 * Calleja no siempre toma todo lo enviado. Por eso:
 *  · lo que viene REGISTRADO queda recibido, con el número de caso;
 *  · lo que iba en el mismo lote PPQ y NO vino vuelve a «por presentar», para meterlo
 *    en el siguiente PPQ. Lo que ya se había presentado en un lote anterior no se toca.
 */
class ReporteCasoCalleja
{
    /**
     * @return array{caso: string, documentos: array<string, string>}
     *
     * @throws RuntimeException si el archivo no es el reporte esperado
     */
    public function leer(string $ruta): array
    {
        try {
            $filas = IOFactory::load($ruta)->getActiveSheet()->toArray();
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer el reporte: '.$e->getMessage(), 0, $e);
        }

        if (! preg_match('/CASO\s+(\d+)/i', (string) ($filas[0][0] ?? ''), $m)) {
            throw new RuntimeException('El archivo no parece el reporte de caso de Calleja (falta «CASO …» en la primera celda).');
        }

        $documentos = [];
        foreach ($filas as $fila) {
            $clave = IdentidadPpq::normalizar((string) ($fila[8] ?? ''));
            if ($clave !== null && preg_match('/^DTE0[35]/', $clave)) {
                $documentos[$clave] = strtoupper(trim((string) ($fila[10] ?? '')));
            }
        }

        if ($documentos === []) {
            throw new RuntimeException('El reporte no trae documentos.');
        }

        return ['caso' => $m[1], 'documentos' => $documentos];
    }

    /**
     * @param  array{caso: string, documentos: array<string, string>}  $reporte
     * @return array{caso: string, recibidos: int, no_en_seguimiento: array<int, string>, devueltos: array<int, CobroDocumento>}
     */
    public function aplicar(Cliente $cliente, array $reporte, ?User $usuario = null, ?PpqLote $lote = null): array
    {
        $caso = $reporte['caso'];
        $registrados = array_keys(array_filter($reporte['documentos'], fn ($estado) => $estado === '' || str_contains($estado, 'REGISTRADO')));

        $porClave = CobroDocumento::deCliente($cliente->id)->get()
            ->keyBy(fn (CobroDocumento $d) => IdentidadPpq::normalizar($d->numero_control));

        $resultado = ['caso' => $caso, 'recibidos' => 0, 'no_en_seguimiento' => [], 'devueltos' => []];

        DB::transaction(function () use ($registrados, $porClave, $caso, $usuario, $lote, &$resultado) {
            foreach ($registrados as $clave) {
                $doc = $porClave->get($clave);
                if ($doc === null) {
                    $resultado['no_en_seguimiento'][] = $clave;

                    continue;
                }
                $doc->forceFill([
                    'presentacion_estado' => EstadoPresentacionCobro::Recibida->value,
                    'revisar_historico' => false,
                ])->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Recibido->value, 'referencia_linea' => 'caso-'.$caso],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'detalle' => "Registrado por Calleja en el caso {$caso}.", 'datos' => ['caso' => $caso]],
                );
                $resultado['recibidos']++;
            }

            // El lote PPQ que llevaba estos documentos: lo suyo que Calleja no tomó vuelve
            // a por presentar (salvo lo que ya se presentó en un lote anterior).
            $lote ??= ($id = $this->loteDelCaso($registrados)) !== null ? PpqLote::find($id) : null;
            if ($lote === null) {
                return;
            }
            // El PPQ queda presentado, con su número de caso.
            $lote->update([
                'estado' => $lote->estado === EstadoPpq::Pagado ? EstadoPpq::Pagado->value : EstadoPpq::Enviado->value,
                'observaciones' => str_contains((string) $lote->observaciones, "Caso {$caso}") ? $lote->observaciones
                    : trim(((string) $lote->observaciones)."
Caso {$caso} de Calleja."),
            ]);
            $lote = $lote->id;
            $anteriores = PpqItem::whereHas('lote')->where('ppq_lote_id', '<', $lote)->pluck('numero_control')
                ->map(fn ($n) => IdentidadPpq::normalizar($n))->filter()->flip();

            foreach (PpqItem::where('ppq_lote_id', $lote)->pluck('numero_control') as $control) {
                $clave = IdentidadPpq::normalizar($control);
                $doc = $clave !== null ? $porClave->get($clave) : null;
                if ($doc === null || in_array($clave, $registrados, true) || $anteriores->has($clave)
                    || $doc->pago_estado !== EstadoPagoCobro::Pendiente) {
                    continue;
                }
                $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
                CobroEvento::firstOrCreate(
                    ['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'referencia_linea' => 'caso-'.$caso.'-fuera'],
                    ['origen' => 'manual', 'fecha' => today()->toDateString(), 'user_id' => $usuario?->id,
                        'detalle' => "Calleja no lo tomó en el caso {$caso}: va en el siguiente PPQ.", 'datos' => ['caso' => $caso]],
                );
                $resultado['devueltos'][] = $doc;
            }
        });

        return $resultado;
    }

    /** Id del lote PPQ que contiene más documentos del reporte, o null. */
    private function loteDelCaso(array $claves): ?int
    {
        $conteo = [];
        foreach (PpqItem::whereHas('lote')->whereNotNull('numero_control')->get(['ppq_lote_id', 'numero_control']) as $item) {
            if (in_array(IdentidadPpq::normalizar($item->numero_control), $claves, true)) {
                $conteo[$item->ppq_lote_id] = ($conteo[$item->ppq_lote_id] ?? 0) + 1;
            }
        }
        arsort($conteo);

        return $conteo === [] ? null : (int) array_key_first($conteo);
    }
}
