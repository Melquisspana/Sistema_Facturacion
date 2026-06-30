<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Support\Sala;
use Illuminate\Console\Command;

/**
 * Asigna manualmente el código de sala de Calleja (4 dígitos, ej. 0230) a una
 * sucursal existente. Para carga masiva, usar la importación CSV de salas
 * (Administración → Importaciones) que ahora admite la columna "Código de sala".
 */
class PpqAsignarSalaCodigo extends Command
{
    protected $signature = 'ppq:sala-codigo
        {codigo : Código de sala de 4 dígitos (ej. 0230)}
        {--id= : ID de la sucursal a actualizar}
        {--buscar= : Texto para localizar la sucursal por nombre}
        {--cliente= : ID del cliente (por defecto el de config ppq.cliente_default_id / Calleja)}';

    protected $description = 'Asigna el código de sala (0230) a una sucursal de cliente';

    public function handle(): int
    {
        $codigo = Sala::normalizar($this->argument('codigo'));
        if ($codigo === null || ! preg_match('/^\d{4}$/', $codigo)) {
            $this->error('El código debe ser de 1 a 4 dígitos (se rellena a 4, ej. 230 → 0230).');

            return self::FAILURE;
        }

        $clienteId = $this->resolverClienteId();
        if ($clienteId === null) {
            $this->error('No se pudo resolver el cliente. Pasá --cliente=ID o configurá ppq.cliente_default_id.');

            return self::FAILURE;
        }

        $base = ClienteSucursal::where('cliente_id', $clienteId);

        if ($id = $this->option('id')) {
            $sucursal = (clone $base)->find($id);
            if (! $sucursal) {
                $this->error("No existe la sucursal #{$id} para ese cliente.");

                return self::FAILURE;
            }

            return $this->asignar($sucursal, $codigo);
        }

        if ($texto = $this->option('buscar')) {
            $coincidencias = (clone $base)->where('nombre', 'like', '%'.$texto.'%')->orderBy('nombre')->get(['id', 'codigo', 'nombre']);

            if ($coincidencias->isEmpty()) {
                $this->warn("Ninguna sucursal del cliente {$clienteId} coincide con \"{$texto}\".");

                return self::FAILURE;
            }
            if ($coincidencias->count() === 1) {
                return $this->asignar(ClienteSucursal::find($coincidencias->first()->id), $codigo);
            }

            $this->info($coincidencias->count().' coincidencias — volvé a correr con --id=<ID>:');
            $this->table(['ID', 'Código', 'Nombre'], $coincidencias->map(fn ($s) => [$s->id, $s->codigo ?? '—', $s->nombre])->all());

            return self::SUCCESS;
        }

        $this->error('Indicá la sucursal con --id=<ID> o --buscar=<texto>.');

        return self::FAILURE;
    }

    private function asignar(ClienteSucursal $sucursal, string $codigo): int
    {
        $anterior = $sucursal->codigo;
        $sucursal->update(['codigo' => $codigo]);

        $this->info("✓ Sala {$codigo} asignada a «{$sucursal->nombre}» (sucursal #{$sucursal->id})"
            .($anterior ? " — reemplazó el código anterior «{$anterior}»" : ''));

        return self::SUCCESS;
    }

    private function resolverClienteId(): ?int
    {
        if ($id = $this->option('cliente')) {
            return (int) $id;
        }
        if ($id = config('ppq.cliente_default_id')) {
            return (int) $id;
        }

        return Cliente::where('nombre', 'like', '%Calleja%')->value('id');
    }
}
