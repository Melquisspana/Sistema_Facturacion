<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\ConversorValor;
use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Enums\ModuloSistema;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración › Módulos › <módulo>: las opciones de negocio de cada módulo que
 * antes vivían solo en el .env (plazos, montos, horarios, textos, correos de aviso y
 * llaves de comportamiento).
 *
 * UNA PANTALLA, VARIAS PÁGINAS. Qué página se abre lo fija el archivo de rutas con
 * `->defaults('pagina', …)`, igual que los secretos; qué ajustes tiene cada página lo
 * fija {@see self::PAGINAS}. Del navegador solo llegan los valores: el nombre de cada
 * campo sale de la clave del catálogo y se lee únicamente para las claves de este mapa,
 * así que un campo manipulado no puede elegir qué ajuste se escribe.
 *
 * Lo de siempre del Centro: valor guardado → .env → defecto. Solo se escribe lo que
 * cambia (lo demás sigue obedeciendo al .env), lo N2 pide confirmación con el antes y el
 * después, y cada cambio queda en la auditoría de ajustes. Un campo vacío NO borra: para
 * volver al .env está «Usar el valor del servidor», que también respeta el nivel.
 *
 * Una página de un módulo apagado no existe (404) y su enlace no se dibuja. Un grupo
 * que depende de otro módulo (el lector de huella, de Asistencia) se esconde con él, y
 * sus campos tampoco se procesan.
 */
class OpcionesModuloController extends Controller
{
    /**
     * Páginas: título, módulo que las enciende (null = siempre) y grupos de ajustes.
     *
     * @var array<string, array{titulo: string, descripcion: string, modulo: ?ModuloSistema, grupos: list<array{titulo: string, descripcion?: string, modulo?: ModuloSistema, claves: list<string>}>}>
     */
    private const PAGINAS = [
        'pronto-pago' => [
            'titulo' => 'Pronto pago',
            'descripcion' => 'Conciliación de los CCF y notas con los albaranes del cliente, y lectura de sus archivos.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Conciliación con el albarán',
                    'descripcion' => 'Cuánta diferencia de monto se acepta antes de marcar un documento.',
                    'claves' => ['ppq.diferencia_coincide', 'ppq.diferencia_pequena', 'ppq.albaranes.tipo_entrega'],
                ],
                [
                    'titulo' => 'Cliente',
                    'claves' => ['ppq.cliente_default_id'],
                ],
                [
                    'titulo' => 'Orden de compra y archivos del cliente',
                    'claves' => ['ppq.sala_oc_prefix', 'ppq.sala_oc_length', 'ppq.codigo_proveedor'],
                ],
                [
                    'titulo' => 'Tareas automáticas',
                    'claves' => ['ppq.albaranes.sincronizacion_automatica'],
                ],
            ],
        ],
        'cobros' => [
            'titulo' => 'Cobros',
            'descripcion' => 'Seguimiento de cada factura hasta su pago.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Seguimiento',
                    'claves' => ['cobros.inicio_seguimiento', 'cobros.dias_revision_historica', 'cobros.dias_albaran_reciente'],
                ],
                [
                    'titulo' => 'Tareas automáticas',
                    'descripcion' => 'Lo que el sistema hace solo, sin que nadie apriete un botón. Los botones de la pantalla siguen funcionando con esto apagado.',
                    'claves' => ['cobros.alta.automatica', 'cobros.vinculacion.automatica'],
                ],
                [
                    'titulo' => 'Respuestas del cliente por correo',
                    'claves' => ['cobros.correo.enabled', 'cobros.correo.automatica', 'cobros.correo.query', 'cobros.correo.limite'],
                ],
                [
                    'titulo' => 'TXT de pago por correo',
                    'claves' => ['cobros.txt_correo.remitente', 'cobros.txt_correo.carpeta'],
                ],
            ],
        ],
        'rutas' => [
            'titulo' => 'Rutas',
            'descripcion' => 'Entregas de los CCF en ruta y pedidos de sala.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Entregas',
                    'claves' => ['rutas.entregas_desde'],
                ],
                [
                    'titulo' => 'Pedidos',
                    'claves' => ['pedidos.fabrica_desde', 'pedidos.correo_resumen_diario', 'pedidos.salas_sin_pedidos'],
                ],
            ],
        ],
        'avisos' => [
            'titulo' => 'Avisos de fallas',
            'descripcion' => 'Correos que avisan cuando algo del sistema falla.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Avisos por correo',
                    'claves' => ['alertas.enabled', 'alertas.correo', 'alertas.reaviso_minutos', 'alertas.tope_diario', 'alertas.respaldo_hora_limite'],
                ],
                [
                    'titulo' => 'Lector de huella',
                    'descripcion' => 'Fuera de este horario no se avisa: de noche el lector puede estar apagado. Hora de El Salvador.',
                    'modulo' => ModuloSistema::Asistencia,
                    'claves' => [
                        'alertas.lector.sin_ping_minutos',
                        'alertas.lector.horario.1', 'alertas.lector.horario.2', 'alertas.lector.horario.3',
                        'alertas.lector.horario.4', 'alertas.lector.horario.5', 'alertas.lector.horario.6',
                        'alertas.lector.horario.7',
                    ],
                ],
            ],
        ],
        'gastos' => [
            'titulo' => 'Gastos',
            'descripcion' => 'Lo que el módulo de gastos hace solo cada mañana.',
            'modulo' => ModuloSistema::Gastos,
            'grupos' => [
                [
                    'titulo' => 'Tareas automáticas',
                    'claves' => ['gastos.recurrencias.generacion_automatica', 'gastos.avisos.automaticos'],
                ],
            ],
        ],
        'compras' => [
            'titulo' => 'Compras',
            'descripcion' => 'Documentos que mandan los proveedores al buzón de compras.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Tareas automáticas',
                    'claves' => ['documentos_recibidos.sincronizacion_automatica'],
                ],
            ],
        ],
        'asistencia' => [
            'titulo' => 'Asistencia',
            'descripcion' => 'Cómo reciben los lectores de huella las marcas, y el horario para los avisos.',
            'modulo' => ModuloSistema::Asistencia,
            'grupos' => [
                [
                    'titulo' => 'Lectores de huella',
                    'claves' => ['asistencia.cooldown_segundos', 'asistencia.diferidas_max_dias'],
                ],
                [
                    'titulo' => 'Horario para los avisos',
                    'descripcion' => 'Solo para los avisos de «Marcas de hoy»: no frenan nada ni cambian la planilla.',
                    'claves' => ['asistencia.horario.entrada', 'asistencia.horario.tolerancia_minutos', 'asistencia.horario.horas_por_dia'],
                ],
            ],
        ],
        'planilla' => [
            'titulo' => 'Planilla',
            'descripcion' => 'Salario mínimo y datos del patrono para el contrato de trabajo. Razón social y NIT salen de la empresa emisora.',
            'modulo' => ModuloSistema::Planilla,
            'grupos' => [
                [
                    'titulo' => 'Salario mínimo',
                    'claves' => ['planilla.sector_salario_minimo'],
                ],
                [
                    'titulo' => 'Patrono en el contrato de trabajo',
                    'descripcion' => 'Sin uno de estos datos el contrato no se genera, en vez de salir con un patrono a medias.',
                    'claves' => [
                        'planilla.contrato.patrono.sexo', 'planilla.contrato.patrono.fecha_nacimiento',
                        'planilla.contrato.patrono.estado_civil', 'planilla.contrato.patrono.profesion',
                        'planilla.contrato.patrono.nacionalidad', 'planilla.contrato.patrono.domicilio',
                        'planilla.contrato.patrono.residencia', 'planilla.contrato.patrono.dui',
                        'planilla.contrato.patrono.dui_expedido_en', 'planilla.contrato.patrono.dui_expedido_el',
                        'planilla.contrato.patrono.actividad', 'planilla.contrato.patrono.ciudad',
                        'planilla.contrato.patrono.direccion_pago',
                    ],
                ],
                [
                    'titulo' => 'Contrato de producción',
                    'claves' => ['planilla.contrato.modelos.produccion.lugar_trabajo'],
                ],
            ],
        ],
        'exportaciones' => [
            'titulo' => 'Exportaciones',
            'descripcion' => 'Datos de la empresa exportadora en la lista de empaque.',
            'modulo' => null,
            'grupos' => [
                [
                    'titulo' => 'Empresa exportadora',
                    'descripcion' => 'Sin nombre ni dirección se usan los de la empresa emisora.',
                    'claves' => ['exportaciones.exportador_nombre', 'exportaciones.exportador_direccion', 'exportaciones.fda_reg_number'],
                ],
            ],
        ],
    ];

    /** Nombres de las páginas, para el archivo de rutas. @return list<string> */
    public static function paginas(): array
    {
        return array_keys(self::PAGINAS);
    }

    /** Título de una página, para el menú interno. */
    public static function titulo(string $pagina): string
    {
        return self::PAGINAS[$pagina]['titulo'];
    }

    /** ¿Se ve la página? Solo si su módulo está encendido. */
    public static function visible(string $pagina): bool
    {
        $modulo = self::PAGINAS[$pagina]['modulo'] ?? null;

        return $modulo === null || $modulo->activo();
    }

    /** Nombre del campo del formulario para una clave: los puntos no viajan bien en POST. */
    public static function campo(string $clave): string
    {
        return str_replace('.', '__', $clave);
    }

    public function edit(Request $request, ServicioAjustes $ajustes, CatalogoAjustes $catalogo): View
    {
        $pagina = $this->pagina($request);

        $grupos = [];
        $conCliente = false;
        $conSalas = false;
        foreach ($this->gruposVisibles($pagina) as $grupo) {
            $campos = [];
            foreach ($grupo['claves'] as $clave) {
                $definicion = $catalogo->definicion($clave);
                $conCliente = $conCliente || $definicion->tipo === TipoAjuste::Cliente;
                $conSalas = $conSalas || in_array('salas', $definicion->reglas, true);
                $campos[self::campo($clave)] = [
                    'definicion' => $definicion,
                    'estado' => $ajustes->estadoParaPantalla($clave),
                ];
            }
            $grupos[] = ['titulo' => $grupo['titulo'], 'descripcion' => $grupo['descripcion'] ?? null, 'campos' => $campos];
        }

        return view('configuracion.opciones.edit', [
            'pagina' => self::PAGINAS[$pagina],
            'accion' => route('configuracion.opciones.'.$pagina.'.update'),
            'volver' => route('configuracion.opciones.'.$pagina),
            'grupos' => $grupos,
            // La lista de clientes, igual que en el filtro de lotes, solo si la página la usa.
            'clientes' => $conCliente ? Cliente::query()->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            // Las salas activas, por nombre, solo si la página elige salas.
            'salas' => $conSalas
                ? ClienteSucursal::query()->where('activo', true)->with('cliente:id,nombre')->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'cliente_id'])
                : collect(),
        ]);
    }

    public function update(
        Request $request,
        ConfirmacionN2 $confirmacion,
        ServicioAjustes $ajustes,
        ConversorValor $conversor,
    ): View|RedirectResponse {
        $pagina = $this->pagina($request);
        $definiciones = $this->definiciones($pagina);
        $ruta = 'configuracion.opciones.'.$pagina;

        $datos = $request->validate(
            array_map(fn (DefinicionAjuste $d) => $this->reglas($d), $definiciones),
            [],
            array_map(fn (DefinicionAjuste $d) => mb_strtolower($d->etiqueta), $definiciones),
        );

        $propuestos = [];
        $errores = [];
        foreach ($definiciones as $campo => $definicion) {
            $valor = $definicion->tipo === TipoAjuste::Booleano
                ? $request->boolean($campo)
                : ($datos[$campo] ?? null);

            // Vacío = «no lo toques», nunca «bórralo»: volver al .env es otra acción.
            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                continue;
            }
            // Una lista de casillas trae siempre la oculta vacía: así desmarcarlas todas
            // la vacía. Sin ninguna marcada y nada guardado, no hay nada que cambiar.
            // (llega como null: los textos vacíos se vuelven null antes del controlador).
            if (is_array($valor)) {
                $valor = array_values(array_filter($valor, static fn ($v) => $v !== null && $v !== ''));
                if ($valor === [] && $ajustes->lista($definicion->clave) === []) {
                    continue;
                }
            }

            // La validación del catálogo (formato de hora, de fecha, lista de correos)
            // va ANTES de calcular los cambios: ConfirmacionN2 omite en silencio lo que
            // no valida, y sin esto un correo mal escrito diría «nada que cambiar».
            try {
                $conversor->validarYNormalizar($definicion, $valor);
            } catch (ValorAjusteInvalidoException $e) {
                $errores[$campo] = $e->getMessage();

                continue;
            }

            $propuestos[$definicion->clave] = $valor;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $cambios = $confirmacion->calcular($propuestos);

        if ($cambios === []) {
            return redirect()->route($ruta)->with('status', 'No había nada que cambiar en '.self::PAGINAS[$pagina]['titulo'].'.');
        }

        if (! $request->boolean('confirmacion') && $confirmacion->requiereConfirmacion($cambios)) {
            return view('configuracion.confirmar', [
                'titulo' => 'Vas a cambiar opciones de '.self::PAGINAS[$pagina]['titulo'],
                'consecuencia' => 'Lo que guardes acá manda sobre el archivo de configuración del servidor (.env) desde el próximo uso, también en las tareas automáticas. Revisá cada cambio antes de confirmar.',
                'cambios' => $cambios,
                'accion' => route($ruta.'.update'),
                // Valores YA validados, con el nombre de campo del formulario. Ninguno
                // es secreto: estas páginas no tienen secretos.
                'valores' => $this->valoresParaReenviar($definiciones, $propuestos),
                'volver' => route($ruta),
            ]);
        }

        // Solo lo que cambia: lo demás sigue saliendo del .env.
        $aGuardar = [];
        foreach ($cambios as $cambio) {
            $aGuardar[$cambio->clave] = $propuestos[$cambio->clave];
        }

        try {
            $ajustes->guardarVarios($aGuardar);
        } catch (ValorAjusteInvalidoException $e) {
            throw ValidationException::withMessages([self::campo($e->clave) => $e->getMessage()]);
        }

        return redirect()->route($ruta)->with('status', 'Opciones de '.self::PAGINAS[$pagina]['titulo'].' guardadas.');
    }

    // ---------------------------------------------------------------- interno

    /**
     * Página de ESTA ruta. Sale de los valores por defecto del archivo de rutas, nunca
     * de la petición. Con su módulo apagado, no existe.
     */
    private function pagina(Request $request): string
    {
        $pagina = (string) ($request->route()?->defaults['pagina'] ?? '');

        abort_unless(isset(self::PAGINAS[$pagina]) && self::visible($pagina), 404);

        return $pagina;
    }

    /** @return list<array{titulo: string, descripcion?: string, modulo?: ModuloSistema, claves: list<string>}> */
    private function gruposVisibles(string $pagina): array
    {
        return array_values(array_filter(
            self::PAGINAS[$pagina]['grupos'],
            static fn (array $g) => ! isset($g['modulo']) || $g['modulo']->activo(),
        ));
    }

    /**
     * Campo del formulario ⇒ definición, solo de los grupos visibles. Un secreto acá
     * sería un error de programación: viajaría en los campos ocultos de la confirmación.
     *
     * @return array<string, DefinicionAjuste>
     */
    private function definiciones(string $pagina): array
    {
        $catalogo = app(CatalogoAjustes::class);
        $definiciones = [];

        foreach ($this->gruposVisibles($pagina) as $grupo) {
            foreach ($grupo['claves'] as $clave) {
                $definicion = $catalogo->definicion($clave);

                if ($definicion->tipo->esSecreto() || ! $definicion->editabilidad->permiteEscritura()) {
                    throw new \LogicException("«{$clave}» no puede ir en una página de opciones.");
                }

                $definiciones[self::campo($clave)] = $definicion;
            }
        }

        return $definiciones;
    }

    /**
     * Reglas de Laravel a partir de la definición: tipo, rango, largo y formato. Son la
     * primera barrera; la del catálogo (ConversorValor) es la segunda.
     *
     * @return list<mixed>
     */
    private function reglas(DefinicionAjuste $definicion): array
    {
        $reglas = ['nullable'];

        $reglas[] = match ($definicion->tipo) {
            TipoAjuste::Booleano => 'boolean',
            // Que el cliente exista lo comprueba el catálogo (ConversorValor), con su mensaje.
            TipoAjuste::Entero, TipoAjuste::Cliente => 'integer',
            TipoAjuste::Decimal => 'numeric',
            TipoAjuste::Email => 'email',
            TipoAjuste::Enumerado => Rule::in($definicion->opciones),
            // Cada elemento lo valida el catálogo (ConversorValor), con su mensaje.
            TipoAjuste::Lista => 'array',
            default => 'string',
        };

        $numerico = in_array($definicion->tipo, [TipoAjuste::Entero, TipoAjuste::Decimal], true);

        foreach ($definicion->reglas as $regla) {
            if ($numerico && (str_starts_with($regla, 'min:') || str_starts_with($regla, 'max:'))) {
                $reglas[] = $regla;
            }
            if (str_starts_with($regla, 'maxlen:')) {
                $reglas[] = 'max:'.substr($regla, 7);
            }
        }

        if (in_array('hora', $definicion->reglas, true)) {
            $reglas[] = 'date_format:H:i';
        }
        if (in_array('fecha', $definicion->reglas, true)) {
            $reglas[] = 'date_format:Y-m-d';
        }

        return $reglas;
    }

    /**
     * @param  array<string, DefinicionAjuste>  $definiciones
     * @param  array<string, mixed>  $propuestos
     * @return array<string, string|list<string>>
     */
    private function valoresParaReenviar(array $definiciones, array $propuestos): array
    {
        $valores = [];

        foreach ($definiciones as $campo => $definicion) {
            // Una lista que no vino en el envío no se reenvía: vacía diría «sin ninguna» y
            // al confirmar la borraría. La vaciada a propósito sí viene, como [].
            if ($definicion->tipo === TipoAjuste::Lista && ! array_key_exists($definicion->clave, $propuestos)) {
                continue;
            }
            $valor = $propuestos[$definicion->clave] ?? null;

            $valores[$campo] = match ($definicion->tipo) {
                TipoAjuste::Booleano => $valor ? '1' : '0',
                // Viaja como lista (`campo[]`, con la casilla oculta vacía delante).
                TipoAjuste::Lista => array_values(array_map('strval', (array) $valor)),
                default => (string) ($valor ?? ''),
            };
        }

        return $valores;
    }
}
