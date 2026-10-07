# 0006. Contingencia: emitir sin conexión con Hacienda y regularizar después

## Estado

Propuesta

## Fecha

2026-10-06

## Contexto

Hacienda 2.0 exige que el emisor pueda seguir facturando cuando no puede transmitir y que regularice después (issue #43, plazo 01/12/2026). Hoy el sistema aplica la política de reintentos (`DteTransmisionResiliente`). Cuando se agota, solo informa `contingencia_requerida`: no genera documentos en contingencia, no arma el evento ni envía lotes.

Fuentes. Las páginas son las impresas en cada documento; los PDF quedan fuera del repositorio.

- **NCu**: Normativa de Cumplimiento de los DTE v2.0.
- **MF**: Manual Funcional del Sistema de Transmisión v2.0.
- **MT**: Manual Técnico para la Integración Tecnológica del Sistema de Transmisión v2.0.
- **Esquema**: `contingencia-schema-v4.json` del ZIP oficial de esquemas.
- **CAT**: catálogos de julio de 2026 (`resources/dte/catalogos/`).

## Decisión propuesta

### 1. Cuándo se entra en contingencia y quién lo decide

- **Causas válidas**: caso fortuito o fuerza mayor que impida transmitir. Por ejemplo: falla del proveedor de internet o de energía, o que la plataforma del MH no esté disponible (NCu p.30, Cuadro 7; MF p.18). Los códigos son los de CAT-005: 1 MH no disponible, 2 sistema del emisor no disponible, 3 falla de internet del emisor, 4 falla de energía del emisor, 5 otro, con motivo de hasta 500 caracteres (MF p.18).
- **Requisito previo**: haber agotado la política de reintentos. Son 8 segundos de espera, consulta del estado y hasta 2 reenvíos (NCu p.30, 13.2.1; MT pp.14-15, 3.3; MF p.19). Ya está implementada.
- **Entrada automática** (permitida: la norma solo exige agotar la política de reintentos, y deja en manos del emisor diseñar sus condiciones de contingencia; MF pp.17 y 19, NCu p.30). Cuando la política termina con `contingencia_requerida`, el sistema entra solo en contingencia. Elige el tipo con una comprobación de conexión: **1** si hay internet pero el MH no responde, **3** si no hay salida a internet. Muestra el aviso en pantalla y deja auditoría. Un administrador puede corregir el tipo o el motivo antes de enviar el evento, y también puede activarla a mano (por ejemplo, tipo 2 o 4).
- **Mientras dura**: el sistema intenta reconectar al MH **por lo menos cada 15 minutos** (NCu p.30). La primera reconexión correcta se registra como **cese**, la fecha y hora desde la que corre el plazo del evento. Se avisa con «Volvió la conexión: enviá el aviso a Hacienda», y el administrador puede ajustar el cese antes de enviar.
- Si la contingencia dura **más de 3 días seguidos**, antes de transmitir el evento hay que presentar al MH un **Informe Técnico de Contingencia** (NCu p.31, 13.2.1.1). El sistema avisa desde el tercer día; el informe se presenta fuera del sistema.

### 1.1 Seguir trabajando sin internet

El servidor y el firmador están en el local: sin internet se puede **generar y firmar**. Solo la transmisión al MH necesita salida a internet. El problema es **entrar** al sistema: siempre se entra por el dominio público, a través de Cloudflare, y producción usa cookies de sesión seguras (`SESSION_SECURE_COOKIE`), que el navegador solo guarda con HTTPS. Por `http://` a la IP local no se puede iniciar sesión.

**Plan** (sin instalar nada en las PC del local y sin pasos con el teléfono, porque quienes usan el sistema no tendrían por qué saber hacerlos):

- **Acceso por la red local**: un vhost de Apache solo para la red local, con **HTTPS y certificado autofirmado**, más un favorito «Facturación (sin internet)» en cada PC que apunta a **la dirección local del servidor (se guarda fuera del repositorio)**.
  - El navegador avisa que el certificado no es de confianza («Avanzado → Continuar»). El aviso puede volver al reiniciar el navegador o después de un tiempo.
  - Como la conexión es HTTPS, la cookie segura se guarda y el inicio de sesión funciona.
  - Para que el favorito no cambie, la IP del servidor queda fija con una **reserva DHCP** en el router del proveedor, o en el sistema de malla si el router no lo permite.
- **Aviso automático** al abrir el dominio sin internet, con el botón al favorito (ver abajo).
- **Contingencia automática**: sin internet, el servidor tampoco llega al MH. Los documentos se generan, firman y entregan como transitorios (sección 2) y se regularizan al volver la conexión (secciones 3 y 4).
- Para que el acceso por la red local funcione hay que comprobar en la configuración del servidor, no en el repositorio:
  - que `SESSION_DOMAIN` esté vacío, porque una cookie atada al dominio público no se guarda al entrar por IP;
  - que la aplicación no fuerce la URL pública en las redirecciones (hoy no lo hace: no hay `forceRootUrl`);
  - que el vhost de la red local no se publique hacia internet.
  - Al entrar por la red local no pasa por Cloudflare Access: protege solo el inicio de sesión de la aplicación, igual que en cualquier acceso desde adentro.
- **Descartadas**: conectar el servidor a internet con el celular (hotspot o anclaje USB; requiere pasos que no todos saben hacer), el archivo `hosts` en las PC y una CA local o mkcert (exigen instalar algo en cada PC), y el DNS local con el mismo dominio y Let's Encrypt (el router del proveedor y la malla en modo punto de acceso no ofrecen DNS local).

**Aviso al abrir el dominio sin internet.** Una redirección desde Cloudflare no sirve: sin internet, las PC tampoco llegan a Cloudflare. Se propone un *service worker* mínimo en el propio dominio. Se instala solo al visitar el sitio, sin tocar las PC.
- Se registra solo para usuarios con sesión iniciada. Al instalarse guarda **una única página estática**: «Sin internet: entrá por la red de la casa». La página trae un botón al favorito «Facturación (sin internet)», con la dirección local tomada de la configuración del servidor y no del repositorio. La ruta que la genera exige sesión, así que la dirección local no queda expuesta a visitantes.
- Solo intercepta **navegaciones GET**, y primero intenta la red. Sirve la página guardada solo si la red **falla**, o si Cloudflare responde que no llega al servidor (502, 504 o 52x). Cualquier otra respuesta pasa intacta.
- No guarda en caché ninguna otra respuesta (nada con datos), no toca POST, el inicio de sesión ni las descargas, y se borra la caché al cerrar sesión.
- Para «Hacienda no responde» ya está el aviso de contingencia dentro de la aplicación; esta página es solo para cuando no hay internet.

**Ensayo antes de necesitarlo**:
1. Desconectar el router de internet.
2. Desde una PC, abrir el dominio de siempre: debe aparecer el aviso «Sin internet» con el botón (cuando exista el PR del aviso).
3. Entrar por el favorito, aceptar el aviso del certificado, **iniciar sesión**, emitir un documento de prueba en el ambiente 00 y comprobar que queda firmado, entregado como transitorio y pendiente de envío (contingencia).
4. Reconectar el router: comprobar que el sistema registra el cese y que el evento y el lote se envían.
5. Reiniciar el router y comprobar que el servidor conserva su IP.

**Corte de luz del servidor**: fuera del alcance del sistema. Si el servidor se apaga, no hay aplicación, ni firma, ni registro. Se recomienda una **UPS** para el servidor, el router y la malla, con autonomía para cerrar ordenadamente. Lo que se facture a mano durante el corte se regulariza fuera de este flujo; consultarlo con la contadora y el MH.

### 2. El documento durante la interrupción

- Se puede emitir en contingencia CCF, NC, ND, FE, FEX, FSE y los demás tipos que lista la norma (NCu p.18, Cuadro 4, fila 3; MF p.18). Este sistema solo emite CCF, NC, FE y FEX.
- El documento se **genera y se firma** con la misma estructura de siempre, con cuatro campos fijos: `tipoModelo` = 2 (diferido), `tipoOperacion` = 2 (contingencia), `tipoContingencia` según CAT-005 y `motivoContin` (MF pp.18-19). Esos campos **no cambian** después: el MH los compara con el evento (MF p.20).
- **Entrega al cliente**: el JSON firmado (con `firmaElectronica`, **sin** `selloRecibido`) junto con su representación gráfica sin sello. Ambos deben mostrar que se transmitió por contingencia (código 2 de CAT-004) (NCu p.24, reglas 11.1 y 11.2; MF p.19). El archivo de entrega (decisión de #39) suma un cuarto estado, **TRANSITORIO**. El PDF lleva la leyenda «Documento emitido en contingencia, pendiente de sello de recepción» y no muestra el QR de consulta hasta tener sello, como hoy. Cuando llega el sello, se ofrece reenviar el archivo completo.
- **Valor**: firmado tiene valor probatorio, pero **no es deducible** ni se tiene por emitido hasta que obtiene el sello (NCu p.18, 8.1; p.24). La numeración y los correlativos siguen igual.
- No se invalida ni se ajusta (NC) un documento transitorio: solo se aplica a documentos con sello (MF p.27, para el retorno; el mismo criterio para la invalidación). Queda bloqueado hasta que tenga sello.

### 3. Evento de contingencia

- **Plazo**: hasta **24 horas desde el cese** (NCu p.30, Cuadro 7; MF p.19). Si el MH lo rechaza por estructura, hay **24 horas** desde el rechazo para corregirlo y reenviarlo (MF p.19). El evento es requisito previo para enviar los documentos (MF p.19; MT p.13).
- **Contenido** (Esquema; MF p.19):
  - `identificacion`: versión 4, ambiente, `codigoGeneracion` propio, `fTransmision` y `hTransmision`.
  - `emisor`: NIT, nombre, `nombreResponsable`, `tipoDocResponsable`, `numeroDocResponsable` (obligatorios, MF p.20), tipo de establecimiento, `codEstableMH`, `codPuntoVentaMH`, teléfono y correo.
  - `detalleDTE`: de **1 a 1000** documentos, con `noItem`, `tipoDoc` y `codigoGeneracion`.
  - `motivo`: `fInicio`, `fFin`, `hInicio`, `hFin`, `tipoContingencia` y `motivoContingencia`.
  - Con más de 1000 documentos se envían varios eventos.
- **Envío**: `POST /fesv/contingencia` con `{nit, documento: JWS firmado}`. La respuesta trae `estado` (RECIBIDO o RECHAZADO), `fechaHora`, `mensaje`, `selloRecibido` y `observaciones` (MT p.27). Se guardan JSON, JWS y respuesta, igual que en la invalidación.
- Los datos del responsable dependen de #52 (responsable y solicitante), y los códigos MH de establecimiento y punto de venta, de #54.

### 4. Envío posterior por lote

- **Plazo**: los documentos informados se transmiten en las **72 horas** siguientes al sello del evento (NCu p.18, Cuadro 4, fila 3; p.31, 13.2.2; MF p.20).
- **Qué se envía**: solo los documentos informados en ese evento (MF p.20). Puede ser uno a uno o por lote (MT p.27). Se propone el lote: **máximo 100 documentos por lote**, hasta 400 lotes (MT pp.15-16). Para contingencia el servicio atiende las **24 horas, todos los días** (MT p.16).
- **Lote**: `POST /fesv/recepcionlote` con `ambiente`, `idEnvio` (UUID v4 en mayúsculas), `version`, `nitEmisor` y `documentos` (lista de JWS) (MT p.21). La respuesta es `RECIBIDO` con un `codigoLote` y **no** trae el resultado (MT pp.14, 21).
- **Consulta**: `GET /fesv/recepcion/consultadtelote/{codigoLote}` cada pocos minutos; un lote de 100 tarda de 1 a 3 minutos (MT pp.14-16). Devuelve `procesados` y `rechazados`, cada uno con `codigoGeneracion`, `selloRecibido`, `observaciones` e `index` (MT p.25). Cada procesado se aplica al documento por el mismo camino que la recepción normal: sello, fecha de procesamiento y estado Aceptado.
- **Rechazados**: el documento queda **Rechazado**, como un rechazo normal, con el motivo a la vista. Como el cliente ya recibió un documento transitorio sin validez, se le avisa y se emite el documento corregido **con un código nuevo, por transmisión normal** (ya hay conexión). El transitorio rechazado no se reenvía con otros datos, porque eso cambiaría lo que se firmó y entregó. **A confirmar con el MH**: las fuentes leídas no regulan este caso.
- **Fuera de plazo** (evento o lote): el documento no queda emitido. Hay que justificarlo por escrito ante la DGII y pedir prórroga (MF p.20). El sistema no lo transmite por su cuenta: muestra el vencimiento y deja el trámite a la persona responsable.

### 5. Qué ve el usuario

- **Aviso fijo arriba**: «Modo contingencia desde las 10:15. Los documentos se generan y firman, pero todavía no se envían a Hacienda». Muestra el tipo y el motivo, y un botón «Terminar contingencia» para quien tenga permiso.
- **Al terminar**: «Tenés hasta mañana a las 10:15 para enviar el aviso a Hacienda», con un botón «Enviar aviso de contingencia».
- **Bandeja «Pendientes de envío»**: los documentos transitorios con cliente, total y estado (pendiente, informado, enviado en lote, aceptado o rechazado), con cuenta regresiva de 24 y 72 horas. Tiene botones «Enviar a Hacienda» y «Consultar resultado». Un rechazado ofrece «Emitir corregido».
- En la ficha del documento, una etiqueta «En contingencia» y el estado de su envío.

### 6. Riesgos fiscales

- Un documento firmado y no transmitido **presume ingresos gravados** (art. 199 del Código Tributario; NCu p.18), y si no se transmite a tiempo **no queda emitido** (NCu p.24). Por eso la bandeja y los avisos de plazo son obligatorios, no opcionales.
- **Doble transmisión**: un documento cuyo envío quedó en duda pudo haber entrado al MH. Antes de incluirlo en el evento, se consulta su estado; si ya tiene sello, sale de la contingencia (es la misma regla de la política de reintentos).
- Las **declaraciones de IVA** deben reflejar las ventas hechas en contingencia (NCu p.31). El reporte de la contadora tiene que mostrarlas, separando las que tienen sello de las que no.
- **Hora**: `fecEmi`, `horEmi` y las fechas del evento deben ir en hora de El Salvador. Hoy `fecEmi` y `horEmi` salen en UTC (decisión 0004). La contingencia **no** debe implementarse antes de resolver eso, porque el MH compara fechas.
- El MH puede **revocar la autorización** de emitir DTE si el emisor no resguarda la seguridad y exactitud de lo emitido (MF p.20). Hay que guardar los JWS, eventos y respuestas con respaldo, como ya se hace con los DTE.
- **Ambigüedad de la fuente**: el Cuadro 5 (NCu p.19) dice «Evento de Invalidación» en la fila de contingencia; el Cuadro 7 (p.30) y el MF (p.19) la aclaran. Se toman las 24 horas desde el cese para el evento de contingencia.

## Plan de implementación (5 PR chicos)

1. **Modo contingencia y documentos transitorios.** Necesita una migración nueva: tabla `contingencias` con tipo, motivo, inicio, cese y estado; requiere autorización según la decisión 0002. Incluye:
   - activar y terminar con permiso y auditoría;
   - generación con los cuatro campos fijos, firma sin transmitir y bloqueo de invalidación y NC;
   - estado TRANSITORIO en el archivo de entrega, y PDF con leyenda;
   - el aviso en pantalla.
   - Pruebas: entrar, emitir, entregar y salir, y que los campos no cambien.
2. **Evento de contingencia.**
   - Esquema v4 al repo, serializador, firma, envío a `/fesv/contingencia` y persistencia de la evidencia.
   - Plazo de 24 horas desde el cese, con corrección en 24 horas tras un rechazo y división cuando hay más de 1000 documentos.
   - Aviso del informe técnico a los 3 días.
   - Pruebas: plazos en el límite (23:59:59 contra 00:00), rechazo y reenvío, más de 1000 documentos.
3. **Lotes y consulta.**
   - Tabla `lotes_dte`, que también necesita migración.
   - Envío en grupos de hasta 100, consulta programada por `codigoLote`, aplicación de sellos y rechazos por documento, y plazo de 72 horas.
   - La bandeja «Pendientes de envío».
   - Pruebas: lote mixto con procesados y rechazados, consulta sin resultado todavía, vencimiento.
4. **Aviso sin internet** (independiente, chico).
   - Service worker de la sección 1.1, la ruta con sesión de la página «Sin internet» y la limpieza al cerrar sesión.
   - Pruebas: la página no lleva datos y su ruta exige sesión. Prueba manual: red cortada, error 52x de Cloudflare, login, POST y descargas sin cambios.
5. **Detección y cierre.**
   - Entrada automática desde `DteTransmisionResiliente`, con el tipo 1 o 3 según la conexión, y prueba de reconexión cada 15 minutos que registra el cese (tarea programada).
   - Vhost HTTPS de la red local con certificado autofirmado, reserva DHCP y el ensayo de la sección 1.1 (inicio de sesión por la red local y contingencia de punta a punta).
   - Avisos de plazo, reenvío del archivo completo al cliente cuando llega el sello y separación en el reporte de la contadora.
   - Prueba de extremo a extremo en el ambiente de pruebas del MH: contingencia simulada, evento, lote y sellos.

Antes del PR 1 hay que resolver la hora local de `fecEmi`/`horEmi` (decisión 0004), #52 (responsable) y #54 (códigos del MH).

## Consecuencias

Permite seguir facturando sin el MH sin perder el control fiscal. Agrega dos tablas, tareas programadas y una bandeja nueva. El sistema entra y sale solo, avisa los plazos y bloquea lo que no corresponde. Revisar el tipo y el motivo y presentar el informe técnico o una prórroga queda en una persona.

## Pendiente confirmar con el MH

- Qué hacer con un documento transitorio rechazado en el lote: emitir otro con un código nuevo, o corregir el mismo.
- Si la representación gráfica transitoria puede llevar QR de consulta.
- La versión exacta del «JSON Schema de Lotes» del campo `version` del lote (MT p.21).
