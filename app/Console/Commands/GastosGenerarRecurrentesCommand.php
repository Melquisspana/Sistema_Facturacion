<?php

namespace App\Console\Commands;

use App\Models\Gastos\Regla;
use App\Services\Gastos\InstalacionGastos;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Genera las obligaciones que les tocan a las reglas recurrentes.
 *
 * DRY-RUN POR DEFECTO. Sin `--aplicar` no escribe nada: dice qué crearía y se va.
 * Es la misma decisión que en `compras:sincronizar` y `ppq:sincronizar-albaranes`, y
 * acá pesa más todavía porque lo que este comando escribe son DEUDAS. Una corrida
 * manual a ciegas no puede inventar un mes de alquiler.
 *
 * Con `--aplicar` exige además el interruptor `gastos.recurrencias.generacion_automatica`,
 * para que una invocación accidental —o un cron que alguien copió de otro servidor—
 * no empiece a crear obligaciones en una máquina donde nadie lo decidió.
 *
 * Es idempotente: repetirlo no duplica. El índice único por regla y período es lo que
 * lo garantiza, no una comprobación de este comando.
 */
class GastosGenerarRecurrentesCommand extends Command
{
    protected $signature = 'gastos:generar-recurrentes
                            {--aplicar : Escribe de verdad. Sin esto es una simulación que no toca la base.}
                            {--hoy= : Fecha a considerar como hoy (Y-m-d). Para probar sin esperar al día.}
                            {--regla= : Id de una sola regla, en vez de todas las activas.}';

    protected $description = 'Crea las obligaciones de las reglas recurrentes que ya deben existir (dry-run sin --aplicar)';

    public function handle(GenerarObligaciones $generador, CalendarioRecurrencia $calendario): int
    {
        if (! config('gastos.enabled')) {
            $this->error('El módulo Gastos está apagado (GASTOS_ENABLED=false). No se genera nada.');

            return self::FAILURE;
        }

        if (! app(InstalacionGastos::class)->fase2Instalada()) {
            $this->error(app(InstalacionGastos::class)->motivoNoInstalada());

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');

        if ($aplicar && ! config('gastos.recurrencias.generacion_automatica')) {
            $this->error('La generación automática está apagada (GASTOS_RECURRENCIAS_AUTO=false).');
            $this->line('Este comando CREA DEUDA: no se aplica sin que alguien lo haya encendido a propósito.');
            $this->line('Para revisar qué haría, corrélo sin --aplicar.');

            return self::FAILURE;
        }

        $hoy = $this->option('hoy')
            ? CarbonImmutable::parse($this->option('hoy'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $reglas = Regla::where('estado', 'activa')
            ->when($this->option('regla'), fn ($q) => $q->whereKey($this->option('regla')))
            ->orderBy('id')
            ->get();

        if ($reglas->isEmpty()) {
            $this->info('No hay reglas activas. Nada que generar.');

            return self::SUCCESS;
        }

        $this->line(($aplicar ? 'APLICANDO' : 'SIMULACIÓN (sin --aplicar no se escribe nada)')
            .' · hoy = '.$hoy->toDateString().' · '.$reglas->count().' regla(s) activa(s)');
        $this->newLine();

        $total = 0;
        $pendientesDeRevisar = 0;

        foreach ($reglas as $regla) {
            if (! $aplicar) {
                [$cuantas, $fuera] = $this->simular($regla, $hoy, $calendario);
                $total += $cuantas;
                $pendientesDeRevisar += $fuera;

                continue;
            }

            $resultado = $generador->paraRegla($regla, $hoy);
            $total += $resultado['generadas'];
            $pendientesDeRevisar += count($resultado['fuera_de_ventana']);

            if ($resultado['generadas'] > 0) {
                $this->info('#'.$regla->id.' '.$regla->nombre.': '.$resultado['generadas']
                    .' obligación(es) — '.implode(', ', $resultado['periodos']));
            }

            $this->avisarFueraDeVentana($regla, $resultado['fuera_de_ventana']);
        }

        $this->newLine();
        $this->line($aplicar
            ? 'Listo: '.$total.' obligación(es) creada(s). Todas nacen IMPAGAS.'
            : 'Simulación: se crearían '.$total.' obligación(es). Ninguna se escribió.');

        if ($pendientesDeRevisar > 0) {
            $this->warn($pendientesDeRevisar.' período(s) quedaron FUERA de la ventana de recuperación y no se generan solos.');
            $this->line('Revisalos en la pantalla de cada regla y generalos a mano si corresponden.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function simular(Regla $regla, CarbonImmutable $hoy, CalendarioRecurrencia $calendario): array
    {
        $ventana = (int) config('gastos.recurrencias.ventana_recuperacion_dias', 62);
        $hasta = $hoy->addDays((int) $regla->dias_generar_antes);

        if ($regla->vigente_hasta !== null) {
            $fin = CarbonImmutable::parse($regla->vigente_hasta->toDateString());
            $hasta = $hasta->gt($fin) ? $fin : $hasta;
        }

        $inicio = CarbonImmutable::parse($regla->vigente_desde->toDateString());
        $limite = $hoy->subDays($ventana);
        $desde = $inicio->gt($limite) ? $inicio : $limite;

        $resueltos = $regla->ocurrencias()->pluck('periodo')->all();
        $nuevos = [];

        foreach ($calendario->periodos($regla->calendario(), $desde, $hasta) as $periodo) {
            if (! in_array($periodo['periodo'], $resueltos, true)) {
                $nuevos[] = $periodo['periodo'].' (vence '.$periodo['vence']->toDateString().')';
            }
        }

        if ($nuevos !== []) {
            $this->line('#'.$regla->id.' '.$regla->nombre.': crearía '.count($nuevos).' — '.implode(', ', $nuevos));
        }

        $fuera = [];
        if ($inicio->lt($limite)) {
            foreach ($calendario->periodos($regla->calendario(), $inicio, $limite->subDay()) as $viejo) {
                if (! in_array($viejo['periodo'], $resueltos, true)) {
                    $fuera[] = $viejo['periodo'];
                }
            }
        }

        $this->avisarFueraDeVentana($regla, $fuera);

        return [count($nuevos), count($fuera)];
    }

    /** @param  array<int, string>  $periodos */
    private function avisarFueraDeVentana(Regla $regla, array $periodos): void
    {
        if ($periodos === []) {
            return;
        }

        $this->warn('#'.$regla->id.' '.$regla->nombre.': '.count($periodos)
            .' período(s) anteriores a la ventana, SIN generar — '
            .implode(', ', array_slice($periodos, 0, 12))
            .(count($periodos) > 12 ? ' …' : ''));
    }
}
