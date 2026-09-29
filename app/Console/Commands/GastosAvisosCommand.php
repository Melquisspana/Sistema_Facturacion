<?php

namespace App\Console\Commands;

use App\Models\Gastos\PreferenciaAvisos;
use App\Models\User;
use App\Services\Gastos\Avisos\ArmarAvisos;
use App\Services\Gastos\Avisos\EnviarResumenes;
use App\Services\Gastos\InstalacionGastos;
use App\Support\Correo\CandadoCorreoReal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Arma la bandeja interna de avisos y manda los resúmenes que toquen.
 *
 * DRY-RUN POR DEFECTO, igual que la generación de recurrentes. Sin `--aplicar` no
 * escribe avisos ni toca el correo: cuenta a cuántas personas alcanzaría y con qué.
 *
 * NO INVENTA NADA. Los avisos salen del saldo real y los resúmenes se recalculan en
 * el momento del envío. Si no hay pendientes, no hay aviso y no hay correo: este
 * comando puede terminar sin hacer absolutamente nada y esa es una corrida normal,
 * no un fallo.
 *
 * Fuera de producción el correo se registra como SIMULADO y no sale del sistema,
 * aunque la preferencia de correo esté encendida. Es el mismo candado del correo
 * fiscal y del de compras.
 */
class GastosAvisosCommand extends Command
{
    protected $signature = 'gastos:avisos
                            {--aplicar : Escribe los avisos y manda los resúmenes. Sin esto es una simulación.}
                            {--hoy= : Fecha a considerar como hoy (Y-m-d).}
                            {--solo-bandeja : Arma la bandeja interna y no manda ningún resumen.}';

    protected $description = 'Arma la bandeja interna de avisos y envía los resúmenes configurados (dry-run sin --aplicar)';

    public function handle(ArmarAvisos $armar, EnviarResumenes $resumenes, CandadoCorreoReal $candado): int
    {
        if (! config('gastos.enabled')) {
            $this->error('El módulo Gastos está apagado (GASTOS_ENABLED=false). No se arma nada.');

            return self::FAILURE;
        }

        if (! app(InstalacionGastos::class)->fase2Instalada()) {
            $this->error(app(InstalacionGastos::class)->motivoNoInstalada());

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');

        if ($aplicar && ! config('gastos.avisos.automaticos')) {
            $this->error('Los avisos automáticos están apagados (GASTOS_AVISOS_AUTO=false).');
            $this->line('Para ver qué se armaría, corrélo sin --aplicar.');

            return self::FAILURE;
        }

        $hoy = $this->option('hoy')
            ? CarbonImmutable::parse($this->option('hoy'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $destinatarios = $armar->destinatarios();

        $this->line(($aplicar ? 'APLICANDO' : 'SIMULACIÓN (sin --aplicar no se escribe ni se envía nada)')
            .' · hoy = '.$hoy->toDateString().' · '.$destinatarios->count().' persona(s) con avisos activos');

        if ($candado->debeSimular()) {
            $this->warn($candado->avisoInterfaz());
        }

        $this->newLine();

        if (! $aplicar) {
            return $this->simular($destinatarios, $resumenes, $hoy);
        }

        $bandeja = $armar->armar($hoy);
        $this->info('Bandeja interna: '.$bandeja['avisos'].' aviso(s) nuevo(s) para '.$bandeja['usuarios'].' persona(s).');

        if ($bandeja['detalle'] !== []) {
            foreach ($bandeja['detalle'] as $tipo => $cuantos) {
                $this->line('  · '.$tipo.': '.$cuantos);
            }
        }

        if ($this->option('solo-bandeja')) {
            return self::SUCCESS;
        }

        $envio = $resumenes->enviar($hoy);

        $this->newLine();
        $this->info('Resúmenes: '.$envio['preparados'].' preparado(s) — '
            .$envio['enviados'].' enviado(s), '.$envio['simulados'].' simulado(s), '.$envio['fallidos'].' fallido(s).');
        $this->line('  · '.$envio['sin_pendientes'].' persona(s) sin pendientes: NO se les mandó nada, que es lo correcto.');
        $this->line('  · '.$envio['fuera_de_ventana'].' persona(s) cuyo resumen no toca hoy.');

        if ($envio['fallidos'] > 0) {
            $this->warn('Hay resúmenes fallidos. Revisá `gastos_resumenes` ANTES de reenviar: '
                .'un fallo después de que el servidor aceptó el mensaje puede significar que salió igual.');
        }

        return self::SUCCESS;
    }

    /** @param  Collection<int, array{usuario: User, preferencias: PreferenciaAvisos, ambitos: array<int, string>}>  $destinatarios */
    private function simular($destinatarios, EnviarResumenes $resumenes, CarbonImmutable $hoy): int
    {
        $conPendientes = 0;

        foreach ($destinatarios as ['usuario' => $usuario, 'preferencias' => $prefs, 'ambitos' => $ambitos]) {
            $contenido = $resumenes->contenido($ambitos, $hoy);

            if ($contenido['obligaciones'] === 0) {
                continue;
            }

            $conPendientes++;
            $ventana = $resumenes->ventana($prefs->resumen, (int) $prefs->resumen_dia_semana, $hoy);

            $this->line($usuario->name.': '.$contenido['obligaciones'].' pendiente(s) — '
                .count($contenido['vencidos']).' vencido(s), '.count($contenido['proximos']).' próximo(s), '
                .count($contenido['sin_monto']).' sin monto · resumen por correo: '
                .(! $prefs->correo ? 'apagado' : ($ventana === null ? 'hoy no toca' : 'SÍ ('.$ventana.')')));
        }

        $this->newLine();
        $this->line('Simulación: '.$conPendientes.' persona(s) con pendientes reales. Nada se escribió ni se envió.');

        return self::SUCCESS;
    }
}
