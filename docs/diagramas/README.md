# Prueba local de Archify

Generada el 7 de septiembre de 2026. Instalación de proyecto en `.claude/skills/archify`, desde `tt-a1i/archify`, subcarpeta `archify`. Disponible para Claude en su siguiente turno/sesión que cargue las skills del proyecto.

## Abrir

- [Arquitectura](arquitectura.html)
- [Emisión de DTE](emision-dte.html)

Los HTML son autónomos. No requieren arrancar Laravel ni conectarse a la base de datos. Los diagramas no están integrados al menú del sistema. Textos en español; controles del visor y atributo HTML lang con fallback inglés. El selector Flow permite comparar los estilos incluidos y el botón Light/Dark cambia el tema.

## Evidencia del código

- `composer.json`, `package.json`: Laravel 12, Livewire 4, Blade/Alpine y Vite.
- `routes/web.php`, rutas y controladores de las áreas: agrupación funcional del mapa.
- `app/Http/Controllers/Facturacion/DteController.php`, `generarTransmitirProduccion`, `procesarFirmaTransmision`: preflight, confirmación, generación, firma y transmisión desde la petición web.
- `app/Services/Dte/DteTransmisionResiliente.php`: consulta antes del reenvío, reintentos acotados, sin activar contingencia automáticamente.
- `app/Services/Dte/DteTransmisionService.php`: recepción y persistencia del resultado.
- `app/Services/Dte/EnvioDteCorreoService.php`: historial y despacho de EnviarDteCorreo.
- `app/Observers/DteObserver.php`: autoenvío al aceptar, sujeto a ajustes, destinatario e historial.

Es un resumen del código local, no una verificación de infraestructura activa. No se consultó .env ni se ejecutó la aplicación. Las flechas del tramo Borrador → Preflight → Generar DTE → Firmar omiten etiquetas porque los extremos ya describen el orden de esas operaciones. El nodo Consultar / reintentar resume un subproceso; sus resultados y retornos no están desglosados.

## Validación y revisión

Ambos diagramas: validación showcase 9/9, 0 errores, 0 advertencias; browser_evidence: passed mediante Edge/Chromium, cuatro tamaños (1440×900, 1600×1000, 1920×1080, 2048×1320), sin desbordamiento.

Revisión perceptual de capturas: passed para la composición estática, temas claro a 1440×900 y oscuro a 2048×1320. No se probaron manualmente búsqueda, selección ni exportación. Las capturas y recibos visual-check están junto a los HTML; visualReview: pending en esos recibos es el estado automático de Archify y no sustituye esta revisión separada.

Arquitectura: correction_rounds: 2. Fuente SHA-256 `4ebf1e8e9bbc1515a22bf28f102e002b84abbba6f1529d974c5690f6a4740c95`; HTML SHA-256 `2cf40d1d73bc256359891808b04b5444d9b432e39a8386ec682d475d66647f67`.

Flujo DTE: correction_rounds: 1. Fuente SHA-256 `b5f80f4749423dd9934cf95cef064a182cfa32e29b2029120ddb0b6a5389073c`; HTML SHA-256 `b9b689a9ad0b1f18336987e723ce79126e30a9c4870f742bb0af553950f6aad4`.

Los recibos completos son `arquitectura.receipt.json` y `emision-dte.receipt.json`. Para regenerar, usar `node .claude/skills/archify/bin/archify.mjs deliver` con el tipo, fuente JSON, salida HTML y `--quality showcase --json`. Leer primero el SKILL.md instalado.

No se modificó código de aplicación, dependencias de Composer/npm, base de datos, servicios, firmware ni respaldos. Sin commit, push ni despliegue.
