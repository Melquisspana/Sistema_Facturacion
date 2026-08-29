<?php

namespace App\Services\Ppq;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LOTE de notas de crédito para el formato del cliente: se eligen las notas pendientes
 * —de cualquier fecha—, se arma un archivo con una fila por nota y queda registrado qué
 * entró.
 *
 * El formato NO se llena todos los días. Las notas se acumulan durante los días o semanas
 * que haga falta y se exportan cuando toca, así que un mismo archivo puede mezclar notas
 * de fechas de emisión distintas. Las fechas son un FILTRO para encontrar, nunca una
 * condición para agrupar: forzar un lote por día dejaría olvidada cualquier nota emitida
 * fuera del día que el operador tuviera en pantalla.
 *
 * El registro de lo exportado no es contabilidad interna: es lo que impide exportar dos
 * veces la misma nota en dos archivos distintos, que para el cliente es un abono
 * duplicado. Por eso `nc_exportacion_items.dte_id` es único GLOBAL y no por lote.
 *
 * Regenerar es re-dibujar, no re-elegir: {@see archivo()} relee los items del lote y no
 * mira qué hay pendiente ahora. Como las notas exportadas están aceptadas por Hacienda y
 * por tanto son inmutables, el archivo regenerado sale idéntico.
 */
class NcExportacionService
{
    public function __construct(
        private readonly PerfilDocumentoResolver $perfiles,
        private readonly ExportadorNcFactory $exportadores,
    ) {}

    /**
     * Notas de crédito del cliente que TODAVÍA no entraron en ningún lote, de cualquier
     * fecha, de la más antigua a la más reciente.
     *
     * El orden no es estético: lo más viejo es lo que más riesgo tiene de quedarse sin
     * cobrar, así que aparece primero aunque el operador solo mire la parte de arriba.
     *
     * @param  array<string, mixed>  $filtros  desde, hasta, tipo, sala, q
     * @return Collection<int, Dte>
     */
    public function pendientes(Cliente $cliente, array $filtros = []): Collection
    {
        return $this->elegibles($cliente)
            ->whereDoesntHave('exportacionItem')
            ->tap(fn (Builder $q) => $this->aplicarFiltros($q, $filtros))
            ->with(['albaran', 'clienteSucursal:id,codigo,nombre'])
            ->orderBy('fecha_emision')
            ->orderBy('numero_control')
            ->orderBy('id')
            ->get();
    }

    /**
     * Las que YA entraron en un lote, con los mismos filtros. Se muestran aparte —y no se
     * ocultan— porque «no aparece en pendientes» es ambiguo: puede ser que ya se exportó,
     * que le falte el albarán o que nunca llegara a aceptarse. Verlas con su lote al lado
     * responde la pregunta sin salir de la pantalla.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Dte>
     */
    public function yaExportadas(Cliente $cliente, array $filtros = []): Collection
    {
        return $this->elegibles($cliente)
            ->whereHas('exportacionItem')
            ->tap(fn (Builder $q) => $this->aplicarFiltros($q, $filtros))
            ->with(['albaran', 'clienteSucursal:id,codigo,nombre', 'exportacionItem.exportacion:id,referencia'])
            ->orderBy('fecha_emision')
            ->orderBy('numero_control')
            ->orderBy('id')
            ->get();
    }

    /**
     * Notas ACEPTADAS del cliente que además tienen albarán registrado: sin él no se
     * pueden llenar cuatro de las diecisiete columnas del formato, así que ofrecerlas
     * sería ofrecer una fila incompleta.
     *
     * Solo entran las realmente aceptadas por Hacienda: el formato pide el sello de
     * recepción, que no existe hasta que el MH lo devuelve.
     *
     * @return Builder<Dte>
     */
    private function elegibles(Cliente $cliente): Builder
    {
        return Dte::query()
            ->where('tipo_dte', TipoDte::NotaCredito->value)
            ->where('cliente_id', $cliente->id)
            ->whereHas('albaran')
            ->aceptadoRealMh();
    }

    /**
     * Filtros OPCIONALES para encontrar, no para agrupar. Ninguno restringe el lote a una
     * fecha: quitarlos siempre devuelve el universo completo de pendientes.
     *
     * @param  Builder<Dte>  $q
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarFiltros(Builder $q, array $filtros): void
    {
        if ($desde = $this->fecha($filtros['desde'] ?? null)) {
            $q->whereDate('fecha_emision', '>=', $desde->toDateString());
        }
        if ($hasta = $this->fecha($filtros['hasta'] ?? null)) {
            $q->whereDate('fecha_emision', '<=', $hasta->toDateString());
        }

        // Tipo: se filtra por el código del ALBARÁN (AC02/AC04) y no por la modalidad
        // interna, porque es el dato que el operador tiene delante en el papel.
        if (filled($filtros['tipo'] ?? null)) {
            $tipo = strtoupper(trim((string) $filtros['tipo']));
            $q->whereHas('albaran', fn (Builder $a) => $a->where('tipo_codigo', $tipo));
        }

        if (filled($filtros['sala'] ?? null)) {
            $sala = trim((string) $filtros['sala']);
            $q->where(fn (Builder $w) => $w
                ->whereHas('albaran', fn (Builder $a) => $a->where('sala_codigo', $sala))
                ->orWhereHas('clienteSucursal', fn (Builder $s) => $s->where('codigo', $sala)));
        }

        if (filled($filtros['q'] ?? null)) {
            $texto = trim((string) $filtros['q']);
            $q->where(fn (Builder $w) => $w
                ->where('numero_control', 'like', "%{$texto}%")
                ->orWhere('numero_interno', 'like', "%{$texto}%")
                ->orWhereHas('albaran', fn (Builder $a) => $a
                    ->where('numero_canonico', 'like', "%{$texto}%")
                    ->orWhere('numero', 'like', "%{$texto}%")));
        }
    }

    private function fecha(mixed $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        return rescue(fn () => Carbon::parse((string) $valor)->startOfDay(), null, false);
    }

    /**
     * Salas presentes en las notas pendientes, para poblar el filtro sin ofrecer opciones
     * que no devolverían nada.
     *
     * @return array<int, string>
     */
    public function salasPendientes(Cliente $cliente): array
    {
        return $this->elegibles($cliente)
            ->whereDoesntHave('exportacionItem')
            ->with('albaran:id,dte_id,sala_codigo')
            ->get(['id'])
            ->map(fn (Dte $nc) => $nc->albaran?->sala_codigo)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Crea el lote con las notas indicadas, sean de la fecha que sean. Valida que todas
     * pertenezcan al cliente, sean elegibles y no estén ya exportadas; si alguna falla, no
     * se crea nada.
     *
     * @param  array<int, int>  $dteIds
     *
     * @throws ValidationException
     */
    public function crear(Cliente $cliente, array $dteIds, ?User $usuario = null): NcExportacion
    {
        $perfil = $this->perfilExportador($cliente);

        $dteIds = array_values(array_unique(array_map('intval', $dteIds)));
        if ($dteIds === []) {
            throw ValidationException::withMessages([
                'dtes' => 'Seleccione al menos una nota de crédito para incluir en el formato.',
            ]);
        }

        return DB::transaction(function () use ($cliente, $dteIds, $usuario, $perfil) {
            // Se relee dentro de la transacción: lo que llegó del navegador es una
            // intención, no una autorización.
            $notas = $this->elegibles($cliente)
                ->whereIn('id', $dteIds)
                ->orderBy('fecha_emision')
                ->orderBy('numero_control')
                ->orderBy('id')
                ->get();

            $this->verificarSeleccion($notas, $dteIds);

            $lote = NcExportacion::create([
                'cliente_id' => $cliente->id,
                'referencia' => $this->referencia($cliente, $perfil),
                'formato' => (string) $perfil->formato_export,
                'archivo_nombre' => $this->nombreArchivo($perfil),
                'user_id' => $usuario?->id,
            ]);

            foreach ($notas as $orden => $nota) {
                // El único de `dte_id` convierte una carrera entre dos operadores en un
                // error de integridad en vez de en un abono duplicado.
                NcExportacionItem::create([
                    'nc_exportacion_id' => $lote->id,
                    'dte_id' => $nota->id,
                    'orden' => $orden + 1,
                ]);
            }

            return $lote->refresh();
        });
    }

    /** Genera (o regenera) el archivo del lote. Devuelve la ruta temporal. */
    public function archivo(NcExportacion $lote): string
    {
        $perfil = $this->perfilExportador($lote->cliente);

        return $this->exportadores->porSlug($lote->formato)->generar($lote, $perfil);
    }

    /**
     * @param  Collection<int, Dte>  $notas
     * @param  array<int, int>  $pedidos
     *
     * @throws ValidationException
     */
    private function verificarSeleccion(Collection $notas, array $pedidos): void
    {
        $faltantes = array_diff($pedidos, $notas->pluck('id')->all());

        if ($faltantes !== []) {
            throw ValidationException::withMessages([
                'dtes' => 'Alguna de las notas seleccionadas ya no corresponde a este cliente, '
                    .'no tiene albarán registrado o todavía no tiene aceptación de Hacienda. '
                    .'Vuelva a cargar la lista.',
            ]);
        }

        $yaExportadas = NcExportacionItem::whereIn('dte_id', $pedidos)
            ->with('dte:id,numero_control')
            ->get();

        if ($yaExportadas->isNotEmpty()) {
            $lista = $yaExportadas
                ->map(fn (NcExportacionItem $i) => $i->dte?->numero_control ?? ('#'.$i->dte_id))
                ->implode(', ');

            throw ValidationException::withMessages([
                'dtes' => "Estas notas ya entraron en un lote anterior: {$lista}. "
                    .'Si necesita el archivo otra vez, descargue de nuevo el lote original: '
                    .'se regenera con el mismo contenido y no duplica documentos.',
            ]);
        }
    }

    /**
     * Perfil ACTIVO con formato configurado. Sin él no hay exportación posible, y decirlo
     * claro es mejor que producir un archivo vacío.
     *
     * @throws ValidationException
     */
    private function perfilExportador(Cliente $cliente): ClientePerfilDocumento
    {
        $perfil = $this->perfiles->paraCliente($cliente->id);

        if ($perfil === null || ! $perfil->exporta()) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Este cliente no tiene un perfil de documentos activo con formato de '
                    .'exportación configurado, así que no se le puede generar el formato de notas de crédito.',
            ]);
        }

        return $perfil;
    }

    /**
     * Referencia legible y única: {codigo}-{YYYYMMDD de generación}-{n del día}. La fecha
     * es la del ARCHIVO, no la de las notas; el correlativo por día solo evita colisiones
     * cuando se generan varios el mismo día.
     */
    private function referencia(Cliente $cliente, ClientePerfilDocumento $perfil): string
    {
        $hoy = Carbon::today();

        $previos = NcExportacion::where('cliente_id', $cliente->id)
            ->whereDate('created_at', $hoy->toDateString())
            ->count();

        $codigo = $perfil->codigo_proveedor ?: ('CLI'.$cliente->id);

        return sprintf('NC-%s-%s-%02d', $codigo, $hoy->format('Ymd'), $previos + 1);
    }

    /**
     * Nombre con el que viaja el archivo: {codigo}{YYYYMMDDHHmm}.xlsx, la misma convención
     * que ya usa el Excel de cobro. Se guarda en el lote para que regenerar devuelva el
     * mismo nombre y no parezca un archivo nuevo.
     */
    private function nombreArchivo(ClientePerfilDocumento $perfil): string
    {
        $codigo = $perfil->codigo_proveedor ?: 'NC';

        return $codigo.now('America/El_Salvador')->format('YmdHi').'.xlsx';
    }
}
