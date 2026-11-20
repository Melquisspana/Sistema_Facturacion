<?php

namespace App\Services\Alertas\Detectores;

use App\Facades\Ajustes;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la página anotó que le falló (GET /api/sistema/errores, guardado por
 * {@see ImportarPedidosWeb}) y los pedidos pagados que no llegan. Dos avisos, como
 * {@see EventosRecientes}, para que lo que impide vender tenga su propio tope diario y
 * una basura que manda cualquiera no se lo gaste:
 *
 *  - pagos(): la tienda no puede cobrar, un pago que no abrió, uno sin comprobar;
 *  - otras(): errores internos, avisos con firma mala, consultas que fallan, pedidos
 *    pagados que no se pudieron leer o quedaron en revisión, y las listas que no llegan.
 *
 * Si la página no contesta, no opinan: de eso avisa {@see TiendaWebSinRespuesta} y no
 * llegan dos correos por lo mismo. Cada texto sale de {@see self::CATALOGO}: nada del
 * comprador, ni montos, ni lo que mandó la página tal cual.
 */
class TiendaWebFallas implements Detector
{
    /**
     * Cada línea, de la más grave a la menos grave: frase, qué hacer y mínimo de veces.
     * «:n» es la cantidad.
     */
    private const CATALOGO = [
        // Pagos
        'no_cobra' => ['La tienda no puede cobrar: los compradores ven “tienda cerrada”',
            'Avisá a soporte: faltan las llaves de Wompi o la dirección del sitio en Cloudflare.', 1],
        'cobro_llaves' => ['No se pudo abrir el pago: Wompi no aceptó las llaves de la tienda',
            'Copiá otra vez el App ID y la API Secret del aplicativo En Vivo en Cloudflare.', 1],
        'cobro_modo' => ['No se pudo abrir el pago: la tienda no está en el modo En Vivo de Wompi',
            'Revisá en Wompi que el aplicativo siga En Vivo.', 1],
        'cobro_wompi' => ['No se pudo abrir el pago: Wompi está fallando o lento',
            'Esperá unos minutos y probá una compra; si sigue, revisá el estado de Wompi o avisá a soporte.', 1],
        'cobro_llamada' => ['No se pudo abrir el pago: la página no logró llamar a Wompi', 'Avisá a soporte.', 1],
        'cobro_guardar' => ['No se pudo abrir el pago: no se pudo guardar el enlace', 'Avisá a soporte.', 1],
        'cobro_otra' => ['No se pudo abrir el pago: otra falla', 'Avisá a soporte.', 1],
        'error_consulta' => ['Llegó un pago que no se pudo comprobar con Wompi',
            'Buscalo en el panel de Wompi. El pedido no se pierde: si no se confirma solo, a los 7 días queda en revisión.', 1],
        'vencido_sin_confirmar' => [':n pedidos se cancelaron solos sin que Wompi confirmara si estaban pagados',
            'Buscá en el panel de Wompi los pagos de hace 7 días y, si alguno entró, atendelo a mano.', 1],
        // Otras
        'invalidos' => [':n pedidos pagados que el sistema no pudo leer (n.º :ids); esos no entran solos',
            'El cliente ya pagó y el pedido no entra: pasale esos números a soporte.', 1],
        'revision' => [':n pedidos quedaron en revisión en la página: :codigos',
            'Buscá cada código en el panel de Wompi; si el pago entró, avisá a soporte para cargarlo.', 1],
        'revision_lista' => ['No se pudo leer la lista de pedidos en revisión de la página', 'Avisá a soporte.', 1],
        'interno_hacer_pedido' => ['La página tuvo un error interno al hacer un pedido', 'Reenviá este correo a soporte.', 1],
        'interno_seguir_pedido' => ['La página tuvo un error interno al seguir un pedido', 'Reenviá este correo a soporte.', 1],
        'interno_aviso_pago' => ['La página tuvo un error interno al recibir un aviso de pago', 'Reenviá este correo a soporte.', 1],
        'interno_sistema' => ['La página tuvo un error interno al contestarle al sistema', 'Reenviá este correo a soporte.', 1],
        'interno_otra' => ['La página tuvo un error interno', 'Reenviá este correo a soporte.', 1],
        'firma_no_cuadra' => ['Llegó un aviso de pago con una firma que no coincide',
            'Revisá que la API Secret en Cloudflare sea la del aplicativo En Vivo.', 1],
        'firma_sin_cabecera' => ['Llegó un aviso de pago sin la firma de Wompi',
            'Avisá a soporte: Cloudflare puede estar quitando la firma de los avisos.', 1],
        'consulta_fallida' => ['La consulta de reserva a Wompi falló :n veces',
            'Los pagos se confirman igual cuando llega el aviso de Wompi; si se repite, avisá a soporte.', 3],
        'lista' => ['No se pudo leer la lista de errores de la página',
            'Si recién se subió el sistema, falta subir la página; si no, avisá a soporte.', 1],
    ];

    private const SINGULAR = [
        'vencido_sin_confirmar' => '1 pedido se canceló solo sin que Wompi confirmara si estaba pagado',
        'invalidos' => '1 pedido pagado que el sistema no pudo leer (n.º :ids); ese no entra solo',
        'revision' => '1 pedido quedó en revisión en la página: :codigos',
    ];

    /** Líneas que dicen cómo están las cosas y no cuántas veces pasó algo. */
    private const ESTADOS = ['no_cobra', 'revision_lista', 'lista'];

    private const MAX_DETALLE = 4;

    private const MAX_QUE_HACER = 3;

    private function __construct(private readonly bool $pagos) {}

    public static function pagos(): self
    {
        return new self(true);
    }

    public static function otras(): self
    {
        return new self(false);
    }

    public function tipo(): string
    {
        return $this->pagos ? 'tienda_web_pagos' : 'tienda_web_fallas';
    }

    public function nombre(): string
    {
        return $this->pagos ? 'Pagos en la tienda web' : 'Otras fallas de la tienda web';
    }

    /**
     * Con la traída automática prendida y una lectura buena reciente. Pagos pide además la
     * lista de errores reciente: con una vieja daría un «normalizado» falso.
     */
    public function aplica(): bool
    {
        if (! Ajustes::boolSinBase('tienda.pedidos.automatica') || ! $this->reciente(TiendaWebSinRespuesta::lectura()['ok'] ?? null)) {
            return false;
        }

        return ! $this->pagos || $this->reciente($this->errores()['en'] ?? null);
    }

    public function revisar(): ?Hallazgo
    {
        $lineas = $this->pagos ? $this->lineasDePagos() : $this->lineasDeOtras();
        $lineas = array_filter($lineas, fn (array $l) => $l['cantidad'] >= self::CATALOGO[$l['clave']][2]);
        if ($lineas === []) {
            return null;
        }
        $orden = array_flip(array_keys(self::CATALOGO));
        usort($lineas, fn (array $a, array $b) => $orden[$a['clave']] <=> $orden[$b['clave']]);

        $una = count($lineas) === 1;
        $detalle = $una
            ? ($lineas[0]['cuando'] !== '' ? ucfirst($lineas[0]['cuando']).'.' : 'Revisado a las '.HoraNegocio::ahora()->format('H:i').'.')
            : implode(' ', array_map(fn (array $l) => $l['titulo'].($l['cuando'] !== '' ? ': '.$l['cuando'] : '').'.',
                array_slice($lineas, 0, self::MAX_DETALLE)));
        $primeras = array_filter(array_column($lineas, 'primera'));

        return new Hallazgo(
            titulo: $una ? $lineas[0]['titulo'] : 'La tienda web tiene '.count($lineas).' problemas',
            detalle: $detalle,
            queHacer: implode(' ', array_slice(array_values(array_unique(array_map(fn (array $l) => self::CATALOGO[$l['clave']][1], $lineas))), 0, self::MAX_QUE_HACER)),
            // Las líneas de estado suman 1: «Sigue la falla» sale solo si empeora de verdad.
            conteo: array_sum(array_map(fn (array $l) => in_array($l['clave'], self::ESTADOS, true) ? 1 : $l['cantidad'], $lineas)),
            desde: $primeras === [] ? null : Carbon::createFromTimestamp(min($primeras)),
        );
    }

    /** @return list<array<string, mixed>> */
    private function lineasDePagos(): array
    {
        $errores = $this->errores();
        $lineas = ($errores['cobra'] ?? true) === false ? [$this->linea('no_cobra', 1)] : [];

        foreach ($this->agrupar($errores['errores'] ?? [], fn (array $e) => match ($e['tipo']) {
            'error_cobro' => self::motivoDeCobro($e['detalle']),
            'error_consulta', 'vencido_sin_confirmar' => $this->enVentana($e) ? $e['tipo'] : null,
            default => null,
        }) as $clave => $grupo) {
            // El pago que no abrió sigue abierto hasta que otro comprador consiga su enlace.
            $lineas[] = $this->linea($clave, $grupo['cantidad'], $grupo, ventana: ! str_starts_with($clave, 'cobro_'));
        }

        return $lineas;
    }

    /** @return list<array<string, mixed>> */
    private function lineasDeOtras(): array
    {
        $lineas = [];
        // Se fija en la primera revisión, no recién cuando falta una lista: así una que
        // vence o deja de llegar más tarde no da un «normalizado» falso de 15 minutos.
        $vigilando = TiendaWebSinRespuesta::vigilando();
        $lectura = TiendaWebSinRespuesta::lectura();
        if (($lectura['invalidos'] ?? 0) > 0) {
            $ids = $lectura['invalidos_ids'] ?? [];
            $mas = $lectura['invalidos'] - count($ids);
            $lineas[] = $this->linea('invalidos', $lectura['invalidos'], reemplazos: [
                ':ids' => implode(', ', $ids).($mas > 0 ? ' y '.$mas.' más' : ''),
            ]);
        }
        // Una lista de revisión vieja (la ruta dejó de contestar) no se usa: el pedido pudo
        // resolverse u otro quedar en revisión. Sin lista, cuenta desde que se vigila. Se
        // pide cada MINUTOS_REVISION: con menos margen, una caída de la página avisaría acá
        // además de en TiendaWebSinRespuesta.
        $revision = Cache::get(ImportarPedidosWeb::CLAVE_REVISION);
        $revision = is_array($revision) ? $revision : [];
        if (! $this->reciente($revision['en'] ?? $vigilando, ImportarPedidosWeb::MINUTOS_REVISION)) {
            $lineas[] = $this->linea('revision_lista', 1);
        } elseif (($revision['n'] ?? 0) > 0) {
            $codigos = $revision['codigos'] ?? [];
            $mas = $revision['n'] - count($codigos);
            $lineas[] = $this->linea('revision', $revision['n'], reemplazos: [
                ':codigos' => implode(', ', $codigos).($mas > 0 ? ' y '.$mas.' más' : ''),
            ]);
        }

        $errores = $this->errores();
        foreach ($this->agrupar($errores['errores'] ?? [], fn (array $e) => ! $this->enVentana($e) ? null : match ($e['tipo']) {
            'error_interno' => 'interno_'.self::ruta($e['detalle']),
            'firma_invalida' => $e['detalle'] === 'sin_cabecera' ? 'firma_sin_cabecera' : 'firma_no_cuadra',
            'consulta_fallida' => 'consulta_fallida',
            default => null,
        }) as $clave => $grupo) {
            $lineas[] = $this->linea($clave, $grupo['cantidad'], $grupo, ventana: true);
        }

        // Una página vieja sin la ruta, o una que dejó de contestarla: cuenta desde que se vigila.
        if (! $this->reciente($errores['en'] ?? $vigilando)) {
            $lineas[] = $this->linea('lista', 1);
        }

        return $lineas;
    }

    /**
     * Las filas de la página, sumadas por la clave que les da $clave (null = no cuenta).
     *
     * @return array<string, array{cantidad: int, primera: int, ultima: int}>
     */
    private function agrupar(array $filas, callable $clave): array
    {
        $grupos = [];
        foreach ($filas as $fila) {
            $k = $clave($fila);
            if ($k === null) {
                continue;
            }
            $grupos[$k] = [
                'cantidad' => ($grupos[$k]['cantidad'] ?? 0) + $fila['cantidad'],
                'primera' => min($grupos[$k]['primera'] ?? $fila['primera'], $fila['primera']),
                'ultima' => max($grupos[$k]['ultima'] ?? $fila['ultima'], $fila['ultima']),
            ];
        }

        return $grupos;
    }

    /** @param  array{primera: int, ultima: int}|null  $grupo */
    private function linea(string $clave, int $cantidad, ?array $grupo = null, bool $ventana = false, array $reemplazos = []): array
    {
        $frase = $cantidad === 1 && isset(self::SINGULAR[$clave]) ? self::SINGULAR[$clave] : self::CATALOGO[$clave][0];
        $cuando = '';
        if ($grupo !== null) {
            $ultima = HoraNegocio::aLocal(Carbon::createFromTimestamp($grupo['ultima']));
            // Si la frase ya dice cuántos, no se repite.
            $cuando = (str_contains(self::CATALOGO[$clave][0], ':n') ? '' : $cantidad.($cantidad === 1 ? ' vez' : ' veces'))
                .($ventana ? ' en las últimas 24 horas' : '')
                .', la última '.($ultima->isSameDay(HoraNegocio::ahora()) ? '' : 'el '.$ultima->format('d/m').' ')
                .'a las '.$ultima->format('H:i');
            $cuando = ltrim($cuando, ' ,');
        }

        return [
            'clave' => $clave, 'cantidad' => $cantidad, 'primera' => $grupo['primera'] ?? null, 'cuando' => $cuando,
            'titulo' => strtr($frase, $reemplazos + [':n' => (string) $cantidad]),
        ];
    }

    /** El detalle fijo que anota la página al fallar el cobro, en una categoría con su frase. */
    private static function motivoDeCobro(string $detalle): string
    {
        return match (true) {
            preg_match('/\btoken HTTP 4\d\d\b|\bHTTP 40[13]\b/', $detalle) === 1 => 'cobro_llaves',
            str_contains($detalle, 'modo equivocado') => 'cobro_modo',
            preg_match('/\bHTTP 5\d\d\b|sin respuesta/', $detalle) === 1 => 'cobro_wompi',
            str_contains($detalle, 'TypeError') => 'cobro_llamada',
            str_contains($detalle, 'guardar') => 'cobro_guardar',
            default => 'cobro_otra',
        };
    }

    /** «seguir_pedido · TypeError» → seguir_pedido; lo desconocido, otra. */
    private static function ruta(string $detalle): string
    {
        $ruta = explode(' · ', $detalle)[0];

        return in_array($ruta, ['hacer_pedido', 'seguir_pedido', 'aviso_pago', 'sistema'], true) ? $ruta : 'otra';
    }

    /** Lo que pasa y se va (no un pago que no abrió) cuenta solo si pasó hace poco, como en EventosRecientes. */
    private function enVentana(array $fila): bool
    {
        return $fila['ultima'] >= now()->subMinutes((int) config('alertas.ventana_eventos_minutos', 30))->getTimestamp();
    }

    /** @param  int  $cadencia  cada cuántos minutos se pide eso a la página, además de la gracia */
    private function reciente(?int $en, int $cadencia = 0): bool
    {
        return $en !== null && $en >= now()->subMinutes(TiendaWebSinRespuesta::MINUTOS + $cadencia)->getTimestamp();
    }

    /** @return array{en?: int, cobra?: bool, errores?: list<array{tipo: string, detalle: string, cantidad: int, primera: int, ultima: int}>} */
    private function errores(): array
    {
        $errores = Cache::get(ImportarPedidosWeb::CLAVE_ERRORES);

        return is_array($errores) ? $errores : [];
    }
}
