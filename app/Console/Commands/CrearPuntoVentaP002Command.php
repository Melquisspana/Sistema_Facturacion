<?php

namespace App\Console\Commands;

use App\Models\Correlativo;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea el punto de venta P002 (sistema nuevo, serie exclusiva e independiente de
 * Conta Portable en P001) bajo un establecimiento existente, y sus 4 correlativos
 * (tipos 01/03/05/11, ultimo_numero=0) de UN SOLO ambiente por corrida.
 *
 * El ambiente es OBLIGATORIO (--ambiente=00 o --ambiente=01) y cada uno se activa por
 * separado a propósito: primero se prepara 00 (pruebas/APITEST) y validado ahí, recién
 * después se prepara 01 (producción) — nunca los dos en la misma corrida, para poder
 * frenar entre una fase y la otra.
 *
 * IDEMPOTENTE: usa firstOrCreate (guardado por la combinación única de cada tabla),
 * así que correr el comando de nuevo (mismo ambiente o el otro) no duplica nada ni
 * pisa un punto de venta o correlativo que ya exista.
 *
 * SEGURIDAD:
 *  - Por defecto corre en DRY-RUN: solo reporta qué haría falta crear, sin escribir.
 *    Aplicar de verdad exige --apply.
 *  - Solo toca los 4 correlativos del ambiente pedido; el otro ambiente queda intacto.
 *  - NUNCA toca P001 ni sus correlativos, ni la tabla `dtes`. No emite, firma,
 *    transmite ni envía correos. No abre candados ni cambia .env.
 */
class CrearPuntoVentaP002Command extends Command
{
    protected $signature = 'dte:crear-punto-venta-p002
        {--estab=M001 : Código del establecimiento (debe existir y estar activo)}
        {--codigo=P002 : Código del punto de venta a crear}
        {--nombre= : Nombre del punto de venta (default: "Sistema nuevo")}
        {--ambiente= : OBLIGATORIO. Ambiente a preparar: 00 (pruebas) o 01 (producción)}
        {--apply : Aplica los cambios de verdad (por defecto, dry-run)}';

    protected $description = 'Crea el punto de venta P002 y sus 4 correlativos de un ambiente (00 o 01, obligatorio; idempotente; dry-run por defecto)';

    private const TIPOS = ['01', '03', '05', '11'];

    private const AMBIENTES_VALIDOS = ['00', '01'];

    public function handle(): int
    {
        $ambiente = (string) $this->option('ambiente');
        if (! in_array($ambiente, self::AMBIENTES_VALIDOS, true)) {
            $this->error('Debés indicar --ambiente=00 (pruebas) o --ambiente=01 (producción). Recibido: '.($ambiente === '' ? '(vacío)' : $ambiente).'. Abortado, no se modificó nada.');

            return self::FAILURE;
        }

        $estabCodigo = strtoupper((string) $this->option('estab'));
        $codigo = strtoupper((string) $this->option('codigo'));
        $nombre = (string) ($this->option('nombre') ?: 'Sistema nuevo');

        $estab = Establecimiento::where('codigo', $estabCodigo)->where('activo', true)->first();
        if ($estab === null) {
            $this->error("No se encontró un establecimiento ACTIVO con código {$estabCodigo}. Abortado.");

            return self::FAILURE;
        }

        $pvExistente = PuntoVenta::where('establecimiento_id', $estab->id)->where('codigo', $codigo)->first();
        $ambienteLabel = $ambiente === '00' ? '00 (pruebas)' : '01 (producción)';

        $this->line("Punto de venta {$codigo} bajo establecimiento {$estabCodigo} (id {$estab->id}) — ambiente {$ambienteLabel}");
        $this->newLine();
        $this->dato('Punto de venta', $pvExistente
            ? "ya existe (id {$pvExistente->id}, activo=".($pvExistente->activo ? 'sí' : 'no').')'
            : 'se crearía nuevo, activo=sí');

        [$existentes, $faltantes] = $this->diagnosticoCorrelativos($estab->id, $pvExistente?->id, $ambiente);
        $this->dato('Correlativos ya existentes ('.$ambiente.')', $existentes ?: 'ninguno');
        $this->dato('Correlativos a crear ('.$ambiente.')', $faltantes ?: 'ninguno (todo al día)');
        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn('DRY RUN: no se escribió nada. Repetí el comando con --apply para crear de verdad.');

            return self::SUCCESS;
        }

        $dtesAntes = (int) DB::table('dtes')->count();
        $p001UltimoAntes = $this->snapshotP001($estab->id);
        $otroAmbiente = $ambiente === '00' ? '01' : '00';
        $otroAmbienteAntes = $pvExistente ? $this->snapshotAmbiente($estab->id, $pvExistente->id, $otroAmbiente) : 0;

        DB::transaction(function () use ($estab, $codigo, $nombre, $ambiente) {
            $pv = PuntoVenta::firstOrCreate(
                ['establecimiento_id' => $estab->id, 'codigo' => $codigo],
                ['nombre' => $nombre, 'activo' => true]
            );

            foreach (self::TIPOS as $tipo) {
                Correlativo::firstOrCreate(
                    [
                        'tipo_dte' => $tipo,
                        'establecimiento_id' => $estab->id,
                        'punto_venta_id' => $pv->id,
                        'ambiente' => $ambiente,
                    ],
                    ['serie' => null, 'ultimo_numero' => 0, 'activo' => true]
                );
            }
        });

        activity('punto_venta')
            ->withProperties(['establecimiento' => $estabCodigo, 'punto_venta' => $codigo, 'ambiente' => $ambiente, 'origen' => 'dte:crear-punto-venta-p002'])
            ->log("Creó/confirmó el punto de venta {$codigo} y sus correlativos de ambiente {$ambiente} bajo {$estabCodigo}");

        $pv = PuntoVenta::where('establecimiento_id', $estab->id)->where('codigo', $codigo)->firstOrFail();
        $totalAmbiente = Correlativo::where('establecimiento_id', $estab->id)->where('punto_venta_id', $pv->id)->where('ambiente', $ambiente)->count();
        $dtesDespues = (int) DB::table('dtes')->count();
        $p001UltimoDespues = $this->snapshotP001($estab->id);
        $otroAmbienteDespues = $this->snapshotAmbiente($estab->id, $pv->id, $otroAmbiente);

        $this->newLine();
        $this->info('APLICADO.');
        $this->dato('Punto de venta', "{$codigo} (id {$pv->id}), activo=".($pv->activo ? 'sí' : 'no'));
        $this->dato('Correlativos de este ambiente', (string) $totalAmbiente.' (esperado 4)');
        $this->dato('Ambiente '.$otroAmbiente.' de P002 intacto', $otroAmbienteAntes === $otroAmbienteDespues ? 'sí ('.$otroAmbienteDespues.' filas)' : 'NO — REVISAR');
        $this->dato('Documentos DTE intactos', $dtesAntes === $dtesDespues ? "sí ({$dtesDespues})" : 'NO — REVISAR');
        $this->dato('Correlativos P001 intactos', $p001UltimoAntes === $p001UltimoDespues ? 'sí' : 'NO — REVISAR');
        $this->newLine();
        $this->warn("*** Solo se tocó el ambiente {$ambiente} de P002. P001 y el otro ambiente quedaron intactos. No se emitió/firmó/transmitió nada. ***");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} lista "tipo" existentes y faltantes del ambiente pedido, como texto. */
    private function diagnosticoCorrelativos(int $establecimientoId, ?int $puntoVentaId, string $ambiente): array
    {
        $existentes = [];
        $faltantes = [];

        foreach (self::TIPOS as $tipo) {
            $existe = $puntoVentaId !== null && Correlativo::where('tipo_dte', $tipo)
                ->where('establecimiento_id', $establecimientoId)
                ->where('punto_venta_id', $puntoVentaId)
                ->where('ambiente', $ambiente)
                ->exists();

            if ($existe) {
                $existentes[] = $tipo;
            } else {
                $faltantes[] = $tipo;
            }
        }

        return [implode(', ', $existentes), implode(', ', $faltantes)];
    }

    /** Cantidad de filas de correlativo de P002 en el ambiente indicado (guarda de "no tocar el otro ambiente"). */
    private function snapshotAmbiente(int $establecimientoId, int $puntoVentaId, string $ambiente): int
    {
        return Correlativo::where('establecimiento_id', $establecimientoId)
            ->where('punto_venta_id', $puntoVentaId)
            ->where('ambiente', $ambiente)
            ->count();
    }

    /** Snapshot de guarda: suma de ultimo_numero de TODOS los correlativos P001 del establecimiento. */
    private function snapshotP001(int $establecimientoId): int
    {
        return (int) Correlativo::query()
            ->where('establecimiento_id', $establecimientoId)
            ->whereHas('puntoVenta', fn ($q) => $q->where('codigo', 'P001'))
            ->sum('ultimo_numero');
    }

    private function dato(string $etiqueta, string $valor): void
    {
        $this->line('  '.str_pad($etiqueta, 34).' : '.$valor);
    }
}
