<?php

namespace App\Services\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Enums\Planta\TipoInsumo;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ÚNICO punto autorizado para crear, actualizar y activar/desactivar
 * configuraciones de empaque, y para decidir cuál es la predeterminada.
 *
 * Existe porque hay invariantes que ninguna clave foránea puede expresar: una
 * FK garantiza que `planta_insumo_bolsa_id` apunta a un insumo, pero no que ese
 * insumo sea de TIPO bolsa ni que esté activo. Esas comprobaciones se hacen
 * aquí, en backend, y se repiten aunque el Form Request ya las haya hecho: un
 * formulario no es una barrera, es una comodidad.
 *
 * Reparto de responsabilidades:
 *   - Las tres columnas derivadas las mantiene el MOTOR (columnas generadas
 *     STORED). Este servicio no las toca ni las calcula.
 *   - Los dos índices únicos son la garantía DURA contra duplicados y contra
 *     dos predeterminadas del mismo mercado. Este servicio comprueba antes para
 *     dar un mensaje decente, y traduce la violación si aun así ocurre.
 *   - `lockForUpdate` sobre la presentación serializa los cambios de
 *     predeterminada; no sustituye al unique, lo acompaña.
 */
class EmpaqueConfigService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): PlantaEmpaqueConfig
    {
        return DB::transaction(function () use ($datos) {
            $this->validar($datos, null);

            $config = new PlantaEmpaqueConfig;
            $config->fill($this->soloAtributos($datos));

            return $this->guardar($config, (bool) ($datos['es_predeterminada'] ?? false));
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(PlantaEmpaqueConfig $config, array $datos): PlantaEmpaqueConfig
    {
        return DB::transaction(function () use ($config, $datos) {
            $this->validar($datos, $config);

            $config->fill($this->soloAtributos($datos));

            return $this->guardar($config, (bool) ($datos['es_predeterminada'] ?? false));
        });
    }

    /**
     * Marca una configuración como la predeterminada de su presentación y
     * mercado, quitándole la condición a la anterior en la MISMA transacción.
     */
    public function marcarPredeterminada(PlantaEmpaqueConfig $config): PlantaEmpaqueConfig
    {
        return DB::transaction(function () use ($config) {
            if (! $config->activo) {
                throw ValidationException::withMessages([
                    'es_predeterminada' => 'Una configuración inactiva no puede ser la predeterminada.',
                ]);
            }

            $config->es_predeterminada = true;

            return $this->guardar($config, true);
        });
    }

    /**
     * Activa o desactiva una configuración.
     *
     * Al DESACTIVAR una predeterminada se le retira además esa condición: si no,
     * seguiría ocupando el hueco único de su mercado y ninguna otra podría
     * tomar el relevo, con la presentación sin predeterminada utilizable.
     *
     * Al REACTIVAR se revalidan las dependencias, y esta vez SIN la exención
     * histórica: una configuración guardada puede conservar referencias a
     * registros que se retiraron después —el histórico es válido y debe poder
     * consultarse—, pero volver a ponerla en circulación es afirmar que se
     * puede usar hoy, y eso exige que su presentación, su bolsa y su viñeta
     * estén vigentes. La comprobación ocurre ANTES de mutar nada.
     */
    public function alternarActivo(PlantaEmpaqueConfig $config): PlantaEmpaqueConfig
    {
        return DB::transaction(function () use ($config) {
            $activar = ! $config->activo;

            if ($activar) {
                $this->validarDependenciasVigentes($config);
            }

            $config->activo = $activar;

            if (! $activar) {
                $config->es_predeterminada = false;
            }

            $config->save();

            return $config;
        });
    }

    /**
     * Comprobación ESTRICTA de las dependencias propias de una configuración,
     * sin exención por «ya era la que tenía». Solo se usa al reactivar.
     */
    private function validarDependenciasVigentes(PlantaEmpaqueConfig $config): void
    {
        $presentacion = PlantaPresentacion::find($config->planta_presentacion_id);

        if (! $presentacion?->activo) {
            throw ValidationException::withMessages([
                'activo' => 'No se puede reactivar: la presentación está inactiva.',
            ]);
        }

        $bolsa = PlantaInsumo::find($config->planta_insumo_bolsa_id);

        if (! $bolsa?->activo) {
            throw ValidationException::withMessages([
                'activo' => "No se puede reactivar: la bolsa «{$bolsa?->nombre}» está inactiva.",
            ]);
        }

        if ($config->planta_insumo_vinieta_id === null) {
            return; // Sin viñeta no hay nada más que comprobar.
        }

        $vinieta = PlantaInsumo::find($config->planta_insumo_vinieta_id);

        if (! $vinieta?->activo) {
            throw ValidationException::withMessages([
                'activo' => "No se puede reactivar: la viñeta «{$vinieta?->nombre}» está inactiva.",
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Interno
    // ------------------------------------------------------------------

    /** Solo los atributos escribibles; las derivadas jamás se tocan desde PHP. */
    private function soloAtributos(array $datos): array
    {
        return collect($datos)
            ->only((new PlantaEmpaqueConfig)->getFillable())
            ->except(PlantaEmpaqueConfig::DERIVADAS)
            ->all();
    }

    /**
     * Persiste dentro de la transacción abierta, resolviendo antes la
     * predeterminada anterior si hace falta.
     */
    private function guardar(PlantaEmpaqueConfig $config, bool $predeterminada): PlantaEmpaqueConfig
    {
        $config->es_predeterminada = $predeterminada;

        if ($predeterminada) {
            // 1. Bloquea la PRESENTACIÓN: es una fila que siempre existe, así que
            //    serializa incluso cuando todavía no hay ninguna configuración.
            PlantaPresentacion::whereKey($config->planta_presentacion_id)->lockForUpdate()->first();

            // 2. Bloquea las hermanas del mismo mercado y le quita la condición a
            //    la anterior, dentro de esta misma transacción.
            $mercado = $config->mercado instanceof MercadoPlanta
                ? $config->mercado->value
                : $config->mercado;

            $hermanas = PlantaEmpaqueConfig::query()
                ->where('planta_presentacion_id', $config->planta_presentacion_id)
                ->where('mercado', $mercado)
                ->where('es_predeterminada', true)
                ->when($config->exists, fn ($q) => $q->whereKeyNot($config->getKey()))
                ->lockForUpdate()
                ->get();

            foreach ($hermanas as $hermana) {
                $hermana->es_predeterminada = false;
                $hermana->save();
            }
        }

        try {
            $config->save();
        } catch (QueryException $e) {
            throw $this->traducirViolacion($e);
        }

        return $config;
    }

    /**
     * Los dos índices únicos son la garantía final. Si saltan pese a las
     * comprobaciones previas (por una carrera), se traducen a un error de
     * validación legible en vez de un 500.
     */
    private function traducirViolacion(QueryException $e): ValidationException|QueryException
    {
        $mensaje = $e->getMessage();

        if (str_contains($mensaje, 'planta_empaque_predet_unico')) {
            return ValidationException::withMessages([
                'es_predeterminada' => 'Otra configuración acaba de quedar como predeterminada para este mercado. Vuelva a intentarlo.',
            ]);
        }

        if (str_contains($mensaje, 'planta_empaque_config_unico')) {
            return ValidationException::withMessages([
                'planta_insumo_bolsa_id' => 'Ya existe una configuración con esa presentación, mercado, marca, viñeta y bolsa.',
            ]);
        }

        return $e;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function validar(array $datos, ?PlantaEmpaqueConfig $config): void
    {
        $this->validarMercado($datos);
        $this->validarPresentacion($datos, $config);
        $this->validarInsumos($datos, $config);
        $this->validarVigencia($datos);
        $this->validarNoDuplicada($datos, $config);
    }

    private function validarMercado(array $datos): void
    {
        if (MercadoPlanta::tryFrom((string) ($datos['mercado'] ?? '')) === null) {
            throw ValidationException::withMessages([
                'mercado' => 'El mercado seleccionado no es válido.',
            ]);
        }
    }

    private function validarPresentacion(array $datos, ?PlantaEmpaqueConfig $config): void
    {
        $presentacion = PlantaPresentacion::find($datos['planta_presentacion_id'] ?? null);

        if (! $presentacion) {
            throw ValidationException::withMessages([
                'planta_presentacion_id' => 'La presentación seleccionada no existe.',
            ]);
        }

        // Se admite conservar la presentación histórica de una configuración ya
        // guardada aunque se haya desactivado; lo que no se admite es apuntar
        // una configuración a una presentación inactiva.
        $esLaMisma = $config?->exists && $config->planta_presentacion_id === $presentacion->id;

        if (! $presentacion->activo && ! $esLaMisma) {
            throw ValidationException::withMessages([
                'planta_presentacion_id' => 'La presentación seleccionada está inactiva.',
            ]);
        }
    }

    /**
     * Bolsa y viñeta: tipo correcto y activos. Al editar se permite CONSERVAR un
     * insumo que quedó inactivo (el histórico es válido), pero no cambiar a otro
     * insumo inactivo distinto.
     */
    private function validarInsumos(array $datos, ?PlantaEmpaqueConfig $config): void
    {
        $bolsaId = $datos['planta_insumo_bolsa_id'] ?? null;
        $bolsa = PlantaInsumo::find($bolsaId);

        if (! $bolsa) {
            throw ValidationException::withMessages([
                'planta_insumo_bolsa_id' => 'La bolsa seleccionada no existe.',
            ]);
        }

        if ($bolsa->tipo !== TipoInsumo::Bolsa) {
            throw ValidationException::withMessages([
                'planta_insumo_bolsa_id' => "El insumo «{$bolsa->nombre}» no es de tipo bolsa.",
            ]);
        }

        if (! $bolsa->activo && $config?->planta_insumo_bolsa_id !== $bolsa->id) {
            throw ValidationException::withMessages([
                'planta_insumo_bolsa_id' => "La bolsa «{$bolsa->nombre}» está inactiva.",
            ]);
        }

        $vinietaId = $datos['planta_insumo_vinieta_id'] ?? null;

        if ($vinietaId === null || $vinietaId === '') {
            return; // La viñeta es opcional: hay empaques que no la llevan.
        }

        $vinieta = PlantaInsumo::find($vinietaId);

        if (! $vinieta) {
            throw ValidationException::withMessages([
                'planta_insumo_vinieta_id' => 'La viñeta seleccionada no existe.',
            ]);
        }

        if ($vinieta->tipo !== TipoInsumo::Vinieta) {
            throw ValidationException::withMessages([
                'planta_insumo_vinieta_id' => "El insumo «{$vinieta->nombre}» no es de tipo viñeta.",
            ]);
        }

        if (! $vinieta->activo && $config?->planta_insumo_vinieta_id !== $vinieta->id) {
            throw ValidationException::withMessages([
                'planta_insumo_vinieta_id' => "La viñeta «{$vinieta->nombre}» está inactiva.",
            ]);
        }
    }

    private function validarVigencia(array $datos): void
    {
        $desde = $datos['vigente_desde'] ?? null;
        $hasta = $datos['vigente_hasta'] ?? null;

        if ($desde && $hasta && strtotime((string) $hasta) < strtotime((string) $desde)) {
            throw ValidationException::withMessages([
                'vigente_hasta' => 'La fecha de fin de vigencia no puede ser anterior a la de inicio.',
            ]);
        }
    }

    /**
     * Comprobación previa del unique compuesto. La garantía final es el índice;
     * esto solo sirve para dar un mensaje claro antes de intentar escribir.
     */
    private function validarNoDuplicada(array $datos, ?PlantaEmpaqueConfig $config): void
    {
        $marcaNorm = trim(mb_strtoupper((string) ($datos['marca'] ?? '')));
        $vinietaKey = (int) ($datos['planta_insumo_vinieta_id'] ?? 0);

        $existe = PlantaEmpaqueConfig::query()
            ->where('planta_presentacion_id', $datos['planta_presentacion_id'] ?? null)
            ->where('mercado', $datos['mercado'] ?? null)
            ->where('marca_norm', $marcaNorm)
            ->where('vinieta_key', $vinietaKey)
            ->where('planta_insumo_bolsa_id', $datos['planta_insumo_bolsa_id'] ?? null)
            ->when($config?->exists, fn ($q) => $q->whereKeyNot($config->getKey()))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'planta_insumo_bolsa_id' => 'Ya existe una configuración con esa presentación, mercado, marca, viñeta y bolsa.',
            ]);
        }
    }
}
