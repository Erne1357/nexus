# ADR-0020 — Staffing a la derecha de Scheduling

**Estado:** Aceptada · **Fecha:** 2026-11-07

## Contexto
El mapa de dependencias (`docs/dominio/modelo-de-datos.md`) tenía `Faculty --> Staffing --> Scheduling`: Scheduling podía leer Staffing y Staffing solo veía Faculty. Pero todo lo que la fase 3 le pide a Staffing es por grupo, y el grupo es de Scheduling:
- El ranking (tarea 3.8) se guarda por grupo (`staffing_rankings.group_id`) y marca «choque» y «sin horas» con HC-01 y HC-08, que son parte del validador de Scheduling (tarea 3.2). El plan de la fase dice que ese validador lo usan B y C.
- La asignación (3.9) escribe el historial por grupo (`group_teacher_assignments.group_id`) y el docente vigente del grupo.
- La vista docente (3.10) muestra la oferta del departamento desde que la carga entra a `staffing` (HC-17) y «mi carga» con horario y salones: lee grupos, sesiones y el estado de `academic_loads`.
- Las solicitudes de cambio (3.11) apuntan a un grupo y mueven la carga a `adjusting`.

Con el mapa anterior, Staffing no podía leer nada de eso. Scheduling, en cambio, no necesita nada de Staffing: el docente vigente vive en `groups.teacher_id`, y HC-01, HC-08 y HC-09 leen Faculty, no el ranking.

## Decisión
El mapa queda `Faculty --> Scheduling --> Staffing`, junto con `AcademicLoad --> Scheduling` y `Facilities --> Scheduling`, que no cambian:
- Scheduling depende de AcademicLoad, Facilities y Faculty. Faculty ya le llegaba por transitividad (CatalogValidation → Imports → Faculty); la flecha directa dice que HC-01, HC-08 y HC-09 leen docentes, plazas, horas externas y disponibilidad.
- Staffing depende de Scheduling y, por transitividad, de todo lo que está a su izquierda (AcademicLoad, Facilities, Faculty, Academic, Core).

El diagrama y `$leftOf` de `tests/Arch/ModulesTest.php` cambian en el mismo PR, como pide la prueba que los compara.

## Consecuencias
- `groups.teacher_id` es de Scheduling y solo lo escribe la Action de Scheduling `SetGroupTeacher` (bitácora y evento `GroupTeacherChanged`). `AssignTeacher` de Staffing la llama dentro de su transacción y guarda el historial (`group_teacher_assignments`, HC-16) y la carga del docente (`teacher_term_loads`).
- HC-08 y HC-09 se quedan en `Scheduling/Rules/Constraints`, como dice la tarea 3.2. Staffing las usa por el validador de Scheduling para marcar el ranking y validar al asignar. Las horas asignadas por docente y periodo salen de una sola Query de Scheduling, que también usa `teacher_term_loads`, para que las dos cuentas no se separen.
- Scheduling avisa a Staffing con eventos (grupos creados, grupo cancelado, horario cambiado, docente cambiado) y Staffing recalcula ranking y carga por docente. Scheduling nunca importa Staffing.
- `change_requests` pasa de AcademicLoad a Staffing: apunta a un grupo, y AcademicLoad está a la izquierda de Scheduling y no lo ve. El estado de la carga lo sigue cambiando AcademicLoad con sus Actions, que Staffing llama.
- Un veto de AcademicLoad que dependa de grupos o docentes (por ejemplo HC-15 al cerrar, fase 4) sigue llegando por un listener de `LoadTransitioning`, ahora desde Scheduling o Staffing.

## Alternativas descartadas
- **Dejar el mapa y pasar a Staffing solo identificadores** (materia, departamento, horas) por eventos de Scheduling: Staffing tendría que copiar grupos y sesiones para rankear y mostrar «mi carga», con dos fuentes de verdad que se desalinean.
- **Poner las pantallas de Staffing en Scheduling**: mezcla el editor de horarios con la asignación docente y deja a Staffing sin pantallas, contra el reparto de la fase 3.
- **Que Staffing tenga su propia tabla con el docente vigente y Scheduling la lea**: HC-01 y la restricción de exclusión de docente en la base necesitan el docente junto a las sesiones, en Scheduling.
