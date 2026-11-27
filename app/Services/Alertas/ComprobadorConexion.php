<?php

namespace App\Services\Alertas;

/**
 * ¿El servidor llega a internet? Lo pregunta {@see RegistroEventos} cuando falla una
 * tarea programada, para avisar «Sin conexión a internet» una vez en lugar de una
 * falla por cada tarea.
 */
interface ComprobadorConexion
{
    public function hayInternet(): bool;
}
