<?php

namespace App\Services\Rutas;

use App\Enums\EstadoPedidoSala;
use App\Facades\Ajustes;
use App\Http\Requests\Fabrica\MandarPedidoRequest;
use App\Models\PedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Models\PedidoSalaLinea;
use App\Models\User;
use App\Support\HoraNegocio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Lo que la fábrica manda de cada pedido de sala (/fabrica/pedidos). Toda la regla está
 * acá: qué falta, cuánto se puede mandar (nunca de más), los estados y la auditoría. Los
 * datos llegan ya validados en su forma por {@see MandarPedidoRequest}.
 *
 * CANDADOS. Toda escritura va en `DB::transaction($cb, 3)` (reintenta ante un deadlock) y
 * su PRIMER statement es el bloqueo del pedido: antes no se lee nada dentro de la
 * transacción. Así, en MySQL (REPEATABLE READ) la vista de lectura se crea DESPUÉS de tomar
 * el candado y las sumas de lo mandado ven lo último confirmado. Las pruebas corren en
 * SQLite y no lo pueden comprobar: lo confirma la CI con MySQL.
 *
 * Orden fijo: pedido → líneas → envíos. La fábrica NUNCA bloquea la salida; el vendedor
 * bloquea salida → pedido, así que no se forma un ciclo.
 *
 * Cantidades con bcmath a escala 4. Nunca float.
 */
class PedidosFabrica
{
    /** Días que se ven los pedidos cerrados en «Hechos», para reabrirlos o mandar lo que faltó. */
    public const DIAS_CERRADOS = 14;

    public const CAMBIO = 'Este pedido cambió mientras lo mirabas. Revisalo de nuevo.';

    public const ANULADO = 'Este pedido está anulado.';

    public const CERRADO = 'Este pedido ya está cerrado.';

    public const FACTURADO = 'Ya se facturó: avisale a la oficina.';

    /** «No mandar nada de este pedido» / «No mandar lo que falta» pide siempre el motivo. */
    public const SIN_MOTIVO = 'Elegí por qué no se manda.';

    public const MOTIVO_LARGO = 'El motivo puede tener hasta 200 caracteres.';

    /**
     * Lo que dice la auditoría cuando un pedido puede quedar cerrado con algo que no va. El
     * resumen diario ({@see ResumenDiarioPedidos}) lee estos registros para saber qué se
     * cerró y cuándo.
     */
    public const AUDITORIA_PARTE = 'mandó una parte del pedido';

    public const AUDITORIA_NO_SE_TRAE = 'marcó que el pedido no se trae';

    public const AUDITORIA_LO_QUE_FALTA = 'cerró el pedido: lo que falta no se trae';

    /** Lo que dice la auditoría al «Corregir» un envío: lo anula y deja marcado lo que llevaba. */
    public const AUDITORIA_CORRIGE = 'corrigió un envío del pedido';

    public const AUDITORIA_BORRO = 'borró lo marcado del pedido';

    public const AUDITORIA_ANULO = 'anuló un envío del pedido';

    public const AUDITORIA_TODO = 'mandó todo el pedido';

    /** Lo que dice la auditoría al mandar un envío; ahí va `corrige_numero` si corrige a otro. */
    public const AUDITORIA_MANDO = [self::AUDITORIA_TODO, self::AUDITORIA_PARTE];

    /**
     * Lo que falta mandar, de cualquier fecha desde `pedidos.fabrica_desde`, del más viejo
     * al más nuevo. Sin precios: la fábrica no tiene `productos.ver`.
     *
     * @return Builder<PedidoSala>
     */
    public function porMandar(): Builder
    {
        $desde = Ajustes::texto('pedidos.fabrica_desde');

        return PedidoSala::query()->porMandar()
            ->when(filled($desde), fn (Builder $q) => $q->whereDate('fecha', '>=', $desde))
            ->with($this->relaciones())
            ->orderBy('fecha')
            ->orderBy('id');
    }

    /**
     * Lo que dibuja la tarjeta y la hoja, de una vez (sin una consulta por pedido): la sala,
     * quién lo sacó, las líneas con lo ya mandado y los envíos para la versión.
     *
     * @return array<int|string, mixed>
     */
    public function relaciones(bool $conSala = false): array
    {
        // `$conSala`: el pedido abierto y la hoja de un envío suman la dirección de la sala (o
        // la del cliente) y el código de cada producto. Va en el mismo renglón y no repetido:
        // dos `select` de la misma relación se combinan y gana el último.
        return array_merge([
            $conSala ? 'sala:id,cliente_id,nombre,direccion' : 'sala:id,nombre',
            // `salida.ruta`: la zona del viaje, que se muestra si el pedido no tiene la suya
            // (la tienda nueva, {@see PedidoSala::zonaNombre()}).
            'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre', 'sacadoPor:id,nombre', 'armandoPor:id,name',
            'lineas' => fn ($q) => $q->orderBy('id'),
            $conSala ? 'lineas.producto:id,nombre,codigo' : 'lineas.producto:id,nombre',
            'lineas.envioLineasVigentes',
            'envios' => fn ($q) => $q->select('id', 'pedido_sala_id', 'numero', 'anulado_en')->orderBy('id'),
        ], $conSala ? ['sala.cliente:id,direccion'] : []);
    }

    /**
     * Lo que NO fue en un envío, tal como salió: por línea, lo pedido menos lo que llevaron
     * ese envío y los vigentes anteriores (por número). Los envíos que salieron después no
     * cuentan: reimpreso, el envío 1 sigue diciendo lo que le faltó. `ultimo` dice si después
     * de este no hubo NINGÚN envío, ni siquiera uno ya deshecho (que pudo dejar su motivo):
     * solo entonces el estado y los motivos actuales del pedido son los de este envío (los
     * motivos se guardan por línea, no por envío).
     *
     * @param  PedidoSala  $pedido  con `lineas.producto`
     * @return array{faltan: \Illuminate\Support\Collection<int, array{linea: PedidoSalaLinea, cantidad: string}>, ultimo: bool}
     */
    public function faltanteAlEnvio(PedidoSala $pedido, PedidoSalaEnvio $envio): array
    {
        $envios = $pedido->envios()->with('lineas')->get();
        $mandado = [];
        foreach ($envios->whereNull('anulado_en')->where('numero', '<=', $envio->numero) as $anterior) {
            foreach ($anterior->lineas as $l) {
                $mandado[$l->pedido_sala_linea_id] = bcadd($mandado[$l->pedido_sala_linea_id] ?? '0', (string) $l->cantidad, 4);
            }
        }

        $faltan = $pedido->lineas
            ->map(fn (PedidoSalaLinea $linea) => ['linea' => $linea, 'cantidad' => bcsub((string) $linea->cantidad, $mandado[$linea->id] ?? '0', 4)])
            ->filter(fn (array $f) => bccomp($f['cantidad'], '0', 4) === 1)
            ->values();

        return ['faltan' => $faltan, 'ultimo' => $envios->where('numero', '>', $envio->numero)->isEmpty()];
    }

    /**
     * Los demás pedidos por mandar, para la fila de arriba del pedido abierto: primero los
     * que se están armando y después el resto, cada grupo del más viejo al más nuevo. Solo
     * el nombre: sin líneas.
     *
     * @return Collection<int, PedidoSala>
     */
    public function otrosPorMandar(PedidoSala $pedido): Collection
    {
        return $this->porMandar()->withOnly(['sala:id,nombre'])->whereKeyNot($pedido->id)
            ->get(['id', 'cliente_sucursal_id', 'sala_nueva_nombre', 'armando_desde', 'fecha'])
            ->sortBy(fn (PedidoSala $p) => $p->armando_desde === null ? 1 : 0)
            ->values();
    }

    /**
     * «Hechos»: los envíos de hoy (también los anulados) y los pedidos cerrados de los
     * últimos días que todavía se pueden reabrir (los que no se traen y los mandados con
     * faltantes).
     *
     * @return array{envios: Collection<int, PedidoSalaEnvio>, cerrados: Collection<int, PedidoSala>, motivos: array<int, string>}
     */
    public function hechos(): array
    {
        $hoy = HoraNegocio::fechaHoy();

        $envios = PedidoSalaEnvio::query()
            ->whereDate('fecha', $hoy)
            // `dte`: el botón «Deshacer» se apaga si ya se facturó, sin una consulta por envío.
            ->with(['pedido.sala:id,nombre', 'registradoPor:id,name', 'anuladoPor:id,name', 'lineas.linea.producto:id,nombre', 'dte:id,estado,deleted_at'])
            ->orderByDesc('id')
            ->get();

        $cerrados = PedidoSala::query()
            ->whereIn('estado', [EstadoPedidoSala::NoSeTrae->value, EstadoPedidoSala::Enviado->value])
            ->whereDate('fecha', '>=', Carbon::parse($hoy)->subDays(self::DIAS_CERRADOS)->toDateString())
            ->with(['sala:id,nombre', 'ruta:id,nombre', 'salida:id,ruta_id', 'salida.ruta:id,nombre', 'lineas.producto:id,nombre', 'lineas.envioLineasVigentes'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PedidoSala $p) => $p->estado === EstadoPedidoSala::NoSeTrae || $p->faltaAlgo())
            ->values();

        return ['envios' => $envios, 'cerrados' => $cerrados, 'motivos' => $this->motivosDeCierre($cerrados->modelKeys())];
    }

    /**
     * Por qué no se mandó cada pedido cerrado con «No mandar…»: [pedido_id => motivo]. Cuenta
     * solo si lo último que lo cerró fue ese cierre; si después se reabrió y salió con un
     * envío, ya no. Los cierres de antes del 13/11/2026 no tienen motivo y no aparecen.
     *
     * @param  iterable<int>  $ids
     * @return array<int, string>
     */
    public function motivosDeCierre(iterable $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $cierres = [self::AUDITORIA_NO_SE_TRAE, self::AUDITORIA_LO_QUE_FALTA];

        return Activity::query()
            ->where('log_name', 'pedido_sala')
            ->where('subject_type', (new PedidoSala)->getMorphClass())
            ->whereIn('subject_id', $ids->all())
            ->whereIn('description', [...self::AUDITORIA_MANDO, ...$cierres])
            ->orderBy('id')
            ->get(['id', 'subject_id', 'description', 'properties'])
            ->keyBy('subject_id')
            ->filter(fn (Activity $a) => in_array($a->description, $cierres, true) && filled($a->properties['motivo'] ?? null))
            ->map(fn (Activity $a) => (string) $a->properties['motivo'])
            ->all();
    }

    /**
     * Guarda lo marcado en la hoja sin mandar nada (se llama solo, con cada cambio). El
     * pedido queda «Armando» desde el primero; eso se audita una vez, no cada tecla.
     *
     * El motivo marcado va en `motivo_borrador`: el oficial (`motivo_no_enviado`, el de un
     * envío anterior) no se toca hasta mandar.
     *
     * @param  array{cantidades?: array<int|string, string>, motivos?: array<int|string, ?string>, sigue?: bool}  $datos
     */
    public function guardarBorrador(PedidoSala $pedido, array $datos, User $usuario): void
    {
        DB::transaction(function () use ($pedido, $datos, $usuario) {
            $pedido = $this->bloquear($pedido);
            $this->exigirPorMandar($pedido);
            Gate::forUser($usuario)->authorize('mandar', $pedido);
            $this->cargarBloqueado($pedido);

            $cantidades = $this->normalizar($datos['cantidades'] ?? []);
            $motivos = $datos['motivos'] ?? [];
            $this->exigirLineasDelPedido($pedido, array_keys($cantidades), array_keys($motivos));
            $this->exigirNoDeMas($pedido, $cantidades);

            foreach ($pedido->lineas as $linea) {
                if (! array_key_exists($linea->id, $cantidades)) {
                    continue;
                }
                $linea->update([
                    'va_borrador' => $cantidades[$linea->id],
                    'motivo_borrador' => $this->motivo($motivos[$linea->id] ?? null),
                ]);
            }

            $pedido->update(['armando_sigue' => ! empty($datos['sigue'])]);
            if ($pedido->armando_desde === null) {
                $pedido->update(['armando_desde' => now(), 'armando_por' => $usuario->id]);
                activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                    ->withProperties(['estado' => $pedido->estado->value])
                    ->log('empezó a armar el pedido');
            }
        }, 3);
    }

    /** «Borrar lo marcado»: limpia el borrador y el armado. Lo ya mandado (y su motivo) no se toca. */
    public function borrarBorrador(PedidoSala $pedido, User $usuario): void
    {
        DB::transaction(function () use ($pedido, $usuario) {
            $pedido = $this->bloquear($pedido);
            $this->exigirPorMandar($pedido);
            Gate::forUser($usuario)->authorize('mandar', $pedido);
            $lineas = $pedido->lineas()->orderBy('id')->lockForUpdate()->get();

            $conBorrador = $lineas->whereNotNull('va_borrador');
            if ($conBorrador->isEmpty() && $pedido->armando_desde === null) {
                return;
            }
            foreach ($conBorrador as $linea) {
                $linea->update(['va_borrador' => null, 'motivo_borrador' => null]);
            }
            $pedido->update(['armando_desde' => null, 'armando_por' => null, 'armando_sigue' => false]);
            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties(['lineas' => $conBorrador->map(fn (PedidoSalaLinea $l) => [
                    'pedido_sala_linea_id' => $l->id, 'producto_id' => $l->producto_id, 'va_borrador' => (string) $l->getOriginal('va_borrador'),
                ])->values()->all()])
                ->log(self::AUDITORIA_BORRO);
        }, 3);
    }

    /**
     * Crea un envío con lo que va. La tarjeta («Se manda todo») y la hoja usan esta misma
     * puerta. Por defecto lo que falta NO va después: el pedido queda «Mandado» con sus
     * faltantes; con `sigue` (la casilla «Lo que falta va en otro envío») queda «Mandado en
     * parte» y sigue por mandar.
     *
     * @param  array{clave: string, version: string, cantidades: array<int|string, string>, motivos?: array<int|string, ?string>, sigue?: bool}  $datos
     */
    public function mandar(PedidoSala $pedido, array $datos, User $usuario): PedidoSalaEnvio
    {
        return DB::transaction(function () use ($pedido, $datos, $usuario) {
            $pedido = $this->bloquear($pedido);
            $cantidades = array_filter($this->normalizar($datos['cantidades']), fn (string $c) => bccomp($c, '0', 4) === 1);

            // IDEMPOTENCIA, antes de mirar el estado, la versión y el permiso: el segundo
            // toque de un doble toque devuelve el envío ya creado, no un 403 ni un error.
            // El unique de `clave` queda como última barrera en la base; con el pedido
            // bloqueado, dos envíos del mismo pedido ya van en fila.
            $existente = PedidoSalaEnvio::where('clave', $datos['clave'])->with('lineas')->first();
            if ($existente) {
                $guardadas = $existente->lineas->mapWithKeys(fn ($l) => [$l->pedido_sala_linea_id => (string) $l->cantidad])->all();
                ksort($guardadas);
                ksort($cantidades);
                if ($existente->pedido_sala_id !== $pedido->id || $existente->registrado_por !== $usuario->id || $guardadas !== $cantidades) {
                    throw ValidationException::withMessages(['clave' => 'Este envío ya se guardó con otros datos. Volvé a abrir el pedido.']);
                }

                return $existente;
            }

            $this->exigirPorMandar($pedido);
            Gate::forUser($usuario)->authorize('mandar', $pedido);
            $this->cargarBloqueado($pedido);
            $this->exigirVersion($pedido, $datos['version']);
            $motivos = $datos['motivos'] ?? [];
            $this->exigirLineasDelPedido($pedido, array_keys($this->normalizar($datos['cantidades'])), array_keys($motivos));
            $this->exigirNoDeMas($pedido, $cantidades);
            if ($cantidades === []) {
                throw ValidationException::withMessages(['cantidades' => 'No marcaste nada para mandar. Si no va nada, tocá «'
                    .($pedido->estado === EstadoPedidoSala::Parcial ? 'No mandar lo que falta' : 'No mandar nada de este pedido').'».']);
            }

            $anterior = $pedido->estado;
            $corrige = $this->correccionPendiente($pedido);
            $envio = $pedido->envios()->create([
                'numero' => ((int) $pedido->envios->max('numero')) + 1,
                'clave' => $datos['clave'],
                'fecha' => HoraNegocio::fechaHoy(),
                'registrado_por' => $usuario->id,
            ]);
            $envio->lineas()->createMany(array_map(
                fn ($id, $cantidad) => ['pedido_sala_linea_id' => (int) $id, 'cantidad' => $cantidad],
                array_keys($cantidades), $cantidades,
            ));

            $this->recargar($pedido);
            foreach ($pedido->lineas as $linea) {
                $linea->update([
                    'va_borrador' => null,
                    'motivo_borrador' => null,
                    'motivo_no_enviado' => $linea->tienePendiente() ? $this->motivo($motivos[$linea->id] ?? null) : null,
                ]);
            }
            $completo = ! $pedido->faltaAlgo();
            $pedido->update([
                'estado' => $this->estadoSegun($pedido, cerrar: empty($datos['sigue'])),
                'armando_desde' => null,
                'armando_por' => null,
                'armando_sigue' => false,
            ]);

            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties([
                    'envio_id' => $envio->id,
                    'numero' => $envio->numero,
                    'clave' => $envio->clave,
                    'fecha' => $envio->fecha->toDateString(),
                    'estado_anterior' => $anterior->value,
                    'estado_nuevo' => $pedido->estado->value,
                    'lineas' => $pedido->lineas->filter(fn ($l) => isset($cantidades[$l->id]))->map(fn ($l) => [
                        'pedido_sala_linea_id' => $l->id, 'producto_id' => $l->producto_id, 'cantidad' => $cantidades[$l->id],
                    ])->values()->all(),
                    'motivos' => $pedido->lineas->whereNotNull('motivo_no_enviado')->map(fn ($l) => [
                        'producto_id' => $l->producto_id, 'motivo' => $l->motivo_no_enviado,
                    ])->values()->all(),
                ] + ($corrige ? ['corrige_envio_id' => $corrige['envio_id'], 'corrige_numero' => $corrige['numero']] : []))
                ->log($completo ? self::AUDITORIA_TODO : self::AUDITORIA_PARTE);

            return $envio->setRelation('pedido', $pedido);
        }, 3);
    }

    /**
     * «No mandar nada de este pedido» (sin envíos vigentes: queda «No se trae») o «No mandar
     * lo que falta» (con envíos: queda «Mandado»): cierra el pedido. El motivo es obligatorio
     * (13/11/2026) y queda en la auditoría del cierre; de ahí lo leen «Hechos hoy» y el correo
     * diario ({@see motivosDeCierre()}). Si ya estaba cerrado no hace nada y devuelve null.
     */
    public function cerrar(PedidoSala $pedido, string $version, User $usuario, string $motivo): ?EstadoPedidoSala
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => self::SIN_MOTIVO]);
        }
        if (mb_strlen($motivo) > 200) {
            throw ValidationException::withMessages(['motivo' => self::MOTIVO_LARGO]);
        }

        return DB::transaction(function () use ($pedido, $version, $usuario, $motivo) {
            $pedido = $this->bloquear($pedido);
            if (in_array($pedido->estado, [EstadoPedidoSala::Enviado, EstadoPedidoSala::NoSeTrae], true)) {
                return null;
            }
            $this->exigirPorMandar($pedido);
            Gate::forUser($usuario)->authorize('mandar', $pedido);
            $this->cargarBloqueado($pedido);
            $this->exigirVersion($pedido, $version);

            $anterior = $pedido->estado;
            // Lo marcado y no mandado se descarta; el motivo oficial (el del último envío) queda.
            foreach ($pedido->lineas as $linea) {
                $linea->update(['va_borrador' => null, 'motivo_borrador' => null]);
            }
            $pedido->update(['estado' => $this->estadoSegun($pedido, cerrar: true), 'armando_desde' => null, 'armando_por' => null, 'armando_sigue' => false]);

            $propiedades = ['estado_anterior' => $anterior->value, 'estado_nuevo' => $pedido->estado->value, 'motivo' => $motivo];
            if ($pedido->estado === EstadoPedidoSala::NoSeTrae) {
                activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)->withProperties($propiedades)
                    ->log(self::AUDITORIA_NO_SE_TRAE);
            } else {
                activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                    ->withProperties($propiedades + ['faltantes' => $pedido->lineas->filter->tienePendiente()->map(fn ($l) => [
                        'producto_id' => $l->producto_id, 'pendiente' => $l->pendiente(),
                    ])->values()->all()])
                    ->log(self::AUDITORIA_LO_QUE_FALTA);
            }

            return $pedido->estado;
        }, 3);
    }

    /**
     * «Volver a pendiente» / «Mandar lo que faltó»: reabre lo cerrado que todavía se puede
     * mandar. No se pierde nada: queda pendiente (sin envíos) o en parte (con envíos).
     * Devuelve false si ya estaba por mandar.
     */
    public function reabrir(PedidoSala $pedido, User $usuario): bool
    {
        return DB::transaction(function () use ($pedido, $usuario) {
            $pedido = $this->bloquear($pedido);
            if ($pedido->estaPorMandar()) {
                return false;
            }
            $this->cargarBloqueado($pedido);
            Gate::forUser($usuario)->authorize('reabrir', $pedido);

            $anterior = $pedido->estado;
            $pedido->update(['estado' => $this->estadoSegun($pedido, cerrar: false)]);
            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties(['estado_anterior' => $anterior->value, 'estado_nuevo' => $pedido->estado->value])
                ->log('volvió a dejar pendiente el pedido');

            return true;
        }, 3);
    }

    /**
     * «Deshacer» un envío del día: queda anulado (no se borra) y el pedido se recalcula; lo
     * que llevaba vuelve a quedar pendiente. Devuelve false si ya estaba anulado.
     */
    public function anularEnvio(PedidoSala $pedido, PedidoSalaEnvio $envio, User $usuario): bool
    {
        return DB::transaction(fn () => $this->anularBloqueado($this->bloquear($pedido), $envio, $usuario) !== null, 3);
    }

    /**
     * «Corregir» un envío del día, en una sola operación: lo anula como «Deshacer» (mismos
     * límites, candados y auditoría) y deja marcado como borrador lo que había ido, para
     * cambiar solo lo que estuvo mal. Los motivos y la casilla «va en otro envío» vuelven
     * si se pueden saber: los motivos son por línea, así que solo si después de este no
     * salió ningún envío (ni uno ya deshecho), como en {@see faltanteAlEnvio()}; la casilla,
     * del estado del pedido antes de anular. Lo que estaba marcado para otro envío se
     * reemplaza. El próximo envío dice que corrige a este ({@see corrigeA()}).
     *
     * Devuelve false si ya estaba anulado (doble toque): no toca nada más.
     */
    public function corregirEnvio(PedidoSala $pedido, PedidoSalaEnvio $envio, User $usuario): bool
    {
        return DB::transaction(function () use ($pedido, $envio, $usuario) {
            $pedido = $this->bloquear($pedido);
            $anterior = $pedido->estado;
            $envio = $this->anularBloqueado($pedido, $envio, $usuario);
            if ($envio === null) {
                return false;
            }

            $ultimo = $pedido->envios->where('numero', '>', $envio->numero)->isEmpty();
            $lleva = $envio->lineas->mapWithKeys(fn ($l) => [$l->pedido_sala_linea_id => bcadd((string) $l->cantidad, '0', 4)])->all();
            $marcado = [];
            foreach ($pedido->lineas->filter->tienePendiente() as $linea) {
                $va = $lleva[$linea->id] ?? '0.0000';
                $linea->update([
                    'va_borrador' => $va,
                    'motivo_borrador' => $ultimo && bccomp($va, $linea->pendiente(), 4) === -1 ? $linea->motivo_no_enviado : null,
                ]);
                $marcado[] = ['pedido_sala_linea_id' => $linea->id, 'va_borrador' => $va];
            }
            $pedido->update(['armando_desde' => now(), 'armando_por' => $usuario->id, 'armando_sigue' => $anterior === EstadoPedidoSala::Parcial]);

            activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
                ->withProperties(['envio_id' => $envio->id, 'numero' => $envio->numero, 'lineas' => $marcado, 'sigue' => $pedido->armando_sigue])
                ->log(self::AUDITORIA_CORRIGE);

            return true;
        }, 3);
    }

    /**
     * El envío (anulado) que corrige este, o null. Se guarda en la auditoría del envío al
     * mandarlo (`corrige_envio_id`), sin columna propia. La hoja lo nombra por su hora.
     */
    public function corrigeA(PedidoSala $pedido, PedidoSalaEnvio $envio): ?PedidoSalaEnvio
    {
        $mando = Activity::query()
            ->where('subject_type', $pedido->getMorphClass())->where('subject_id', $pedido->id)
            ->whereIn('description', self::AUDITORIA_MANDO)
            ->orderByDesc('id')->get(['id', 'properties'])
            ->first(fn (Activity $a) => (int) $a->properties['envio_id'] === $envio->id);

        return isset($mando?->properties['corrige_envio_id'])
            ? $pedido->envios()->whereKey((int) $mando->properties['corrige_envio_id'])->first()
            : null;
    }

    /**
     * Si lo último que pasó con el pedido fue «Corregir», el envío que se está mandando
     * corrige a ese: [envio_id, numero]. La corrección se pierde con cualquier cosa que
     * pase después: otro envío, «Borrar lo marcado» (lo que se mande ya no sale de la
     * corrección), un cierre o deshacer otro envío (lo que se mande ya no reemplaza solo al
     * corregido). La anulación propia de «Corregir» se audita ANTES que «corrigió», en la
     * misma transacción, así que no la corta. Se lee con el pedido bloqueado: dos envíos
     * no se llevan la misma corrección.
     *
     * @return array{envio_id: int, numero: int}|null
     */
    private function correccionPendiente(PedidoSala $pedido): ?array
    {
        $ultima = Activity::query()
            ->where('subject_type', $pedido->getMorphClass())->where('subject_id', $pedido->id)
            ->whereIn('description', [
                ...self::AUDITORIA_MANDO, self::AUDITORIA_CORRIGE, self::AUDITORIA_BORRO, self::AUDITORIA_ANULO,
                self::AUDITORIA_NO_SE_TRAE, self::AUDITORIA_LO_QUE_FALTA,
            ])
            ->orderByDesc('id')->first(['id', 'description', 'properties']);

        return $ultima?->description === self::AUDITORIA_CORRIGE
            ? ['envio_id' => (int) $ultima->properties['envio_id'], 'numero' => (int) $ultima->properties['numero']]
            : null;
    }

    /**
     * La anulación de un envío, dentro de la transacción y con el pedido ya bloqueado:
     * bloquea las líneas y el envío (el orden de la clase), revisa los límites y audita.
     * Devuelve el envío anulado (con el pedido recargado) o null si ya lo estaba.
     */
    private function anularBloqueado(PedidoSala $pedido, PedidoSalaEnvio $envio, User $usuario): ?PedidoSalaEnvio
    {
        $lineas = $pedido->lineas()->orderBy('id')->lockForUpdate()->get();
        $envio = $pedido->envios()->whereKey($envio->id)->lockForUpdate()->firstOrFail()->setRelation('pedido', $pedido);
        if (! $envio->estaVigente()) {
            return null;
        }
        // Con las filas bloqueadas (FacturarPedido toma los mismos candados): si la
        // oficina lo facturó mientras tanto, se avisa en vez de dar un 403.
        if ($envio->estaFacturado()) {
            throw ValidationException::withMessages(['envio' => self::FACTURADO]);
        }
        Gate::forUser($usuario)->authorize('anular', $envio);

        $anterior = $pedido->estado;
        $envio->update(['anulado_en' => now(), 'anulado_por' => $usuario->id]);
        $pedido->setRelation('lineas', $lineas);
        $this->recargar($pedido);
        foreach ($pedido->lineas as $linea) {
            if (! $linea->tienePendiente() && $linea->motivo_no_enviado !== null) {
                $linea->update(['motivo_no_enviado' => null]);
            }
        }
        $pedido->update(['estado' => $this->estadoSegun($pedido, cerrar: false)]);

        activity('pedido_sala')->performedOn($pedido)->causedBy($usuario)
            ->withProperties([
                'envio_id' => $envio->id,
                'numero' => $envio->numero,
                'lineas' => $envio->lineas()->get(['pedido_sala_linea_id', 'cantidad'])->toArray(),
                'estado_anterior' => $anterior->value,
                'estado_nuevo' => $pedido->estado->value,
            ])
            ->log(self::AUDITORIA_ANULO);

        return $envio;
    }

    /**
     * La regla única de estado. Con algún envío vigente: «Mandado» si no falta nada o si se
     * cierra; si no, «Mandado en parte». Sin envíos vigentes: «No se trae» si se cierra; si
     * no, «Pendiente».
     */
    private function estadoSegun(PedidoSala $pedido, bool $cerrar): EstadoPedidoSala
    {
        if ($pedido->envios->whereNull('anulado_en')->isEmpty()) {
            return $cerrar ? EstadoPedidoSala::NoSeTrae : EstadoPedidoSala::Registrado;
        }

        return $cerrar || ! $pedido->faltaAlgo() ? EstadoPedidoSala::Enviado : EstadoPedidoSala::Parcial;
    }

    /** El PRIMER statement de cada transacción (ver la nota de la clase). */
    private function bloquear(PedidoSala $pedido): PedidoSala
    {
        return PedidoSala::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
    }

    /** Líneas (bloqueadas, en orden) y envíos (bloqueados), con datos frescos. */
    private function cargarBloqueado(PedidoSala $pedido): void
    {
        $pedido->setRelation('lineas', $pedido->lineas()->orderBy('id')->lockForUpdate()->with(['producto:id,nombre', 'envioLineasVigentes'])->get());
        $pedido->setRelation('envios', $pedido->envios()->orderBy('id')->lockForUpdate()->get());
    }

    /** Después de escribir un envío: vuelve a leer lo mandado por línea y los envíos. */
    private function recargar(PedidoSala $pedido): void
    {
        $pedido->lineas->load(['producto:id,nombre', 'envioLineasVigentes']);
        $pedido->setRelation('envios', $pedido->envios()->orderBy('id')->get());
    }

    private function exigirPorMandar(PedidoSala $pedido): void
    {
        if ($pedido->estado === EstadoPedidoSala::Anulado) {
            throw ValidationException::withMessages(['pedido' => self::ANULADO]);
        }
        if (! $pedido->estaPorMandar()) {
            throw ValidationException::withMessages(['pedido' => self::CERRADO]);
        }
    }

    private function exigirVersion(PedidoSala $pedido, string $version): void
    {
        if (! hash_equals($pedido->versionProduccion(), $version)) {
            throw ValidationException::withMessages(['pedido' => self::CAMBIO]);
        }
    }

    /**
     * Cada clave tiene que ser una línea de ESTE pedido: si no, la pantalla es vieja (el
     * vendedor corrigió antes de que la fábrica empezara) o la mandaron a mano.
     *
     * @param  list<int|string>  ...$listas
     */
    private function exigirLineasDelPedido(PedidoSala $pedido, array ...$listas): void
    {
        $ids = $pedido->lineas->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach ($listas as $claves) {
            foreach ($claves as $clave) {
                if (! in_array((string) $clave, $ids, true)) {
                    throw ValidationException::withMessages(['pedido' => self::CAMBIO]);
                }
            }
        }
    }

    /**
     * Nunca de más: lo que va no pasa de lo que falta.
     *
     * @param  array<int, string>  $cantidades
     */
    private function exigirNoDeMas(PedidoSala $pedido, array $cantidades): void
    {
        $errores = [];
        foreach ($pedido->lineas as $linea) {
            $va = $cantidades[$linea->id] ?? '0';
            $pendiente = $linea->pendiente();
            if (bccomp($va, $pendiente, 4) === 1) {
                $errores['cantidades.'.$linea->id] = 'De '.($linea->producto?->nombre ?? 'ese producto').' faltaba mandar '.PedidoSalaLinea::legible($pendiente).'.';
            }
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * [linea_id => cantidad a escala 4]. Vacío es 0.
     *
     * @param  array<int|string, mixed>  $cantidades
     * @return array<int, string>
     */
    private function normalizar(array $cantidades): array
    {
        $normalizadas = [];
        foreach ($cantidades as $id => $cantidad) {
            $normalizadas[$id] = bcadd(filled($cantidad) ? (string) $cantidad : '0', '0', 4);
        }

        return $normalizadas;
    }

    private function motivo(mixed $motivo): ?string
    {
        return filled($motivo) ? trim((string) $motivo) : null;
    }
}
