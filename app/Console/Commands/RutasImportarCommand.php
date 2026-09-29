<?php

namespace App\Console\Commands;

use App\Enums\FuncionPersonalRuta;
use App\Models\ClienteSucursal;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga las rutas exportadas con `rutas:exportar`.
 *
 * Por defecto NO escribe nada: muestra qué haría. Con `--aplicar` lo hace, todo en una
 * transacción. Pensado para producción, así que es conservador:
 *
 *   · una sala se asigna solo si su id existe Y su nombre coincide con el exportado; si
 *     no, se busca por nombre exacto y único; si tampoco, se informa y no se toca;
 *   · un lugar que ya cubre OTRA ruta no se le quita: se informa;
 *   · las rutas y vendedores existentes se reutilizan por nombre, no se duplican.
 *
 * No borra nada: ni rutas, ni lugares, ni asignaciones que el archivo no mencione.
 */
class RutasImportarCommand extends Command
{
    protected $signature = 'rutas:importar
        {archivo=storage/app/rutas-exportadas.json : JSON generado por rutas:exportar}
        {--aplicar : Escribe los cambios (sin esto solo muestra lo que haría)}';

    protected $description = 'Importa rutas exportadas con rutas:exportar (por defecto, solo muestra lo que haría)';

    /** @var array<int, string> */
    private array $avisos = [];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');
        $origen = str_starts_with($ruta, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $ruta) ? $ruta : base_path($ruta);

        if (! is_file($origen)) {
            $this->error("No existe el archivo {$origen}.");

            return self::FAILURE;
        }

        $datos = json_decode((string) file_get_contents($origen), true);
        if (! is_array($datos) || ($datos['version'] ?? null) !== 1) {
            $this->error('El archivo no es una exportación de rutas válida (versión 1).');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $resumen = ['rutas' => 0, 'lugares' => 0, 'salas' => 0, 'vendedores' => 0];

        // Una sola transacción: con --aplicar se confirma; sin él se deshace (simulación).
        DB::beginTransaction();
        try {
            foreach ($datos['rutas'] as $def) {
                $this->importarRuta($def, $resumen);
            }
            foreach ($datos['vendedores'] ?? [] as $def) {
                $this->importarVendedor($def, $resumen);
            }
            $aplicar ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->table(['Rutas', 'Lugares', 'Salas', 'Vendedores'], [[$resumen['rutas'], $resumen['lugares'], $resumen['salas'], $resumen['vendedores']]]);

        foreach ($this->avisos as $aviso) {
            $this->warn($aviso);
        }

        $aplicar
            ? $this->info('Rutas importadas.')
            : $this->info('Simulación: no se escribió nada. Volvé a correrlo con --aplicar para cargarlo.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, int>  $resumen
     */
    private function importarRuta(array $def, array &$resumen): void
    {
        $ruta = Ruta::firstOrNew(['nombre' => $def['nombre']]);
        $ruta->activa = (bool) ($def['activa'] ?? true);
        $ruta->frecuencia_objetivo_dias = $def['frecuencia_objetivo_dias'] ?? null;
        $ruta->save();
        $resumen['rutas']++;

        foreach ($def['coberturas'] ?? [] as $lugar) {
            $this->importarLugar($ruta, $lugar, $resumen);
        }

        foreach ($def['salas'] ?? [] as $sala) {
            $encontrada = $this->sala($sala);

            if ($encontrada === null) {
                $this->avisos[] = "«{$def['nombre']}»: no se encontró la sala «{$sala['nombre']}» (id {$sala['id']}). Asignala a mano.";

                continue;
            }

            if ($encontrada->ruta_id !== $ruta->id) {
                $encontrada->update(['ruta_id' => $ruta->id]);
            }
            $resumen['salas']++;
        }
    }

    /**
     * @param  array<string, string|null>  $lugar
     * @param  array<string, int>  $resumen
     */
    private function importarLugar(Ruta $ruta, array $lugar, array &$resumen): void
    {
        $departamento = Departamento::where('nombre', $lugar['departamento'] ?? '')->first();

        if ($departamento === null) {
            $this->avisos[] = "«{$ruta->nombre}»: no existe el departamento «".($lugar['departamento'] ?? '—').'».';

            return;
        }

        if (isset($lugar['distrito'])) {
            $distrito = Distrito::where('departamento_id', $departamento->id)->where('nombre', $lugar['distrito'])->first();
            if ($distrito === null) {
                $this->avisos[] = "«{$ruta->nombre}»: no existe el distrito «{$lugar['distrito']}» en {$departamento->nombre}.";

                return;
            }
            $campo = 'distrito_id';
            $id = $distrito->id;
        } else {
            $campo = 'departamento_id';
            $id = $departamento->id;
        }

        $existente = RutaCobertura::with('ruta:id,nombre')->where($campo, $id)->first();

        if ($existente !== null && $existente->ruta_id !== $ruta->id) {
            $this->avisos[] = "«{$ruta->nombre}»: ".($lugar['distrito'] ?? $departamento->nombre)." ya lo cubre «{$existente->ruta->nombre}»; no se movió.";

            return;
        }

        if ($existente === null) {
            $ruta->coberturas()->create([$campo => $id]);
        }
        $resumen['lugares']++;
    }

    /** @param  array{id: int, nombre: string}  $sala */
    private function sala(array $sala): ?ClienteSucursal
    {
        $porId = ClienteSucursal::find($sala['id']);
        if ($porId !== null && $porId->nombre === $sala['nombre']) {
            return $porId;
        }

        $porNombre = ClienteSucursal::where('nombre', $sala['nombre'])->get();

        return $porNombre->count() === 1 ? $porNombre->first() : null;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, int>  $resumen
     */
    private function importarVendedor(array $def, array &$resumen): void
    {
        $persona = PersonalRuta::firstOrCreate(
            ['nombre' => $def['nombre']],
            ['telefono' => $def['telefono'] ?? null, 'activo' => (bool) ($def['activo'] ?? true)],
        );

        foreach ($def['funciones'] ?? [] as $funcion) {
            if (FuncionPersonalRuta::tryFrom($funcion) !== null) {
                $persona->funciones()->firstOrCreate(['funcion' => $funcion]);
            }
        }
        $resumen['vendedores']++;
    }
}
