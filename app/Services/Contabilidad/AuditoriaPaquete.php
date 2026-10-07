<?php

namespace App\Services\Contabilidad;

use App\Models\User;

/**
 * Auditoría de los intentos de envío del paquete (usuario, destino, rango, conteos,
 * ZIP, estado). Un envío BLOQUEADO por cobertura también se audita, con los días que
 * faltaban: si mañana alguien pregunta por qué el paquete de agosto salió tarde, la
 * respuesta tiene que estar escrita en algún lado.
 */
class AuditoriaPaquete
{
    /**
     * @param  array<string, mixed>  $rango
     * @param  array<string, mixed>  $resumen
     * @param  array<string, mixed>|null  $cobertura
     */
    public function registrar(?User $usuario, string $estado, string $correo, array $rango, array $resumen, string $nombreZip, ?string $error, ?int $comprasMarcadas = null, ?array $cobertura = null, ?string $archivoDriveId = null): void
    {
        activity('paquete_contabilidad')
            ->causedBy($usuario)
            ->withProperties(array_filter([
                'correo_destino' => $correo,
                'rango' => $rango['desde'].' a '.$rango['hasta'],
                'etiqueta' => $rango['etiqueta'],
                'compras_cantidad' => $resumen['compras_cantidad'],
                'compras_total' => $resumen['compras_total'],
                'ventas_cantidad' => $resumen['ventas_cantidad'],
                'ventas_total' => $resumen['ventas_total'],
                'compras_marcadas_enviadas' => $comprasMarcadas,
                'cobertura_incompleta' => $cobertura === null ? null : ! $cobertura['cubierto'],
                'dias_faltantes' => $cobertura === null ? null : collect($cobertura['dias_pendientes'])->pluck('dia')->all(),
                'zip' => $nombreZip,
                'drive_archivo_id' => $archivoDriveId,
                'estado' => $estado,
                'error' => $error,
            ], fn ($v) => $v !== null))
            ->log("Envío de paquete de contabilidad {$rango['etiqueta']}: {$estado}");
    }
}
