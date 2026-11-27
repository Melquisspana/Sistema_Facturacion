<?php

namespace App\Services\Importacion;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use App\Support\Ubicacion\CoherenciaUbicacion;
use App\Support\Ubicacion\UbicacionCoherenteFactory;
use Illuminate\Support\Str;

/**
 * Resuelve departamento, municipio y distrito (CAT-008) para la importación de salas,
 * con normalización de texto y un mapa de EQUIVALENCIAS para los nombres "nuevos" que el
 * catálogo de municipios no tiene (ej. "San Salvador Centro" → "San Salvador").
 *
 * No bloquea la importación: si no hay coincidencia segura, devuelve una
 * advertencia y la sala se crea igual sin municipio o sin distrito.
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

    /** @var array<int, array{modelo: Distrito, norm: string, municipio: string}>|null */
    private ?array $distritos = null;

    /**
     * El trío que devuelve es siempre coherente ({@see CoherenciaUbicacion}): si el
     * distrito no encaja, vuelve sin distrito y con una advertencia que lo explica.
     *
     * @return array{
     *   departamentoId: int|null,
     *   municipioId: int|null,
     *   distritoId: int|null,
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
        $infoEquivalencia = null;
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
                    $infoEquivalencia = "Municipio mapeado por equivalencia: {$c['texto']} -> {$encontrado['nombre']}";
                }
                break;
            }
        }

        // Si la fila nombra tal cual un municipio 2024 («Cabañas Este»), esa agrupación manda
        // sobre lo de arriba, que solo conoce los nombres anteriores a 2024: si no, un
        // distrito de otra agrupación arrastraba su municipio y el choque no se veía.
        $agrupacion = $this->llena($municipio) ? $this->distritoDeAgrupacion($municipio, $departamentoId) : null;
        if ($agrupacion !== null && ! $agrupacion->perteneceAMunicipio($municipioId ? Municipio::find($municipioId) : null)) {
            $representante = UbicacionCoherenteFactory::municipioDe($agrupacion);
            if ($representante !== null) {
                $municipioId = (int) $representante->id;
                $infoEquivalencia = null;
            }
        }
        if ($infoEquivalencia !== null) {
            $infos[] = $infoEquivalencia;
        }

        // Distrito (CAT-008), que el CCF v4 exige en la sala. Si la fila no lo trae se
        // prueba con el municipio: muchos municipios anteriores a 2024 hoy son distritos
        // con el mismo nombre (p. ej. «Soyapango»).
        $distritoId = null;
        $textoDistrito = $this->llena($distrito) ? $distrito : $municipio;
        $coinciden = $this->llena($textoDistrito) ? $this->buscarDistritos($textoDistrito, $departamentoId) : [];
        $encontrado = count($coinciden) === 1 ? $coinciden[0] : null;
        if (count($coinciden) > 1) {
            $advertencias[] = "Distrito ambiguo: «{$textoDistrito}» existe en más de un departamento; indicá el departamento";
        } elseif ($encontrado === null && $this->llena($distrito)) {
            $advertencias[] = $departamentoId
                ? "Distrito «{$distrito}» no está en el departamento indicado"
                : "Distrito no encontrado: {$distrito}";
        }
        if ($encontrado !== null) {
            // Lo que falte sale del distrito; el trío se guarda solo si es coherente.
            $depto = $departamentoId ?? (int) $encontrado->departamento_id;
            $muni = $municipioId ?? UbicacionCoherenteFactory::municipioDe($encontrado)?->id;
            $problema = CoherenciaUbicacion::problema($depto, $muni, (int) $encontrado->id);
            if ($problema !== null) {
                $advertencias[] = "Distrito no enlazado: {$problema}";
            } else {
                if ($municipioId === null && $muni !== null) {
                    $infos[] = "Municipio tomado del distrito: {$encontrado->nombre}";
                }
                [$departamentoId, $municipioId, $distritoId] = [$depto, $muni, (int) $encontrado->id];
            }
        }

        if ($municipioId === null) {
            $textoMuni = $this->llena($municipio) ? $municipio : $distrito;
            if ($this->llena($textoMuni)) {
                $advertencias[] = "Municipio no encontrado: {$textoMuni}";
            }
        }

        return compact('departamentoId', 'municipioId', 'distritoId', 'infos', 'advertencias');
    }

    /**
     * Distritos activos con ese nombre, dentro del departamento si se conoce o en todo el
     * país si no. Solo uno sirve: varios es un nombre ambiguo (p. ej. «San Isidro»).
     *
     * @return array<int, Distrito>
     */
    private function buscarDistritos(string $nombre, ?int $departamentoId): array
    {
        $n = $this->normalizar($nombre);

        return array_values(array_map(
            fn (array $d) => $d['modelo'],
            array_filter(
                $this->distritos(),
                fn (array $d) => $d['norm'] === $n
                    && ($departamentoId === null || (int) $d['modelo']->departamento_id === $departamentoId),
            ),
        ));
    }

    /** Un distrito del municipio 2024 con ese nombre exacto (la agrupación), o null. */
    private function distritoDeAgrupacion(string $municipio, ?int $departamentoId): ?Distrito
    {
        $n = $this->normalizar($municipio);
        foreach ($this->distritos() as $d) {
            if ($d['municipio'] === $n && ($departamentoId === null || (int) $d['modelo']->departamento_id === $departamentoId)) {
                return $d['modelo'];
            }
        }

        return null;
    }

    /** @return array<int, array{modelo: Distrito, norm: string, municipio: string}> */
    private function distritos(): array
    {
        return $this->distritos ??= Distrito::query()
            ->where('activo', true)
            ->get(['id', 'nombre', 'departamento_id', 'municipio', 'municipio_codigo'])
            ->map(fn (Distrito $d) => [
                'modelo' => $d,
                'norm' => $this->normalizar($d->nombre),
                'municipio' => $this->normalizar((string) $d->municipio),
            ])
            ->all();
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

        // Dentro del departamento si se conoce; en todo el país solo si no. Tomar un
        // municipio de otro departamento dejaba la sala con un par incoherente.
        foreach ($this->municipios() as $m) {
            if ($m['norm'] === $n && ($departamentoId === null || $m['departamento_id'] === $departamentoId)) {
                return ['id' => $m['id'], 'nombre' => $m['nombre']];
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
