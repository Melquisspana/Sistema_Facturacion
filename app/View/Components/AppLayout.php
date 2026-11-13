<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  bool  $pantallaCompleta  Sin la barra lateral ni el menú del sistema: arriba
     *                                  solo la empresa, la persona y «Salir» (la pantalla de
     *                                  la fábrica, `<x-app-layout :pantalla-completa="true">`).
     */
    public function __construct(public bool $pantallaCompleta = false) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
