<?php

namespace App\Support\Exportaciones;

use App\Ajustes\Ajustes;
use App\Models\Empresa;

/**
 * Fuente ÚNICA de los datos de la empresa exportadora que van en la lista de
 * empaque: nombre, dirección y número de registro FDA.
 *
 * Antes vivían en tres sitios a la vez —`config/exportaciones.php`, el campo FDA
 * de cada perfil de cliente, y una copia congelada en cada lista—, así que el
 * mismo número de la empresa se editaba en un lugar y seguía viejo en otros dos.
 *
 * CADENA DE RESOLUCIÓN, en este orden y sin saltos:
 *
 *   1. Configuración → Parámetros fiscales (el ajuste administrable).
 *   2. `config('exportaciones.*')`, el valor histórico. Es el RESPALDO que hace
 *      segura la migración: mientras nadie configure nada, todo sigue dando
 *      exactamente el mismo resultado que antes.
 *   3. Para nombre y dirección, la Empresa emisora registrada. Es la fuente más
 *      correcta conceptualmente, pero va última a propósito: la razón social
 *      fiscal no siempre es el nombre con el que se exporta, y cambiar eso en
 *      silencio alteraría la cabecera de documentos que hoy salen bien.
 *
 * El FDA NO cae a la Empresa: no existe esa columna y no se va a inventar. Si no
 * está ni en Configuración ni en la config histórica, la respuesta es null y la
 * pantalla lo dice.
 */
class DatosExportador
{
    public function __construct(private readonly Ajustes $ajustes) {}

    public function fdaRegNumber(): ?string
    {
        return $this->primeroNoVacio([
            $this->deAjustes('exportaciones.fda_reg_number'),
            (string) config('exportaciones.fda_reg_number', ''),
        ]);
    }

    public function nombre(): ?string
    {
        return $this->primeroNoVacio([
            $this->deAjustes('exportaciones.exportador_nombre'),
            (string) config('exportaciones.exportador_nombre', ''),
            (string) (Empresa::query()->where('activo', true)->orderBy('id')->value('razon_social') ?? ''),
        ]);
    }

    public function direccion(): ?string
    {
        return $this->primeroNoVacio([
            $this->deAjustes('exportaciones.exportador_direccion'),
            (string) config('exportaciones.exportador_direccion', ''),
            (string) (Empresa::query()->where('activo', true)->orderBy('id')->value('direccion') ?? ''),
        ]);
    }

    /**
     * Valores por defecto del encabezado de una lista nueva.
     *
     * @return array{exportador_nombre: string, exportador_direccion: string, fda_reg_number: string}
     */
    public function paraEncabezado(): array
    {
        return [
            'exportador_nombre' => (string) ($this->nombre() ?? ''),
            'exportador_direccion' => (string) ($this->direccion() ?? ''),
            'fda_reg_number' => (string) ($this->fdaRegNumber() ?? ''),
        ];
    }

    /**
     * Lectura del catálogo de ajustes que NO revienta si la clave todavía no está
     * declarada. El catálogo es una lista blanca y pedir una clave desconocida
     * lanza; acá eso significaría que resolver el FDA tumba la pantalla en una
     * instalación a medio migrar, cuando lo correcto es seguir al respaldo.
     */
    private function deAjustes(string $clave): string
    {
        try {
            return (string) ($this->ajustes->texto($clave, '') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param  list<string>  $candidatos */
    private function primeroNoVacio(array $candidatos): ?string
    {
        foreach ($candidatos as $candidato) {
            $valor = trim($candidato);
            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }
}
