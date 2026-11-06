<?php

namespace App\Services\Alertas\Detectores;

use App\Models\DocumentoRecibido;
use App\Services\Alertas\Detector;
use App\Services\Alertas\Hallazgo;
use App\Services\Contabilidad\AuditoriaPaquete;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Services\Reportes\ReporteContadoraQuery;
use App\Support\HoraNegocio;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Terminó el mes y su paquete para contabilidad (compras + ventas) no se mandó.
 *
 * Reemplaza a los avisos compra por compra: lo que hay que hacer una vez por mes es
 * mandar el paquete, y eso es lo único que se avisa.
 *
 * «Enviado» es lo que ya deja escrito el envío del paquete
 * ({@see AuditoriaPaquete}): una entrada `paquete_contabilidad`
 * con estado `enviado` y el rango del mes completo. Un envío simulado, fallido o
 * bloqueado no cuenta, y tampoco un envío de solo una parte del mes.
 *
 * El tipo lleva el mes: el paquete de octubre y el de noviembre son avisos distintos.
 * Así, si el 1 de diciembre sigue sin salir el de noviembre, llega su propio correo
 * en vez de quedar tapado por el de octubre.
 */
class PaqueteMensualPendiente implements Detector
{
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function tipo(): string
    {
        return 'paquete_mensual_pendiente.'.$this->mes()->format('Y-m');
    }

    public function nombre(): string
    {
        $mes = $this->mes();

        return 'Paquete de contabilidad de '.self::MESES[$mes->month].' '.$mes->year;
    }

    public function aplica(): bool
    {
        return true;
    }

    public function revisar(): ?Hallazgo
    {
        $mes = $this->mes();
        $desde = $mes->toDateString();
        $hasta = $mes->copy()->endOfMonth()->toDateString();

        if ($this->seEnvio($desde, $hasta) || ! $this->hayDocumentos($desde, $hasta)) {
            return null;
        }

        $nombre = self::MESES[$mes->month].' '.$mes->year;

        return new Hallazgo(
            titulo: 'Falta mandar el paquete de '.$nombre.' a contabilidad',
            detalle: 'Terminó '.$nombre.' y su paquete mensual (compras y ventas) todavía no se envió a contabilidad.',
            queHacer: 'Entrá a Contabilidad › Paquete mensual: el resumen de '.$nombre.' sale directo. Revisalo y apretá «Enviar a contabilidad».',
            desde: $mes->copy()->addMonthNoOverflow(),
            enlace: 'contabilidad.paquete',
        );
    }

    /** Primer día del mes que toca mandar: el anterior al de hoy, en hora del negocio. */
    private function mes(): Carbon
    {
        return HoraNegocio::ahora()->startOfMonth()->subMonthNoOverflow();
    }

    private function seEnvio(string $desde, string $hasta): bool
    {
        return Activity::query()
            ->where('log_name', 'paquete_contabilidad')
            ->where('properties->estado', 'enviado')
            ->where('properties->rango', $desde.' a '.$hasta)
            ->exists();
    }

    /** Mismo recorte que el paquete ({@see PeriodoPaquete}). */
    private function hayDocumentos(string $desde, string $hasta): bool
    {
        if (DocumentoRecibido::query()->paraContabilidad()->periodoFiscal($desde, $hasta)->exists()) {
            return true;
        }

        $f = ReporteContadoraQuery::filtros(['fecha_desde' => $desde, 'fecha_hasta' => $hasta]);
        $f['estado'] = 'fiscal';

        return ReporteContadoraQuery::query($f)->exists();
    }
}
