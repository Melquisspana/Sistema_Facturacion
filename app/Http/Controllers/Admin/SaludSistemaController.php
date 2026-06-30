<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoNotaCredito;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Panel SOLO LECTURA "Salud del sistema / Preparación para empresa".
 * No toca facturación ni cálculos: solo lee config, archivos, conteos y auditoría.
 * No expone secretos (.env, APP_KEY, DB_PASSWORD).
 */
class SaludSistemaController extends Controller
{
    private const EMAIL_ADMIN_TEMPORAL = 'admin@dulceslanegrita.test';

    public function index(): View
    {
        // Acceso solo administrador (además del middleware de ruta).
        abort_unless(request()->user()?->hasRole('administrador'), 403);

        $seguridad = $this->seguridad();
        $backups = $this->backups();
        $datos = $this->datos();
        $alertas = $this->alertas();
        $auditoria = $this->auditoriaReciente();

        // Estado general: critico > advertencia > ok.
        $niveles = collect($seguridad)->pluck('estado')
            ->merge([$backups['ultimo']['estado']])
            ->merge(collect($backups['scripts'])->pluck('estado'))
            ->merge(collect($backups['docs'])->pluck('estado'))
            ->merge(collect($alertas)->where('count', '>', 0)->pluck('estado'));

        if ($niveles->contains('critico')) {
            $general = ['texto' => 'Sistema NO listo para producción', 'estado' => 'critico'];
        } elseif ($niveles->contains('advertencia')) {
            $general = ['texto' => 'Sistema requiere atención', 'estado' => 'advertencia'];
        } else {
            $general = ['texto' => 'Sistema listo para pruebas internas', 'estado' => 'ok'];
        }

        return view('admin.salud-sistema', compact('general', 'seguridad', 'backups', 'datos', 'alertas', 'auditoria'));
    }

    /** @return array<int, array<string, mixed>> */
    private function seguridad(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $adminTemp = User::where('email', self::EMAIL_ADMIN_TEMPORAL)->first();
        $adminsActivos = User::role('administrador')->where('activo', true)->count();
        $inactivos = User::where('activo', false)->count();

        return [
            [
                'label' => 'Entorno (APP_ENV)', 'valor' => $env,
                'estado' => $env === 'production' ? 'ok' : 'advertencia',
                'detalle' => $env === 'production' ? 'Producción.' : 'Entorno local/desarrollo.',
            ],
            [
                'label' => 'Modo debug (APP_DEBUG)', 'valor' => $debug ? 'true' : 'false',
                'estado' => $debug ? 'critico' : 'ok',
                'detalle' => $debug ? 'APP_DEBUG=true no debe usarse en producción.' : 'Debug desactivado.',
            ],
            [
                'label' => 'URL (APP_URL)', 'valor' => (string) config('app.url'),
                'estado' => 'info', 'detalle' => 'URL configurada de la aplicación.',
            ],
            [
                'label' => 'Admin temporal', 'valor' => ($adminTemp && $adminTemp->activo) ? 'activo' : ($adminTemp ? 'inactivo' : 'no existe'),
                'estado' => ($adminTemp && $adminTemp->activo) ? 'critico' : 'ok',
                'detalle' => ($adminTemp && $adminTemp->activo)
                    ? 'Existe '.self::EMAIL_ADMIN_TEMPORAL.' activo: crear admin real y darlo de baja.'
                    : 'Sin admin temporal activo.',
            ],
            [
                'label' => 'Administradores activos', 'valor' => (string) $adminsActivos,
                'estado' => $adminsActivos === 0 ? 'critico' : ($adminsActivos === 1 ? 'advertencia' : 'ok'),
                'detalle' => $adminsActivos >= 2 ? 'Hay respaldo de administrador.' : 'Conviene tener al menos 2 administradores.',
            ],
            [
                'label' => 'Usuarios inactivos', 'valor' => (string) $inactivos,
                'estado' => 'info', 'detalle' => 'Cantidad de usuarios inactivos.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function backups(): array
    {
        $nombre = (string) config('backup.backup.name', config('app.name'));
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$nombre);
        $rutaMostrada = 'storage/app/private/'.$nombre;

        $ultimo = ['estado' => 'critico', 'detalle' => 'No se encontró ningún backup.', 'nombre' => null, 'fecha' => null, 'tamano' => null];
        if (File::isDirectory($dir)) {
            $zips = collect(File::files($dir))->filter(fn ($f) => strtolower($f->getExtension()) === 'zip')
                ->sortByDesc(fn ($f) => $f->getMTime())->values();
            if ($zips->isNotEmpty()) {
                $f = $zips->first();
                $fecha = Carbon::createFromTimestamp($f->getMTime());
                $reciente = $fecha->gt(now()->subDay());
                $ultimo = [
                    'estado' => $reciente ? 'ok' : 'advertencia',
                    'detalle' => $reciente ? 'Backup reciente (menos de 1 día).' : 'El último backup tiene más de 1 día.',
                    'nombre' => $f->getFilename(),
                    'fecha' => $fecha->format('d/m/Y H:i'),
                    'tamano' => $this->humano($f->getSize()),
                ];
            }
        }

        $scripts = [];
        foreach (['scripts/backup-run.bat', 'scripts/backup-clean.bat', 'scripts/backup-restore-test.bat'] as $rel) {
            $existe = File::exists(base_path($rel));
            $scripts[] = ['label' => $rel, 'valor' => $existe ? 'presente' : 'falta', 'estado' => $existe ? 'ok' : 'advertencia'];
        }
        $docs = [];
        foreach (['docs/BACKUPS_WINDOWS.md', 'docs/RESTORE_BACKUP_WINDOWS.md'] as $rel) {
            $existe = File::exists(base_path($rel));
            $docs[] = ['label' => $rel, 'valor' => $existe ? 'presente' : 'falta', 'estado' => $existe ? 'ok' : 'advertencia'];
        }

        return ['ruta' => $rutaMostrada, 'ultimo' => $ultimo, 'scripts' => $scripts, 'docs' => $docs];
    }

    /** @return array<int, array{label: string, valor: int}> */
    private function datos(): array
    {
        $activos = Producto::where('activo', true);
        $conBarra = (clone $activos)->whereNotNull('codigo_barra')->where('codigo_barra', '!=', '')->count();
        $totalProd = $activos->count();
        $conPrecio = Producto::where('activo', true)->where('precio_unitario', '>', 0)->count();

        $porTipo = fn (TipoDte $t) => Dte::where('tipo_dte', $t->value)->count();
        $porEstado = fn (EstadoDte $e) => Dte::where('estado', $e->value)->count();

        return [
            ['label' => 'Clientes activos', 'valor' => Cliente::where('activo', true)->count()],
            ['label' => 'Salas/sucursales activas', 'valor' => ClienteSucursal::where('activo', true)->count()],
            ['label' => 'Productos activos', 'valor' => $totalProd],
            ['label' => 'Productos con código de barra', 'valor' => $conBarra],
            ['label' => 'Productos sin código de barra', 'valor' => $totalProd - $conBarra],
            ['label' => 'Productos con precio general', 'valor' => $conPrecio],
            ['label' => 'Productos sin precio general', 'valor' => $totalProd - $conPrecio],
            ['label' => 'Precios especiales activos', 'valor' => ProductoPrecioCliente::where('activo', true)->count()],
            ['label' => 'Documentos DTE (total)', 'valor' => Dte::count()],
            ['label' => 'Borradores', 'valor' => $porEstado(EstadoDte::Borrador)],
            ['label' => 'Generados', 'valor' => $porEstado(EstadoDte::Generado)],
            ['label' => 'Invalidados/anulados', 'valor' => $porEstado(EstadoDte::Invalidado)],
            ['label' => 'CCF', 'valor' => $porTipo(TipoDte::CreditoFiscal)],
            ['label' => 'Facturas consumidor final', 'valor' => $porTipo(TipoDte::Factura)],
            ['label' => 'Exportaciones', 'valor' => $porTipo(TipoDte::FacturaExportacion)],
            ['label' => 'Notas de crédito', 'valor' => $porTipo(TipoDte::NotaCredito)],
        ];
    }

    /** @return array<int, array{label: string, count: int, estado: string}> */
    private function alertas(): array
    {
        // Productos activos sin precio aplicable (general <=0 y sin especial activo).
        $conEspecial = ProductoPrecioCliente::where('activo', true)->pluck('producto_id')->unique();
        $sinPrecio = Producto::where('activo', true)
            ->where(fn ($q) => $q->whereNull('precio_unitario')->orWhere('precio_unitario', '<=', 0))
            ->whereNotIn('id', $conEspecial)->count();

        $sinBarra = Producto::where('activo', true)
            ->where(fn ($q) => $q->whereNull('codigo_barra')->orWhere('codigo_barra', ''))->count();

        $cliDup = Cliente::whereNotNull('num_documento')
            ->selectRaw('num_documento, count(*) c')->groupBy('num_documento')->havingRaw('count(*) > 1')->get()->count();

        // Salas duplicadas por cliente + nombre normalizado (colapsar espacios).
        $salasDup = ClienteSucursal::where('activo', true)->get(['cliente_id', 'nombre'])
            ->groupBy(fn ($s) => $s->cliente_id.'|'.mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s->nombre))))
            ->filter(fn ($g) => $g->count() > 1)->count();

        $preciosDup = ProductoPrecioCliente::where('activo', true)
            ->selectRaw('producto_id, cliente_id, cliente_sucursal_id, count(*) c')
            ->groupBy('producto_id', 'cliente_id', 'cliente_sucursal_id')->havingRaw('count(*) > 1')->get()->count();

        $ccfSinNumero = Dte::where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('estado', EstadoDte::Generado->value)->whereNull('numero_interno')->count();

        $generadosSinLineas = Dte::where('estado', EstadoDte::Generado->value)->whereDoesntHave('lineas')->count();

        $borradorTotal0 = Dte::where('estado', EstadoDte::Borrador->value)->where('total_pagar', 0)->count();

        $ncAutoRel = Dte::where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereColumn('dte_relacionado_id', 'id')->count();

        $ncSinTipo = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->whereNull('tipo_nota_credito')->count();

        $ncDevolSinRel = Dte::where('tipo_dte', TipoDte::NotaCredito->value)
            ->whereIn('tipo_nota_credito', [TipoNotaCredito::DevolucionProducto->value, TipoNotaCredito::FaltanteEntrega->value])
            ->whereNull('dte_relacionado_id')->count();

        // estado: 'critico' = rompe invariantes; 'advertencia' = a revisar.
        return [
            ['label' => 'Productos activos sin precio aplicable', 'count' => $sinPrecio, 'estado' => 'advertencia'],
            ['label' => 'Productos activos sin código de barra', 'count' => $sinBarra, 'estado' => 'advertencia'],
            ['label' => 'Clientes duplicados por número de documento', 'count' => $cliDup, 'estado' => 'critico'],
            ['label' => 'Salas duplicadas (cliente + nombre normalizado)', 'count' => $salasDup, 'estado' => 'advertencia'],
            ['label' => 'Precios activos duplicados (producto+cliente+sucursal)', 'count' => $preciosDup, 'estado' => 'critico'],
            ['label' => 'CCF generados sin número interno', 'count' => $ccfSinNumero, 'estado' => 'critico'],
            ['label' => 'Documentos generados sin líneas', 'count' => $generadosSinLineas, 'estado' => 'critico'],
            ['label' => 'Documentos borrador con total 0', 'count' => $borradorTotal0, 'estado' => 'advertencia'],
            ['label' => 'Notas de crédito relacionadas consigo mismas', 'count' => $ncAutoRel, 'estado' => 'critico'],
            ['label' => 'Notas de crédito sin tipo', 'count' => $ncSinTipo, 'estado' => 'critico'],
            ['label' => 'NC devolución/faltante sin documento relacionado', 'count' => $ncDevolSinRel, 'estado' => 'critico'],
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function auditoriaReciente()
    {
        return Activity::with('causer')->latest()->limit(10)->get()->map(fn (Activity $a) => [
            'usuario' => $a->causer?->name ?? 'sistema',
            'log' => $a->log_name ?? '—',
            'accion' => $a->description,
            'modelo' => $a->subject_type ? class_basename($a->subject_type).($a->subject_id ? ' #'.$a->subject_id : '') : '—',
            'fecha' => $a->created_at?->format('d/m/Y H:i'),
        ]);
    }

    private function humano(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }
}
