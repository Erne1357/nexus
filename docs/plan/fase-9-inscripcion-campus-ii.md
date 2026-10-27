# Fase 9 — Inscripción Campus II

**Entrega:** R3 · **Ventana:** may – jul 2027 · **Modo:** paralelo · **Estado:** 🟦 (vaga) · **Milestone:** `Fase 9 — Inscripción Campus II`

> Fase vaga. No se arranca sin una sesión de planeación detallada (brainstorming + writing-plans) que la convierta en ⬜ con tareas, pruebas y criterios de aceptación. Este archivo recoge lo que ya sabemos y las preguntas que esa sesión debe responder.

## Objetivo

Que los alumnos de Campus II se inscriban para Ago-Dic 2027 dentro de Nexus: con un orden de inscripción calculado y publicado, una cita por alumno, y una auto-inscripción que respete seriación, cupos por carrera y empalmes sobre la carga ya publicada y re-analizada. Al terminar, Nexus es la fuente de verdad de los grupos y sus inscritos en Campus II.

## Lo que ya sabemos

- El **orden de inscripción** se construye con semestre, promedio y créditos faltantes. La fórmula exacta y los desempates no están levantados.
- Cada alumno recibe una **cita de inscripción** (`EnrollmentAppointment`): una ventana de fecha y hora en la que puede entrar a inscribirse.
- La **auto-inscripción** aplica HC-19: solo materias cuya seriación está aprobada y que no se tienen aprobadas, con cupo disponible en la carrera del alumno (`group_quotas`) y sin empalme con sus otros grupos.
- Solo aplica a **Campus II**, modalidad presencial. Campus I sigue inscribiendo en el SII en R3.
- Los alumnos entran con Fortify usando **username = número de control**, igual que docentes con número de empleado. La provisión masiva de usuarios se hace desde el catálogo de alumnos, con el mismo mecanismo que `ProvisionTeacherUsers` de fase 1.
- Los cupos por carrera (`group_quotas`) ya existen desde fase 3 y son el control de cupo de esta fase; no hay que rediseñarlos.
- La carga sobre la que se inscribe es el **snapshot publicado** más reciente (fase 4), ajustado por el re-análisis (fase 8).

## Preguntas abiertas (se resuelven en la sesión de planeación)

| # | Pregunta | Por qué importa |
|---|---|---|
| P1 | ¿Cuál es la fórmula exacta del orden de inscripción (pesos de semestre, promedio y créditos faltantes) y cómo se desempata? | Define `enrollment_orders` y la pantalla de publicación del orden. Debe ser configurable en `settings`, pero hay que conocer la regla vigente. |
| P2 | ¿Cuántos alumnos entran por ventana y cuánto dura cada ventana? ¿Hay ventanas por carrera o globales por campus? | Dimensiona la carga pico y el diseño de `enrollment_appointments`. |
| P3 | ¿Cómo se tratan alumnos irregulares (reprobaron y cursan materias de varios semestres) y alumnos en especialidad (eligen módulo)? | Cambia la elegibilidad y la vista de retícula que ve el alumno. |
| P4 | ¿Pagos o adeudos (financieros, de biblioteca, documentos) condicionan la inscripción? Si sí, ¿de dónde sale ese dato? | Puede requerir una importación nueva o un semáforo manual de Servicios Escolares. |
| P5 | ¿Nexus es la fuente de la inscripción, o Nexus inscribe y exporta al SII para que el SII siga siendo el registro oficial? | Define si hay sincronización de regreso, su layout y su frecuencia. |
| P6 | ¿Hay lista de espera? ¿Se permiten bajas y cambios de grupo después de la cita y hasta cuándo? | Define `waitlists` y las reglas de ajuste de inscripción. |
| P7 | Si el cupo de la carrera del alumno está agotado pero otra carrera tiene lugares libres en el mismo grupo, ¿se puede ceder cupo? ¿Quién lo autoriza? | Afecta la regla de cupos por carrera y requiere una acción de reasignación de cupos durante la inscripción. |
| P8 | ¿Cómo se inscriben alumnos de intercambio, de movilidad o de otra modalidad que toman grupos presenciales? | Pueden no tener `student_progress` ni carrera en Campus II. |
| P9 | ¿Quién opera la inscripción (Servicios Escolares, División de Estudios Profesionales) y qué ve mientras corre (tablero en vivo)? | Define el rol `servicios_escolares` y sus pantallas. |
| P10 | ¿Qué recibe el alumno al terminar (comprobante, horario en PDF, correo)? | Define exportes y notificaciones de esta fase. |

## Módulo `Enrollment` y tablas previstas

| Tabla | Campos tentativos | Notas |
|---|---|---|
| `enrollment_orders` | `term_id`, `campus_id`, `student_id`, `position`, `score`, `breakdown` (json: semestre, promedio, créditos faltantes), `computed_at` | Una fila por alumno y periodo. Se recalcula por job con la fórmula de P1; el desglose se muestra al alumno para que el orden sea explicable. |
| `enrollment_appointments` | `term_id`, `campus_id`, `student_id`, `starts_at`, `ends_at`, `status` (`scheduled`, `open`, `used`, `expired`), `assigned_by` | Ventana de cita. Se generan en bloque a partir del orden y de los parámetros de P2. |
| `enrollments` | `term_id`, `student_id`, `group_id`, `career_id` (cupo que consumió), `status` (`enrolled`, `dropped`, `moved`), `enrolled_at`, `source` (`self`, `staff`, `import`) | Una fila por alumno y grupo. `career_id` deja claro de qué cupo salió el lugar. |
| `waitlists` | `term_id`, `student_id`, `group_id`, `career_id`, `position`, `status` | Solo si P6 confirma lista de espera. |
| `group_quotas` (ya existe) | se agrega `used_seats` o se deriva | Decisión de la sesión: contador materializado con bloqueo atómico, o conteo en vivo sobre `enrollments`. |

## Dependencias

- `group_quotas` y validador de empalmes de fase 3 (reutilizado para el horario del alumno).
- `student_progress` y `EligibleStudentsQuery` de fase 1 (elegibilidad por seriación).
- Snapshot publicado de fase 4 y re-análisis de fase 8 (la carga sobre la que se inscribe debe estar cerrada antes de abrir citas).
- Fortify y provisión de usuarios de fase 0 y 1, extendida a alumnos.
- Perfil de flujo del campus (`campuses.settings`) para fechas de etapa `enrollment` en `term_stages`.
- Respuesta a P4 y P5 (posibles importaciones o exportaciones nuevas en `Imports`).

## Bosquejo de tareas (sin detalle)

1. Provisión de usuarios de alumnos de Campus II y pantalla de primer acceso.
2. Cálculo del orden de inscripción (job + pantalla de consulta y publicación del orden).
3. Generación de citas por ventana y notificación al alumno.
4. Pantalla de auto-inscripción: retícula con grupos disponibles, cupo de su carrera, validación en vivo de HC-19, confirmación.
5. Acción de inscripción atómica (descuento de cupo sin sobrecupo bajo concurrencia).
6. Tablero en vivo para Servicios Escolares: avance por ventana, grupos llenos, incidencias; inscripción asistida por personal.
7. Lista de espera, bajas y cambios (según P6 y P7).
8. Comprobante y horario del alumno; exporte o sincronización al SII (según P5).
9. Pruebas de carga con el volumen de P2 antes de abrir la primera ventana.

## Riesgos

| Riesgo | Mitigación prevista |
|---|---|
| **Carga pico**: miles de alumnos entrando en ventanas cortas | Octane ya está; la inscripción se procesa con una Action atómica (bloqueo a nivel de fila sobre el cupo o contador atómico en PostgreSQL) y las notificaciones y comprobantes van a cola. Pruebas de carga obligatorias antes del piloto, con el volumen real de Campus II. |
| **Sobrecupo por concurrencia** | Nunca "leer y luego escribir": el descuento de cupo se hace en una sola instrucción con condición, y se prueba con pruebas de concurrencia en feature. |
| **Soporte a alumnos** | Mesa de ayuda con Servicios Escolares, inscripción asistida por personal como respaldo, guía de una página. |
| **Coexistencia con el SII** | Hasta resolver P5, asumir que Nexus exporta al SII el resultado de la inscripción con el mismo layout que la carga (fase 4). |
| **Datos de alumnos desactualizados** | Re-importar alumnos y kardex justo antes de calcular el orden; la etapa `enrollment` no abre sin importación reciente. |

## Criterios para considerarla detallada

Esta fase pasa de 🟦 a ⬜ cuando exista:

1. Respuesta escrita a P1 a P10 en una sección "Decisiones" de este archivo, con fecha y fuente (quién lo confirmó).
2. Reglas de inscripción añadidas a `docs/dominio/reglas-de-negocio.md` (fórmula del orden, ventanas, lista de espera, cesión de cupo) con identificadores propios.
3. Modelo de `Enrollment` cerrado en `docs/dominio/modelo-de-datos.md`, incluida la decisión sobre `used_seats`.
4. Tabla de tareas con el formato de las fases 1 a 4 (id, módulo, dónde y por qué, pruebas, persona), con las pruebas de carga como tarea explícita.
5. Fecha de piloto acordada con Servicios Escolares y Subdirección, y reflejada en `PLAN.md`.
