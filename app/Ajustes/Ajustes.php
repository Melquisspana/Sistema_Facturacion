<?php

namespace App\Ajustes;

use App\Ajustes\Adaptadores\AdaptadorConfiguraciones;
use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\AjusteNoEditableException;
use App\Ajustes\Excepciones\AutorizacionAjusteException;
use App\Ajustes\Excepciones\ConflictoDeAjusteException;
use App\Models\Configuracion;
use App\Support\Dinero;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * API ÚNICA de la configuración administrable por la aplicación.
 *
 * Se usa a través de la fachada {@see \App\Facades\Ajustes}:
 *
 *   Ajustes::texto('contabilidad.correo')
 *   Ajustes::bool('correo.auto_envio')
 *   Ajustes::secretoParaRuntime('mail.smtp.password')
 *   Ajustes::estadoParaPantalla('mail.smtp.password')   // sin el valor
 *   Ajustes::guardar('contabilidad.correo', $request->input('correo'))
 *
 * NO reemplaza a `config()`. `config()` sigue siendo la configuración del
 * FRAMEWORK y del despliegue (base de datos, colas, discos, rutas del servidor).
 * Esta capa cubre solo lo que un administrador puede cambiar desde la aplicación,
 * y de hecho se apoya en `config()` como fallback.
 *
 * ORDEN DE RESOLUCIÓN, explícito por ajuste (nunca un `env()` dinámico sobre una
 * clave arbitraria):
 *
 *   1. override en la ubicación que la definición declare (tabla nueva o tabla
 *      `configuraciones`); si declara Persistencia::Ninguna, este paso no existe;
 *   2. `config($definicion->claveConfig)`, solo si la definición lo declara;
 *   3. el valor por defecto de la definición;
 *   4. null.
 *
 * SEPARACIÓN RUNTIME / PANTALLA: `secretoParaRuntime()` devuelve el secreto al
 * servicio que se va a autenticar; `estadoParaPantalla()` devuelve un
 * {@see EstadoAjuste} que no puede llevarlo. `get()` y sus variantes tipadas
 * RECHAZAN los secretos a propósito: pedir un secreto tiene que ser un acto
 * deliberado y visible en el código, no el resultado de llamar al mismo método
 * que para el resto.
 */
class Ajustes
{
    public function __construct(
        private readonly CatalogoAjustes $catalogo,
        private readonly RepositorioAjustes $repositorio,
        private readonly AdaptadorConfiguraciones $legacy,
        private readonly ConversorValor $conversor,
        private readonly AuditoriaAjustes $auditoria,
    ) {}

    // ------------------------------------------------------------- lectura

    /**
     * Valor tipado del ajuste. NO acepta secretos: para eso está
     * {@see secretoParaRuntime()}.
     *
     * @throws Excepciones\AjusteDesconocidoException clave fuera de la lista blanca
     */
    public function get(string $clave): mixed
    {
        $definicion = $this->catalogo->definicion($clave);

        if ($definicion->tipo->esSecreto()) {
            throw new \LogicException(
                "«{$clave}» es un secreto: usá Ajustes::secretoParaRuntime() para obtenerlo, o estadoParaPantalla() para mostrar si está configurado."
            );
        }

        return $this->resolver($clave)->valor;
    }

    public function texto(string $clave, ?string $porDefecto = null): ?string
    {
        $valor = $this->get($clave);

        return $valor === null ? $porDefecto : (string) $valor;
    }

    public function bool(string $clave, bool $porDefecto = false): bool
    {
        $this->exigirTipo($clave, TipoAjuste::Booleano);
        $valor = $this->get($clave);

        return $valor === null ? $porDefecto : (bool) $valor;
    }

    public function entero(string $clave, ?int $porDefecto = null): ?int
    {
        $this->exigirTipo($clave, TipoAjuste::Entero);
        $valor = $this->get($clave);

        return $valor === null ? $porDefecto : (int) $valor;
    }

    /** Decimal como CADENA, para operarlo con {@see Dinero} sin perder precisión. */
    public function decimal(string $clave, ?string $porDefecto = null): ?string
    {
        $this->exigirTipo($clave, TipoAjuste::Decimal);
        $valor = $this->get($clave);

        return $valor === null ? $porDefecto : (string) $valor;
    }

    /** @return array<int, string> */
    public function lista(string $clave): array
    {
        $this->exigirTipo($clave, TipoAjuste::Lista);

        return (array) ($this->get($clave) ?? []);
    }

    /**
     * Valor de un SECRETO, para el servicio autorizado que va a usarlo.
     *
     * El nombre es largo a propósito: cada llamada queda visible en una búsqueda
     * del código y es un punto que revisar. Nunca se pasa el resultado a una
     * vista, a un JSON, a un log ni a la auditoría.
     */
    public function secretoParaRuntime(string $clave): ?string
    {
        $definicion = $this->catalogo->definicion($clave);

        if (! $definicion->tipo->esSecreto()) {
            throw new \LogicException("«{$clave}» no es un secreto: usá Ajustes::get().");
        }

        $valor = $this->resolver($clave)->valor;

        return $valor === null ? null : (string) $valor;
    }

    /** Resolución completa: valor tipado + fuente. Uso interno y de diagnóstico. */
    public function resolver(string $clave): ValorAjuste
    {
        $definicion = $this->catalogo->definicion($clave);

        // 1) Override, en la ÚNICA ubicación que la definición declara.
        $texto = $this->overrideAlmacenado($definicion);

        if ($texto !== null) {
            return new ValorAjuste(
                $definicion,
                $this->conversor->aValor($definicion, $texto),
                $definicion->persistencia === Persistencia::Legacy
                    ? FuenteAjuste::BaseDeDatosLegacy
                    : FuenteAjuste::BaseDeDatos,
            );
        }

        // 2) config()/.env, solo si la definición declara la clave. Nunca env()
        //    dinámico sobre una clave arbitraria.
        if ($definicion->claveConfig !== null) {
            $deConfig = config($definicion->claveConfig);

            if ($deConfig !== null && $deConfig !== '') {
                return new ValorAjuste(
                    $definicion,
                    $this->conversor->aValor($definicion, $this->conversor->aTexto($definicion, $deConfig)),
                    FuenteAjuste::Configuracion,
                );
            }
        }

        // 3) Valor por defecto de la definición.
        if ($definicion->porDefecto !== null) {
            return new ValorAjuste(
                $definicion,
                $this->conversor->aValor($definicion, $this->conversor->aTexto($definicion, $definicion->porDefecto)),
                FuenteAjuste::Defecto,
            );
        }

        // 4) Nada.
        return new ValorAjuste($definicion, null, FuenteAjuste::NoConfigurado);
    }

    public function fuente(string $clave): FuenteAjuste
    {
        return $this->resolver($clave)->fuente;
    }

    public function estaConfigurado(string $clave): bool
    {
        return $this->resolver($clave)->configurado();
    }

    // ------------------------------------------------------------- pantalla

    /** Lo único que puede ir a una vista o a un JSON. Para secretos NO lleva el valor. */
    public function estadoParaPantalla(string $clave): EstadoAjuste
    {
        return EstadoAjuste::desde($this->resolver($clave));
    }

    /** @return array<string, EstadoAjuste> */
    public function estadosDeSeccion(string $seccion): array
    {
        $estados = [];

        foreach ($this->catalogo->porSeccion($seccion) as $clave => $_definicion) {
            $estados[$clave] = $this->estadoParaPantalla($clave);
        }

        return $estados;
    }

    // ------------------------------------------------------------- escritura

    /**
     * Guarda el override de un ajuste.
     *
     * ORDEN DE LAS COMPROBACIONES, a propósito:
     *   1. la clave existe en el catálogo (si no: AjusteDesconocidoException);
     *   2. el actor tiene el permiso que exige el NIVEL;
     *   3. el ajuste está abierto a escritura;
     *   4. nadie lo cambió mientras tanto (si se pasa $vistoEn);
     *   5. el valor es válido para su tipo.
     *
     * El permiso se comprueba ANTES que la editabilidad para que la respuesta a
     * "un usuario sin `configuracion.critica` intenta tocar el ambiente fiscal"
     * sea 403 y no "todavía no es editable". Cuando N3 se abra, el mismo código
     * seguirá negando el acceso sin cambiar una línea.
     *
     * @param  Carbon|null  $vistoEn  `updated_at` que el formulario tenía al abrirse.
     *
     * @throws AutorizacionAjusteException|AjusteNoEditableException|ConflictoDeAjusteException|Excepciones\ValorAjusteInvalidoException
     */
    public function guardar(string $clave, mixed $valor, ?Carbon $vistoEn = null): void
    {
        $definicion = $this->catalogo->definicion($clave);

        $this->exigirPermiso($definicion, $this->actor());
        $this->escribir($definicion, $valor, $vistoEn);
    }

    /**
     * Escritura SIN comprobación de permisos, para comandos de consola, seeders y
     * migraciones de datos, donde no hay usuario autenticado. Es un método aparte
     * y con nombre explícito para que "saltarse el permiso" nunca sea el camino
     * por defecto ni pase inadvertido en una revisión.
     */
    public function guardarComoSistema(string $clave, mixed $valor): void
    {
        $this->escribir($this->catalogo->definicion($clave), $valor, null);
    }

    /**
     * Guarda varios ajustes de una vez, en UNA transacción: o entran todos o no
     * entra ninguno. Es lo que necesita un formulario con varios campos, para que
     * un valor inválido en el tercero no deje los dos primeros aplicados.
     *
     * @param  array<string, mixed>  $valores  clave ⇒ valor
     * @param  array<string, Carbon>  $vistosEn  clave ⇒ updated_at al abrir el formulario
     */
    public function guardarVarios(array $valores, array $vistosEn = []): void
    {
        // Los permisos se comprueban TODOS antes de abrir la transacción: si falta
        // uno, no se escribe nada y no hay que confiar en el rollback.
        $actor = $this->actor();

        foreach (array_keys($valores) as $clave) {
            $this->exigirPermiso($this->catalogo->definicion($clave), $actor);
        }

        DB::transaction(function () use ($valores, $vistosEn) {
            foreach ($valores as $clave => $valor) {
                $this->escribir($this->catalogo->definicion($clave), $valor, $vistosEn[$clave] ?? null);
            }
        });
    }

    /** Quita el override para que el ajuste vuelva a resolverse por su fallback. */
    public function quitarOverride(string $clave): void
    {
        $definicion = $this->catalogo->definicion($clave);

        $this->exigirPermiso($definicion, $this->actor());

        if (! $definicion->editabilidad->permiteEscritura()) {
            throw AjusteNoEditableException::para($definicion);
        }

        $fuenteAntes = $this->fuente($clave);

        match ($definicion->persistencia) {
            Persistencia::Nueva => $this->repositorio->eliminar($clave),
            Persistencia::Legacy => $this->legacy->eliminar((string) $definicion->claveLegacy),
            Persistencia::Ninguna => null,
        };

        $this->auditoria->overrideQuitado($definicion, $fuenteAntes, $this->fuente($clave));
    }

    // ------------------------------------------------------------- permisos

    /** ¿Este usuario puede escribir este ajuste? (para pintar/ocultar en la UI). */
    public function puedeEditar(?Authorizable $usuario, string $clave): bool
    {
        $definicion = $this->catalogo->definicion($clave);

        return $usuario !== null
            && $usuario->can($definicion->nivel->permisoRequerido()->value)
            && $definicion->editabilidad->permiteEscritura();
    }

    /** Nivel de confirmación que exige un ajuste. Lo consulta la UI para decidir la ceremonia. */
    public function nivel(string $clave): NivelConfirmacion
    {
        return $this->catalogo->definicion($clave)->nivel;
    }

    public function catalogo(): CatalogoAjustes
    {
        return $this->catalogo;
    }

    // ---------------------------------------------------------------- interno

    private function escribir(DefinicionAjuste $definicion, mixed $valor, ?Carbon $vistoEn): void
    {
        if (! $definicion->editabilidad->permiteEscritura()) {
            throw AjusteNoEditableException::para($definicion);
        }

        $this->exigirSinConflicto($definicion, $vistoEn);

        $texto = $this->conversor->validarYNormalizar($definicion, $valor);

        // Estado ANTERIOR: se captura antes de escribir, porque después ya no
        // habría cómo saber de dónde se resolvía.
        $resueltoAntes = $this->resolver($definicion->clave);

        match ($definicion->persistencia) {
            Persistencia::Nueva => $this->repositorio->guardar($definicion->clave, $texto, $definicion->seCifra()),
            Persistencia::Legacy => $this->legacy->guardar((string) $definicion->claveLegacy, $texto),
            // Inalcanzable: `hacer()` prohíbe editable + Persistencia::Ninguna, y
            // arriba ya se exigió editabilidad. Queda por exhaustividad del match.
            Persistencia::Ninguna => throw AjusteNoEditableException::para($definicion),
        };

        $resueltoDespues = $this->resolver($definicion->clave);

        if ($definicion->tipo->esSecreto()) {
            $this->auditoria->reemplazoDeSecreto($definicion, $resueltoAntes->fuente, $resueltoDespues->fuente);

            return;
        }

        $this->auditoria->cambio(
            $definicion,
            $resueltoAntes->valor,
            $resueltoDespues->valor,
            $resueltoAntes->fuente,
            $resueltoDespues->fuente,
        );
    }

    /** Actor autenticado capaz de responder `can()`, o null. */
    private function actor(): ?Authorizable
    {
        $usuario = Auth::user();

        return $usuario instanceof Authorizable ? $usuario : null;
    }

    private function carbon(mixed $valor): ?Carbon
    {
        return $valor === null ? null : Carbon::parse($valor);
    }

    private function exigirPermiso(DefinicionAjuste $definicion, ?Authorizable $actor): void
    {
        $permiso = $definicion->nivel->permisoRequerido()->value;

        if ($actor === null || ! $actor->can($permiso)) {
            throw AutorizacionAjusteException::para($definicion);
        }
    }

    /**
     * Comprobación OPTIMISTA de concurrencia. Ver docs/CENTRO_CONFIGURACION.md:
     * cada ajuste es su propia fila, así que dos administradores que tocan
     * pantallas distintas no chocan; el choque real es "los dos editaron el mismo
     * campo", y eso se detecta comparando el `updated_at` que el formulario tenía
     * al abrirse contra el actual.
     */
    private function exigirSinConflicto(DefinicionAjuste $definicion, ?Carbon $vistoEn): void
    {
        if ($vistoEn === null) {
            return;
        }

        $actual = $this->actualizadoEn($definicion);

        if ($actual !== null && $actual->greaterThan($vistoEn)) {
            throw ConflictoDeAjusteException::para($definicion->clave);
        }
    }

    /** Momento del último cambio del override, en la ubicación que corresponda. */
    public function actualizadoEn(DefinicionAjuste $definicion): ?Carbon
    {
        return match ($definicion->persistencia) {
            Persistencia::Nueva => $this->repositorio->actualizadoEn($definicion->clave),
            Persistencia::Legacy => $this->carbon(
                Configuracion::query()->where('clave', $definicion->claveLegacy)->value('updated_at')
            ),
            Persistencia::Ninguna => null,
        };
    }

    private function overrideAlmacenado(DefinicionAjuste $definicion): ?string
    {
        return match ($definicion->persistencia) {
            Persistencia::Nueva => $this->repositorio->valor($definicion->clave),
            Persistencia::Legacy => $this->legacy->valor((string) $definicion->claveLegacy),
            Persistencia::Ninguna => null,
        };
    }

    private function exigirTipo(string $clave, TipoAjuste $esperado): void
    {
        $definicion = $this->catalogo->definicion($clave);

        if ($definicion->tipo !== $esperado) {
            throw new \LogicException(
                "«{$clave}» es de tipo {$definicion->tipo->value}, no {$esperado->value}."
            );
        }
    }
}
