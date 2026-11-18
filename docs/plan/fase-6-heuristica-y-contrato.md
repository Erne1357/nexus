# Fase 6 — Heurística de horarios y contrato del motor

**Entrega:** R2 · **Ventana:** feb – mar 2027 · **Modo:** paralelo · **Estado:** 🟨 (abierta el 17 de noviembre de 2026) · **Milestone:** `Fase 6 — Heurística y contrato del motor`

## Objetivo

Que Nexus proponga horarios completos en lugar de solo validarlos. Esta fase fija el **contrato JSON del motor** (lo que cualquier motor recibe y devuelve), construye la primera implementación en PHP (construcción voraz más búsqueda local) y la pantalla para lanzar corridas, comparar con la carga actual y aceptar el resultado. El contrato es lo que permite que en la fase 7 el solver CP-SAT en Python se enchufe sin tocar pantallas ni flujo.

## Dependencias

- Fase 3: `groups`, `group_sessions`, `group_quotas`, reglas HC-01 a HC-13 como clases aisladas en `Scheduling/Rules/Constraints/`, `RoomSuggestionQuery`, disponibilidad y horas restantes de docentes.
- Fase 5: propuestas precargadas (los grupos a programar salen de ellas).
- `docs/arquitectura/solver.md` como especificación del contrato.

## Punto de partida (17 de noviembre de 2026)

Se escribió al cerrar el núcleo de la fase 5 (Riel A) para que la fase 6 arranque con lo que hoy existe y con las decisiones del usuario. El plan de abajo se escribió cuando la carga era por campus. Desde la ronda 2 «carga por modalidad» (#418, #464; ADR-0025) la carga es del **departamento en una modalidad**, con grupos en varios campus. Donde choquen, mandan estos ajustes.

### Qué ya existe y se reutiliza

- **Reglas duras como clases aisladas:** `Scheduling/Rules/Constraints/`, con `TeacherOverlap`, `RoomOverlap`, `TypicalStudentSchedule`, `ModalityWindow`, `RoomCapacity`, `LabRoom`, `QuotasWithinCapacity`, `TeacherHours`, `TeacherAvailability`, `RoomLoan`, `RoomBlocked`, `WeeklyHours`, `OfferableGroup` y `CampusTravel`.
  - Las alimenta `ScheduleStateQuery`, la foto de un periodo que usan el validador y el tablero de choques.
  - El evaluador en memoria (6.5) les da otra fuente de datos; no las reescribe.
- **Salones:** `RoomSuggestionQuery`, que ordena por SC-03; los préstamos de salón (`interdepartmental_agreements`) y los bloqueos.
- **Lo que el motor recibe:**
  - los grupos de la carga, que nacen de las propuestas aceptadas (`CreateGroupsFromProposal`), cada uno con su campus (M8) y sus cupos por carrera;
  - las horas asignadas de cada docente (`TeacherAssignedHoursQuery`, en Scheduling).
  - La fase 5 deja `demand_forecasts`, que en R2 precarga las propuestas.
- **Traslado entre campus:** ya es una regla, SC-09 (`staffing.min_campus_travel_minutes`, 60), que el validador avisa como blanda.
- **Mapa de módulos:** `Scheduling --> Solver`. Solver ve AcademicLoad, Facilities, Faculty y Core, pero **no Staffing**, que está a la derecha de Scheduling: el ranking y las asignaciones de Staffing no entran al motor. El docente del grupo sí (`groups.teacher_id`).
- **Parámetros:** el catálogo de Core (CF-S7). Las llaves `solver.*` se declaran en el provider de Solver.
- **Datos para medir:** la demo por puntos (`demo:seed --hasta=horarios`) deja grupos sin sesiones listos para programar, y el punto `asignacion`, un horario limpio para comparar.

### Respuestas del usuario (17 de noviembre de 2026)

1. **Una corrida abarca la carga completa: el departamento en una modalidad, en todos sus campus a la vez.** No se corre un campus a la vez.
2. **El jefe de departamento lanza y acepta.** El coordinador puede lanzar corridas de su carrera para explorar y comparar, pero no las acepta. Subdirección ve todo.
3. **El tronco común primero no se exige.** Cada departamento corre cuando quiera, con lo ya aceptado como fijo. La pantalla avisa si los grupos con los que comparte alumno tipo (por ejemplo, los de Ciencias Básicas) todavía no tienen horario aceptado.
4. (De la fase 5, DM-09, 16 de noviembre.) Las materias de un **paquete de primer semestre** no se empalman: el motor lo trata como un alumno tipo más.

### Ajustes a las tareas

- **6.1, contrato v1:**
  - `campus` pasa a `campuses[]`, uno por campus de la corrida, con su rejilla (`block_minutes`, `day_start`, `day_end`, `days`) y `uses_rooms`. Cada grupo y cada salón llevan su `campus_code`.
  - Entra `travel_minutes` (`staffing.min_campus_travel_minutes`), y SC-09 se suma al puntaje blando: un docente con sesiones seguidas en campus distintos sin ese tiempo se penaliza.
  - El «alumno tipo» se generaliza a **cohortes**: cada grupo lleva `cohorts[]`, claves de conjuntos de grupos que un mismo alumno toma juntos y cuyas sesiones no se empalman. Hoy hay dos fuentes:
    - HC-03 (plan, semestre y campus, cuando la materia tiene un solo grupo para la carrera);
    - los paquetes de primer semestre (DM-09). Los paquetes son iguales para toda la carrera: una composición por propuesta (`proposal_packages` con sus materias), y cada campus captura cuántos abre (elección del usuario, 16 de noviembre; tarea 5.6 del Riel B). Cada instancia *n* de un paquete abre un grupo de cada una de sus materias, con `groups.package_id` y `groups.package_number`, y su cohorte es `package:<package_id>:<campus_code>:<package_number>`.
    El evaluador y CP-SAT ya no necesitan saber de dónde sale cada cohorte.
  - **Una sola definición de cohorte:** una pieza de Scheduling (`Rules/Cohorts`) calcula las cohortes de un conjunto de grupos: las de HC-03 y las de los paquetes. La usan `TypicalStudentSchedule` (el validador y el tablero de choques), `BuildSolverInput` (6.4) y el evaluador en memoria (6.5). La escribe el Riel B en la 6.5. Así un paquete también se valida en el editor manual.
  - **HC-03 incluye cada paquete:** dos materias de la misma instancia de un paquete que se empalman son un choque duro, también en el editor manual (supuesto por confirmar; revisión del Riel B en #532).
  - `scope.kind` queda `department` (la carga, con su modalidad) o `career` (exploración del coordinador). El campus deja de ser alcance.
  - `docs/arquitectura/solver.md` se actualiza en el mismo PR que los DTO.
- **6.2, `solver_runs`:** periodo, **modalidad**, departamento o carrera, motor, estado, entrada y salida, métricas, autor y aplicación. Sin campus.
  - Permisos del módulo Solver (ADR-0018; #538):
    - `solver.department_run.view`, `solver.department_run.launch` y `solver.department_run.apply`, con modalidad y unidad;
    - `solver.career_run.view` y `solver.career_run.launch`, con modalidad y carrera.
    - **La corrida de carrera no tiene `apply`:** solo sirve para explorar.
  - Lanzar: el jefe sobre su departamento y el coordinador sobre su carrera. Aceptar: solo el jefe, sobre su departamento, y solo una corrida terminada. Ver: Subdirección todo, el jefe lo de su departamento y el coordinador lo de su carrera.
  - **Una corrida de departamento a la vez** por periodo, modalidad y departamento, mientras esté en cola o corriendo (índice único parcial).
  - **Al aceptar, la entrada tiene que seguir vigente.** Se compara un hash de la entrada con el estado de hoy; si cambió, la pantalla ofrece «Volver a correr» (6.8, Riel C).
- **6.4, armado de la entrada:**
  - Los grupos movibles son los de la carga (departamento × modalidad) en todos sus campus. Una corrida de carrera toma solo los grupos de esa carrera dentro de las cargas que toca.
  - Lo demás entra fijo: los grupos de otras cargas que comparten docentes, salones o cohortes, y las sesiones ya aceptadas.
  - El aviso del tronco común se calcula de las cohortes: grupos de otra carga con los que comparte cohorte y sin sesiones. No se usa ninguna lista fija de departamentos.
- **6.10, pantalla de corridas:** la comparación con la carga actual usa el filtro de campus segmentado de las pantallas de la carga (todos o un campus), porque la corrida abarca todos los campus.
- **6.6, construcción:** sesiones de 1 hora, una por día en días distintos y a la misma hora: 4 horas de lunes a jueves, 5 de lunes a viernes, 6 con sábado (respuesta del usuario, 17 de noviembre de 2026). Si no cabe, otros días y, al final, un día de 2 horas (supuesto por confirmar). Detalle en reglas-de-negocio.md, «Patrón de sesiones», y en solver.md, «Heurística PHP: construcción».
- **6.9, `PlanRun`:** deja de imponer «tronco común, luego departamentos». Da un orden sugerido (primero las cargas cuyos grupos más cohortes comparten) y el aviso de la respuesta 3.
- **6.11, fixtures:** `campus-ii` se cambia por `department-multicampus`: un departamento con grupos en C1 y C2, docentes en los dos campus y un préstamo de salón.
- **Fases siguientes** (para su propio punto de partida):
  - 7.11 elige el motor predeterminado por modalidad, no por campus.
  - 7.8 (descomposición en el servicio y en PHP) sigue el `PlanRun` de la 6.9: un orden sugerido con aviso, no «tronco común y luego departamentos» obligatorio.
  - 8.1 no puede escuchar `BatchApplied` de Imports, que está a la derecha de AcademicLoad. Usa `StudentProgressRefreshed`, como la 5.4.
  - 8.2 y 8.8 leen la demanda con la llave de `demand_forecasts` (periodo, modalidad, campus, carrera y materia).

### Reparto propuesto

El del plan (A contrato, B evaluador y heurística, C corrida y pantalla). A arranca con 6.1 a 6.3 en cuanto entre este punto de partida, porque son la dependencia de todos. B y C se suman al cerrar sus tareas de la fase 5. El contrato se publica la primera semana con un motor de prueba, como dice el plan.

## Alcance

### Incluye

- Módulo `Solver` en PHP: contrato, `solver_runs`, interfaz `SolverEngine`, `PhpHeuristicEngine`, corrida en cola, pantalla de corridas.
- Reutilización de las reglas HC de `Scheduling` como evaluador y de las SC como función de puntaje.
- Descomposición por departamento o carrera.
- Fixtures compartidos y benchmark básico.

### No incluye

- El servicio Python (fase 7).
- Asignación automática de docentes: el motor puede sugerir docente, pero la asignación sigue siendo del jefe con el ranking de la fase 3.
- Re-análisis (fase 8).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 6.1 ✅ [#537](https://github.com/Erne1357/nexus/pull/537) | Contrato JSON del motor, versión 1: objetos de entrada (periodo y campus con ventana y bloques; grupos a programar con horas, tipo, carrera dueña, semestre reticular y sesiones fijas; docentes con disponibilidad, horas restantes y asignación actual; salones con tipo, capacidad, bloqueos y dueño; acuerdos vigentes; reglas HC activas; pesos SC; límite de tiempo) y de salida (sesiones por grupo con salón, sugerencia opcional de docente, violaciones duras residuales, puntaje blando desglosado, métricas); DTOs en PHP con validación y serialización | Solver | `Solver/Contract/`. Se escribe antes que cualquier motor porque la fase 7 lo implementa en otro lenguaje; el detalle campo por campo está en `docs/arquitectura/solver.md`. | Unit: serializar y deserializar ida y vuelta; entradas inválidas rechazadas con mensaje | A |
| 6.2 ✅ [#538](https://github.com/Erne1357/nexus/pull/538) | Modelo y migración de `solver_runs` (periodo, campus, departamento o carrera, motor, estado, entrada y salida en JSON o archivo, métricas, autor, fecha de aplicación); política: solo coordinador y jefe con alcance pueden lanzar y aceptar | Solver | Modelo de datos. Entradas grandes se guardan en disco (`storage/solver`) y la fila conserva la ruta; así cada corrida es reproducible. | Unit: relaciones; feature: política por alcance | A |
| 6.3 ✅ [#539](https://github.com/Erne1357/nexus/pull/539) | Interfaz `SolverEngine` (recibe el contrato de entrada, devuelve el de salida, reporta progreso) y registro de motores disponibles en `settings` (`solver.engines`) | Solver | `Solver/Engines/`. Una sola interfaz para la heurística PHP y para el cliente CP-SAT de la fase 7. | Unit: un motor de prueba que devuelve una salida fija pasa por todo el flujo | A |
| 6.4 ✅ [#542](https://github.com/Erne1357/nexus/pull/542) | Armado de la entrada: Query que construye el contrato desde la base de datos para un periodo, campus y alcance (departamento o carrera), incluyendo como sesiones fijas las de grupos ya confirmados o de otros departamentos que comparten docentes y salones | Solver, Scheduling | `Solver/Queries/BuildSolverInput`. Lo que ya está decidido se fija; el motor solo mueve lo pendiente. | Feature: la entrada refleja grupos, docentes, salones, bloqueos y acuerdos del seeder | A |
| 6.5 (6.5a: [#571](https://github.com/Erne1357/nexus/issues/571), cohortes de paquete en `Cohorts` y HC-03; 6.5b: [#572](https://github.com/Erne1357/nexus/issues/572), `HardRuleEvaluator`, `SoftScorer` y el adaptador `ContractState`/`ContractEvaluator`, con prueba de equivalencia contra la base) | Evaluador compartido: adaptador que corre las clases de reglas HC-01 a HC-13 de `Scheduling` sobre una solución candidata en memoria (sin tocar la base de datos) y una función de puntaje para SC-01 a SC-08 con los pesos del contrato | Scheduling, Solver | `Scheduling/Rules/Evaluator`. Las reglas de la fase 3 ya están aisladas; aquí solo se les da una fuente de datos en memoria. Evita tener dos definiciones de la misma regla. | Unit: cada regla da el mismo veredicto en memoria que contra la base de datos; puntaje blando conocido para soluciones de prueba | B |
| 6.6 ([#573](https://github.com/Erne1357/nexus/issues/573), PR en revisión) | `PhpHeuristicEngine`, construcción: ordena los grupos por semestre reticular y por lo restringido de la materia (laboratorio, docente con poca disponibilidad, muchas horas), coloca cada grupo en el primer bloque y salón sin violación dura, usando `RoomSuggestionQuery` para el orden de salones; si no hay lugar, deja el grupo sin programar y lo reporta como violación residual | Solver | `Solver/Engines/PhpHeuristic/Builder`. Es la parte que garantiza una solución factible rápida; el orden de colocación es lo que más afecta el resultado y se parametriza. | Unit: instancias pequeñas con solución conocida; instancia sin solución reporta el grupo pendiente | B |
| 6.7 | `PhpHeuristicEngine`, búsqueda local: movimientos de "mover sesión", "intercambiar dos sesiones" y "cambiar salón" aceptados solo si no crean violaciones duras y mejoran el puntaje blando (SC-01 a SC-08); límite de tiempo y de iteraciones sin mejora; reporte de progreso | Solver | `Solver/Engines/PhpHeuristic/LocalSearch`. Mejora la compacidad del alumno tipo y del docente sin arriesgar la factibilidad. | Unit: a partir de una solución factible el puntaje nunca empeora; respeta el límite de tiempo | B |
| 6.8 | Corrida en cola: job `RunSolver` que arma la entrada, ejecuta el motor elegido, guarda la salida y las métricas en `solver_runs`, actualiza progreso para `wire:poll` y notifica al autor; fallos quedan en `failed` con el error | Solver | `Solver/Jobs/`. Una corrida puede tardar minutos; nunca en la petición. | Feature: estados `queued` → `running` → `done` o `failed`; notificación al terminar | C |
| 6.9 | Descomposición: lanzar por departamento o por carrera; las sesiones ya programadas del resto del campus entran como fijas; opción "tronco común primero" que programa las materias compartidas entre carreras antes que las de una sola carrera | Solver | `Solver/Actions/PlanRun`. Un campus completo en una sola corrida es demasiado para la heurística; por partes converge y se puede revisar por pedazos. | Feature: dos corridas consecutivas por departamento no se pisan | C |
| 6.10 | Pantalla de corridas: lanzar una corrida (alcance, motor, pesos, límite de tiempo), ver progreso, comparar la solución con la carga actual en la cuadrícula del editor (sesiones nuevas, movidas y sin cambio), lista de violaciones residuales y desglose del puntaje, aceptar o descartar; al aceptar, Action `ApplySolverRun` escribe en `group_sessions` dentro de una transacción con bitácora y recalcula `schedule_conflicts` | Solver, Scheduling | `Solver/Livewire/pages/runs`. Reutiliza la cuadrícula de la fase 3 en modo comparación. Nada se escribe hasta que una persona acepta. | Feature: aceptar escribe solo lo diferente y queda en bitácora; descartar no toca nada; browser: lanzar, revisar y aceptar | C |
| 6.11 ✅ [#555](https://github.com/Erne1357/nexus/pull/555) | Fixtures compartidos: conjunto de instancias del contrato en JSON (pequeña con solución conocida, mediana con laboratorios y tronco común, grande del tamaño estimado de Campus II con 400 grupos) y pruebas parametrizadas que cualquier `SolverEngine` debe pasar: cero violaciones duras en las que tienen solución, reporte correcto en las que no | Solver | `tests/Fixtures/solver/`. Las mismas instancias se usan en la fase 7 desde Python. | Suite parametrizada corriendo con `PhpHeuristicEngine` | A |
| 6.12 ✅ [#556](https://github.com/Erne1357/nexus/pull/556) | Benchmark básico: comando que corre un motor sobre los fixtures y reporta tiempo, violaciones y puntaje; resultados guardados en `docs/arquitectura/solver.md` como línea base para comparar con CP-SAT | Solver | `Solver/Console/Benchmark`. Sin línea base no se puede decir que la fase 7 mejoró algo. | Comando corre en CI en modo corto (instancia pequeña y mediana) | A |

## Pruebas de la fase

- **Unit:** contrato, evaluador en memoria contra reglas de base de datos, construcción y búsqueda local con instancias pequeñas.
- **Feature:** armado de entrada, corridas en cola, aplicar y descartar, descomposición.
- **Browser:** lanzar una corrida sobre el seeder, comparar y aceptar.
- **Rendimiento:** instancia de 400 grupos resuelta sin violaciones duras en menos de 5 minutos en el contenedor `queue`; instancia pequeña en menos de 5 segundos.
- **Arquitectura:** `Solver` depende de `Scheduling`, `Faculty`, `Facilities`, `AcademicLoad` y `Core`; `Scheduling` no depende de `Solver`.

## Criterios de aceptación

1. El contrato versión 1 está documentado en `docs/arquitectura/solver.md`, implementado como DTOs y cubierto con fixtures.
2. Un coordinador lanza la heurística sobre su carrera y obtiene una propuesta sin violaciones duras para la instancia real de Campus II, la compara con el editor y la acepta o descarta.
3. La solución aceptada pasa el validador de la fase 3 con cero choques duros (misma definición, mismas clases).
4. La línea base de tiempo y puntaje de la heurística está registrada para cada fixture.
5. CI verde con cobertura mínima de 80 % en `Solver`.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | Solver (contrato, entrada, fixtures, benchmark) | 6.1 → 6.2 → 6.3 → 6.4 → 6.11 → 6.12; publica el contrato y un motor de prueba la primera semana |
| B | Scheduling (evaluador) y Solver (heurística) | 6.5 → 6.6 → 6.7 usando el motor de prueba y los fixtures de A |
| C | Solver (corridas, descomposición, pantalla) | 6.8 → 6.9 → 6.10 con el motor de prueba hasta que B entregue la heurística |

## Riesgos y notas

- La heurística no garantiza encontrar solución aunque exista; cuando deje grupos sin programar, la pantalla debe decir cuáles y por qué (docente sin disponibilidad, sin laboratorio libre), para que el coordinador decida si relajar algo o esperar al CP-SAT.
- La búsqueda local con Octane: el motor corre en el contenedor `queue`, nunca en un worker de Octane, y no debe guardar estado estático.
- El contrato se congela al cerrar esta fase; cualquier cambio posterior sube la versión y mantiene compatibilidad con las corridas guardadas.
- Sugerir docente en la salida es opcional en versión 1; si se incluye, se presenta como sugerencia y el jefe lo decide con el ranking.
