<?php

namespace App\Services\Importacion;

use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Support\Str;

/**
 * Resuelve departamento/municipio para la importación de salas, con normalización
 * de texto y un mapa de EQUIVALENCIAS para los nombres "nuevos" (distritos) que el
 * catálogo oficial todavía no tiene (ej. "San Salvador Centro" → "San Salvador").
 *
 * No bloquea la importación: si no hay coincidencia segura, devuelve una
 * advertencia y la sala se crea igual sin municipio.
 */
class ResolvedorUbicacionImportacion
{
    /** Equivalencias (clave normalizada → municipio real del catálogo). */
    private const EQUIVALENCIAS = [
        'san salvador centro' => 'San Salvador',
        'san salvador este' => 'Soyapango',
        'san salvador oeste' => 'Apopa',
        'sonsonate centro' => 'Sonsonate',
        'santa ana centro' => 'Santa Ana',
        'san miguel centro' => 'San Miguel',
        'la libertad sur' => 'Santa Tecla',
        'la libertad este' => 'Antiguo Cuscatlán',
        'la paz este' => 'Zacatecoluca',
    ];

    /** Sufijos regionales a quitar como último recurso. */
    private const SUFIJOS = ['centro', 'norte', 'sur', 'este', 'oeste', 'oriente', 'poniente'];

    /** @var array<int, array{id: int, norm: string, departamento_id: int}>|null */
    private ?array $municipios = null;

    /** @var array<int, array{id: int, norm: string}>|null */
    private ?array $departamentos = null;

    /**
     * @return array{
     *   departamento_id: int|null,
     *   municipio_id: int|null,
     *   infos: array<int, string>,
     *   advertencias: array<int, string>
     * }
     */
    public function resolver(?string $distrito, ?string $municipio, ?string $departamento): array
    {
        $infos = [];
        $advertencias = [];

        // Departamento.
        $departamentoId = null;
        if ($this->llena($departamento)) {
            $departamentoId = $this->buscarDepartamento($departamento);
            if (! $departamentoId) {
                $advertencias[] = "Departamento no encontrado: {$departamento}";
            }
        }

        // Municipio, por prioridad: distrito → municipio → equiv(municipio) → equiv(distrito).
        $municipioId = null;
        $candidatos = [
            ['texto' => $distrito, 'equivalencia' => false],
            ['texto' => $municipio, 'equivalencia' => false],
            ['texto' => $municipio, 'equivalencia' => true],
            ['texto' => $distrito, 'equivalencia' => true],
        ];

        foreach ($candidatos as $c) {
            if (! $this->llena($c['texto'])) {
                continue;
            }

            $nombreBuscado = $c['equivalencia'] ? $this->equivalencia($c['texto']) : $c['texto'];
            if ($nombreBuscado === null) {
                continue;
            }

            $encontrado = $this->buscarMunicipio($nombreBuscado, $departamentoId);
            if ($encontrado !== null) {
                $municipioId = $encontrado['id'];
                if ($c['equivalencia']) {
                    $infos[] = "Municipio mapeado por equivalencia: {$c['texto']} -> {$encontrado['nombre']}";
                }
                break;
            }
        }

        if ($municipioId === null) {
            $textoMuni = $this->llena($municipio) ? $municipio : $distrito;
            if ($this->llena($textoMuni)) {
                $advertencias[] = "Municipio no encontrado: {$textoMuni}";
            }
        }

        return compact('departamentoId', 'municipioId', 'infos', 'advertencias');
    }

    private function buscarDepartamento(string $nombre): ?int
    {
        $n = $this->normalizar($nombre);
        foreach ($this->departamentos() as $d) {
            if ($d['norm'] === $n) {
                return $d['id'];
            }
        }

        return null;
    }

    /** @return array{id: int, nombre: string}|null */
    private function buscarMunicipio(string $nombre, ?int $departamentoId): ?array
    {
        $n = $this->normalizar($nombre);
        if ($n === '') {
            return null;
        }

        // Primero dentro del departamento (si se conoce), luego global.
        foreach ([$departamentoId, null] as $filtro) {
            foreach ($this->municipios() as $m) {
                if ($m['norm'] === $n && ($filtro === null || $m['departamento_id'] === $filtro)) {
                    return ['id' => $m['id'], 'nombre' => $m['nombre']];
                }
            }
        }

        return null;
    }

    /** Mapa de equivalencias + quitar sufijo regional como último recurso. */
    private function equivalencia(string $texto): ?string
    {
        $n = $this->normalizar($texto);

        if (isset(self::EQUIVALENCIAS[$n])) {
            return self::EQUIVALENCIAS[$n];
        }

        // "<base> centro|este|..." → intentar con la base.
        $patron = '/^(.*?)\s+('.implode('|', self::SUFIJOS).')$/';
        if (preg_match($patron, $n, $m) && trim($m[1]) !== '') {
            return trim($m[1]);
        }

        return null;
    }

    /** @return array<int, array{id: int, nombre: string, norm: string, departamento_id: int}> */
    private function municipios(): array
    {
        return $this->municipios ??= Municipio::query()
            ->get(['id', 'nombre', 'departamento_id'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'nombre' => $m->nombre,
                'norm' => $this->normalizar($m->nombre),
                'departamento_id' => $m->departamento_id,
            ])->all();
    }

    /** @return array<int, array{id: int, norm: string}> */
    private function departamentos(): array
    {
        return $this->departamentos ??= Departamento::query()
            ->get(['id', 'nombre'])
            ->map(fn ($d) => ['id' => $d->id, 'norm' => $this->normalizar($d->nombre)])
            ->all();
    }

    private function llena(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }

    /** trim, sin comas, sin dobles espacios, sin acentos, minúsculas. */
    private function normalizar(string $texto): string
    {
        $texto = Str::ascii($texto);
        $texto = str_replace(',', ' ', $texto);
        $texto = strtolower(trim($texto));

        return preg_replace('/\s+/', ' ', $texto);
    }
}
