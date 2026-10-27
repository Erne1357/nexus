# Fase 4 — Cierre, publicación y piloto Campus II

**Entrega:** R1 · **Ventana:** 30 nov – 18 dic 2026 · **Modo:** 3 en paralelo · **Estado:** ⬜ · **Milestone:** `Fase 4 — Cierre, publicación y piloto`

## Objetivo

Cerrar la carga de cada departamento de Campus II con todas sus condiciones verificadas, obtener el visto bueno si aplica, publicar una versión consultable y exportable, y operar el piloto con usuarios reales hasta el inicio del semestre. Al terminar, hito H5.

## Dependencias

- Fase 3: carga capturada sin choques duros.
- Servidor de despliegue listo (tarea 0.14).
- Formato de importación del SII para cargas (pedir a sistemas del ITCJ en noviembre).

## Alcance

### Incluye

- Transiciones `closed`, `approved`, `published` con sus guardas y pantallas.
- Snapshots versionados y consulta publicada por carrera y semestre, por docente y por salón.
- Exportes PDF y Excel; exportación al SII.
- Despliegue, respaldos, endurecimiento y soporte del piloto.

### No incluye

- Re-análisis con calificaciones (R2). Inscripción (R3).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 4.1 | Cierre por departamento: Action `CloseLoad` con guardas HC-15 (cero choques duros, docente y salón en todos los grupos confirmados o justificación), resumen de bloqueos antes de cerrar, reapertura por Subdirección o admin con motivo | AcademicLoad | `AcademicLoad/Actions/Transitions/CloseLoad`. La pantalla solo muestra lo que falta; la regla vive en la Action. | Unit: cada guarda; feature: cierre y reapertura registrados | C |
| 4.2 | Visto bueno de Subdirección: tablero del campus con cargas cerradas, aprobar o regresar a `adjusting` con comentarios; se activa solo si el campus tiene `requires_subdireccion_approval` | AcademicLoad | Perfil de flujo de `campuses.settings`. Queda listo aunque Campus II no lo exija. | Feature: con y sin el parámetro | C |
| 4.3 | Publicación: Action `PublishLoad` que genera `load_snapshots` (grupos, sesiones, docentes, salones, cupos) con versión, cambia a `published` y registra en `teacher_subject_history` las materias impartidas | AcademicLoad, Faculty | El snapshot es lo que se consulta y exporta; la tabla viva sigue editable solo vía re-análisis (R2). | Feature: snapshot completo y versionado; historial docente alimentado | A |
| 4.4 | Consulta publicada: páginas de solo lectura por carrera y semestre, por docente y por salón, leyendo del snapshot; acceso público por campus y periodo (sin login) para alumnos, con búsqueda por carrera | AcademicLoad | `AcademicLoad/Livewire/pages/published/*`. En R1 los alumnos no tienen cuenta; la consulta pública reemplaza la publicación en el SII. | Feature: solo cargas publicadas son visibles; browser: consultar horario de un semestre | B |
| 4.5 | Exportes PDF (por carrera y semestre, por docente, por salón, por departamento) y Excel (lista completa de grupos con sesiones) desde el snapshot, generados en cola y descargables | AcademicLoad | `AcademicLoad/Exports/`. PDF con una librería basada en HTML (DomPDF) para no depender de Chromium en producción. | Feature: archivos generados con el contenido esperado | B |
| 4.6 | Exportación al SII: archivo con el layout que el SII acepte para cargar grupos y horarios (o, si no existe, el Excel que Servicios Escolares captura) | AcademicLoad | Depende de lo que sistemas del ITCJ confirme. Es el respaldo para que la inscripción de Ene-Jun 2027 siga en el SII con datos de Nexus. | Feature: layout validado con un archivo de ejemplo del SII | A |
| 4.7 | Despliegue: imagen `prod` en el servidor, HTTPS, variables, migraciones, `pg_dump` diario con retención, restauración probada; `main` recibe el primer PR desde `develop` | — | README de operación en `docs/operacion.md`. Sin restauración probada no hay piloto. | Restauración de un respaldo en el servidor de pruebas | A |
| 4.8 | Endurecimiento: límites de intentos de login, cabeceras de seguridad, políticas revisadas en todas las pantallas (`/security-review`), revisión de que ningún dato de otro campus se filtre | Core | Checklist en `CONTRIBUTING.md`. | Feature: pruebas negativas de alcance por rol en cada módulo | C |
| 4.9 | Piloto: sesión de arranque con jefes y coordinadores de Campus II, guía de una página por rol, canal de soporte, triage diario de incidencias en issues con etiqueta `piloto`, corrección en PRs pequeños | — | Las incidencias del piloto se atienden antes que cualquier tarea de R2. | Incidencias críticas en cero antes de publicar | A, B, C |
| 4.10 | Publicar la carga de Campus II `2027-1` desde Nexus (hito H5) y entregar el exporte al SII | — | | Carga publicada, consultable y exportada | A, B, C |
| 4.11 | Retrospectiva de R1 y planeación detallada de R2 (fases 5 a 8) con lo aprendido; actualizar `PLAN.md` | — | Convierte las fases 5 a 8 de "detalladas en papel" a "detalladas con datos reales". | `PLAN.md` actualizado | A, B, C |

## Pruebas de la fase

- **Unit:** guardas de cierre, construcción del snapshot.
- **Feature:** transiciones con y sin visto bueno, consulta publicada, exportes, pruebas negativas de seguridad.
- **Browser:** cerrar carga como jefe, publicar, consultar como público, descargar PDF.
- **Operación:** restauración de respaldo, despliegue desde `main`.

## Criterios de aceptación

1. Cada departamento de Campus II cierra su carga solo cuando cumple HC-15; el tablero muestra qué falta cuando no.
2. La carga publicada se consulta sin login por carrera y semestre, por docente y por salón, y coincide con el snapshot.
3. Los PDFs y el Excel se generan desde el snapshot y el exporte al SII se acepta.
4. La aplicación corre en el servidor con HTTPS, respaldos diarios y una restauración probada.
5. `main` contiene la versión publicada, etiquetada `v1.0.0`.

## Riesgos y notas

- Si el 4 de diciembre la captura no está completa, publicar lo que esté y terminar el resto directamente en Nexus durante la semana siguiente; la consulta pública admite republicar (nueva versión).
- La consulta pública expone nombres de docentes y salones, como hoy lo hace el SII; no expone datos de alumnos.
- La fase cierra formalmente el 18 de diciembre, pero el soporte del piloto continúa hasta el inicio del semestre en enero con una persona de guardia.
