<?php

namespace Tests\Feature\Autorizacion;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Matriz de acceso por rol. Verifica en BACKEND (no solo visual) que cada rol
 * pueda entrar a lo que le corresponde y reciba 403 en lo prohibido, tanto en
 * GET de lectura como en acciones POST/PUT/PATCH/DELETE y URLs directas.
 *
 * Los permisos por rol los siembra RolesSeeder (vía Tests\TestCase::setUp).
 */
class RolesPermisosTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /**
     * Universo de roles contra el que se prueba CADA ruta. Incluye `produccion`,
     * que no pertenece al área Facturación: al no figurar en ninguna lista de
     * permitidos, la matriz exige 403 para él en todo el sistema existente. Ese
     * es el test formal del aislamiento del área nueva.
     */
    private const ROLES = ['administrador', 'jefatura', 'facturacion', 'contabilidad', 'produccion'];

    /**
     * Los cuatro roles del área Facturación: los que SÍ pueden entrar a las
     * pantallas abiertas a "todos". Antes esta lista y {@see ROLES} eran la misma
     * constante; se separaron al aparecer un rol fuera del área.
     */
    private const ROLES_FACTURACION = ['administrador', 'jefatura', 'facturacion', 'contabilidad'];

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    // ---------------------------------------------------------------------
    // Lecturas (GET): 200 para roles permitidos, 403 para el resto.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function lecturas(): array
    {
        return [
            // 'dashboard' NO va en esta matriz: no tiene solo dos desenlaces. Para los
            // cuatro roles del área Facturación responde 200, pero para el rol
            // `produccion` responde 302 (a /planta) o 404 (módulo apagado), que no es
            // ni 2xx ni 403. Ese caso se cubre entero en
            // Tests\Feature\Planta\PlantaRedireccionTest.
            'facturación (DTE)' => ['facturacion.index', self::ROLES_FACTURACION],
            'clientes' => ['clientes.index', self::ROLES_FACTURACION],
            'productos' => ['productos.index', self::ROLES_FACTURACION],
            'ppq' => ['ppq.index', self::ROLES_FACTURACION],
            'exportaciones' => ['exportaciones.index', self::ROLES_FACTURACION],
            'compras (documentos recibidos)' => ['documentos-recibidos.index', self::ROLES_FACTURACION],
            'reporte contadora (ventas)' => ['facturacion.reporte-contadora', self::ROLES_FACTURACION],
            'paquete contabilidad' => ['contabilidad.paquete', self::ROLES_FACTURACION],
            'auditoría' => ['auditoria.index', ['administrador', 'contabilidad']],
            'usuarios' => ['usuarios.index', ['administrador']],
            'configuración empresa' => ['configuracion.empresa.edit', ['administrador']],
            'salud del sistema' => ['admin.salud-sistema', ['administrador']],
            'importaciones' => ['importaciones.index', ['administrador']],
            'preparar emisión real' => ['facturacion.preparar-produccion', ['administrador', 'facturacion']],
        ];
    }

    /**
     * @param  array<int, string>  $permitidos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lecturas')]
    public function test_lectura_por_rol(string $rutaNombre, array $permitidos): void
    {
        foreach (self::ROLES as $rol) {
            $response = $this->actingAs($this->usuario($rol))->get(route($rutaNombre));

            if (in_array($rol, $permitidos, true)) {
                $response->assertSuccessful(); // 2xx: pudo entrar
            } else {
                $response->assertForbidden(); // 403: bloqueado en backend
            }
        }
    }

    // ---------------------------------------------------------------------
    // Escrituras (POST/PATCH/DELETE): 403 para roles sin permiso; los que sí
    // tienen permiso pasan la autorización (no 403; validación puede devolver
    // 302/422, lo que confirma que el gate los dejó pasar).
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function escrituras(): array
    {
        return [
            // Gestión de clientes/productos: SOLO administrador.
            'crear cliente' => ['post', 'clientes.store', ['administrador']],
            'crear producto' => ['post', 'productos.store', ['administrador']],
            // PPQ y exportaciones: administrador y facturación.
            'crear lote PPQ' => ['post', 'ppq.lotes.store', ['administrador', 'facturacion']],
            'crear lista de empaque' => ['post', 'exportaciones.store', ['administrador', 'facturacion']],
            // Sincronizar buzón Yahoo/IMAP: SOLO administrador.
            'sincronizar buzón' => ['post', 'documentos-recibidos.sincronizar', ['administrador']],
            // Enviar paquete a contabilidad: administrador y contabilidad (NO facturación).
            'enviar paquete contabilidad' => ['post', 'contabilidad.paquete.enviar', ['administrador', 'contabilidad']],
        ];
    }

    /**
     * @param  array<int, string>  $permitidos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('escrituras')]
    public function test_escritura_por_rol(string $metodo, string $rutaNombre, array $permitidos): void
    {
        foreach (self::ROLES as $rol) {
            $response = $this->actingAs($this->usuario($rol))->{$metodo}(route($rutaNombre), []);

            if (in_array($rol, $permitidos, true)) {
                $this->assertNotSame(403, $response->getStatusCode(), "El rol {$rol} debería pasar la autorización de {$rutaNombre}.");
            } else {
                $response->assertForbidden();
            }
        }
    }

    // ---------------------------------------------------------------------
    // Invalidación de DTE: SOLO administrador (regla explícita).
    // ---------------------------------------------------------------------

    public function test_invalidacion_dte_prohibida_para_no_administradores(): void
    {
        $dte = $this->crearDteBorrador();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            // El middleware permission:dte.invalidar bloquea antes del controlador.
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.invalidacion.mock', $dte))
                ->assertForbidden();

            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.invalidacion.dry-run', $dte))
                ->assertForbidden();
        }
    }

    // ---------------------------------------------------------------------
    // El invitado nunca entra (defensa base de auth).
    // ---------------------------------------------------------------------

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get(route('facturacion.index'))->assertRedirect(route('login'));
        $this->get(route('usuarios.index'))->assertRedirect(route('login'));
        $this->get(route('ppq.index'))->assertRedirect(route('login'));
    }

    /** Crea un CCF en borrador (solo para tener un {dte} válido en las rutas de invalidación). */
    private function crearDteBorrador(): Dte
    {
        $this->seedCatalogosDte();
        $emisor = $this->crearEmisorDte();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
    }
}
