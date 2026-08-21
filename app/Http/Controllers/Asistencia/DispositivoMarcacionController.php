<?php

namespace App\Http\Controllers\Asistencia;

use App\DataTransferObjects\Asistencia\ResultadoRegistroMarcacion;
use App\Enums\Asistencia\ResultadoMarcacion;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AutenticaDispositivoAsistencia;
use App\Http\Requests\Asistencia\MarcarRequest;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\HoraOficial;
use App\Services\Asistencia\RegistrarMarcacion;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * La marcación real. El controlador NO decide nada de asistencia: recibe el
 * número de ranura ya validado, se lo pasa al servicio y traduce el desenlace a
 * JSON y a un código HTTP.
 *
 * Todas las respuestas comparten forma —`ok`, `estado`, `mensaje`— para que el
 * firmware tenga un solo camino de lectura: pinta `mensaje` en la pantalla y
 * ramifica sobre `estado`, que es una cadena estable de
 * {@see ResultadoMarcacion}.
 *
 * Lo que NUNCA sale de acá: excepciones, consultas, nombres de tabla, tokens ni
 * configuración. Si algo revienta, el manejador de Laravel responde su JSON
 * genérico; nada de este controlador agrega detalle a un error.
 */
class DispositivoMarcacionController extends Controller
{
    public function __invoke(
        MarcarRequest $request,
        RegistrarMarcacion $registrar,
        HoraOficial $horaOficial,
    ): JsonResponse {
        /** @var AsistenciaDispositivo $dispositivo */
        $dispositivo = $request->attributes->get(AutenticaDispositivoAsistencia::ATRIBUTO);

        $resultado = $registrar($dispositivo, $request->fingerprintId(), $request->ip());

        return response()->json(
            $this->cuerpo($resultado, $horaOficial),
            $resultado->estado->httpStatus(),
        );
    }

    /** @return array<string, mixed> */
    private function cuerpo(ResultadoRegistroMarcacion $resultado, HoraOficial $horaOficial): array
    {
        return match ($resultado->estado) {
            ResultadoMarcacion::Registrada => $this->cuerpoRegistrada($resultado, $horaOficial),

            ResultadoMarcacion::HuellaDesconocida => [
                'ok' => false,
                'estado' => $resultado->estado->value,
                'mensaje' => 'Huella no registrada',
                // Se devuelve el número que mandó el lector —no es un dato del
                // sistema, es lo que él mismo envió— para que el técnico vea qué
                // ranura leyó el sensor sin tener que abrir un log.
                'fingerprint_id' => $resultado->fingerprintId,
            ],

            ResultadoMarcacion::EmpleadoInactivo => [
                'ok' => false,
                'estado' => $resultado->estado->value,
                'mensaje' => 'Empleado inactivo',
                'empleado' => $this->empleado($resultado->empleado),
            ],

            ResultadoMarcacion::Cooldown => [
                'ok' => false,
                'estado' => $resultado->estado->value,
                'mensaje' => sprintf(
                    'Ya marcaste %s a las %s',
                    $resultado->marcacionPrevia->tipo->label(),
                    $horaOficial->desglosar($resultado->marcacionPrevia->marcado_at)['hora'],
                ),
                'empleado' => $this->empleado($resultado->empleado),
                'espera_segundos' => $resultado->esperaSegundos,
                'marcacion_previa' => $this->marcacion($resultado->marcacionPrevia, $horaOficial),
            ],

            // Los dos estados que decide una capa ANTERIOR (credenciales y
            // validación del cuerpo) no pueden llegar hasta acá: si llegan, es un
            // fallo de programación y tiene que sonar como tal, no devolver un
            // JSON a medias que el firmware interpretaría como una respuesta
            // legítima. El `match` queda exhaustivo sobre el enum entero, así que
            // agregar un estado nuevo obliga a decidir qué se contesta.
            ResultadoMarcacion::PayloadInvalido,
            ResultadoMarcacion::DispositivoNoAutorizado => throw new LogicException(
                'El estado «'.$resultado->estado->value.'» lo resuelve el middleware o el FormRequest, '
                .'nunca RegistrarMarcacion. Que haya llegado al controlador significa que algo lo construyó a mano.'
            ),
        };
    }

    /** @return array<string, mixed> */
    private function cuerpoRegistrada(ResultadoRegistroMarcacion $resultado, HoraOficial $horaOficial): array
    {
        return [
            'ok' => true,
            'estado' => $resultado->estado->value,
            'mensaje' => $resultado->marcacion->tipo->label().' registrada',
            'empleado' => $this->empleado($resultado->empleado),
            'marcacion' => $this->marcacion($resultado->marcacion, $horaOficial),
        ];
    }

    /** @return array<string, mixed> */
    private function empleado(AsistenciaEmpleado $empleado): array
    {
        return [
            'id' => $empleado->id,
            'nombre' => $empleado->nombreCompleto(),
            // Para la pantalla de 128x128, donde el nombre completo no cabe.
            'nombre_corto' => $empleado->nombreCorto(),
        ];
    }

    /** @return array<string, mixed> */
    private function marcacion(AsistenciaMarcacion $marcacion, HoraOficial $horaOficial): array
    {
        $tiempo = $horaOficial->desglosar($marcacion->marcado_at);

        return [
            'id' => $marcacion->id,
            'tipo' => $marcacion->tipo->value,
            'tipo_label' => $marcacion->tipo->label(),
            'fecha' => $tiempo['fecha'],
            'hora' => $tiempo['hora'],
            'fecha_hora' => $tiempo['fecha_hora'],
            'zona' => $tiempo['zona'],
        ];
    }
}
