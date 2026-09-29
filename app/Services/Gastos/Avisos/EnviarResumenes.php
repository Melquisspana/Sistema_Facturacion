<?php

namespace App\Services\Gastos\Avisos;

use App\Mail\Gastos\ResumenGastosCorreo;
use App\Models\Gastos\Resumen;
use App\Models\User;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Dinero;
use App\Support\Correo\CandadoCorreoReal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * El resumen por correo de lo que está pendiente.
 *
 * ─────────────────────────── Nunca un correo vacío ───────────────────────────
 *
 * Si al momento de armarlo no hay nada pendiente, NO se manda y NO se guarda fila.
 * No existe el estado «vacío» porque no existe el resumen. Un correo semanal que
 * dice «no hay nada» entrena a la gente a no abrirlos, y el día que sí haya algo
 * urgente nadie lo va a leer.
 *
 * ──────────────────────── Se recalcula justo antes ────────────────────────
 *
 * El contenido NO sale de la bandeja de avisos: se vuelven a consultar los saldos y
 * el alcance de la persona en el instante del envío. Un aviso de ayer puede
 * corresponder a una deuda que se pagó esta mañana, y reclamarla por correo es
 * peor que no avisar.
 *
 * ───────────────────────────── Fuera de producción ─────────────────────────────
 *
 * El envío pasa por {@see CandadoCorreoReal}, igual que el correo fiscal y el de
 * compras: fuera de `production` NADA sale del sistema y el resumen queda con
 * estado `simulado`. Se distingue de `enviado` a propósito —«no hubo error» no
 * significa «llegó»— y encender la preferencia de correo en una máquina de
 * desarrollo no puede escribirle a un proveedor.
 *
 * ───────────────────────────── Sin duplicados ─────────────────────────────
 *
 * `gastos_resumenes.clave` es usuario + canal + ventana, y la fila se escribe ANTES
 * de tocar el transporte (outbox). Dos corridas de la misma ventana producen un
 * resumen. Lo que esto NO promete es exactitud absoluta del transporte: si el SMTP
 * corta después de aceptar, el estado queda `fallido` y hay que mirarlo antes de
 * reenviar, porque el mensaje pudo haber salido igual.
 */
final class EnviarResumenes
{
    public function __construct(
        private ConsultaGastos $consulta,
        private ArmarAvisos $avisos,
        private CandadoCorreoReal $candado,
    ) {}

    /**
     * @return array{preparados: int, enviados: int, simulados: int, fallidos: int, sin_pendientes: int, fuera_de_ventana: int}
     */
    public function enviar(CarbonImmutable $hoy): array
    {
        $conteo = ['preparados' => 0, 'enviados' => 0, 'simulados' => 0, 'fallidos' => 0,
            'sin_pendientes' => 0, 'fuera_de_ventana' => 0];

        foreach ($this->avisos->destinatarios() as ['usuario' => $usuario, 'preferencias' => $prefs, 'ambitos' => $ambitos]) {
            if (! $prefs->correo || $prefs->resumen === 'nunca' || blank($usuario->email)) {
                continue;
            }

            $ventana = $this->ventana($prefs->resumen, (int) $prefs->resumen_dia_semana, $hoy);

            if ($ventana === null) {
                $conteo['fuera_de_ventana']++;

                continue;
            }

            $contenido = $this->contenido($ambitos, $hoy);

            if ($contenido['obligaciones'] === 0) {
                // Nada que decir: ni fila ni correo.
                $conteo['sin_pendientes']++;

                continue;
            }

            $resumen = $this->prepararOutbox($usuario, $ventana, $contenido);

            if ($resumen === null) {
                continue; // ya estaba preparado para esta ventana
            }

            $conteo['preparados']++;
            $conteo[$this->entregar($usuario, $resumen, $contenido)]++;
        }

        return $conteo;
    }

    /**
     * Etiqueta de la ventana, o null si hoy no toca.
     *
     * Semanal significa UN día de la semana, no «cada siete corridas»: el proceso
     * puede correr todos los días y solo el día elegido produce resumen.
     */
    public function ventana(string $frecuencia, int $diaSemana, CarbonImmutable $hoy): ?string
    {
        return match ($frecuencia) {
            'diario' => 'd:'.$hoy->toDateString(),
            'semanal' => $hoy->dayOfWeekIso === $diaSemana ? 's:'.$hoy->format('o-\WW') : null,
            default => null,
        };
    }

    /**
     * Lo que hay pendiente AHORA, dentro del alcance recibido.
     *
     * @param  array<int, string>  $ambitos
     * @return array<string, mixed>
     */
    public function contenido(array $ambitos, CarbonImmutable $hoy): array
    {
        $cuotas = $this->consulta->cuotasAbiertas($hoy->toDateString(), $ambitos);
        $sinMonto = $this->consulta->esperandoMontoAbiertos($ambitos);

        $vencidos = [];
        $proximos = [];
        $totales = [];

        foreach ($cuotas as $cuota) {
            $fila = [
                'concepto' => $cuota->concepto,
                'beneficiario' => $cuota->beneficiario,
                'ambito' => $cuota->ambito,
                'moneda' => $cuota->moneda,
                'saldo' => Dinero::decimal((int) $cuota->saldo),
                'vence' => $cuota->vence,
            ];

            $totales[$cuota->moneda] = ($totales[$cuota->moneda] ?? 0) + (int) $cuota->saldo;

            // Sin fecha no es «próximo» ni «vencido»: es pendiente sin plazo, y va con
            // los próximos al final, nunca reclamado como atrasado.
            if ((int) $cuota->vencida === 1) {
                $vencidos[] = $fila;
            } else {
                $proximos[] = $fila;
            }
        }

        return [
            'vencidos' => $vencidos,
            'proximos' => $proximos,
            'sin_monto' => $sinMonto->map(fn ($g) => [
                'concepto' => $g->concepto,
                'beneficiario' => $g->beneficiario,
                'ambito' => $g->ambito,
                'vence_esperado' => $g->vence_esperado,
            ])->all(),
            'totales' => array_map(fn (int $c) => Dinero::decimal($c), $totales),
            'obligaciones' => count($vencidos) + count($proximos) + $sinMonto->count(),
            'hasta' => $hoy->toDateString(),
        ];
    }

    /**
     * Escribe la fila del outbox. Devuelve null si otra corrida ya la escribió para
     * esta misma ventana.
     */
    private function prepararOutbox(User $usuario, string $ventana, array $contenido): ?Resumen
    {
        $clave = $usuario->id.'|correo|'.$ventana;

        $entro = DB::table('gastos_resumenes')->insertOrIgnore([
            'clave' => $clave,
            'usuario_id' => $usuario->id,
            'canal' => 'correo',
            'ventana' => $ventana,
            'estado' => 'preparado',
            'obligaciones' => $contenido['obligaciones'],
            'contenido' => json_encode($contenido, JSON_THROW_ON_ERROR),
            'intentos' => 0,
            'created_at' => now(),
        ]);

        return $entro === 1 ? Resumen::where('clave', $clave)->first() : null;
    }

    /** @return 'enviados'|'simulados'|'fallidos' */
    private function entregar(User $usuario, Resumen $resumen, array $contenido): string
    {
        if ($this->candado->debeSimular()) {
            $resumen->update([
                'estado' => 'simulado',
                'intentos' => $resumen->intentos + 1,
                'error' => $this->candado->motivo(),
                'enviado_at' => now(),
            ]);

            Log::info('Gastos resumen SIMULADO', [
                'usuario_id' => $usuario->id,
                'ventana' => $resumen->ventana,
                'obligaciones' => $contenido['obligaciones'],
                'motivo' => $this->candado->motivo(),
            ]);

            return 'simulados';
        }

        try {
            Mail::to($usuario->email)->send(new ResumenGastosCorreo($usuario->name, $contenido));

            $resumen->update(['estado' => 'enviado', 'intentos' => $resumen->intentos + 1, 'enviado_at' => now()]);

            return 'enviados';
        } catch (\Throwable $e) {
            // Puede haber salido igual: el estado dice «falló», no «no llegó». Reenviar
            // sin mirar es como se mandan dos correos por lo mismo.
            $resumen->update([
                'estado' => 'fallido',
                'intentos' => $resumen->intentos + 1,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('Gastos resumen FALLIDO', [
                'usuario_id' => $usuario->id,
                'ventana' => $resumen->ventana,
                'error' => $e->getMessage(),
            ]);

            return 'fallidos';
        }
    }
}
