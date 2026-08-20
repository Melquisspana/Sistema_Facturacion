<?php

namespace App\Ajustes;

use App\Ajustes\Ceremonias\CeremoniaN3;
use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\ActivityLogger;

/**
 * Auditoría CENTRAL de los ajustes. Un solo sitio decide qué se registra, y por
 * eso puede aplicar la regla de secretos sin excepciones.
 *
 * Para un ajuste normal queda el antes y el después:
 *
 *   Usuario cambió «contabilidad.correo»: "conta@ejemplo.com" → "conta2@ejemplo.com"
 *
 * Para un secreto queda el HECHO y nada más:
 *
 *   Usuario reemplazó el secreto «mail.smtp.password»
 *
 * Sin valor, sin valor anterior, sin longitud y SIN HASH. Un hash parece inofensivo
 * y no lo es: convierte el registro de auditoría —que leen más personas que la
 * configuración misma— en un objetivo contra el que probar contraseñas offline.
 *
 * Lo que sí se registra siempre, porque es lo que hace útil una auditoría de
 * configuración: qué clave, de qué sección, de qué nivel, qué acción, y de dónde
 * venía y adónde pasó a resolverse el valor (fuente antes/después). Eso permite
 * reconstruir "esto dejó de leerse del .env y pasó a leerse de la base" sin
 * conocer el contenido.
 */
class AuditoriaAjustes
{
    private const LOG = 'ajustes';

    /** Cambio de un ajuste NO secreto: se registra el antes y el después. */
    public function cambio(
        DefinicionAjuste $definicion,
        mixed $valorAntes,
        mixed $valorDespues,
        FuenteAjuste $fuenteAntes,
        FuenteAjuste $fuenteDespues,
    ): void {
        $propiedades = $this->comunes($definicion, 'cambio', $fuenteAntes, $fuenteDespues);

        // Doble candado: el llamador ya distingue secretos, pero si algún día se
        // equivoca, acá no pasa el valor igualmente.
        if ($definicion->valorAuditable()) {
            $propiedades['valor_antes'] = $this->presentable($valorAntes);
            $propiedades['valor_despues'] = $this->presentable($valorDespues);
        }

        $this->escribir(
            "cambió la configuración «{$definicion->clave}»",
            $propiedades,
        );
    }

    /** Reemplazo de un SECRETO: solo el hecho. Nunca antes/después, nunca hash. */
    public function reemplazoDeSecreto(
        DefinicionAjuste $definicion,
        FuenteAjuste $fuenteAntes,
        FuenteAjuste $fuenteDespues,
    ): void {
        $this->escribir(
            "reemplazó el secreto «{$definicion->clave}»",
            $this->comunes($definicion, 'reemplazo_secreto', $fuenteAntes, $fuenteDespues),
        );
    }

    /** Se quitó el override y el ajuste vuelve a resolverse por su fallback. */
    public function overrideQuitado(
        DefinicionAjuste $definicion,
        FuenteAjuste $fuenteAntes,
        FuenteAjuste $fuenteDespues,
    ): void {
        $this->escribir(
            "quitó el valor guardado de «{$definicion->clave}»",
            $this->comunes($definicion, 'override_quitado', $fuenteAntes, $fuenteDespues),
        );
    }

    /**
     * Una comprobación de configuración que alguien disparó a mano (probar la
     * conexión SMTP, y mañana Hacienda o el firmador).
     *
     * Se registra el RESULTADO, nunca lo que se usó para conseguirlo. `$mensaje`
     * debe llegar ya saneado por quien conoce la excepción original: acá no hay
     * forma de distinguir la parte útil del texto de la que trae credenciales.
     */
    public function verificacion(string $que, bool $exito, ?string $mensaje = null): void
    {
        $this->escribir(
            'probó '.$que.': '.($exito ? 'éxito' : 'error'),
            array_filter([
                'accion' => 'verificacion',
                'verificacion' => $que,
                'resultado' => $exito ? 'exito' : 'fallo',
                'mensaje' => $mensaje,
                'ip' => $this->ip(),
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * Se desconectó una integración externa.
     *
     * Queda la CUENTA, que es lo que hace falta para saber qué dejó de funcionar y
     * a quién avisarle. Nunca los tokens: ni enteros, ni en fragmentos, ni en
     * hashes. Un token de acceso en el registro de auditoría es una credencial
     * viva en un sitio que lee más gente que la configuración misma.
     */
    public function integracionDesconectada(string $integracion, string $cuenta): void
    {
        $this->escribir(
            'desconectó '.$integracion.' ('.$cuenta.')',
            array_filter([
                'accion' => 'integracion_desconectada',
                'integracion' => $integracion,
                'cuenta' => $cuenta,
                'ip' => $this->ip(),
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * Una acción crítica que pasó la ceremonia N3.
     *
     * La contraseña con la que el usuario se reautenticó NO llega hasta acá y no
     * podría registrarse aunque se quisiera: {@see CeremoniaN3}
     * la comprueba y la descarta sin pasarla a nadie.
     */
    public function accionCritica(string $accion, string $titulo, bool $ejecutada, ?string $detalle = null): void
    {
        $this->escribir(
            ($ejecutada ? 'ejecutó la acción crítica' : 'intentó la acción crítica').' «'.$titulo.'»',
            array_filter([
                'accion' => 'n3',
                'accion_critica' => $accion,
                'nivel' => 'n3',
                'resultado' => $ejecutada ? 'ejecutada' : 'rechazada',
                'detalle' => $detalle,
                'ip' => $this->ip(),
            ], static fn ($v) => $v !== null),
        );
    }

    // ---------------------------------------------------------------- interno

    /** @return array<string, mixed> */
    private function comunes(
        DefinicionAjuste $definicion,
        string $accion,
        FuenteAjuste $fuenteAntes,
        FuenteAjuste $fuenteDespues,
    ): array {
        return array_filter([
            'clave' => $definicion->clave,
            'seccion' => $definicion->seccion,
            'nivel' => $definicion->nivel->value,
            'impacto' => $definicion->impacto->value,
            'accion' => $accion,
            'fuente_antes' => $fuenteAntes->value,
            'fuente_despues' => $fuenteDespues->value,
            'ip' => $this->ip(),
        ], static fn ($v) => $v !== null);
    }

    /**
     * IP del actor, o null.
     *
     * No se comprueba `runningInConsole()`: el discriminante correcto es si la
     * petición trae REMOTE_ADDR. En un comando de consola o en un worker la
     * request construida desde las globales no lo tiene y `ip()` devuelve null
     * —que es lo que queremos: mejor ausente que una IP local inventada—,
     * mientras que un cambio hecho desde el navegador sí lo trae.
     */
    private function ip(): ?string
    {
        $ip = request()?->ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** Un booleano en el log se lee mejor como Sí/No que como 1/0. */
    private function presentable(mixed $valor): mixed
    {
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        return $valor;
    }

    /** @param  array<string, mixed>  $propiedades */
    private function escribir(string $descripcion, array $propiedades): void
    {
        /** @var ActivityLogger $registro */
        $registro = activity(self::LOG);

        $registro
            ->causedBy(Auth::user())
            ->withProperties($propiedades)
            ->log($descripcion);
    }
}
