<?php

namespace App\Http\Controllers;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\DocumentoRecibido;
use App\Models\Exportacion;
use App\Services\Dte\DteTransmisionService;
use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Panel de inicio: resumen operativo con datos que YA existen (nada de llamadas
 * a Hacienda, nada de firma/autenticación). Todas las consultas son conteos o
 * sumas simples con índices existentes; las que se repiten entre secciones se
 * calculan una sola vez por request. Los conteos agregados (tarjetas) se
 * cachean 60s por usuario para que recargar la página no vuelva a pegarle a la
 * BD en cada click. Respeta los mismos roles/permisos que el sidebar
 * (layouts/navigation.blade.php): no se muestra ninguna acción que el usuario
 * no pueda ejecutar.
 */
class DashboardController extends Controller
{
    public function index(Request $request, DteTransmisionService $transmision, DiagnosticoSistemaService $diagnosticoService): View
    {
        $usuario = $request->user();
        $esAdmin = (bool) $usuario->hasRole('administrador');
        $esGestorDte = (bool) $usuario->hasAnyRole(['administrador', 'facturacion']);
        $veOperativos = (bool) $usuario->hasAnyRole(['administrador', 'contador', 'facturacion']);
        $veFacturacion = (bool) $usuario->can('viewAny', Dte::class);

        $jobsFallidos = (int) DB::table('failed_jobs')->count();
        $jobsPendientes = (int) DB::table('jobs')->count();

        $stats = Cache::remember(
            "dashboard.stats.{$usuario->id}",
            60,
            fn () => $this->calcularStats($veFacturacion, $veOperativos, $jobsFallidos)
        );

        $actividad = $veFacturacion ? $this->actividadReciente() : collect();

        $worker = WorkerHeartbeat::estado();
        $modo = $esGestorDte ? $transmision->estadoOperativo() : null;
        $estadoTecnico = $esGestorDte ? $this->estadoTecnico($modo, $worker, $jobsPendientes, $jobsFallidos) : null;

        // Diagnóstico real (BD, worker, backup, firmador, transmisión, correlativos
        // P002, storage, migraciones): reemplaza el semáforo anterior, que solo miraba
        // estadoOperativo()+worker+jobsFallidos (y este último apenas como advertencia).
        // Nunca hace red: ver DiagnosticoSistemaService.
        $diagnostico = $diagnosticoService->evaluar();
        $estadoGeneral = match ($diagnostico['nivel']) {
            'critico' => 'critico',
            'advertencia' => 'advertencia',
            default => 'ok',
        };

        return view('dashboard', [
            'saludo' => $this->saludo(),
            'stats' => $stats,
            'actividad' => $actividad,
            'estadoTecnico' => $estadoTecnico,
            'estadoGeneral' => $estadoGeneral,
            'diagnostico' => $esGestorDte ? $diagnostico : null,
            'esAdmin' => $esAdmin,
            'esGestorDte' => $esGestorDte,
            'veOperativos' => $veOperativos,
            'veFacturacion' => $veFacturacion,
        ]);
    }

    private function saludo(): string
    {
        return match (true) {
            now()->hour < 12 => 'Buenos días',
            now()->hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };
    }

    /**
     * Tarjetas principales. Cacheadas 60s por usuario (ver index()): son conteos
     * livianos, pero no hay razón para repetirlos en cada recarga rápida.
     *
     * @return array<string, mixed>
     */
    private function calcularStats(bool $veFacturacion, bool $veOperativos, int $jobsFallidos): array
    {
        $inicioMes = now()->startOfMonth()->toDateString();

        $dteAceptadosMes = $ventasMes = null;
        if ($veFacturacion) {
            $dteAceptadosMes = Dte::where('estado', EstadoDte::Aceptado->value)
                ->where('fecha_emision', '>=', $inicioMes)->count();

            $ventasMes = (float) Dte::where('estado', EstadoDte::Aceptado->value)
                ->whereIn('tipo_dte', [TipoDte::Factura->value, TipoDte::CreditoFiscal->value, TipoDte::FacturaExportacion->value])
                ->where('fecha_emision', '>=', $inicioMes)
                ->sum('total_pagar');
        }

        return [
            'dte_aceptados_mes' => $dteAceptadosMes,
            'ventas_mes' => $ventasMes,
            'documentos_pendientes' => $veOperativos ? DocumentoRecibido::where('estado', 'pendiente')->count() : null,
            'listas_recientes' => $veOperativos ? Exportacion::where('created_at', '>=', $inicioMes)->count() : null,
            'jobs_fallidos' => $jobsFallidos,
        ];
    }

    /**
     * Últimos DTE con algún movimiento real (enviados/aceptados/rechazados): un
     * borrador nunca aparece acá, no es "actividad" todavía. Un solo SELECT con
     * LIMIT 8 y el cliente precargado (evita N+1 en la tabla).
     */
    private function actividadReciente(): Collection
    {
        return Dte::query()
            ->with('cliente:id,nombre')
            ->whereIn('estado', [EstadoDte::Enviado->value, EstadoDte::Aceptado->value, EstadoDte::Rechazado->value])
            ->orderByDesc('fecha_emision')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'tipo_dte', 'estado', 'numero_control', 'cliente_id', 'total_pagar', 'fecha_emision']);
    }

    /**
     * Estado técnico compacto. Todo config()/cache: NADA de red (ni a Hacienda ni
     * al firmador) para que el dashboard nunca se quede esperando un servicio
     * externo. El firmador se reporta como "habilitado/mock/apagado" por
     * configuración, no con un ping en vivo (ese chequeo real ya existe, pero es
     * un health-check de red pensado para el checklist de preparación de
     * producción, no para cargar en cada visita al dashboard).
     *
     * @param  array<string, mixed>|null  $modo
     * @param  array<string, mixed>  $worker
     * @return array<string, mixed>
     */
    private function estadoTecnico(?array $modo, array $worker, int $jobsPendientes, int $jobsFallidos): array
    {
        return [
            'ambiente' => AmbienteHacienda::tryFrom((string) config('dte.ambiente'))?->label() ?? (string) config('dte.ambiente'),
            'punto_venta_predeterminado' => config('dte.punto_venta_predeterminado') ?: 'automático (único punto activo)',
            'dry_run' => (bool) config('dte.transmision.dry_run'),
            'worker_estado' => $worker['estado'],
            'worker_hace' => $worker['hace'],
            'jobs_pendientes' => $jobsPendientes,
            'jobs_fallidos' => $jobsFallidos,
            'firma_mock' => (bool) config('dte.firma.mock'),
            'modo' => $modo,
        ];
    }
}
