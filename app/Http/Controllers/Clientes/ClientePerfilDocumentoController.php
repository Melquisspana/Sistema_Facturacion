<?php

namespace App\Http\Controllers\Clientes;

use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoNotaCredito;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clientes\PerfilDocumentoRequest;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Configuración del perfil documental de un cliente desde su ficha.
 *
 * Va detrás de `clientes.gestionar` (que hoy solo tiene el administrador) y no de un
 * permiso nuevo, porque este perfil decide el DESCUENTO de las notas de crédito, y quien
 * ya puede mover `descuento_global_default` en el formulario del cliente tiene exactamente
 * la misma palanca sobre el mismo cálculo. Inventar un segundo permiso para la mitad de
 * una decisión que ya está protegida solo daría una falsa sensación de control.
 *
 * Es una pantalla propia y no un bloque más del formulario del cliente porque el perfil
 * tiene su propia forma —una cabecera y una fila por modalidad— y mezclarla con los ~25
 * campos fiscales del cliente haría más difícil ver qué se está cambiando.
 *
 * No emite, no firma, no transmite y no recalcula documentos existentes: los borradores
 * toman la regla nueva al recalcularse y los generados son inmutables.
 */
class ClientePerfilDocumentoController extends Controller
{
    use AuthorizesRequests;

    public function edit(Cliente $cliente): View
    {
        $this->authorize('update', $cliente);

        $perfil = $cliente->perfilDocumento()->with('tiposNc')->first();

        return view('clientes.perfil-documento', [
            'cliente' => $cliente,
            'perfil' => $perfil,
            'modalidades' => $this->modalidades($perfil),
            'formatos' => ExportadorNcFactory::slugs(),
            'origenes' => OrigenDescuentoNc::opciones(),
        ]);
    }

    public function update(PerfilDocumentoRequest $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('update', $cliente);

        $datos = $request->validated();

        DB::transaction(function () use ($cliente, $datos) {
            $perfil = ClientePerfilDocumento::updateOrCreate(
                ['cliente_id' => $cliente->id],
                [
                    'activo' => (bool) ($datos['activo'] ?? false),
                    'codigo_proveedor' => $datos['codigo_proveedor'] ?? null,
                    'formato_export' => $datos['formato_export'] ?? null,
                    'exige_albaran_en_nc' => (bool) ($datos['exige_albaran_en_nc'] ?? false),
                    'tolerancia_albaran' => $datos['tolerancia_albaran'],
                ]
            );

            $this->guardarModalidades($perfil, (array) ($datos['modalidades'] ?? []));
        });

        // El resolutor memoriza los perfiles dentro de la petición; sin esto, cualquier
        // cálculo posterior en este mismo request seguiría viendo el perfil viejo.
        app(PerfilDocumentoResolver::class)->olvidar();

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('status', 'Perfil documental actualizado. Aplica a los borradores que se recalculen desde ahora; los documentos ya generados no cambian.');
    }

    /**
     * Sincroniza el mapeo: crea o actualiza las modalidades marcadas y borra las que se
     * desmarcaron. Desmarcar devuelve esa modalidad al criterio histórico, que es
     * justamente lo que el operador espera al desactivarla.
     *
     * @param  array<string, array<string, mixed>>  $modalidades
     */
    private function guardarModalidades(ClientePerfilDocumento $perfil, array $modalidades): void
    {
        $conservar = [];

        foreach ($modalidades as $clave => $datos) {
            $tipo = TipoNotaCredito::tryFrom((string) $clave);

            if ($tipo === null || ! (bool) ($datos['usar'] ?? false)) {
                continue;
            }

            $origen = OrigenDescuentoNc::from((string) $datos['descuento_origen']);

            ClientePerfilTipoNc::updateOrCreate(
                ['cliente_perfil_documento_id' => $perfil->id, 'tipo_nota_credito' => $tipo->value],
                [
                    'codigo_externo' => strtoupper(trim((string) $datos['codigo_externo'])),
                    'etiqueta_externa' => filled($datos['etiqueta_externa'] ?? null)
                        ? trim((string) $datos['etiqueta_externa'])
                        : null,
                    'descuento_origen' => $origen->value,
                    // La tasa solo se guarda cuando la regla la usa: dejarla escrita en
                    // una regla «ninguno» invita a creer que se está aplicando.
                    'descuento_tasa' => $origen->requiereTasa() ? (float) $datos['descuento_tasa'] : null,
                ]
            );

            $conservar[] = $tipo->value;
        }

        $perfil->tiposNc()->whereNotIn('tipo_nota_credito', $conservar ?: ['__ninguna__'])->delete();
    }

    /**
     * Una fila por modalidad interna, con lo ya configurado o los valores en blanco.
     * Se listan TODAS para que el operador vea qué existe y qué no está mapeado, en vez
     * de tener que adivinar qué nombres son válidos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function modalidades(?ClientePerfilDocumento $perfil): array
    {
        return collect(TipoNotaCredito::cases())
            ->map(function (TipoNotaCredito $tipo) use ($perfil) {
                $regla = $perfil?->reglaPara($tipo);

                return [
                    'tipo' => $tipo,
                    'usar' => $regla !== null,
                    'codigo_externo' => $regla?->codigo_externo,
                    'etiqueta_externa' => $regla?->etiqueta_externa,
                    'descuento_origen' => $regla?->descuento_origen?->value ?? OrigenDescuentoNc::Ccf->value,
                    'descuento_tasa' => $regla?->descuento_tasa,
                ];
            })
            ->all();
    }
}
