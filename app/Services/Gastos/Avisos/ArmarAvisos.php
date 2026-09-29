<?php

namespace App\Services\Gastos\Avisos;

use App\Models\Gastos\Aviso;
use App\Models\Gastos\PreferenciaAvisos;
use App\Models\User;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma la bandeja interna de avisos a partir del saldo REAL.
 *
 * Tres reglas gobiernan todo lo de acá:
 *
 *  1. SOLO PENDIENTES REALES. Un aviso sale de una cuota con saldo mayor que cero,
 *     calculado con la misma aritmética del listado. Una deuda saldada —por pago o
 *     por ajuste— deja de producir avisos nuevos en la corrida siguiente. Los
 *     avisos ya emitidos se quedan como historia: no se borran hacia atrás, porque
 *     el 3 de marzo esa deuda existía de verdad.
 *
 *  2. NUNCA SE INVENTA UNA CIFRA. Un gasto sin monto conocido no genera «vencido»
 *     ni «vence pronto»: genera «falta el monto», que habla del recibo que no
 *     llegó y no de dinero que se deba. Es la diferencia entre recordar un trámite
 *     y reclamar una deuda que nadie calculó.
 *
 *  3. EL PERMISO MANDA SOBRE LA PREFERENCIA. Marcar «personal» en las preferencias
 *     no agrega ni una fila si la persona no tiene `gastos.personales`. El ámbito
 *     se cruza antes de consultar, así que esos gastos ni siquiera se leen.
 *
 * IDEMPOTENTE. `gastos_avisos.clave` es usuario + tipo + obligación + ventana, y la
 * inserción es `insertOrIgnore`. Correr esto cinco veces en el día deja un aviso,
 * no cinco; y la ventana es lo que hace que un vencido vuelva a recordarse la
 * semana siguiente sin repetirse todos los días.
 */
final class ArmarAvisos
{
    public function __construct(private ConsultaGastos $consulta) {}

    /**
     * @return array{avisos: int, usuarios: int, detalle: array<string, int>}
     */
    public function armar(CarbonImmutable $hoy): array
    {
        $destinatarios = $this->destinatarios();

        if ($destinatarios->isEmpty()) {
            return ['avisos' => 0, 'usuarios' => 0, 'detalle' => []];
        }

        // Los ámbitos posibles son pocos (empresarial, personal, ambos), así que se
        // consulta UNA vez por combinación y no una por persona.
        $porAmbitos = [];
        $creados = 0;
        $detalle = ['vence' => 0, 'vencido' => 0, 'falta_monto' => 0];
        $alcanzados = 0;

        foreach ($destinatarios as ['usuario' => $usuario, 'preferencias' => $prefs, 'ambitos' => $ambitos]) {
            $llave = implode(',', $ambitos);

            $porAmbitos[$llave] ??= [
                'cuotas' => $this->consulta->cuotasAbiertas($hoy->toDateString(), $ambitos),
                'sin_monto' => $this->consulta->esperandoMontoAbiertos($ambitos),
            ];

            $filas = array_merge(
                $this->porVencimiento($usuario, $prefs, $porAmbitos[$llave]['cuotas'], $hoy),
                $this->porFaltaDeMonto($usuario, $porAmbitos[$llave]['sin_monto'], $hoy),
            );

            if ($filas === []) {
                continue;
            }

            $alcanzados++;

            foreach (array_chunk($filas, 200) as $lote) {
                // insertOrIgnore contra el índice único: dos procesos a la vez no
                // duplican, y no hace falta consultar antes qué existe ya.
                $creados += DB::table('gastos_avisos')->insertOrIgnore($lote);
            }

            foreach ($filas as $fila) {
                $detalle[$fila['tipo']]++;
            }
        }

        return ['avisos' => $creados, 'usuarios' => $alcanzados, 'detalle' => $detalle];
    }

    /**
     * Quién puede recibir avisos, con su alcance ya resuelto.
     *
     * @return Collection<int, array{usuario: User, preferencias: PreferenciaAvisos, ambitos: array<int, string>}>
     */
    public function destinatarios(): Collection
    {
        return User::query()
            ->where('activo', true)
            ->permission('gastos.ver')
            ->get()
            ->map(function (User $usuario) {
                $prefs = PreferenciaAvisos::de($usuario);

                return [
                    'usuario' => $usuario,
                    'preferencias' => $prefs,
                    'ambitos' => $this->ambitosDe($usuario, $prefs),
                ];
            })
            ->filter(fn (array $fila) => $fila['preferencias']->activo && $fila['ambitos'] !== [])
            ->values();
    }

    /**
     * Ámbitos que esta persona recibe: lo que pidió, cruzado con lo que puede ver.
     *
     * @return array<int, string>
     */
    public function ambitosDe(User $usuario, PreferenciaAvisos $prefs): array
    {
        $permitidos = ['empresarial'];

        if ($usuario->can('gastos.personales')) {
            $permitidos[] = 'personal';
        }

        return array_values(array_intersect($prefs->ambitos ?? ['empresarial'], $permitidos));
    }

    /**
     * Avisos de vencimiento: los que están por vencer con la anticipación pedida, y
     * los que ya vencieron.
     *
     * @return array<int, array<string, mixed>>
     */
    private function porVencimiento(User $usuario, PreferenciaAvisos $prefs, Collection $cuotas, CarbonImmutable $hoy): array
    {
        $anticipacion = array_map('intval', $prefs->dias_anticipacion ?? [7, 3, 0]);
        $filas = [];

        foreach ($cuotas as $cuota) {
            // Sin fecha no hay nada que recordar: no se puede reclamar lo que no vence.
            if ($cuota->vence === null) {
                continue;
            }

            $vence = CarbonImmutable::parse($cuota->vence)->startOfDay();
            // Carbon 3 devuelve float acá: sin el cast, el `in_array` estricto contra
            // los días de anticipación no encontraría nunca una coincidencia.
            $dias = (int) $hoy->startOfDay()->diffInDays($vence, false);
            $importe = Dinero::decimal((int) $cuota->saldo);

            if ($dias < 0) {
                // Vencido: se recuerda UNA vez por semana. Repetirlo a diario convierte
                // la bandeja en ruido y la gente deja de mirarla.
                $filas[] = $this->fila($usuario, 'vencido', $cuota, $hoy->format('o-\WW'), [
                    'titulo' => 'Vencido: '.$cuota->concepto,
                    'detalle' => $cuota->beneficiario.' · vencía el '.$vence->toDateString()
                        .' · quedan '.$cuota->moneda.' '.$importe.' por pagar ('.abs($dias).' días de atraso).',
                    'vence' => $vence->toDateString(),
                    'importe' => $importe,
                ]);

                continue;
            }

            if (! in_array($dias, $anticipacion, true)) {
                continue;
            }

            $filas[] = $this->fila($usuario, 'vence', $cuota, 'd'.$dias.'-'.$vence->toDateString(), [
                'titulo' => ($dias === 0 ? 'Vence hoy: ' : 'Vence en '.$dias.' días: ').$cuota->concepto,
                'detalle' => $cuota->beneficiario.' · vence el '.$vence->toDateString()
                    .' · '.$cuota->moneda.' '.$importe.' pendientes.',
                'vence' => $vence->toDateString(),
                'importe' => $importe,
            ]);
        }

        return $filas;
    }

    /**
     * «Falta el monto»: el recibo que no llegó. Se recuerda una vez al mes y NUNCA
     * habla de importes, porque no hay ninguno.
     *
     * @return array<int, array<string, mixed>>
     */
    private function porFaltaDeMonto(User $usuario, Collection $gastos, CarbonImmutable $hoy): array
    {
        $filas = [];

        foreach ($gastos as $gasto) {
            $esperado = $gasto->vence_esperado !== null
                ? ' · se esperaba para el '.CarbonImmutable::parse($gasto->vence_esperado)->toDateString()
                : '';

            $filas[] = [
                'clave' => $usuario->id.'|falta_monto|g'.$gasto->gasto_id.'|'.$hoy->format('Y-m'),
                'usuario_id' => $usuario->id,
                'tipo' => 'falta_monto',
                'gasto_id' => $gasto->gasto_id,
                'cuota_id' => null,
                'titulo' => 'Falta el monto: '.$gasto->concepto,
                'detalle' => $gasto->beneficiario.' · sigue sin importe'.$esperado
                    .'. No se cuenta como deuda hasta que se complete.',
                'vence' => $gasto->vence_esperado,
                'importe' => null,
                'moneda' => null,
                'created_at' => now(),
            ];
        }

        return $filas;
    }

    /** @return array<string, mixed> */
    private function fila(User $usuario, string $tipo, object $cuota, string $ventana, array $extra): array
    {
        return [
            'clave' => $usuario->id.'|'.$tipo.'|c'.$cuota->cuota_id.'|'.$ventana,
            'usuario_id' => $usuario->id,
            'tipo' => $tipo,
            'gasto_id' => $cuota->gasto_id,
            'cuota_id' => $cuota->cuota_id,
            'moneda' => $cuota->moneda,
            'created_at' => now(),
        ] + $extra;
    }
}
