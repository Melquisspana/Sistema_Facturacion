<?php

namespace App\Console\Commands;

use App\Enums\TipoCliente;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Distrito;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Support\Ubicacion\CoherenciaUbicacion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * AUDITA la ubicación fiscal (departamento → municipio 2024 → distrito) de todo lo que
 * viaja en el JSON del MH: empresa emisora, establecimientos, clientes y salas.
 *
 * Es de SOLO LECTURA por defecto: informa y no escribe nada. Sirve para medir el daño
 * antes de tocar datos, en desarrollo y en producción (que puede diferir).
 *
 * Detecta:
 *   - distrito ausente (el JSON saldría con `distrito: ""` y el MH lo rechaza);
 *   - municipio ausente;
 *   - distrito de otro departamento;
 *   - municipio de otro departamento;
 *   - municipio INCOMPATIBLE con el distrito (la causa de
 *     «[receptor.direccion.distrito] VALOR NO ES PERMITIDO»);
 *   - distritos del catálogo sin vínculo a su municipio 2024.
 *
 * Con --aplicar corrige ÚNICAMENTE lo INEQUÍVOCO: cuando el distrito está bien y el
 * municipio elegido no le corresponde, reasigna el municipio a la agrupación del distrito
 * (existe una sola). Nunca adivina un distrito faltante ni toca DTE emitidos o JSON
 * históricos.
 */
class AuditarUbicaciones extends Command
{
    protected $signature = 'ubicaciones:auditar
        {--dry-run : Explícitamente sin escribir (comportamiento por defecto)}
        {--aplicar : Aplica SOLO las correcciones inequívocas (reasignar el municipio del distrito)}
        {--todos : Incluye también entidades inactivas o eliminadas}';

    protected $description = 'Audita la coherencia departamento → municipio 2024 → distrito de empresa, establecimientos, clientes y salas. Solo lectura salvo --aplicar.';

    /** @var array<int, array<string, mixed>> */
    private array $hallazgos = [];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $todos = (bool) $this->option('todos');

        if ($aplicar && $this->option('dry-run')) {
            $this->error('No se puede usar --aplicar junto con --dry-run.');

            return self::FAILURE;
        }

        $this->line('Auditoría de ubicaciones fiscales (departamento → municipio 2024 → distrito)');
        $this->line($aplicar
            ? '  MODO: aplicar correcciones INEQUÍVOCAS'
            : '  MODO: solo lectura (no se escribe nada)');
        $this->newLine();

        $this->auditarCatalogo();

        $this->auditarEntidades('Empresa emisora', Empresa::query()->get(), true);
        $this->auditarEntidades('Establecimiento', Establecimiento::query()->get(), true);
        $this->auditarEntidades(
            'Cliente',
            Cliente::query()
                ->when($todos, fn ($q) => $q->withTrashed(), fn ($q) => $q->where('activo', true))
                // Solo clientes nacionales: en exportación el receptor no lleva ubicación
                // territorial salvadoreña (va país + complemento).
                ->where('tipo_cliente', '!=', TipoCliente::Exportacion->value)
                ->get(),
            false
        );
        $this->auditarEntidades(
            'Sala de cliente',
            ClienteSucursal::query()
                ->when($todos, fn ($q) => $q->withTrashed(), fn ($q) => $q->where('activo', true))
                ->get(),
            true
        );

        if ($this->hallazgos === []) {
            $this->info('✔ Sin inconsistencias de ubicación.');

            return self::SUCCESS;
        }

        $this->mostrar();

        if ($aplicar) {
            $this->aplicarCorrecciones();
        } else {
            $inequivocos = count(array_filter($this->hallazgos, fn ($h) => $h['municipio_sugerido'] !== null));
            $this->newLine();
            $this->comment("Correcciones inequívocas disponibles: {$inequivocos}. "
                .'Para aplicarlas: php artisan ubicaciones:auditar --aplicar');
            if ($inequivocos < count($this->hallazgos)) {
                $this->comment('El resto requiere decisión manual (p. ej. falta el distrito: hay que elegirlo).');
            }
        }

        // Código de salida 1: hubo hallazgos (útil para revisiones automatizadas).
        return $aplicar ? self::SUCCESS : self::FAILURE;
    }

    /** Distritos del catálogo sin vínculo a su municipio 2024. */
    private function auditarCatalogo(): void
    {
        $sinVinculo = Distrito::whereNull('municipio_codigo')->count();
        $total = Distrito::count();

        if ($sinVinculo > 0) {
            $this->warn("Catálogo: {$sinVinculo} de {$total} distritos NO tienen municipio 2024 vinculado.");
            $this->line('  Sin ese vínculo no se puede validar la coherencia municipio ↔ distrito.');
            $this->line('  Corregir con: php artisan distritos:vincular-municipio');
            $this->newLine();

            return;
        }

        $this->line("Catálogo: {$total} distritos, todos vinculados a su municipio 2024. ✔");
        $this->newLine();
    }

    /**
     * @param  Collection<int, Model>  $entidades
     * @param  bool  $exigeDistrito  si la entidad debe tener distrito para poder facturar
     */
    private function auditarEntidades(string $tipo, $entidades, bool $exigeDistrito): void
    {
        foreach ($entidades as $entidad) {
            $problemas = [];

            if (blank($entidad->departamento_id)) {
                $problemas[] = 'sin departamento';
            }
            if (blank($entidad->municipio_id)) {
                $problemas[] = 'sin municipio';
            }
            if ($exigeDistrito && blank($entidad->distrito_id)) {
                $problemas[] = 'sin distrito';
            }

            $incoherencia = CoherenciaUbicacion::problemaDe($entidad);
            if ($incoherencia !== null) {
                $problemas[] = $incoherencia;
            }

            if ($problemas === []) {
                continue;
            }

            $distrito = $entidad->distrito_id ? Distrito::find($entidad->distrito_id) : null;
            $municipio = $entidad->municipio_id ? Municipio::find($entidad->municipio_id) : null;

            $this->hallazgos[] = [
                'tipo' => $tipo,
                'modelo' => $entidad,
                'id' => $entidad->id,
                'nombre' => $entidad->nombre ?? $entidad->razon_social ?? ('#'.$entidad->id),
                'departamento' => $entidad->departamento?->nombre ?? '—',
                'municipio_actual' => $municipio ? $municipio->nombreFiscal().' ('.($municipio->codigo ?? 'sin código').')' : '—',
                'distrito_actual' => $distrito ? $distrito->nombre.' ('.($distrito->codigo ?? 'sin código').')' : '—',
                'problemas' => implode('; ', $problemas),
                // Sugerencia INEQUÍVOCA: el municipio de la agrupación del distrito.
                'municipio_sugerido' => $this->municipioSugerido($entidad, $distrito, $municipio),
            ];
        }
    }

    /**
     * Municipio correcto cuando el distrito es válido y el municipio no le corresponde.
     * Devuelve null si no hay una única respuesta obvia (p. ej. falta el distrito).
     */
    private function municipioSugerido(Model $entidad, ?Distrito $distrito, ?Municipio $municipio): ?Municipio
    {
        if (! $distrito || blank($distrito->municipio_codigo)) {
            return null; // sin distrito no se puede deducir nada
        }
        if ((int) $distrito->departamento_id !== (int) $entidad->departamento_id) {
            return null; // el distrito no es del departamento: decisión manual
        }
        if ($municipio && $distrito->perteneceAMunicipio($municipio)) {
            return null; // ya es coherente
        }

        $candidatos = Municipio::where('departamento_id', $distrito->departamento_id)
            ->where('codigo', $distrito->municipio_codigo)
            ->orderBy('id')
            ->get();

        // Todas las filas con ese (departamento, código) son la MISMA agrupación fiscal:
        // cualquiera sirve y se toma la primera de forma estable.
        return $candidatos->first();
    }

    private function mostrar(): void
    {
        $this->error('Se encontraron '.count($this->hallazgos).' ubicación(es) con problemas:');
        $this->newLine();

        $this->table(
            ['Tipo', 'ID', 'Nombre', 'Departamento', 'Municipio actual', 'Distrito actual', 'Problema', 'Municipio sugerido'],
            array_map(fn ($h) => [
                $h['tipo'],
                $h['id'],
                Str::limit((string) $h['nombre'], 34),
                $h['departamento'],
                $h['municipio_actual'],
                $h['distrito_actual'],
                Str::limit($h['problemas'], 60),
                $h['municipio_sugerido']
                    ? $h['municipio_sugerido']->nombreFiscal().' ('.$h['municipio_sugerido']->codigo.')'
                    : '— manual —',
            ], $this->hallazgos)
        );
    }

    /** Reasigna el municipio SOLO donde la respuesta es única. No toca nada más. */
    private function aplicarCorrecciones(): void
    {
        $aplicados = 0;
        $omitidos = 0;

        foreach ($this->hallazgos as $h) {
            $sugerido = $h['municipio_sugerido'];
            if (! $sugerido) {
                $omitidos++;

                continue;
            }

            /** @var Model $entidad */
            $entidad = $h['modelo'];
            $anterior = $entidad->municipio_id;
            $entidad->municipio_id = $sugerido->id;
            $entidad->save();

            activity('ubicaciones_auditoria')
                ->performedOn($entidad)
                ->withProperties([
                    'municipio_anterior' => $anterior,
                    'municipio_nuevo' => $sugerido->id,
                    'municipio_nuevo_codigo' => $sugerido->codigo,
                    'distrito_id' => $entidad->distrito_id,
                    'motivo' => 'ubicaciones:auditar --aplicar (municipio incompatible con el distrito)',
                ])
                ->log('corrigió el municipio para que coincida con el distrito');

            $aplicados++;
        }

        $this->newLine();
        $this->info("Correcciones aplicadas: {$aplicados}. Requieren decisión manual: {$omitidos}.");
        $this->comment('No se modificó ningún DTE emitido ni ningún JSON histórico.');
    }
}
