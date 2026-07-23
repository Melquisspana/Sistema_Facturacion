<?php

namespace App\Enums;

/**
 * Catálogo ÚNICO de permisos del sistema y su asignación por rol. Centraliza los
 * nombres (evita "strings mágicos") y es la fuente de verdad para el RolesSeeder,
 * las policies, el middleware `permission:` de las rutas y las pruebas.
 *
 * Convención de nombres: «recurso.acción» en minúsculas y en español. `ver` es
 * solo lectura; `gestionar` cubre crear/editar/eliminar; los verbos específicos
 * (`emitir`, `enviar-correo`, `invalidar`, `sincronizar` vía gestionar) aíslan
 * acciones sensibles.
 */
enum PermisoSistema: string
{
    // Inicio.
    case DashboardVer = 'dashboard.ver';

    // DTE (CCF, Factura, NC, FEX). La lógica fiscal/estado no cambia: estos
    // permisos solo gobiernan QUIÉN puede llegar a cada acción.
    case DteVer = 'dte.ver';
    case DteGestionar = 'dte.gestionar';
    case DteEmitir = 'dte.emitir';
    case DteEnviarCorreo = 'dte.enviar-correo';
    case DteInvalidar = 'dte.invalidar';

    // Comercial.
    case ClientesVer = 'clientes.ver';
    case ClientesGestionar = 'clientes.gestionar';
    case ProductosVer = 'productos.ver';
    case ProductosGestionar = 'productos.gestionar';

    // Prontos Pagos (PPQ).
    case PpqVer = 'ppq.ver';
    case PpqGestionar = 'ppq.gestionar';
    case PpqGmail = 'ppq.gmail';

    // Exportaciones / listas de empaque.
    case ExportacionesVer = 'exportaciones.ver';
    case ExportacionesGestionar = 'exportaciones.gestionar';

    // Documentos recibidos (compras). `gestionar` incluye la sincronización del
    // buzón Yahoo/IMAP y el cambio de estados internos (solo administrador).
    case DocumentosRecibidosVer = 'documentos-recibidos.ver';
    case DocumentosRecibidosGestionar = 'documentos-recibidos.gestionar';

    // Contabilidad / reportes.
    case ReportesVer = 'reportes.ver';
    case ContabilidadEnviar = 'contabilidad.enviar';

    // Administración.
    case AuditoriaVer = 'auditoria.ver';
    case UsuariosGestionar = 'usuarios.gestionar';
    case ConfiguracionGestionar = 'configuracion.gestionar';
    case ImportacionesGestionar = 'importaciones.gestionar';
    case SistemaSalud = 'sistema.salud';
    case PreparacionVer = 'preparacion.ver';
    case RespaldosEjecutar = 'respaldos.ejecutar';

    /** @return array<int, string> Todos los permisos (valores). */
    public static function todos(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * Permisos asignados a un rol. El administrador recibe TODOS (acceso total):
     * así cualquier permiso nuevo lo cubre automáticamente sin editar este mapa.
     *
     * @return array<int, string>
     */
    public static function paraRol(RolSistema $rol): array
    {
        return match ($rol) {
            RolSistema::Administrador => self::todos(),

            // Jefatura: solo lectura amplia. No gestiona, no emite, no invalida,
            // no sincroniza, no administra.
            RolSistema::Jefatura => self::valores([
                self::DashboardVer,
                self::DteVer,
                self::ClientesVer,
                self::ProductosVer,
                self::PpqVer,
                self::ExportacionesVer,
                self::DocumentosRecibidosVer,
                self::ReportesVer,
            ]),

            // Facturación: operación diaria. Emite y gestiona DTE, PPQ y
            // exportaciones; ve (no gestiona) clientes/productos; ve compras.
            RolSistema::Facturacion => self::valores([
                self::DashboardVer,
                self::DteVer,
                self::DteGestionar,
                self::DteEmitir,
                self::DteEnviarCorreo,
                self::ClientesVer,
                self::ProductosVer,
                self::PpqVer,
                self::PpqGestionar,
                self::ExportacionesVer,
                self::ExportacionesGestionar,
                self::DocumentosRecibidosVer,
                self::ReportesVer,
                self::PreparacionVer,
            ]),

            // Contabilidad: solo lectura contable + envío del paquete a la
            // contadora + consulta de auditoría. No emite ni edita nada.
            RolSistema::Contabilidad => self::valores([
                self::DashboardVer,
                self::DteVer,
                self::ClientesVer,
                self::ProductosVer,
                self::PpqVer,
                self::ExportacionesVer,
                self::DocumentosRecibidosVer,
                self::ReportesVer,
                self::ContabilidadEnviar,
                self::AuditoriaVer,
            ]),
        };
    }

    /**
     * @param  array<int, self>  $permisos
     * @return array<int, string>
     */
    private static function valores(array $permisos): array
    {
        return array_map(fn (self $p) => $p->value, $permisos);
    }
}
