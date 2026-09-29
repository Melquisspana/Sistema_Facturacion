# Plantilla de quedan de Calleja — revisión en desarrollo (23/09/2026)

## Resultado

La plantilla aportada por el usuario en `C:\Users\<usuario>\Desktop\FORMATO DE CARGA MASIVA QUEDAN.xlsx` se inspeccionó en lectura y se copió **sin modificar sus bytes** a `resources/templates/calleja/FORMATO DE CARGA MASIVA QUEDAN.xlsx`. SHA-256 de ambas copias: `F987B37F5919FFE183BA8399C8B61760F98CEA50673F07348F91528B25AFB5EF`. El archivo tiene una sola hoja visible, `Hoja3`, cinco encabezados en A1:E1, y no contiene filas de ejemplo, fórmulas ni validaciones. Los encabezados son: `CODIGO DE SALA O CD`, `# ALBARAN`, `AÑO (ultimos 2 digitos)`, `MES  (en numero)` (dos espacios) y `TIPO ALBARAN`.

Claude Opus 5.5, mediante el puente y en la SessionId registrada en `docs/COLABORACION_CODEX_CLAUDE.md`, ajustó `ExportadorSolicitudCargaMasivaV1` para partir de esa copia del proyecto. Valida la hoja y los encabezados antes de generar; conserva estilo y anchos de la plantilla, agrega las filas desde la 2, mantiene sala/número/tipo como texto y escribe año/mes como números. Si la plantilla falta, está dañada o difiere, se detiene con error; no inventa otro formato. No se lee el Escritorio en tiempo de ejecución.

Codex detectó durante la revisión que `SolicitudCobroService::archivo()` regeneraba el Excel aun cuando había guardado una copia con SHA-256. Claude corrigió el flujo: la primera descarga archiva y verifica los bytes; las siguientes entregan exactamente esa copia, también verificada. Copia ausente, alterada, ilegible o registro parcial producen error sin regeneración ni aumento del contador. Se mantiene independiente el registro explícito de presentación. El nombre y el tipo MIME de Excel se conservaron en la respuesta HTTP. Se corrigieron además los temporales vacíos que dejaba `tempnam()` al concatenarle `.xlsx`.

## Revisión y límites

Codex verificó la igualdad de SHA-256 entre original y copia, revisó código y ejecutó `CobrosSolicitudTest`: **25 pruebas, 161 aserciones correctas**, más Pint `--test` sobre los archivos tocados. Las pruebas cubren encabezados, estilos, tipos, redescarga byte a byte aunque cambie la fuente, copias faltantes/corruptas, fallo de storage y separación de descarga y presentación. El registro de Claude confirma `claude-opus-5-5` como modelo efectivo.

Esto valida el archivo en desarrollo, **no su aceptación en el portal de Calleja**. Si una solicitud antigua no tiene `archivo_hash`/`archivo_path`, su primera descarga futura se genera con el exportador vigente; no es posible asegurar por esos campos que coincida con un archivo entregado anteriormente. Una copia archivada dañada genera hoy una página de error técnico (HTTP 500) en vez de un aviso dentro de Cobros: pendiente de mejorar el tratamiento visual sin sustituir el original. No se ejecutaron migraciones, altas masivas, envíos, despliegues ni cambios de producción por esta entrega.

## Próximo paso del módulo

Completar la bandeja de Cobros con paginación real y una acción de preparación/revisión de quedan; mostrar por documento el motivo concreto que impide presentarlo. Luego ordenar el historial de PPQ y los formatos de NC con redescarga original, y conciliar TXT/pagos. Conservar la separación entre generado, presentado, recibido y pagado.
