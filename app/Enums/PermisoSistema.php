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
    // Deshacer un cobro ya registrado. Va SEPARADO de `ppq.gestionar` por la misma razón
    // que `planta.ajustes.confirmar` va separado de `planta.ajustes.crear`: agregar un
    // documento a un lote es operación diaria, y contradecir un pago que ya se dio por
    // cobrado es un acto que altera el saldo sin contrapartida del cliente.
    //
    // Conciliar —aplicar lo que dice el archivo del cliente— sigue bajo `ppq.gestionar`,
    // a propósito: es la operación de todos los días y no debe quedar bloqueada si este
    // permiso todavía no se sembró. Solo la reversión lo exige.
    case PpqRevertirConciliacion = 'ppq.revertir-conciliacion';

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
    // `ver` para entrar y consultar, `gestionar` para lo que escribe sobre rutas y
    // salidas (crear/editar rutas, asignar salas, crear salidas y moverles el estado).
    //
    // Los verbos finos que anticipaba la fase anterior ya existen debajo: la custodia
    // del CCF físico los necesitaba de verdad, porque quien lleva el papel y quien lo
    // recibe en oficina TIENEN que ser dos actores distintos.
    case RutasVer = 'rutas.ver';
    case RutasGestionar = 'rutas.gestionar';
    // Personal de campo: quién sale a vender, repartir o cobrar. `ver` es consulta;
    // `gestionar` da de alta y desactiva. Va aparte de `rutas.gestionar` porque el
    // catálogo de personas es un marco de trabajo, no la operación del día.
    case RutasPersonalVer = 'rutas.personal.ver';
    case RutasPersonalGestionar = 'rutas.personal.gestionar';
    // Custodia del CCF FÍSICO. Se parte en tres porque son tres actores distintos y
    // ese es justamente el control que el módulo existe para dar:
    //
    //  - `ver`: consultar dónde está cada papel. Solo lectura.
    //  - `registrar`: los hechos de CAMPO —entregar, transferir, reportar una
    //    incidencia—. Los declara quien anduvo la ruta.
    //  - `recepcion`: confirmar que el papel firmado volvió a la oficina. Lo declara
    //    quien recibe, NUNCA quien lo llevaba: si un vendedor pudiera cerrar su propia
    //    devolución, el control no controlaría nada.
    //  - `corregir`: anular un registro mal hecho. Contradice algo ya asentado, así que
    //    va con motivo obligatorio y con su propio permiso.
    case RutasCustodiaVer = 'rutas.custodia.ver';
    case RutasCustodiaRegistrar = 'rutas.custodia.registrar';
    case RutasRecepcion = 'rutas.recepcion';
    case RutasCustodiaCorregir = 'rutas.custodia.corregir';

    // Control de Asistencia (lector de huella ESP32). Área de personal: quién
    // marcó y a qué hora. NO emite DTE, no toca correlativos, firma, transmisión,
    // PPQ, Planta ni Rutas.
    //
    // Se parte en tres y no en «ver/gestionar» porque son tres decisiones de
    // riesgo distinto y las tres las va a tomar gente distinta:
    //
    //  - `ver`: consultar marcaciones y reportes. Solo lectura.
    //  - `gestionar`: dar de alta personas y ASIGNAR o LIBERAR ranuras del sensor.
    //    Asignar una ranura decide de quién van a ser las marcaciones que vengan
    //    después, así que es el permiso que de verdad hay que cuidar.
    //  - `dispositivos.gestionar`: dar de alta lectores y ROTARLES el token.
    //    Va aparte porque produce un secreto y porque quien administra al personal
    //    no tiene por qué poder dejar el lector de la puerta sin autenticar.
    //
    // Hoy no hay pantallas: los tres existen para que las de la fase siguiente
    // nazcan con su candado en vez de heredar `configuracion.gestionar`.
    case AsistenciaVer = 'asistencia.ver';
    case AsistenciaGestionar = 'asistencia.gestionar';
    case AsistenciaDispositivosGestionar = 'asistencia.dispositivos.gestionar';

    // Contabilidad / reportes.
    case ReportesVer = 'reportes.ver';
    case ContabilidadEnviar = 'contabilidad.enviar';

    // Administración.
    case AuditoriaVer = 'auditoria.ver';
    case UsuariosGestionar = 'usuarios.gestionar';
    case ConfiguracionGestionar = 'configuracion.gestionar';
    // Configuración de impacto FISCAL (nivel N3 del Centro de Configuración):
    // ambiente del MH, interruptores de firma y transmisión, credenciales de
    // Hacienda, correlativos. Va SEPARADO de `configuracion.gestionar` a
    // propósito: quien administra el correo de contabilidad o la plantilla del
    // mensaje no tiene por qué poder poner el sistema a emitir en producción,
    // y hasta ahora un solo permiso cubría ambas cosas.
    //
    // Hoy solo lo tiene el administrador (recibe todos). No amplía el acceso de
    // ningún rol existente: es un permiso NUEVO que nadie más recibe.
    case ConfiguracionCritica = 'configuracion.critica';
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
                // Facturación ya podía deshacer un pago sin querer —bastaba subir un TXT
                // parcial y el conciliador limpiaba lo que no venía—, así que darle el
                // permiso explícito no amplía lo que puede hacer: lo vuelve deliberado,
                // con motivo y con su nombre.
                self::PpqRevertirConciliacion,
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
