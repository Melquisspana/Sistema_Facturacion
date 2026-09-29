# Aviso de TXT compartido entre PPQ y Cobros — 24/09/2026

El mismo contenido TXT se identifica por su SHA-256. Tras procesarlo en PPQ, el informe de Cobros muestra las conciliaciones previas y enlaza los lotes disponibles; tras procesarlo en Cobros, el informe PPQ muestra la cantidad de pagos, documentos y ajustes registrados. El aviso es informativo: no sincroniza estados, no suma pagos y no bloquea la carga. Se conserva la prevención de duplicados propia de cada circuito.

Claude Opus 5.5 dejó cambios parciales antes de alcanzar el límite de sesión. El usuario encargó expresamente a Codex completar solo este aviso. Codex acotó la búsqueda a conciliaciones TXT y eventos de pago TXT, evitó enlazar lotes eliminados, completó el texto para archivos solo con QD y añadió pruebas de ambos sentidos, huellas distintas, QD y lotes eliminados.

Verificación local: `EvidenciaEntreCircuitosTest`, 5 pruebas y 25 aserciones; `CobrosPagoDuplicadoTest` y `ConciliacionNoDestructivaTest`, 31 pruebas y 169 aserciones. Pint `--test` correcto. No hubo migraciones nuevas ni cambios en producción.

Límite de evidencia: Cobros no registra el archivo como entidad independiente. Un TXT sin documentos identificados ni ajustes puede no dejar evento o ajuste; por eso la ausencia de aviso en PPQ no demuestra que nunca se intentara cargar en Cobros. La huella se compara sobre el contenido exacto, incluidos espacios y saltos de línea.
