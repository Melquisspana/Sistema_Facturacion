<?php

namespace App\Ajustes\Fiscal;

use App\Ajustes\Definicion\Editabilidad;

/**
 * QUÉ PUEDE HACERSE con un parámetro fiscal desde la aplicación.
 *
 * No es lo mismo que {@see Editabilidad}, y por eso
 * existe. `Editabilidad` responde "¿el registry tiene hoy dónde escribir esto?";
 * esta clasificación responde "¿esto DEBERÍA administrarse alguna vez desde una
 * pantalla web, y con cuánta ceremonia?". Son preguntas distintas: hoy TODOS los
 * parámetros fiscales son de solo lectura para el registry, y aun así no todos
 * merecen el mismo destino — el umbral de retención acabará siendo un campo
 * normal y la contraseña del certificado no debe salir del servidor nunca.
 *
 * Sin esta distinción, "todo está en solo lectura" sería la única respuesta que
 * la pantalla podría dar, y el administrador no tendría forma de saber si eso es
 * temporal o definitivo.
 */
enum ClasificacionFiscal: string
{
    /** Se muestra y no se edita nunca desde la web. Su valor lo fija la ley o el catálogo del MH. */
    case SoloLectura = 'solo_lectura';

    /** Candidato a campo normal (N1): cambiarlo no puede emitir, firmar ni transmitir nada. */
    case EditableNormal = 'editable_normal';

    /** Candidato a campo con confirmación N2: cambia el comportamiento de documentos futuros. */
    case EditableN2 = 'editable_n2';

    /** Crítica: solo con ceremonia N3 (permiso, precondiciones, frase exacta y contraseña). */
    case CriticaN3 = 'critica_n3';

    /**
     * NO debe existir como ajuste web, ni siquiera con ceremonia. Credenciales del MH,
     * contraseña del certificado y hosts a los que se manda material firmado: una
     * pantalla que los cambie es una pantalla que puede desviar documentos fiscales o
     * la clave del certificado a otro destino, y la sesión de un administrador basta
     * para operarla. Viven en el archivo del servidor y se cambian entrando al servidor.
     */
    case SoloServidor = 'solo_servidor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SoloLectura => 'Solo lectura',
            self::EditableNormal => 'Editable',
            self::EditableN2 => 'Editable con confirmación',
            self::CriticaN3 => 'Crítica (ceremonia N3)',
            self::SoloServidor => 'Solo en el servidor',
        };
    }

    /** Qué significa para quien administra, en una frase. */
    public function explicacion(): string
    {
        return match ($this) {
            self::SoloLectura => 'Se muestra para poder consultarlo; no se edita desde la aplicación.',
            self::EditableNormal => 'Previsto como campo editable normal. Todavía no está abierto.',
            self::EditableN2 => 'Previsto como campo editable con pantalla de confirmación. Todavía no está abierto.',
            self::CriticaN3 => 'Cambiarlo altera la validez fiscal de los documentos. Exigirá permiso crítico, frase exacta y contraseña. Todavía no está abierto.',
            self::SoloServidor => 'No se administra desde la web por diseño: se cambia en el archivo de configuración del servidor.',
        };
    }

    /** Clases del badge. Mismos colores que el resto del Centro de Configuración. */
    public function clases(): string
    {
        return match ($this) {
            self::SoloLectura => 'bg-gray-100 text-gray-700',
            self::EditableNormal => 'bg-green-100 text-green-800',
            self::EditableN2 => 'bg-blue-100 text-blue-800',
            self::CriticaN3 => 'bg-red-100 text-red-800',
            self::SoloServidor => 'bg-purple-100 text-purple-800',
        };
    }

    /** ¿Llegará alguna vez a ser editable desde la web? */
    public function abrirseAlgunDia(): bool
    {
        return in_array($this, [self::EditableNormal, self::EditableN2, self::CriticaN3], true);
    }
}
