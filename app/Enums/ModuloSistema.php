<?php

namespace App\Enums;

use App\Ajustes\CatalogoAjustes;
use App\Facades\Ajustes;

/**
 * Módulos que se prenden y apagan desde Configuración › Módulos.
 *
 * Cada uno es un ajuste del Centro de Configuración (`modulos.*`, declarado en
 * {@see CatalogoAjustes}) y se resuelve como cualquier otro: valor
 * guardado desde la pantalla → config/.env → defecto. Así, lo que se decide en la
 * pantalla manda aunque el .env diga lo contrario, y una instalación que nunca
 * tocó la pantalla sigue haciendo exactamente lo que dice su .env.
 *
 * TODO lector de estos interruptores pasa por {@see activo()}: el menú, las áreas,
 * los middlewares `modulo.*`, los controladores, los comandos y el programador de
 * tareas. Si alguno leyera `config('gastos.enabled')` directo, apagar Gastos desde
 * la pantalla escondería el menú pero dejaría la puerta abierta (o al revés).
 *
 * ARRANQUE SIN BASE. Estos interruptores se leen en sitios donde la base puede no
 * estar: `routes/console.php` corre con cualquier comando de artisan (también con
 * `package:discover` en un `composer install`), y el menú se dibuja en todas las
 * pantallas. Sin la tabla `ajustes_sistema` el repositorio ya cae solo al valor de
 * config; si además la base no responde, {@see activo()} cae al mismo valor de config
 * en vez de romper. Es lo que el sistema hacía antes de que existiera la pantalla.
 */
enum ModuloSistema: string
{
    case Gastos = 'gastos';
    case Planta = 'planta';
    case Asistencia = 'asistencia';
    case Planilla = 'planilla';
    case PlanillaPagos = 'planilla_pagos';

    /** Clave del Centro de Configuración. */
    public function clave(): string
    {
        return 'modulos.'.$this->value;
    }

    /** Clave de config/*.php que hace de respaldo (y que a su vez lee el .env). */
    public function claveConfig(): string
    {
        return match ($this) {
            self::Gastos => 'gastos.enabled',
            self::Planta => 'planta.enabled',
            self::Asistencia => 'asistencia.enabled',
            self::Planilla => 'planilla.enabled',
            self::PlanillaPagos => 'planilla.pagos_enabled',
        };
    }

    /** Variable del .env, para quien administra el servidor. */
    public function variableEntorno(): string
    {
        return match ($this) {
            self::Gastos => 'GASTOS_ENABLED',
            self::Planta => 'PLANTA_ENABLED',
            self::Asistencia => 'ASISTENCIA_ENABLED',
            self::Planilla => 'PLANILLA_ENABLED',
            self::PlanillaPagos => 'PLANILLA_PAGOS_ENABLED',
        };
    }

    /** Mismo valor por defecto que config/*.php. */
    public function porDefecto(): bool
    {
        return $this === self::PlanillaPagos;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Gastos => 'Gastos y pagos',
            self::Planta => 'Producción',
            self::Asistencia => 'Asistencia',
            self::Planilla => 'Planilla',
            self::PlanillaPagos => 'Pagos de planilla',
        };
    }

    /** Qué es el módulo, en una línea. */
    public function descripcion(): string
    {
        return match ($this) {
            self::Gastos => 'Gastos, pagos, repeticiones y avisos de vencimiento.',
            self::Planta => 'Insumos, recepciones, traslados, lotes y existencias de la planta.',
            self::Asistencia => 'Marcaciones con el lector de huella y jornadas, dentro de Personal.',
            self::Planilla => 'Planillas de sueldos y fichas de las personas, dentro de Personal.',
            self::PlanillaPagos => 'Lo pagado de cada planilla y los anticipos; necesita Planilla encendida. Los pagos quedan registrados en Gastos.',
        };
    }

    /** Qué deja de verse al apagarlo. Se muestra en la confirmación. */
    public function alApagar(): string
    {
        return match ($this) {
            self::Gastos => 'Desaparece el área «Gastos y pagos» del menú, sus pantallas responden «no encontrado» y dejan de generarse los gastos repetidos y los avisos de vencimiento.',
            self::Planta => 'Desaparece el área «Producción» del menú y sus pantallas responden «no encontrado», también para la administración.',
            self::Asistencia => 'Desaparece Asistencia de Personal y los lectores de huella dejan de registrar marcaciones: el servidor les responde «no encontrado».',
            self::Planilla => 'Desaparece Planilla de Personal y sus pantallas responden «no encontrado».',
            self::PlanillaPagos => 'La planilla deja de llevar lo pagado: no se ven las pantallas de pagos ni de anticipos, y la hoja impresa muestra el total a pagar de cada persona.',
        };
    }

    /**
     * ¿Está encendido? Valor guardado desde la pantalla → config/.env → defecto.
     *
     * Si la base no responde, cae al valor de config: el menú y el programador no
     * pueden romperse por leer un interruptor.
     */
    public function activo(): bool
    {
        return (bool) rescue(
            fn () => Ajustes::bool($this->clave(), $this->porDefecto()),
            fn () => (bool) config($this->claveConfig(), $this->porDefecto()),
            report: false,
        );
    }
}
