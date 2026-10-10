<?php

namespace App\Services\Dte\Serializadores;

use App\Models\Contingencia;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\DocumentoIdentidadMh;
use App\Support\HoraNegocio;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SerializadorContingenciaMh
{
    public function serializar(Contingencia $contingencia, Collection $documentos, ?string $codigo = null): array
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        if (! $contingencia->cese || $documentos->isEmpty() || $documentos->count() > 1000) {
            throw ValidationException::withMessages(['contingencia' => 'Se requiere cese y entre 1 y 1000 documentos.']);
        }
        $responsable = config('dte.invalidacion.responsable', []);
        foreach (['nombre', 'tipo_doc', 'num_doc'] as $campo) {
            if (blank($responsable[$campo] ?? null)) {
                throw ValidationException::withMessages(['contingencia' => 'Faltan datos del responsable del evento.']);
            }
        }
        $problemas = DocumentoIdentidadMh::problemas('responsable', $responsable['nombre'], $responsable['tipo_doc'], $responsable['num_doc']);
        if ($problemas !== []) {
            throw ValidationException::withMessages(['contingencia' => implode(' ', $problemas)]);
        }
        $dte = $documentos->first();
        $dte->loadMissing(['establecimiento.empresa', 'puntoVenta']);
        if (! $dte->establecimiento?->empresa || ! $dte->puntoVenta) {
            throw ValidationException::withMessages(['contingencia' => 'Faltan el emisor, establecimiento o punto de venta del evento.']);
        }
        $empresa = $dte->establecimiento->empresa;
        $estable = (string) config('dte.invalidacion.cod_estable_mh') ?: $dte->establecimiento->codigo;
        $punto = (string) config('dte.invalidacion.cod_punto_venta_mh') ?: $dte->puntoVenta->codigo;
        if (! preg_match('/^[MSBP]\d{3}$/', $estable) || ! preg_match('/^P\d{3}$/', $punto)) {
            throw ValidationException::withMessages(['contingencia' => 'Los codigos MH del establecimiento y punto de venta no son validos.']);
        }
        $ahora = HoraNegocio::ahora();
        $inicio = HoraNegocio::aLocal($contingencia->inicio);
        $fin = HoraNegocio::aLocal($contingencia->cese);

        return [
            'identificacion' => ['version' => 4, 'ambiente' => $dte->ambiente->value,
                'codigoGeneracion' => strtoupper($codigo ?? CodigoGeneracion::generar()),
                'fTransmision' => $ahora->format('Y-m-d'), 'hTransmision' => $ahora->format('H:i:s')],
            'emisor' => ['nit' => preg_replace('/\D/', '', $empresa->nit), 'nombre' => $empresa->razon_social,
                'nombreResponsable' => trim($responsable['nombre']), 'tipoDocResponsable' => $responsable['tipo_doc'],
                'numeroDocResponsable' => DocumentoIdentidadMh::normalizarNumero($responsable['tipo_doc'], $responsable['num_doc']),
                'tipoEstablecimiento' => $dte->establecimiento->tipo_establecimiento->value,
                'codEstableMH' => $estable, 'codPuntoVentaMH' => $punto,
                'telefono' => $empresa->telefono, 'correo' => $empresa->correo],
            'detalleDTE' => $documentos->values()->map(fn ($doc, $i) => ['noItem' => $i + 1,
                'tipoDoc' => $doc->tipo_dte->value, 'codigoGeneracion' => strtoupper($doc->codigo_generacion)])->all(),
            'motivo' => ['fInicio' => $inicio->format('Y-m-d'), 'hInicio' => $inicio->format('H:i:s'),
                'fFin' => $fin->format('Y-m-d'), 'hFin' => $fin->format('H:i:s'),
                'tipoContingencia' => $contingencia->tipo, 'motivoContingencia' => $contingencia->motivo],
        ];
    }
}
