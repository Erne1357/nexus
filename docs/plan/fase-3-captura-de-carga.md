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
