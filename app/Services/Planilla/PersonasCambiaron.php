<?php

namespace App\Services\Planilla;

/**
 * Entre leer quiénes están en una planilla y tomar sus candados, alguien agregó a otra
 * persona. No se bloquea su ficha suelta, después de la planilla: se suelta todo y se
 * vuelve a empezar con el conjunto nuevo ({@see PrepararPlanilla::conCandados()}).
 */
final class PersonasCambiaron extends \RuntimeException {}
