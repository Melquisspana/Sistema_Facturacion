# 0001. Flujo con ramas y pull requests

## Estado

Aceptada

## Fecha

2026-09-28

## Contexto

Varias sesiones de desarrollo en paralelo, realizadas por personas y asistentes de IA, se interferían al trabajar sobre `master`. Se acumularon semanas de cambios sin versionar, lo que dificultaba la revisión y el relevo entre sesiones.

El repositorio es público y sirve de portafolio. Su historial debe ser legible y no puede contener datos reales de clientes o empleados, identificadores tributarios, correos, credenciales ni detalles del servidor o del despliegue.

## Decisión

1. Organizar el trabajo en GitHub Issues y Projects mediante un tablero Kanban, con etiquetas de módulo, tipo y prioridad. Usar un milestone por etapa: **Portafolio**, **Ciberseguridad** y **Calidad**.
2. Mantener los hallazgos de seguridad fuera de los issues públicos. Se reportan y gestionan en Security Advisories privados hasta que se corrigen, conforme a `SECURITY.md`.
3. Desarrollar cada línea de trabajo en su propia rama y terminarla en un pull request. A `master` solo entran cambios con la integración continua (CI) en verde y revisión de código. No se permiten commits directos.
4. Usar el pull request como relevo: debe explicar qué se hizo, cómo se probó y qué queda pendiente.
5. Etiquetar cada despliegue a producción como `vAAAA.MM.DD` y registrar sus cambios en `CHANGELOG.md`. El detalle operativo del despliegue queda fuera del repositorio.
6. Conservar en `docs/` solo documentación duradera: arquitectura, módulos y decisiones. No agregar notas sueltas por fecha.
7. Aplicar la siguiente definición de terminado, compartida con la plantilla de pull requests. Marcar solo lo verificado y explicar cuando un punto no aplique.

### Definición de terminado

- [ ] Validación en servidor (FormRequest o equivalente).
- [ ] Autorización revisada (policy o middleware `permission:`).
- [ ] Pruebas nuevas o actualizadas.
- [ ] Suite completa en verde.
- [ ] Pint sobre los archivos tocados.
- [ ] Revisión de código hecha.
- [ ] Sin datos reales (clientes, empleados, NIT/NRC, correos, servidor) ni credenciales.
- [ ] Documentación y CHANGELOG al día.
- [ ] Sin migraciones ni cambios de producción no autorizados.

## Consecuencias

Las ramas separan las líneas de trabajo y reducen las interferencias. Los pull requests facilitan la revisión, el relevo y la trazabilidad entre una necesidad y su implementación. Las etiquetas de versión y el registro de cambios permiten identificar qué se incluyó en cada entrega. El canal privado de seguridad evita divulgar vulnerabilidades antes de corregirlas.

El flujo agrega pasos por cambio. Se mitiga con plantillas breves, pull requests de alcance definido y una definición de terminado compartida. La suite es larga y la CI tarda; durante el desarrollo se ejecutan pruebas puntuales y Pint sobre los archivos tocados, y antes de integrar se exige la suite completa en verde. Esto reduce repeticiones sin omitir la validación final.

Mantener etiquetas, milestones y `CHANGELOG.md` requiere disciplina. Se mitiga asignando módulo, tipo, prioridad y etapa al organizar el trabajo, y revisando la documentación y el registro de cambios en cada pull request.

## Relacionadas

- [Política de reporte de vulnerabilidades](../../SECURITY.md).
- [Plantilla de pull requests](../../.github/pull_request_template.md).
- [Integración continua](../../.github/workflows/ci.yml).
