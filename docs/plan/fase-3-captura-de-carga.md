# Fase 3 — Captura de carga: grupos, horarios, docentes y salones

**Entrega:** R1 · **Ventana:** 17 nov – 4 dic 2026 · **Modo:** 3 en paralelo · **Estado:** ⬜ · **Milestone:** `Fase 3 — Captura de carga`

## Objetivo

Reemplazar el Excel: crear grupos a partir de las propuestas, acomodar horarios en un editor con validación en vivo, repartir cupos por carrera, asignar docentes con ranking visible y salones sugeridos, y registrar acuerdos entre departamentos. Al terminar, hito H4: la carga de Campus II capturada sin choques duros.

## Dependencias

- Fase 2: cargas en `proposed`, propuestas aceptadas, docentes con disponibilidad y perfil, salones validados.

## Alcance

### Incluye

- Módulo `Scheduling`: grupos, sesiones, cupos, validador de restricciones, tablero de choques, editor de horarios, salones.
- Módulo `Staffing`: ranking, asignación, carga por docente, vista docente, solicitudes de cambio (flujo simple).
- Acuerdos interdepartamentales de préstamo de salón y grupo compartido en `AcademicLoad`.

### No incluye

- Generación automática de horarios (R2), impacto calculado de cambios (R2), cierre y publicación (fase 4).

## Prerrequisito: permisos por puesto

Decisión en [ADR-0018](../decisiones/0018-permisos-por-puesto-y-perfil-activo.md); reglas AZ-01 y AZ-S1 a AZ-S9 en `docs/dominio/reglas-de-negocio.md`. Las tareas 3.P1 a 3.P4 (del 3 al 7 de noviembre, persona A) entregan el motor y el perfil activo **antes** de empezar la fase, para que las Policies de la fase 3 se escriban directamente con permisos. Las tareas 3.P5 a 3.P9 migran los módulos actuales en PRs encadenados, en paralelo con la fase; la API vieja de `ScopedContext` sigue viva hasta 3.P9.

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 3.P1 ✅ [#216](https://github.com/Erne1357/nexus/pull/216) | Docs y decisión: ADR-0018, glosario, reglas AZ-01 y AZ-S1 a AZ-S9, nota de transición en arquitectura y tareas 3.P1 a 3.P9 en el plan | Core | `docs/decisiones/`, `docs/dominio/`, `docs/arquitectura/`. Deja escrito qué se construye y por qué antes de tocar código. | Enlaces revisados; revisión cruzada | A |
| 3.P2 ✅ [#217](https://github.com/Erne1357/nexus/pull/217) | Árbol de unidades: `org_units` (una raíz, sin ciclos), `departments` con llave compartida con su unidad y triggers de sincronía, siembra del organigrama tipo marcado «por confirmar» (AZ-S1, AZ-S2); actualiza `modelo-de-datos.md` | Core, Academic | `Core/Database/Migrations`, `Academic/Database/Migrations`. Los nombramientos necesitan unidades; ninguna tabla con `department_id` cambia. | Unit: ciclos y raíz única; feature: la BD crea la unidad de todo departamento nuevo, venga de donde venga | A |
| 3.P3 ✅ [#220](https://github.com/Erne1357/nexus/pull/220) | Motor: `Permission`, `Dimension`, `PermissionRegistry`, `OrgTree`, `Target`, `AuthorizationSnapshot`, caché por `authz_version` y huella; `role_scopes` pasa a `role_assignments` (AZ-S3, AZ-S7), el admin es un nombramiento y sale spatie/permission; actualiza `modelo-de-datos.md` (renombra `role_scopes` a `role_assignments` y agrega `users.last_role` y `users.authz_version`) | Core | `Core/Authorization/`. Una sola definición de la regla de alcance para PHP y SQL (`allows`, `allowsAny`, `constrain`). | Unit: regla por dimensión (nulos, varios valores, objetivo general, descendientes), vigencia por día, llaves de caché y matriz congelada en `tests/Fixtures/authorization/`; feature: invalidación al nombrar y terminar, exclusividad; arch: sin spatie | A |
| 3.P4 ✅ [#221](https://github.com/Erne1357/nexus/pull/221) | Perfil activo: `SwitchRole`, credencial «Actuando como», `ScopedContext` por perfil, pestañas viejas (AZ-S4 a AZ-S6), Inicio por perfil. El área «Mi espacio» llega con la primera pantalla personal de la fase 3 (el motor ya admite permisos personales) | Core | `Core/Actions`, `Core/Livewire/components`, `Core/Navigation`. Que cada puesto de una persona vea y haga cosas distintas desde el primer día. | Feature: la misma persona con otro perfil es rechazada, cambio de campus, pestaña vieja; browser: persona con dos puestos cambia de perfil | A |
| 3.P5 ✅ [#223](https://github.com/Erne1357/nexus/pull/223)–[#229](https://github.com/Erne1357/nexus/pull/229) | Migrar cada módulo a permisos y `constrain()`: Core, Academic, Facilities, Imports, Students, Faculty, CatalogValidation y AcademicLoad, un PR por módulo | Todos | Policies, Queries, menú y componentes sin nombrar puestos. Las tareas de esta fase escriben sus Policies ya con permisos. | Feature por Policy: positiva, negativa por otro puesto y por otro alcance; matriz congelada al día | A/B/C |
| 3.P6 ✅ [#230](https://github.com/Erne1357/nexus/pull/230)–[#234](https://github.com/Erne1357/nexus/pull/234) | Avisos por puesto y bitácora: `RecipientsQuery::holders()` con puesto, `data.role` en la notificación, `OpenNotification` cambia de perfil (AZ-S8), `acting_as` en toda entrada y `self_approval` (AZ-01). Además, `StartTeachersReviewOnFacultyCapture` y el `unavailableReason` y los destinatarios de los avisos (`CatalogReviewsPending`, `ProposalSubmitted`, `ProposalReturned`) todavía deciden por puesto y pasan a permisos aquí | Core, AcademicLoad, CatalogValidation | `Core/Queries`, `Core/Actions`, listener `StampActingAs` sobre `Activity::creating`. La campana muestra todos los avisos etiquetados por puesto. | Feature: aviso que cambia de perfil, aviso de un puesto perdido, marca de autoaprobación | A/B/C |
| 3.P7 🟨 | Pantallas «Personas y puestos» y «Organigrama» (solo admin), con maquetas antes de construir | Core | `Core/Livewire/pages`. Primera forma de nombrar coordinadores y de corregir el organigrama sembrado. | Feature: alcance (otro puesto no ve ni edita); browser: nombrar y terminar un puesto | A/B/C |
| 3.P8 | Fusión de `department_heads` en `role_assignments`: las jefaturas se nombran con las Actions de Core y la ficha del docente las muestra desde ahí | Core, Faculty | Quita la tercera fuente de verdad del puesto; la exclusividad de `department_heads_one_jefe` pasa a los nombramientos (AZ-S7). | Feature: migración de datos, ficha del docente, exclusividad | A/B/C |
| 3.P9 | Cierre: borrar la API vieja de `ScopedContext` (`has`, `covers`, `coversDepartment`, `coversWithScope`), `UsesScopedContext`, `RecipientsQuery::for` y las listas de roles; migrar a permisos los guardias de `StartTeachersReviewOnFacultyCapture` (`isAdmin`, `has(JefeDepartamento)`, `coversDepartment`), la prueba del fixture `TestStrictJefeNotice` (su `has()`) y todo uso de `UsesScopedContext`; prueba arch estricta; reescribir «Autorización y alcance» en `arquitectura.md` | Core | Termina la transición de ADR-0018. | Arch: nada fuera de `Core/Authorization` decide con `Role::` (la prueba estricta exceptúa también las piezas de nombramientos, `RoleAssignmentRules`, `AppointRole`, `EndRoleAssignment`, `PeopleQuery` y la ficha de «Personas y puestos», que nombran puestos por oficio); sin `UsesScopedContext`; todo permiso está concedido o es del admin; spec §7.1: ningún permiso sobre un recurso con `campus_id` omite la dimensión campus (primeros casos por juzgar: `core.term.open`, sin dimensiones, y `core.assignment.*`, solo del admin y sin dimensiones, sobre una tabla con `campus_id`); y un CHECK en la base o validación en la Action de las columnas de alcance por puesto | A/B/C |

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 3.1 | Modelos y migraciones de `groups`, `group_sessions`, `group_quotas`, `schedule_conflicts`; restricciones de exclusión en PostgreSQL para empalmes de salón y docente por periodo | Scheduling | Modelo de datos. La restricción de BD es la red de seguridad de HC-01 y HC-02. | Unit: relaciones; feature: la BD rechaza empalmes aunque el código falle | A |
| 3.2 | Validador de restricciones: una clase por regla dura HC-01 a HC-13 con una interfaz común, un orquestador que valida un grupo, un docente, un salón o toda una carga, y un job que recalcula `schedule_conflicts` del periodo | Scheduling | `Scheduling/Rules/Constraints/`. Cada regla aislada se prueba sola y se reutiliza en el motor de R2. | Unit por regla con casos límite; feature: job recalcula al guardar sesión, docente o salón | A |
| 3.3 | Crear grupos desde propuestas aceptadas: por cada `proposal_item` crear los grupos solicitados con código según nomenclatura del SII, carrera dueña, capacidad por defecto y cupo del dueño | Scheduling, AcademicLoad | Action `CreateGroupsFromProposal`. Evita capturar grupos a mano. | Feature: cantidad y códigos correctos; idempotente | A |
| 3.4 | Tablero de choques y avance por departamento y carrera: choques duros, grupos sin horario, sin docente, sin salón; enlaces a la pantalla que los corrige | Scheduling | `Scheduling/Livewire/pages/conflicts`. Es lo que el jefe mira para saber qué falta para cerrar (HC-15). | Feature: conteos por alcance | A |
| 3.5 | Editor de horarios: cuadrícula día x hora por carrera y semestre reticular, con los grupos de ese semestre como piezas; colocar, mover y quitar sesiones; mensajes del validador en vivo; vistas por docente y por salón | Scheduling | `Scheduling/Livewire/pages/schedule-editor` con Alpine para la interacción. Es la pantalla central del coordinador; sustituye la hoja de Excel. | Feature: colocar sesión guarda y valida; browser: acomodar un semestre completo sin choques | B |
| 3.6 | Cupos por carrera: en el grupo, repartir la capacidad entre carreras (HC-07); al agregar otra carrera de otro departamento se crea un acuerdo `shared_group` pendiente de aceptar | Scheduling, AcademicLoad | Componente en el detalle del grupo. Reemplaza al "paralelo". | Feature: suma no excede capacidad; acuerdo creado | B |
| 3.7 | Salones: sugerencia ordenada (por defecto, historial, tipo requerido, capacidad, mismo edificio que la sesión anterior del semestre), asignación por sesión desde el editor, préstamo de salón (`room_loan`) con aceptación del departamento dueño (HC-10) | Scheduling, Facilities, AcademicLoad | `Scheduling/Queries/RoomSuggestionQuery`. Resuelve D7 y D8. | Unit: orden de sugerencia; feature: préstamo necesario y aceptado | B |
| 3.8 | Ranking docente: job que calcula `staffing_rankings` por grupo con desglose (base, historial, perfil, evaluación, horas restantes, choque) según `reglas-de-negocio.md`; recálculo al cambiar cualquier entrada | Staffing | `Staffing/Jobs/ComputeRankings`, `Staffing/Rules/RankingScorer`. Pesos en `settings`. | Unit: orden determinista con empates; feature: recálculo por eventos | C |
| 3.9 | Asignación: pantalla del jefe por grupo con el ranking completo, asignar con un clic, motivo obligatorio si no es el primero (HC-16), historial de asignaciones, `teacher_term_loads` (HC-08, HC-09) | Staffing | `Staffing/Livewire/pages/assign`. Transparencia por diseño. | Feature: motivo obligatorio; horas y disponibilidad validadas; browser: asignar | C |
| 3.10 | Vista docente: toda la oferta del departamento desde `staffing` (HC-17) y "mi carga" con horario y salones | Staffing | `Staffing/Livewire/pages/teacher-offer`, `my-load`. Elimina la posibilidad de esconder materias. | Feature: docente ve todos los grupos del departamento, no edita | C |
| 3.11 | Solicitudes de cambio (flujo simple): el docente pide cambio de horario o salón con motivo; el jefe aprueba o rechaza; al aprobar la carga pasa a `adjusting` y el coordinador reacomoda en el editor; al validar vuelve a `staffing` | Staffing, AcademicLoad, Scheduling | `change_requests`. R2 añade el impacto calculado. | Feature: transiciones y permisos; browser: pedir y aprobar | C |
| 3.12 | Carga por docente: tablero por departamento con horas base, externas, asignadas y restantes; lista de docentes sin carga | Staffing | `Staffing/Livewire/pages/teacher-loads`. Da al jefe la foto que hoy arma en Excel. | Feature: sumas correctas entre campus | C |
| 3.13 | Integración: capturar en Nexus la carga real de Campus II (lo ya decidido en Excel más lo pendiente) con los coordinadores y jefes; corregir choques | Todos | Hito H4. Días 3 y 4 de diciembre. | Cero choques duros en el tablero | A, B, C |

## Pruebas de la fase

- **Unit:** cada regla HC-01 a HC-13, `RankingScorer`, sugerencia de salones.
- **Feature:** creación de grupos, jobs de recálculo, transiciones por solicitudes, restricciones de BD.
- **Browser:** acomodar un semestre en el editor, asignar docente con motivo, pedir y aprobar un cambio.
- **Rendimiento:** validar una carga de un campus completo (estimado 400 grupos) en menos de 5 segundos en job; el editor responde en menos de 300 ms por movimiento (medir con datos del seeder).

## Criterios de aceptación

1. Un coordinador acomoda el horario de un semestre completo sin producir empalmes para el alumno tipo, docentes ni salones; cualquier intento se marca en vivo.
2. Un jefe asigna docentes a todos sus grupos viendo el ranking; asignar fuera del ranking exige motivo y queda registrado.
3. Un docente ve toda la oferta de su departamento y su carga, y puede pedir un cambio que el jefe resuelve.
4. Los cupos por carrera y los préstamos de salón quedan registrados y el validador los respeta.
5. El tablero de choques de Campus II en cero duros el 4 de diciembre.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | Scheduling (núcleo) | 3.1 → 3.2 → 3.3 → 3.4; publica el validador antes del día 4 para que B y C lo usen |
| B | Scheduling (editor y salones) | 3.5 primero con validación simulada, luego real; 3.6 y 3.7 |
| C | Staffing | 3.8 → 3.9 → 3.10 → 3.11 → 3.12 |

## Riesgos y notas

- El editor de horarios es la pieza con más riesgo de UI. Primero una versión por clic (seleccionar grupo, clic en celda) y solo si sobra tiempo arrastrar y soltar.
- El ranking expone información sensible (evaluación docente): solo lo ven jefes, jefe de proyecto docente, Subdirección y admin; el docente ve su posición y su desglose, no el de los demás.
- Materias compartidas entre departamentos (tronco común): el grupo pertenece al departamento que imparte la materia, no al de la carrera dueña. Dejarlo claro en la UI.
