<?php

namespace App\Http\Controllers\Gastos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gastos\RegistrarGastoRequest;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Aviso;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\AccesoGastos;
use App\Services\Gastos\AdjuntarDespues;
use App\Services\Gastos\CompletarMonto;
use App\Services\Gastos\ConsultaGastos;
use App\Services\Gastos\InstalacionGastos;
use App\Services\Gastos\Recurrencia\RepetirGasto;
use App\Services\Gastos\RegistrarGasto;
use App\Services\Gastos\SaldosGastos;
use App\Services\Gastos\VincularCompra;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gastos: listado, alta, ficha y archivos.
 *
 * El candado de ámbito se aplica en la CONSULTA (ver {@see ConsultaGastos}) y en
 * {@see AccesoGastos}, nunca al pintar: si se filtrara en la vista, los totales,
 * los contadores de pestaña y las exportaciones seguirían delatando lo personal.
 */
class GastoController extends Controller
{
    public function index(Request $request, ConsultaGastos $consulta, SaldosGastos $saldos, InstalacionGastos $instalacion)
    {
        $usuario = $request->user();
        $hoy = now()->toDateString();
        $filtros = $this->filtros($request, $usuario);

        return view('gastos.index', [
            // Los avisos se muestran ACÁ y no en una bandeja aparte: un aviso no es un
            // sitio al que ir, es algo que tiene que verse donde ya se está mirando el
            // trabajo. Vacío si la fase 2 no está migrada en esta base.
            'avisos' => $instalacion->fase2Instalada() ? Aviso::resumenSinLeer($usuario) : [],
            'filtros' => $filtros,
            'hoy' => $hoy,
            'gastos' => $consulta->pagina($usuario, $filtros, $hoy),
            'conteos' => $consulta->conteos($usuario, $filtros, $hoy),
            'totales' => $consulta->totales($usuario, $filtros, $hoy),
            'esperandoMonto' => $consulta->esperandoMonto($usuario, $filtros),
            'categorias' => $consulta->categorias($usuario),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'saldos' => $saldos,
        ]);
    }

    public function create(Request $request, AccesoGastos $acceso, SaldosGastos $saldos, VincularCompra $compras, InstalacionGastos $instalacion)
    {
        abort_unless($request->user()->can('gastos.registrar'), 403);

        // Alta desde Compras: se PRELLENA, no se crea. El operador sigue revisando
        // clasificación, período, vencimiento y responsable, que el documento no trae.
        $documento = $request->filled('documento')
            ? DocumentoRecibido::find((int) $request->input('documento'))
            : null;

        if ($documento !== null) {
            abort_unless($request->user()->can('documentos-recibidos.ver'), 403);
        }

        return view('gastos.create', [
            // «Este gasto se repite» solo se ofrece si hay permiso Y el esquema de
            // recurrencias existe en esta base: ofrecerlo sin las tablas llevaría a un
            // 503 justo después de guardar.
            'puedeRepetir' => $instalacion->fase2Instalada() && $request->user()->can('gastos.recurrencias'),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'clave' => (string) Str::uuid(),
            'confirmacion' => $request->filled('guardado')
                ? $this->confirmacion($request, (int) $request->input('guardado'), $acceso, $saldos)
                : null,
            'documento' => $documento,
            'prellenado' => $documento ? $compras->prellenado($documento) : [],
            'documentoBloqueado' => $documento ? $compras->motivoNoGeneraDeuda($documento) : null,
            'gastoDelDocumento' => $documento ? $compras->gastoDeLaDeuda($documento) : null,
        ]);
    }

    public function store(RegistrarGastoRequest $request, RegistrarGasto $registrar, RepetirGasto $repetir)
    {
        $gasto = $registrar->registrar($request);

        // «Este gasto se repite»: el gasto recién creado ES el primer período. No se
        // crea otro para esta fecha y no se vuelve a capturar ni un dato —ni siquiera
        // el importe, que sale del propio gasto—.
        //
        // Va después y no dentro de RegistrarGasto porque este paso es OPCIONAL y no
        // puede poner en riesgo el registro del gasto, que es lo que el operador vino
        // a hacer. Sus datos ya se validaron en el FormRequest, así que acá no puede
        // fallar por un error de captura; si aun así fallara, el gasto queda bien
        // guardado y la repetición se configura desde su ficha.
        if ($request->boolean('se_repite') && $request->user()->can('gastos.recurrencias')) {
            $repetir->desdeGasto($request->user(), $gasto, $request->input('repeticion', []));
        }

        // Redirect-after-POST: refrescar la confirmación no reenvía nada.
        return redirect()->route('gastos.create', ['guardado' => $gasto->id]);
    }

    /**
     * Convertir en repetición un gasto que YA EXISTE, desde su ficha.
     *
     * El gasto original y sus pagos no se tocan: queda como el período que le toca y
     * la generación nunca lo duplica. Ver {@see RepetirGasto}.
     */
    public function repetir(Request $request, Gasto $gasto, RepetirGasto $repetir)
    {
        $regla = $repetir->desdeGasto($request->user(), $gasto, $request->input('repeticion', []));

        $proximo = $repetir->proximoVencimiento($regla, CarbonImmutable::now());

        return redirect()
            ->route('gastos.reglas.show', $regla)
            ->with('gastos.aviso', 'Listo: este gasto ahora se repite. El de ahora quedó como está, con sus pagos intactos'
                .($proximo !== null ? ', y el próximo vence el '.$proximo['vence'].'.' : '.'));
    }

    public function show(Request $request, Gasto $gasto, AccesoGastos $acceso, SaldosGastos $saldos, InstalacionGastos $instalacion, RepetirGasto $repetir)
    {
        $usuario = $request->user();
        abort_unless($acceso->ver($usuario, $gasto), 403);

        // Todo lo de «se repite» se calcula SOLO si su esquema existe: preguntarlo sin
        // las tablas reventaría la ficha, que es de fase 1 y tiene que seguir abriendo.
        $fase2 = $instalacion->fase2Instalada();
        $reglaDeEsteGasto = $fase2 ? $repetir->reglaDe($gasto) : null;
        $ancla = $repetir->anclaDe($gasto);

        $gasto->load(['cuotas.ajustes', 'fuentes.documento', 'responsable', 'registrador']);
        $cuotaIds = $gasto->cuotas->pluck('id');

        $todos = Pago::whereIn('id', DB::table('gastos_pago_aplicaciones')
            ->whereIn('cuota_id', $cuotaIds)->distinct()->pluck('pago_id'))
            ->with(['pagador', 'registrador', 'reversor'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();

        // Cabecera y comprobantes SOLO de los pagos que se alcanzan enteros. De los
        // demás se muestra únicamente lo aplicado a ESTE gasto: es información suya, y
        // sin ella el saldo de la cuota bajaría sin explicación.
        $completos = $todos->filter(fn (Pago $p) => $acceso->pagoCompleto($usuario, $p))->values();
        $reservados = $todos->reject(fn (Pago $p) => $acceso->pagoCompleto($usuario, $p))->values();

        return view('gastos.show', [
            'puedeRepetir' => $fase2 && $usuario->can('gastos.recurrencias'),
            'reglaDeEsteGasto' => $reglaDeEsteGasto,
            'motivoNoRepetible' => $fase2 ? $repetir->motivoNoRepetible($gasto) : null,
            // Propuesta tomada del propio vencimiento del gasto: no se vuelve a preguntar.
            'diaPropuesto' => (int) $ancla->format('d'),
            'mesPropuesto' => (int) $ancla->format('m'),
            'gasto' => $gasto,
            'resumen' => $saldos->resumen($gasto, now()->toDateString()),
            'saldos' => $saldos,
            'pagos' => $completos,
            'aplicaciones' => DB::table('gastos_pago_aplicaciones')
                ->whereIn('pago_id', $completos->pluck('id'))->get()->groupBy('pago_id'),
            'aplicadoReservado' => $reservados
                ->flatMap(fn (Pago $p) => $acceso->aplicacionesVisibles($usuario, $p))
                ->where('gasto_id', $gasto->id)->values(),
            'documentos' => DB::table('gastos_adjuntos')->where('gasto_id', $gasto->id)->orderBy('id')->get(),
            'comprobantes' => $completos->isEmpty() ? collect()
                : DB::table('gastos_adjuntos')->whereIn('pago_id', $completos->pluck('id'))->orderBy('id')->get()->groupBy('pago_id'),
            'ajustes' => Ajuste::whereIn('cuota_id', $cuotaIds)->orderByDesc('id')->get(),
            'historial' => $this->historial($gasto, $completos->pluck('id')),
            'hoy' => now()->toDateString(),
            // Notas de crédito de Compras que todavía tienen remanente: se ofrecen para
            // aplicarlas como ajuste, con su disponible a la vista.
            'creditosDisponibles' => $usuario->can('gastos.administrar') && $usuario->can('documentos-recibidos.ver')
                ? app(VincularCompra::class)->notasDeCreditoConRemanente()
                : collect(),
        ]);
    }

    /** Documentos de cobro que llegaron después del registro. */
    public function documentos(Request $request, Gasto $gasto, AdjuntarDespues $adjuntar)
    {
        $datos = $request->validate([
            'documentos' => ['required', 'array', 'max:'.config('gastos.max_archivos')],
            'documentos.*' => ['file', 'mimes:'.implode(',', config('gastos.mimes')), 'max:'.config('gastos.max_archivo_kb')],
        ]);

        $cuantos = $adjuntar->documentos($request->user(), $gasto, $datos['documentos']);

        return redirect()->route('gastos.show', $gasto)
            ->with('gastos.aviso', $cuantos === 1 ? 'Documento adjuntado.' : "{$cuantos} documentos adjuntados.");
    }

    /**
     * Poner el importe a una obligación que lo estaba esperando. COMPLETA el registro
     * existente: no crea otro. Ver App\Services\Gastos\CompletarMonto.
     */
    public function completar(Request $request, Gasto $gasto, CompletarMonto $servicio)
    {
        $datos = $request->validate([
            'cuotas' => ['required', 'array', 'min:1', 'max:'.config('gastos.max_cuotas')],
            'cuotas.*.importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'cuotas.*.vence' => ['nullable', 'date_format:Y-m-d'],
            // El recibo de Compras del que sale la cifra, cuando se completa desde ahí.
            'documento' => ['nullable', 'integer', Rule::exists('documentos_recibidos', 'id')],
        ]);

        $gasto = $servicio->completar(
            $request->user(),
            $gasto,
            $datos['cuotas'],
            isset($datos['documento']) ? DocumentoRecibido::find((int) $datos['documento']) : null,
        );

        return redirect()->route('gastos.show', $gasto)->with('gastos.aviso',
            'Monto completado: '.$gasto->moneda.' '.$gasto->importe.'. La obligación ya se puede pagar.');
    }

    public function archivo(Request $request, int $adjunto, AccesoGastos $acceso)
    {
        $archivo = DB::table('gastos_adjuntos')->find($adjunto);
        abort_unless($archivo !== null, 404);

        // El archivo hereda el candado de su dueño: el documento, el del gasto; el
        // comprobante, el del pago COMPLETO (todas sus obligaciones, de los dos
        // ámbitos). Un comprobante compartido no se entrega a quien solo ve empresa.
        $permitido = $archivo->pago_id !== null
            ? $acceso->pagoCompleto($request->user(), Pago::findOrFail($archivo->pago_id))
            : $archivo->gasto_id !== null && $acceso->ver($request->user(), Gasto::findOrFail($archivo->gasto_id));
        abort_unless($permitido, 403);

        abort_unless(Storage::disk('local')->exists($archivo->ruta), 404);

        // Descarga autorizada incluso para la vista previa: jamás una URL del disco.
        $headers = [
            'Content-Type' => $archivo->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ];

        return $request->boolean('ver')
            ? Storage::disk('local')->response($archivo->ruta, $archivo->nombre, $headers, 'inline')
            : Storage::disk('local')->download($archivo->ruta, $archivo->nombre, $headers);
    }

    /** @return array<string, mixed> */
    private function filtros(Request $request, User $usuario): array
    {
        $datos = $request->validate([
            'pestana' => ['nullable', Rule::in(array_keys(ConsultaGastos::PESTANAS))],
            'q' => ['nullable', 'string', 'max:120'],
            'ambito' => ['nullable', Rule::in(['empresarial', 'personal'])],
            'moneda' => ['nullable', Rule::in(config('gastos.monedas'))],
            'categoria' => ['nullable', 'string', 'max:100'],
            'responsable_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        return [
            'pestana' => $datos['pestana'] ?? 'pendientes',
            'q' => $datos['q'] ?? '',
            // Pedir el filtro «personal» sin alcance no devuelve nada raro: se ignora,
            // y la consulta ya recorta a empresarial de todos modos.
            'ambito' => $usuario->can('gastos.personales') ? ($datos['ambito'] ?? '') : '',
            'moneda' => $datos['moneda'] ?? '',
            'categoria' => $datos['categoria'] ?? '',
            'responsable_id' => $datos['responsable_id'] ?? '',
        ];
    }

    /** Historial cronológico del gasto y de los pagos que el usuario alcanza. */
    private function historial(Gasto $gasto, $pagoIds)
    {
        return DB::table('gastos_eventos as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.usuario_id')
            ->where(fn ($q) => $q->where('e.gasto_id', $gasto->id)->orWhereIn('e.pago_id', $pagoIds))
            ->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->limit(100)
            ->get(['e.id', 'e.accion', 'e.datos', 'e.created_at', 'e.pago_id', 'e.ajuste_id', 'u.name as usuario']);
    }

    /**
     * Resumen de lo que acaba de guardarse. Se recalcula desde la base —no se
     * arrastra en sesión— y se filtra por permiso.
     */
    private function confirmacion(Request $request, int $id, AccesoGastos $acceso, SaldosGastos $saldos): array
    {
        $gasto = Gasto::findOrFail($id);
        abort_unless($acceso->ver($request->user(), $gasto), 403);

        $usuario = $request->user();
        $cuotas = $gasto->cuotas;

        $todos = Pago::whereIn('id', DB::table('gastos_pago_aplicaciones')
            ->whereIn('cuota_id', $cuotas->pluck('id'))->distinct()->pluck('pago_id'))
            ->orderBy('id')->get();

        $pagos = $todos->filter(fn (Pago $pago) => $acceso->pagoCompleto($usuario, $pago))->values();

        return [
            'gasto' => $gasto,
            'cuotas' => $cuotas,
            'resumen' => $saldos->resumen($gasto, now()->toDateString()),
            'pagos' => $pagos,
            'aplicaciones' => DB::table('gastos_pago_aplicaciones')
                ->whereIn('pago_id', $pagos->pluck('id'))->get()->groupBy('pago_id'),
            'aplicadoReservado' => $todos
                ->reject(fn (Pago $pago) => $acceso->pagoCompleto($usuario, $pago))
                ->flatMap(fn (Pago $pago) => $acceso->aplicacionesVisibles($usuario, $pago))
                ->where('gasto_id', $gasto->id)->values(),
            'documentos' => DB::table('gastos_adjuntos')->where('gasto_id', $gasto->id)->orderBy('id')->get(),
            'comprobantes' => $pagos->isEmpty() ? collect()
                : DB::table('gastos_adjuntos')->whereIn('pago_id', $pagos->pluck('id'))->orderBy('id')->get(),
            'saldos' => $saldos,
        ];
    }
}
