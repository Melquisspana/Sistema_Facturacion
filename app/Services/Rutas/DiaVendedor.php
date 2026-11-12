<?php

namespace App\Services\Rutas;

use App\Enums\FormaCobroEntrega;
use App\Enums\ResultadoEntrega;
use App\Facades\Ajustes;
use App\Models\Dte;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaGasto;
use App\Models\User;
use App\Support\HoraNegocio;
use App\Support\NombreEmpresa;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as HojaPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * El día del vendedor (maqueta del 10/11/2026): lo que entregó, lo que no se pudo y lo que
 * cobró, en cualquier viaje o salida (cualquier vendedor entrega cualquier CCF). Lo usan el
 * inicio, «Terminar el día» y su PDF, de hoy o de un día anterior ({@see self::DIAS_ATRAS});
 * la oficina abre el de cualquier día de un viaje. Solo lectura: no cierra nada ni mueve
 * dinero. El PDF es el control: con él el vendedor entrega el efectivo (menos sus gastos del día), los
 * cheques y los quedan, y lo firman quien entrega y quien recibe.
 *
 * Las sumas van en centavos ({@see CierreDia::centavos()}): ninguna depende de flotantes.
 */
class DiaVendedor
{
    /** Sin viaje abierto, el vendedor abre su día de hasta estos días atrás. */
    public const DIAS_ATRAS = 15;

    /**
     * @return array{
     *     fecha: string,
     *     entregadas: Collection<int, SalidaRutaEntrega>,
     *     no_entregadas: Collection<int, SalidaRutaEntrega>,
     *     efectivo: int, cheque: int, transferencia: int, cobrado: int,
     *     cuantas: array<string, int>,
     *     quedan: array{cantidad: int, monto: int},
     *     portal: array{cantidad: int, monto: int},
     *     portal_id: ?int,
     *     pedidos: int,
     *     gastos: Collection<int, SalidaRutaGasto>,
     *     gastos_total: int,
     *     a_entregar: int,
     * }
     */
    public function resumen(PersonalRuta $persona, ?User $usuario, string $fecha): array
    {
        $filas = $this->suyas($persona, $usuario)
            ->with(['dte:id,numero_control,total_pagar,cliente_id', 'sala:id,nombre'])
            ->whereDate('fecha_resultado', $fecha)
            ->orderBy('fecha_resultado')->orderBy('id')
            ->get();

        $entregadas = $filas->where('resultado', ResultadoEntrega::Entregado)->values();
        $portalId = self::clientePortal();
        $resumen = [
            'fecha' => $fecha,
            'entregadas' => $entregadas,
            'no_entregadas' => $filas->where('resultado', ResultadoEntrega::NoEntregado)->values(),
            FormaCobroEntrega::Efectivo->value => 0,
            FormaCobroEntrega::Cheque->value => 0,
            FormaCobroEntrega::Transferencia->value => 0,
            'cuantas' => array_fill_keys(array_column(FormaCobroEntrega::cases(), 'value'), 0),
            'quedan' => ['cantidad' => 0, 'monto' => 0],
            'portal' => ['cantidad' => 0, 'monto' => 0],
            'portal_id' => $portalId,
        ];
        foreach ($entregadas as $entrega) {
            if ($entrega->forma_cobro !== null) {
                $resumen[$entrega->forma_cobro->value] += CierreDia::centavos($entrega->monto_cobrado ?? '0');
                $resumen['cuantas'][$entrega->forma_cobro->value]++;

                continue;
            }
            $tipo = $portalId !== null && (int) $entrega->dte?->cliente_id === $portalId ? 'portal' : 'quedan';
            $resumen[$tipo]['cantidad']++;
            $resumen[$tipo]['monto'] += CierreDia::centavos($entrega->dte?->total_pagar ?? '0');
        }
        $resumen['cobrado'] = $resumen['efectivo'] + $resumen['cheque'] + $resumen['transferencia'];
        $resumen['pedidos'] = PedidoSala::vigentes()->where('sacado_por_id', $persona->id)->whereDate('fecha', $fecha)->count();
        // Sus gastos del día (gasolina, parqueo…), del viaje o de la salida donde los anotó.
        $resumen['gastos'] = SalidaRutaGasto::query()->where('personal_id', $persona->id)->whereDate('fecha', $fecha)->orderBy('id')->get();
        $resumen['gastos_total'] = $resumen['gastos']->sum(fn (SalidaRutaGasto $g) => CierreDia::centavos($g->monto));
        // Lo que entrega en efectivo: lo cobrado en efectivo menos lo que gastó. Puede dar
        // negativo si gastó más de lo que cobró: la hoja lo dice tal cual.
        $resumen['a_entregar'] = $resumen['efectivo'] - $resumen['gastos_total'];

        return $resumen;
    }

    /**
     * Lo que cuenta como suyo: lo que entregó (aunque lo haya anotado otro) y los «No se pudo»
     * que anotó él. `$usuario` es el login de la persona; sin login no anotó nada.
     *
     * @return Builder<SalidaRutaEntrega>
     */
    private function suyas(PersonalRuta $persona, ?User $usuario): Builder
    {
        return SalidaRutaEntrega::query()->sinFacturadosDespues()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('resultado', ResultadoEntrega::Entregado->value)->where('entregado_por_id', $persona->id))
                ->orWhere(fn (Builder $q) => $q->where('resultado', ResultadoEntrega::NoEntregado->value)->where('registrado_por', $usuario?->id ?? 0)));
    }

    /**
     * Los días, entre dos fechas, en que tuvo algo que va en el PDF: una entrega, un «No se
     * pudo» o un gasto. Del más viejo al más nuevo.
     *
     * @return list<string>
     */
    public function diasConMovimiento(PersonalRuta $persona, ?User $usuario, string $desde, string $hasta): array
    {
        $entregas = $this->suyas($persona, $usuario)
            ->whereDate('fecha_resultado', '>=', $desde)->whereDate('fecha_resultado', '<=', $hasta)
            ->get(['id', 'fecha_resultado'])->toBase()->map(fn (SalidaRutaEntrega $e) => $e->fecha_resultado->toDateString());
        $gastos = SalidaRutaGasto::query()->where('personal_id', $persona->id)
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)
            ->get(['id', 'fecha'])->toBase()->map(fn (SalidaRutaGasto $g) => $g->fecha->toDateString());

        return $entregas->concat($gastos)->unique()->sort()->values()->all();
    }

    /**
     * Los días de un viaje: del día en que se abrió al de su cierre, o a hoy si sigue abierto.
     *
     * @return array{0: string, 1: string}
     */
    public static function periodo(SalidaRuta $viaje): array
    {
        $hoy = HoraNegocio::fechaHoy();

        return [$viaje->fecha_inicio->toDateString(), min($hoy, $viaje->fecha_fin_real?->toDateString() ?? $hoy)];
    }

    /**
     * Para la oficina: cada día del viaje con movimiento de cada persona que va en él (quien
     * lo abrió y quien entregó algo suyo). Cada uno tiene su PDF, el que firma al entregar.
     *
     * @return list<array{fecha: string, persona: PersonalRuta}>
     */
    public function diasDelViaje(SalidaRuta $viaje): array
    {
        [$desde, $hasta] = self::periodo($viaje);
        $dias = [];
        foreach ($viaje->personal()->with('user')->orderBy('rutas_personal.nombre')->get() as $persona) {
            foreach ($this->diasConMovimiento($persona, $persona->user, $desde, $hasta) as $fecha) {
                $dias[] = ['fecha' => $fecha, 'persona' => $persona];
            }
        }
        usort($dias, fn (array $a, array $b) => [$a['fecha'], $a['persona']->nombre] <=> [$b['fecha'], $b['persona']->nombre]);

        return $dias;
    }

    /**
     * Quienes tienen algo que va en el PDF de ese día (una entrega, un «No se pudo» o un
     * gasto), por nombre. Lo pide el correo diario ({@see ResumenDiarioPedidos}).
     *
     * Las mismas personas que daría {@see diasConMovimiento()} ficha por ficha, con la regla
     * de {@see suyas()}, pero partiendo de lo anotado ese día: las consultas no crecen con
     * la cantidad de fichas del Personal.
     *
     * @return Collection<int, PersonalRuta>
     */
    public function personasConMovimiento(string $fecha): Collection
    {
        $entregas = SalidaRutaEntrega::query()->sinFacturadosDespues()->whereDate('fecha_resultado', $fecha)
            ->whereIn('resultado', [ResultadoEntrega::Entregado->value, ResultadoEntrega::NoEntregado->value])
            ->select('resultado', 'entregado_por_id', 'registrado_por')->distinct()->toBase()->get();
        // Quien entregó o tiene un gasto, por su ficha; quien anotó un «No se pudo», por su login.
        $fichas = $entregas->where('resultado', ResultadoEntrega::Entregado->value)->pluck('entregado_por_id')
            ->concat(SalidaRutaGasto::query()->whereDate('fecha', $fecha)->distinct()->pluck('personal_id'))
            ->filter()->unique()->values();
        $usuarios = $entregas->where('resultado', ResultadoEntrega::NoEntregado->value)->pluck('registrado_por')
            ->filter()->unique()->values();

        return PersonalRuta::query()->with('user')
            ->where(fn (Builder $q) => $q->whereIn('id', $fichas)->orWhereIn('user_id', $usuarios))
            ->orderBy('nombre')->orderBy('id')->get();
    }

    /** El PDF del día de una persona: el mismo para el vendedor, la oficina y el correo diario. */
    public function pdf(PersonalRuta $persona, ?User $usuario, string $fecha): Response
    {
        return $this->hoja($persona, $usuario, $fecha)->stream(self::nombrePdf($persona, $fecha));
    }

    /** Ese mismo PDF, en bytes: es el que adjunta el correo diario. */
    public function pdfBinario(PersonalRuta $persona, ?User $usuario, string $fecha): string
    {
        return $this->hoja($persona, $usuario, $fecha)->output();
    }

    public static function nombrePdf(PersonalRuta $persona, string $fecha): string
    {
        return 'dia-'.$fecha.'-'.Str::slug($persona->nombre).'.pdf';
    }

    private function hoja(PersonalRuta $persona, ?User $usuario, string $fecha): HojaPdf
    {
        return Pdf::loadView('rutas.mi-salida.dia-pdf', [
            'resumen' => $this->resumen($persona, $usuario, $fecha),
            'persona' => $persona,
            'empresa' => NombreEmpresa::actual(),
            'generado' => HoraNegocio::aLocal(now()),
        ])->setPaper('letter');
    }

    /**
     * El supermercado que cobra por el portal: el cliente del pronto pago (Ajustes,
     * `ppq.cliente_default_id`). No se le cobra al entregar y su albarán confirma la entrega.
     */
    public static function clientePortal(): ?int
    {
        return Ajustes::cliente('ppq.cliente_default_id');
    }

    public static function esPortal(Dte $dte): bool
    {
        $portal = self::clientePortal();

        return $portal !== null && (int) $dte->cliente_id === $portal;
    }
}
