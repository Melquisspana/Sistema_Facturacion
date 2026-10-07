<?php

namespace Tests\Feature\Contabilidad;

use App\Services\Contabilidad\SubidaDrivePaqueteContrato;

class DrivePaqueteFalso implements SubidaDrivePaqueteContrato
{
    public array $archivos = [];

    public array $llamadas = [];

    public ?\Throwable $error = null;

    public function subir(string $ruta, string $nombre, int $anio, int $mes, string $correo): array
    {
        $this->llamadas[] = compact('ruta', 'nombre', 'anio', 'mes', 'correo');
        if ($this->error) {
            throw $this->error;
        }
        $clave = sprintf('Paquetes contabilidad/%04d/%02d/%s', $anio, $mes, $nombre);

        return $this->archivos[$clave] = ['id' => 'drive-paquete-1', 'webViewLink' => 'https://drive.google.com/file/d/drive-paquete-1/view'];
    }
}
