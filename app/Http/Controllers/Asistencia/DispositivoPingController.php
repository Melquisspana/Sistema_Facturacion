<?php

namespace App\Http\Controllers\Asistencia;

use App\Http\Controllers\Controller;
use App\Services\Asistencia\AutenticadorDispositivo;
use App\Services\Asistencia\HoraOficial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DIAGNÓSTICO del lector de huella: comprueba el camino físico completo
 * ESP32 -> Wi-Fi -> Laravel -> respuesta HTTP -> ESP32, y nada más.
 *
 * Deliberadamente NO hace nada más que contestar:
 *  - no crea marcaciones ni ninguna otra fila;
 *  - no consulta empleados ni huellas;
 *  - no requiere sesión de usuario;
 *  - no devuelve nombre de la empresa, versión, entorno ni ninguna configuración.
 *
 * ─────────────── Por qué el ping NO exige credencial de dispositivo ───────────────
 *
 * Es la herramienta para diagnosticar por qué algo NO funciona, y una herramienta
 * de diagnóstico que también puede fallar por credenciales sirve la mitad: con el
 * ping cerrado, un 401 dejaría al técnico sin saber si el problema es el Wi-Fi, el
 * DNS, el vhost, la URL o el token. Abierto, separa el problema en dos: «llego al
 * servidor» se contesta acá, y «tengo credenciales buenas» se contesta con el
 * bloque `dispositivo` de abajo.
 *
 * El precio está acotado a propósito: quien alcance este endpoint solo consigue
 * saber que existe un servidor con hora. No revela empleados, ni huellas, ni
 * lectores dados de alta, y no escribe nada. Lo que sí escribe —la marcación— vive
 * en otra ruta y esa sí exige token ({@see DispositivoMarcacionController}).
 *
 * ─────────────────────── El bloque `dispositivo`, opcional ───────────────────────
 *
 * Si la petición trae cabeceras de credencial, la respuesta agrega si el servidor
 * las reconoce. Así el técnico verifica el token del firmware SIN generar una
 * marcación de prueba que después haya que explicar en la planilla. No es una
 * filtración: para llegar a ese dato hay que traer ya un token, y la respuesta
 * nunca lo devuelve ni dice cuál de las dos cabeceras estaba mal.
 *
 * Sin cabeceras, la respuesta es EXACTAMENTE la de siempre: el firmware que ya
 * existe no cambia.
 */
class DispositivoPingController extends Controller
{
    public function __invoke(
        Request $request,
        HoraOficial $horaOficial,
        AutenticadorDispositivo $autenticador,
    ): JsonResponse {
        $cuerpo = [
            'ok' => true,
            'mensaje' => 'ESP32 conectado',
            // La HORA la pone el servidor. Es la regla del módulo: el ESP32 no
            // tiene reloj de batería, se reinicia y se desfasa, así que su hora
            // nunca es fuente de verdad. Va formateada en la zona oficial para
            // que la pantalla TFT la pinte tal cual, más `epoch` por si el
            // firmware quiere poner su reloj en hora.
            'servidor' => $horaOficial->desglosar($horaOficial->instante()),
        ];

        if ($autenticador->traeCredenciales($request)) {
            $dispositivo = $autenticador->resolver($request);

            $cuerpo['dispositivo'] = [
                'reconocido' => $dispositivo !== null,
                // El nombre solo aparece cuando la credencial YA resultó válida.
                'nombre' => $dispositivo?->nombre,
            ];
        }

        return response()->json($cuerpo);
    }
}
