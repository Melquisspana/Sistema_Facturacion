<?php

namespace App\Services\Ppq;

use App\Enums\ProcedenciaArchivoNc;
use App\Enums\TipoDte;
use App\Exceptions\CopiaArchivadaInservibleException;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Dte;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\Exportadores\ExportadorNc;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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
 * El archivo se genera UNA vez, releyendo los items del lote —nunca lo que hay pendiente
 * ahora—, y se archiva con su SHA-256; las descargas siguientes sirven esa copia byte a
 * byte ({@see archivo()}). Los lotes descargados antes de existir el archivado se
 * reconstruyen una vez y quedan marcados como reconstrucción.
 *
 * El FORMATO queda congelado en el lote (`nc_exportaciones.formato`) y no se relee del
 * perfil. Un cliente puede cambiar de formato —de un Excel que se adjunta a un correo a
 * uno que se sube a su portal— y los lotes anteriores se generan con el formato con el
 * que nacieron.
 *
 * Y ninguna nota entra con un hueco: cada formato declara qué datos necesita
 * ({@see ExportadorNc::faltantes()}) y el sistema lo dice
 * antes de armar el archivo, en vez de rellenar la celda con algo supuesto.
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
        return $this->consultaPendientes($cliente, $filtros)->get();
    }

    /**
     * Las MISMAS pendientes de {@see pendientes()}, por páginas: la pantalla no carga todas
     * de golpe. Una sola consulta define las reglas y el orden (fecha, control, id), que
     * termina en `id` y por eso es estable entre páginas.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<Dte>
     */
    public function pendientesPaginadas(Cliente $cliente, array $filtros, int $porPagina, string $parametro): LengthAwarePaginator
    {
        return $this->consultaPendientes($cliente, $filtros)
            ->paginate($porPagina, ['*'], $parametro)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Dte>
     */
    private function consultaPendientes(Cliente $cliente, array $filtros): Builder
    {
        return $this->elegibles($cliente)
            ->whereDoesntHave('exportacionItem')
            ->tap(fn (Builder $q) => $this->aplicarFiltros($q, $filtros))
            ->with(['albaran', 'clienteSucursal:id,codigo,nombre'])
            ->orderBy('fecha_emision')
            ->orderBy('numero_control')
            ->orderBy('id');
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
            $this->verificarDatosCompletos($notas, $perfil);

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

    /**
     * Registra que una persona CARGÓ el archivo del lote al portal del cliente. El sistema
     * no lo sube ni lo comprueba: es la declaración de alguien, con su nombre y su fecha.
     *
     * Es un hecho aparte: no toca el `estado` del lote (generado/descargado), ni sus notas,
     * ni ningún valor fiscal. Se registra una sola vez —la historia no se reescribe— y solo
     * sobre un lote que se descargó alguna vez: no se puede haber subido un archivo que
     * nunca salió del sistema.
     *
     * @throws ValidationException
     */
    public function registrarPresentacion(
        NcExportacion $lote,
        User $usuario,
        ?Carbon $cuando = null,
        ?string $referenciaPortal = null,
        ?string $nota = null,
    ): NcExportacion {
        return DB::transaction(function () use ($lote, $usuario, $cuando, $referenciaPortal, $nota) {
            $fila = NcExportacion::lockForUpdate()->findOrFail($lote->id);

            if ($fila->presentada()) {
                throw ValidationException::withMessages([
                    'presentacion' => 'La carga de este archivo al portal ya estaba registrada el '
                        .$fila->presentada_en->format('d/m/Y').'. No se vuelve a registrar.',
                ]);
            }

            if (! $fila->descargadoAlgunaVez()) {
                throw ValidationException::withMessages([
                    'presentacion' => 'Este archivo nunca se descargó, así que no pudo subirse al portal. '
                        .'Descárguelo, súbalo y después registre la carga.',
                ]);
            }

            $fila->forceFill([
                'presentada_en' => $cuando ?? now(),
                'presentada_por' => $usuario->id,
                'referencia_portal' => filled($referenciaPortal) ? trim($referenciaPortal) : null,
                'presentada_nota' => filled($nota) ? trim($nota) : null,
            ])->save();

            activity('nc_exportacion')
                ->performedOn($fila)
                ->causedBy($usuario)
                ->withProperties([
                    'presentada_en' => $fila->presentada_en->toIso8601String(),
                    'referencia_portal' => $fila->referencia_portal,
                ])
                ->log('registró la carga del archivo de notas de crédito al portal');

            return $fila;
        });
    }

    /**
     * Ruta TEMPORAL con el archivo del lote (quien la sirve la borra al enviarla).
     *
     * ═════════ La primera vez se genera y archiva; después se sirve LA COPIA ═════════
     *
     * Con copia archivada (`archivo_hash` + `archivo_path`) se devuelven esos bytes tras
     * comprobar su SHA-256, sin llamar al exportador ni leer el perfil actual. Copia
     * ausente, ilegible, alterada o registro a medias: error claro, sin regenerar.
     *
     * Sin copia, se genera con el formato con el que nació el lote, se archiva verificada y
     * se sirven esos mismos bytes. Si el lote YA se había descargado antes (sin copia),
     * la copia se registra como RECONSTRUCCIÓN: la base no demuestra qué se bajó
     * entonces. Todo bajo bloqueo de la fila: dos primeras descargas simultáneas no
     * archivan dos archivos distintos.
     *
     * @throws RuntimeException
     */
    public function archivo(NcExportacion $lote): string
    {
        return DB::transaction(function () use ($lote) {
            $fila = NcExportacion::lockForUpdate()->findOrFail($lote->id);

            if ($fila->tieneCopiaArchivada()) {
                $contenido = $this->leerCopia($fila);
                $this->sincronizarCopia($lote, $fila);

                return $this->temporal($contenido);
            }

            $procedencia = $fila->descargadoSinCopia()
                ? ProcedenciaArchivoNc::Reconstruccion
                : ProcedenciaArchivoNc::PrimeraDescarga;

            $fila->loadMissing('cliente');
            $perfil = $this->perfilExportador($fila->cliente);
            $generado = $this->exportadores->porSlug($fila->formato)->generar($fila, $perfil);
            $contenido = file_get_contents($generado);
            @unlink($generado);

            if ($contenido === false || $contenido === '') {
                throw new RuntimeException("No se pudo generar el archivo del lote {$fila->referencia}.");
            }

            // Si archivar falla, la excepción deshace la transacción: el lote queda sin
            // copia y la próxima descarga vuelve a intentarlo.
            $this->guardarCopia($fila, $contenido, $procedencia);
            $this->sincronizarCopia($lote, $fila);

            return $this->temporal($contenido);
        });
    }

    /**
     * Los bytes archivados, verificados contra su huella.
     *
     * @throws RuntimeException
     */
    private function leerCopia(NcExportacion $lote): string
    {
        $referencia = $lote->referencia;

        // Huella, ruta, procedencia y fecha: sin cualquiera de las cuatro no se sabe qué se
        // archivó ni de dónde salió, y eso no se infiere.
        if (! $lote->registroDeCopiaCompleto()) {
            throw new CopiaArchivadaInservibleException("El lote {$referencia} tiene el registro de su copia incompleto "
                .'(huella, ruta, procedencia o fecha de archivado vacía). No se regenera: revisar la copia '
                .'archivada antes de volver a descargarlo.', (string) $referencia);
        }

        try {
            $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
            $existe = $disco->exists($lote->archivo_path);
            $contenido = $existe ? $disco->get($lote->archivo_path) : null;
        } catch (Throwable $e) {
            throw new CopiaArchivadaInservibleException("No se pudo leer la copia archivada del lote {$referencia}: "
                .$e->getMessage().'. No se regenera.', (string) $referencia, $e);
        }

        if (! $existe) {
            throw new CopiaArchivadaInservibleException("No se encontró la copia archivada del lote {$referencia}. "
                .'No se regenera un archivo distinto del que se archivó.', (string) $referencia);
        }

        if (! is_string($contenido) || ! hash_equals((string) $lote->archivo_hash, hash('sha256', $contenido))) {
            throw new CopiaArchivadaInservibleException("La copia archivada del lote {$referencia} no coincide con su huella "
                .'SHA-256 o no se pudo leer. No se sirve ni se regenera.', (string) $referencia);
        }

        return $contenido;
    }

    /**
     * Guarda la copia direccionada por su contenido y solo registra la huella cuando la
     * escritura está comprobada.
     *
     * @throws RuntimeException
     */
    private function guardarCopia(NcExportacion $lote, string $contenido, ProcedenciaArchivoNc $procedencia): void
    {
        $hash = hash('sha256', $contenido);
        $directorio = trim((string) config('ppq.nc_exportaciones.storage_dir', 'ppq/nc-exportaciones'), '/');
        $destino = $directorio.'/'.$hash.'.xlsx';

        try {
            $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
            $escrito = $disco->put($destino, $contenido);
            $releido = $escrito ? $disco->get($destino) : null;
        } catch (Throwable $e) {
            throw new RuntimeException("No se pudo archivar el archivo del lote {$lote->referencia}: "
                .$e->getMessage(), 0, $e);
        }

        if (! is_string($releido) || ! hash_equals($hash, hash('sha256', $releido))) {
            throw new RuntimeException("No se pudo archivar el archivo del lote {$lote->referencia}: "
                .'la copia escrita no se pudo comprobar.');
        }

        $lote->forceFill([
            'archivo_hash' => $hash,
            'archivo_path' => $destino,
            'archivo_origen' => $procedencia->value,
            'archivado_en' => now(),
        ])->save();
    }

    /** El modelo del llamador refleja la copia registrada, sin otra escritura. */
    private function sincronizarCopia(NcExportacion $lote, NcExportacion $fila): void
    {
        if ($lote === $fila) {
            return;
        }

        $campos = ['archivo_hash', 'archivo_path', 'archivo_origen', 'archivado_en'];
        foreach ($campos as $campo) {
            $lote->setAttribute($campo, $fila->getAttribute($campo));
        }
        $lote->syncOriginalAttributes($campos);
    }

    /**
     * Copia temporal de los bytes para servirlos. Se usa el archivo de tempnam() tal cual
     * (sin extensión, para no dejar un vacío huérfano); el nombre y el tipo de la descarga
     * los pone el controlador.
     *
     * @throws RuntimeException
     */
    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'nc_lote_');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo preparar la descarga del archivo del lote.');
        }

        if (file_put_contents($ruta, $contenido) !== strlen($contenido)) {
            @unlink($ruta);

            throw new RuntimeException('No se pudo preparar la descarga del archivo del lote.');
        }

        return $ruta;
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
     * Ninguna nota entra en el archivo con un hueco donde el formato espera un dato.
     *
     * Qué hace falta lo decide el FORMATO, no este servicio: el de carga masiva necesita
     * año y mes del albarán, el de correo tolera celdas vacías porque quien lo recibe es
     * una persona. Acá solo se junta lo que cada formato reporta y se dice, nota por nota,
     * qué falta. Se corta el lote entero —y no se exporta «lo que sí está»— porque un lote
     * a medias obliga a rastrear después cuáles quedaron fuera, y las que entraron ya no
     * pueden volver a salir en otro archivo.
     *
     * @param  Collection<int, Dte>  $notas
     *
     * @throws ValidationException
     */
    private function verificarDatosCompletos(Collection $notas, ClientePerfilDocumento $perfil): void
    {
        $exportador = $this->exportadores->para($perfil);

        $problemas = [];
        foreach ($notas as $nota) {
            $faltantes = $exportador->faltantes($nota, $perfil);
            if ($faltantes !== []) {
                $problemas[] = ($nota->numero_control ?: ('#'.$nota->id)).': falta '.implode(', ', $faltantes);
            }
        }

        if ($problemas !== []) {
            throw ValidationException::withMessages([
                'dtes' => 'No se generó ningún archivo: a estas notas les falta un dato que el formato '
                    .'exige, y el sistema no lo inventa. Completalo y volvé a intentar. — '
                    .implode(' · ', $problemas),
            ]);
        }
    }

    /**
     * Qué le falta a cada nota para poder exportarse con el formato del cliente, para
     * mostrarlo ANTES de generar nada. Devuelve solo las que tienen algo pendiente, con el
     * id de la nota como clave.
     *
     * @param  Collection<int, Dte>  $notas
     * @return array<int, array<int, string>>
     */
    public function faltantes(Cliente $cliente, Collection $notas): array
    {
        $exportador = $this->exportador($cliente);
        $perfil = $this->perfiles->paraCliente($cliente->id);

        if ($exportador === null || $perfil === null) {
            return [];
        }

        $faltantes = [];
        foreach ($notas as $nota) {
            $suyos = $exportador->faltantes($nota, $perfil);
            if ($suyos !== []) {
                $faltantes[$nota->id] = $suyos;
            }
        }

        return $faltantes;
    }

    /**
     * Exportador configurado hoy para el cliente, o null si no tiene uno utilizable.
     *
     * Versión NO lanzadora de {@see perfilExportador()}, para las pantallas: entrar a ver
     * el historial de un cliente mal configurado tiene que mostrar el historial, no una
     * excepción. Generar sí falla, y con el motivo.
     */
    public function exportador(Cliente $cliente): ?ExportadorNc
    {
        $perfil = $this->perfiles->paraCliente($cliente->id);

        if ($perfil === null || ! $perfil->exporta() || ! $this->exportadores->existe((string) $perfil->formato_export)) {
            return null;
        }

        return $this->exportadores->para($perfil);
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
