<?php

namespace App\Ajustes\Definicion;

use App\Ajustes\Ajustes;

/**
 * Qué tan expuesto puede estar el VALOR de un ajuste.
 *
 *  - Publico: puede verse en pantalla y quedar escrito en la auditoría.
 *  - Interno: puede verse en pantalla (es configuración de la empresa), pero no
 *    tiene por qué salir de la aplicación.
 *  - SecretoCritico: jamás vuelve al navegador, jamás se audita su valor y se
 *    guarda cifrado. Ver {@see Ajustes::secretoParaRuntime()}.
 */
enum Sensibilidad: string
{
    case Publico = 'publico';
    case Interno = 'interno';
    case SecretoCritico = 'secreto_critico';

    /** ¿El valor puede quedar escrito en el registro de auditoría? */
    public function valorAuditable(): bool
    {
        return $this === self::Publico || $this === self::Interno;
    }
}
