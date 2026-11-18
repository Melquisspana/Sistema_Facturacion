<?php

namespace App\Exceptions\Dte;

/**
 * El pedido de token a Hacienda no tuvo NINGUNA respuesta HTTP: no resolvió el nombre,
 * no pudo conectar o se acabó el tiempo. Sin token no se arma el envío, así que el
 * documento no salió de la máquina.
 *
 * Es una {@see DteTransmisionException} a propósito: para todos los que ya atrapan esa
 * sigue siendo el mismo error de siempre. Solo la política de reintentos la distingue,
 * para saber que ese intento no llegó a mandar nada.
 *
 * NO es esto un login rechazado (401/403), una respuesta rara ni credenciales que
 * faltan: ahí Hacienda o la configuración contestaron algo, y eso es otro error.
 */
class DteHaciendaSinConexionException extends DteTransmisionException {}
