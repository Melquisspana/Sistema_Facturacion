<?php

namespace App\Services\Planilla;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién encabeza los papeles de planilla: el negocio y la empleadora.
 *
 * ═══════ Dos datos, no uno ═══════
 *
 * El NEGOCIO es «Dulces La Negrita», el nombre con el que se le conoce.
 *
 * La EMPLEADORA es la persona: la razón social del registro fiscal, que en un negocio
 * a nombre de una persona natural es su nombre completo. Encabeza el documento porque
 * es de quien es el negocio.
 *
 * ═══════ Lo que esto NO es ═══════
 *
 * **No es quien entregó el pago.** Ese dato sale del pago registrado, persona por
 * persona, y puede ser cualquiera: quien llevó el sobre ese día. Poner a la empleadora
 * ahí por comodidad haría que un papel firmado dijera que entregó un dinero que no
 * entregó, y eso no se arregla después.
 *
 * ═══════ De dónde salen ═══════
 *
 * Solo de la tabla `empresas` (Configuración › Empresa emisora), que es la fuente fiscal:
 * un solo lugar para los datos de la empresa. Sin nombre comercial cargado, el negocio es
 * la razón social. `config/company.php` ya no participa: era una segunda fuente que se
 * quedaba vieja en cuanto alguien corregía la empresa en la pantalla. Se memoiza: lo
 * consultan todas las hojas de una impresión.
 */
final class IdentidadNegocio
{
    /** @var array<string, string>|null */
    private ?array $memo = null;

    /** @return array{negocio: string, empleadora: ?string, nit: ?string} */
    public function datos(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $fila = null;

        // `hasTable` antes de consultar: los impresos no pueden depender de que una
        // tabla ajena exista. Es la misma lección del menú que tumbó el sistema.
        if (Schema::hasTable('empresas')) {
            $fila = DB::table('empresas')
                ->whereNull('deleted_at')
                ->where('activo', true)
                ->orderBy('id')
                ->first(['razon_social', 'nombre_comercial', 'nit']);
        }

        $negocio = $fila?->nombre_comercial ?: $fila?->razon_social;

        // La razón social solo se ofrece como empleadora si es distinta del nombre
        // comercial: si coinciden, no hay persona que nombrar y repetir la misma línea
        // dos veces no informa de nada.
        // Se compara con el nombre comercial CARGADO, no con el negocio resuelto: sin
        // nombre comercial, el negocio cae a la razón social y la empleadora no puede
        // desaparecer por eso (el contrato la necesita).
        $empleadora = $fila?->razon_social;
        if ($empleadora !== null && trim($empleadora) === trim((string) $fila?->nombre_comercial)) {
            $empleadora = null;
        }

        return $this->memo = [
            'negocio' => (string) $negocio,
            'empleadora' => filled($empleadora) ? trim($empleadora) : null,
            'nit' => filled($fila?->nit) ? trim($fila->nit) : null,
        ];
    }

    public function negocio(): string
    {
        return $this->datos()['negocio'];
    }

    public function empleadora(): ?string
    {
        return $this->datos()['empleadora'];
    }

    public function nit(): ?string
    {
        return $this->datos()['nit'];
    }
}
