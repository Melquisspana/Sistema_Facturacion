<?php

namespace App\Http\Controllers\Rutas;

use App\Enums\ConceptoGastoRuta;
use App\Http\Controllers\Controller;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaGasto;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SalidaGastoController extends Controller
{
    public function store(Request $request, SalidaRuta $salida): RedirectResponse
    {
        $vendedor = $request->routeIs('rutas.mi-salida.*');
        $fecha = $vendedor ? HoraNegocio::fechaHoy() : ($request->validate([
            'fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.HoraNegocio::fechaHoy()],
        ])['fecha'] ?? HoraNegocio::fechaHoy());
        $archivo = null;
        try {
            DB::transaction(function () use ($request, $salida, $vendedor, $fecha, &$archivo) {
                $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
                if ($vendedor) {
                    Gate::authorize('registrar', $salida);
                }
                Gate::authorize('agregarGasto', [$salida, $fecha]);
                $datos = $request->validate([
                    'concepto' => ['required', Rule::enum(ConceptoGastoRuta::class)],
                    'monto' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'gt:0', 'max:'.SalidaRutaGasto::MONTO_MAXIMO],
                    'detalle' => ['required_if:concepto,otro', 'nullable', 'string', Rule::when($request->input('concepto') === 'otro', ['min:3']), 'max:200'],
                    'personal_id' => $vendedor ? ['exclude'] : ['nullable', 'integer', Rule::in($salida->personal()->pluck('rutas_personal.id')->all())],
                    'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.SalidaRutaGasto::FOTO_MAX_KB],
                ]);
                unset($datos['foto']);
                $datos['fecha'] = $fecha;
                $datos['registrado_por'] = $request->user()->id;
                if ($vendedor) {
                    $datos['personal_id'] = $salida->personal()->where('user_id', $request->user()->id)->where('activo', true)->firstOrFail()->id;
                }
                if ($foto = $request->file('foto')) {
                    $archivo = 'rutas/gastos/'.$salida->id.'/'.Str::uuid().'.'.$foto->extension();
                    if (! Storage::disk('local')->putFileAs(dirname($archivo), $foto, basename($archivo), ['visibility' => 'private'])) {
                        throw new RuntimeException('No se pudo guardar la foto del gasto.');
                    }
                    $datos += ['archivo_ruta' => $archivo, 'archivo_mime' => $foto->getMimeType(), 'archivo_bytes' => $foto->getSize(), 'archivo_sha256' => hash_file('sha256', $foto->getRealPath())];
                }
                $gasto = $salida->gastos()->create($datos);
                activity('salida_ruta_gasto')->performedOn($gasto)->causedBy($request->user())->log('registró el gasto');
            });
        } catch (Throwable $e) {
            if ($archivo) {
                Storage::disk('local')->delete($archivo);
            }
            throw $e;
        }

        return back()->with('status', 'Gasto registrado.');
    }

    public function destroy(Request $request, SalidaRuta $salida, SalidaRutaGasto $gasto): RedirectResponse
    {
        DB::transaction(function () use ($request, $salida, $gasto) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            $gasto = $salida->gastos()->lockForUpdate()->findOrFail($gasto->id);
            if ($request->routeIs('rutas.mi-salida.*')) {
                Gate::authorize('registrar', $salida);
            }
            Gate::authorize('borrarGasto', [$salida, $gasto]);
            activity('salida_ruta_gasto')->performedOn($gasto)->causedBy($request->user())
                ->withProperties($gasto->only(['concepto', 'detalle', 'monto', 'fecha', 'personal_id', 'archivo_ruta', 'archivo_sha256']))->log('borró el gasto');
            $archivo = $gasto->archivo_ruta;
            $gasto->delete();
            if ($archivo) {
                DB::afterCommit(function () use ($archivo) {
                    if (! Storage::disk('local')->delete($archivo)) {
                        throw new RuntimeException('No se pudo borrar la foto del gasto.');
                    }
                });
            }
        });

        return back()->with('status', 'Gasto borrado.');
    }

    public function foto(SalidaRuta $salida, SalidaRutaGasto $gasto): BinaryFileResponse
    {
        Gate::authorize('verFotoGasto', $salida);
        abort_unless($gasto->archivo_ruta && Storage::disk('local')->exists($gasto->archivo_ruta), 404);

        return response()->file(Storage::disk('local')->path($gasto->archivo_ruta), [
            'Content-Type' => $gasto->archivo_mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
