<?php

namespace App\Console\Commands;

use App\Services\Rutas\AsignadorAutomaticoDocumentos;
use Illuminate\Console\Command;

/**
 * Asocia los CCF nuevos a la salida EN CURSO de su ruta, cuando no hay ninguna duda.
 *
 * NO ESTÁ PROGRAMADO Y NO DEBE ESTARLO todavía: no se registra en el scheduler ni
 * en ninguna tarea de Windows. Se corre a mano, o desde el botón de la pantalla de
 * la salida, mientras el módulo está en rodaje. Automatizar un barrido que escribe
 * antes de haberlo visto funcionar es cómo se llenan las bases de datos de
 * asignaciones que nadie recuerda haber hecho.
 *
 * Modo por defecto: EN SECO. Escribir hay que pedirlo con `--aplicar`. La corrida en
 * seco usa exactamente el mismo evaluador, así que lo que informa es lo que haría.
 *
 * No toca `dtes` en ningún caso: solo lee. Nada de correlativos, firma, transmisión,
 * invalidaciones, PPQ ni Planta.
 *
 *   php artisan rutas:asociar-documentos              # en seco, últimos N días (config)
 *   php artisan rutas:asociar-documentos --dias=15
 *   php artisan rutas:asociar-documentos --aplicar
 */
class RutasAsociarDocumentosCommand extends Command
{
    protected $signature = 'rutas:asociar-documentos
        {--dias= : Días hacia atrás a revisar (por defecto, config rutas.asociacion_dias)}
        {--aplicar : Escribe las asociaciones. Sin esta bandera solo informa}';

    protected $description = 'Asocia CCF nuevos a la salida en curso de su ruta cuando la coincidencia es inequívoca (en seco por defecto)';

    public function handle(AsignadorAutomaticoDocumentos $automatico): int
    {
        $dias = (int) ($this->option('dias') ?: config('rutas.asociacion_dias'));
        $aplicar = (bool) $this->option('aplicar');

        $this->line('Asociación de documentos a salidas de ruta');
        $this->line('Ventana: últimos '.$dias.' días · Modo: '.($aplicar ? 'APLICAR' : 'en seco'));
        $this->newLine();

        $resultado = $automatico->barrer($dias, null, enSeco: ! $aplicar);

        if ($resultado === []) {
            $this->info('No hay CCF en la ventana. Nada que hacer.');

            return self::SUCCESS;
        }

        // Los asignados primero, después las excepciones que alguien debe resolver, y
        // al final los "no aplica" (ruido normal, pero se muestra para que el total cuadre).
        $orden = [
            AsignadorAutomaticoDocumentos::ASIGNADO,
            AsignadorAutomaticoDocumentos::VARIAS_SALIDAS_EN_CURSO,
            AsignadorAutomaticoDocumentos::SUCURSAL_SIN_RUTA,
            AsignadorAutomaticoDocumentos::SIN_SALIDA_EN_CURSO,
            AsignadorAutomaticoDocumentos::YA_ASIGNADO,
            AsignadorAutomaticoDocumentos::SIN_SUCURSAL,
            AsignadorAutomaticoDocumentos::SERIE_NO_AUTOMATICA,
            AsignadorAutomaticoDocumentos::NO_ES_CCF_VIGENTE,
        ];

        $excepciones = AsignadorAutomaticoDocumentos::motivosDeExcepcion();

        foreach ($orden as $estado) {
            $filas = $resultado[$estado] ?? [];
            if ($filas === []) {
                continue;
            }

            $titulo = AsignadorAutomaticoDocumentos::MOTIVOS[$estado].' ('.count($filas).')';

            match (true) {
                $estado === AsignadorAutomaticoDocumentos::ASIGNADO => $this->info($titulo),
                $excepciones->contains($estado) => $this->warn($titulo.'  <-- requiere decisión de una persona'),
                default => $this->line($titulo),
            };

            // El detalle solo para lo que importa: lo asignado y las excepciones. El
            // resto es ruido esperado y listarlo entero esconde lo que sí hay que ver.
            if ($estado === AsignadorAutomaticoDocumentos::ASIGNADO || $excepciones->contains($estado)) {
                foreach ($filas as $fila) {
                    $this->line(sprintf(
                        '    %s  %s  %s',
                        $fila['dte']->numero_control,
                        str_pad((string) ($fila['dte']->clienteSucursal?->nombre ?? 'sin sala'), 28),
                        $fila['salida']?->descripcionCorta() ?? '',
                    ));
                }
            }
        }

        $this->newLine();

        if (! $aplicar) {
            $this->warn('Corrida EN SECO: no se escribió nada. Volvé a correrlo con --aplicar para aplicar.');
        }

        return self::SUCCESS;
    }
}
