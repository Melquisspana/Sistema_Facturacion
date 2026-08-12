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

    // Producción / planta. Área operativa AISLADA: no interviene en DTE,
    // correlativos, firma, transmisión ni correo. Nombre técnico «planta»;
    // etiqueta visible «Producción» (ver config/planta.php).
    case PlantaVer = 'planta.ver';
    case PlantaGestionar = 'planta.gestionar';
    // Catálogos de Planta (insumos, proveedores, ubicaciones, productos base,
    // presentaciones, empaques, lotes). Leerlos es operativo; gestionarlos
    // define el MARCO DE TRABAJO y por eso es de administrador.
    case PlantaCatalogosVer = 'planta.catalogos.ver';
    case PlantaCatalogosGestionar = 'planta.catalogos.gestionar';
    // Recepciones de insumos. `confirmar` es la acción que MUEVE inventario;
    // `reversar` deshace una entrada ya contabilizada y por eso va aparte:
    // si lo cubriera `confirmar`, todo operador podría anular entradas firmes.
    case PlantaRecepcionesVer = 'planta.recepciones.ver';
    case PlantaRecepcionesCrear = 'planta.recepciones.crear';
    case PlantaRecepcionesConfirmar = 'planta.recepciones.confirmar';
    case PlantaRecepcionesReversar = 'planta.recepciones.reversar';
    // Traslados entre ubicaciones. Enviar y recibir son dos actos distintos
    // (salida física y llegada física) y pueden recaer en personas distintas.
    case PlantaTrasladosVer = 'planta.traslados.ver';
    case PlantaTrasladosCrear = 'planta.traslados.crear';
    case PlantaTrasladosEnviar = 'planta.traslados.enviar';
    case PlantaTrasladosRecibir = 'planta.traslados.recibir';
    case PlantaTrasladosReversar = 'planta.traslados.reversar';
    // Ajustes: carga inicial, mermas, daños, vencimientos y correcciones de
    // conteo. `ver` y `crear` son operativos —planta consulta lo que se ajustó y
    // PREPARA el borrador de lo que ve en su bodega—; `confirmar` y `reversar`
    // son de ADMINISTRADOR, porque son los que alteran la cantidad física sin
    // contrapartida documental. Un borrador no mueve nada. La auditoría (motivo
    // obligatorio + Activitylog + mayor inmutable) acompaña a la autorización,
    // NO la sustituye.
    case PlantaAjustesVer = 'planta.ajustes.ver';
    case PlantaAjustesCrear = 'planta.ajustes.crear';
    case PlantaAjustesConfirmar = 'planta.ajustes.confirmar';
    case PlantaAjustesReversar = 'planta.ajustes.reversar';
    // Control BÁSICO de disponibilidad: elegir si una recepción entra retenida
    // o disponible, y liberar / rechazar / retener saldo después. Un solo
    // permiso cubre las cuatro cosas porque quien decide una decide todas.
    // No es evaluación de calidad (parámetros, análisis): es el interruptor
    // que determina qué saldo puede trasladarse o utilizarse.
    case PlantaCalidadGestionar = 'planta.calidad.gestionar';
    // Consultas de solo lectura sobre el inventario y su historial.
    case PlantaExistenciasVer = 'planta.existencias.ver';
    case PlantaMovimientosVer = 'planta.movimientos.ver';

    // Rutas / Cobros. Área comercial de campo: qué ruta visita cada sala, qué
    // salidas se hacen y quién va en ellas. NO emite DTE, no toca correlativos,
    // firma, transmisión, PPQ ni Planta.
    //
    // Solo dos permisos en esta fase: `ver` para entrar y consultar, `gestionar`
    // para todo lo que escribe (crear/editar rutas, asignar salas, crear salidas
    // y moverles el estado). Cuando exista el seguimiento documental se partirá
    // en verbos más finos; abrir hoy permisos que nadie usa es ruido.
    case RutasVer = 'rutas.ver';
    case RutasGestionar = 'rutas.gestionar';

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

            // Producción (planta): SOLO su área. Deliberadamente sin dte.ver,
            // clientes.ver, productos.ver, ppq.ver, reportes.ver,
            // exportaciones.ver ni documentos-recibidos.ver — así el aislamiento
            // del área es demostrable (403 en todo lo demás, ver
            // RolesPermisosTest) y no hereda visibilidad fiscal por descuido.
            //
            // Dentro de su área recibe la OPERACIÓN DIARIA: recibir insumos,
            // confirmarlos, trasladarlos entre Casa y Fábrica, y consultar
            // existencias, movimientos y ajustes.
            //
            // AJUSTES: producción PREPARA, administración CONFIRMA. Tiene
            // `ajustes.crear` —que cubre crear, editar y anular borradores— y no
            // tiene `confirmar`. La razón es operativa: quien ve la merma, el
            // daño o el vencimiento es quien está en la bodega, y obligarle a
            // llamar al administrador para siquiera empezar a registrarlo hace
            // que las mermas se anoten tarde, en bloque o nunca. Un borrador NO
            // mueve inventario: no escribe en el mayor ni toca `planta_existencias`.
            // El acto que sí lo mueve —confirmar— sigue exigiendo un segundo par
            // de ojos, y así quien pudiera ser responsable de un faltante no
            // puede aplicarlo solo.
            //
            // `ajustes.crear` NO distingue por tipo: producción puede preparar
            // también una carga inicial o un ajuste positivo. El riesgo queda
            // acotado porque ninguno de ellos altera nada hasta que el
            // administrador lo confirma leyendo el motivo, la cantidad y el
            // bucket.
            //
            // Queda FUERA, reservado a administrador (y a un supervisor futuro,
            // que NO se crea todavía):
            //   - planta.gestionar y planta.catalogos.gestionar: definen el
            //     marco de trabajo, no la operación;
            //   - las tres reversiones: deshacen inventario ya contabilizado;
            //   - ajustes.confirmar: es el acto que altera la cantidad física
            //     sin contrapartida documental;
            //   - planta.calidad.gestionar: decide qué saldo es utilizable.
            // La auditoría NO es motivo para ampliar este set: son capas
            // complementarias, no intercambiables.
            RolSistema::Produccion => self::valores([
                self::DashboardVer,
                self::PlantaVer,
                self::PlantaCatalogosVer,
                self::PlantaRecepcionesVer,
                self::PlantaRecepcionesCrear,
                self::PlantaRecepcionesConfirmar,
                self::PlantaTrasladosVer,
                self::PlantaTrasladosCrear,
                self::PlantaTrasladosEnviar,
                self::PlantaTrasladosRecibir,
                self::PlantaAjustesVer,
                self::PlantaAjustesCrear,
                self::PlantaExistenciasVer,
                self::PlantaMovimientosVer,
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
