<?php

namespace App\Services\Contabilidad;

class PermisoDriveFaltante extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Falta autorizar Drive en Configuración → Integraciones.');
    }
}
