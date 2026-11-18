# Motor de horarios (solver)

Arquitectura del componente que sugiere horarios y salones para los grupos de un periodo. Desde el 17 de noviembre de 2026 una corrida abarca la carga de un departamento en una modalidad, en todos sus campus a la vez (decisión del usuario; «Punto de partida» de la fase 6). Dos implementaciones bajo una sola interfaz y un solo contrato: una heurística en PHP (fase 6) y un servicio en Python con OR-Tools CP-SAT (fase 7). Las reglas que ambas respetan están en `docs/dominio/reglas-de-negocio.md`; las tablas, en `docs/dominio/modelo-de-datos.md`.

## Propósito

- Convertir la validación de la fase 3 (que solo dice "esto choca") en una propuesta completa de sesiones y salones sin choques duros y con buen puntaje blando.
- Mantener a la persona en control: el motor **propone**, el coordinador o jefe **acepta**; nada se escribe en `group_sessions` sin aceptación explícita y bitácora.
- Permitir cambiar de motor sin tocar pantallas, flujo ni base de datos.

## Ciclo de vida de una corrida

```mermaid
stateDiagram-v2
    [*] --> queued : el usuario lanza una corrida
    queued --> running : el job la toma del worker queue
    queued --> cancelled : se cancela antes de correr
    running --> done : salida válida según el contrato
    running --> failed : error, tiempo agotado sin solución, servicio caído
    running --> cancelled : se pidió cancelar y el motor avisó avance
    done --> applied : el usuario acepta; se escribe en group_sessions
    done --> discarded : el usuario descarta
    failed --> [*]
    cancelled --> [*]
```

Cada corrida es una fila en `solver_runs` con su entrada y salida completas, métricas y autor. La entrada y la salida van en la fila (jsonb) si su JSON cabe en 1 MiB y si no, en un archivo `solver/runs/<id>/input.json` u `output.json` del disco `local` (`storage/app`, compartido por la aplicación y la cola), con la ruta en la fila (`Solver\Support\RunPayloads`). Una corrida en `done` puede consultarse y compararse cuantas veces haga falta; `applied` es terminal y deja en `activity_log` qué sesiones cambiaron.

**Lanzar y correr (tarea 6.8).** `Actions\LaunchSolverRun` valida el motor y el límite de tiempo (10 a 600 s, SV-04), revisa que el departamento no tenga otra corrida en curso (SV-01), arma la entrada con `BuildSolverInput`, guarda su huella (`input_hash`, SV-02) y despacha `Jobs\RunSolver` después del commit. El job toma la corrida solo si sigue en cola, la pasa a `running` y llama a `solve()`; su aviso de avance guarda `progress` y `best_score` a lo más una vez por segundo y, si se pidió cancelar (`cancel_requested_at`, `Actions\CancelSolverRun`, SV-03), corta al motor y deja la corrida `cancelled`. Al terminar guarda la salida validada, las métricas con un resumen y la deja `done` aunque la salida sea `partial` o `infeasible` (la pantalla decide qué mostrar); una salida `error` o una excepción la dejan `failed` con el mensaje, sin traza. Lista o fallida, avisa a quien la lanzó (`Notifications\SolverRunFinished`, que lleva a `solver.runs?corrida=<id>`). Su `timeout` es el límite de la corrida más un minuto, con un solo intento. El guardado final bloquea la fila y solo escribe si la corrida sigue `running`: un job tardío no pisa una corrida que ya se dio por perdida o se canceló. Lanzar y cancelar autorizan a quien las llama con `SolverRunPolicy` (`launchForDepartment`, `launchForCareer`, `cancel`).

**Limpieza de archivos.** `php artisan solver:prune-files [--days=30]` (`Actions\PruneSolverRunFiles`) borra los archivos de las corridas fallidas, canceladas y descartadas que terminaron hace más de esos días y quita su ruta de la fila, y las carpetas de `solver/runs` sin corrida de más de un día. Nunca toca las aplicadas, las terminadas ni las que están en curso. Hoy se corre a mano; no está en el schedule.

## Interfaz `SolverEngine`

Una interfaz en `app/Modules/Solver/Engines/` con tres responsabilidades: resolver (recibe el contrato de entrada, devuelve el contrato de salida), reportar progreso (porcentaje y mejor puntaje intermedio para `wire:poll`) y cancelar. El registro de motores disponibles vive en `settings` (`solver.engines`) y el motor predeterminado por modalidad en `solver.default_engine`.

| Implementación | Dónde corre | Cuándo usarla |
|---|---|---|
| `PhpHeuristicEngine` | Contenedor `queue`, en PHP | Rápida, sin dependencias externas; buena para corridas por carrera, re-análisis y alternativas de solicitudes de cambio |
| `CpSatEngine` | Cliente en PHP que llama al servicio `solver` (Python) | Cargas grandes o muy restringidas (departamentos con muchos grupos en varios campus), mejor puntaje blando |

La pantalla de corridas (fase 6) no distingue entre ambas: elige motor, lanza, compara, acepta.

## Contrato JSON

Versión actual: `1`. El contrato es la frontera entre Nexus y cualquier motor; los DTOs de PHP (`Solver/Contract/`) y los modelos pydantic del servicio lo implementan por igual, y los fixtures compartidos lo prueban en ambos lados.

### Entrada

| Campo | Tipo | Obligatorio | Significado |
|---|---|---|---|
| `version` | entero | sí | Versión del contrato. Un motor rechaza versiones que no soporte. |
| `run_id` | cadena | sí | Identificador de la corrida (`solver_runs.id`) para correlación en logs. |
| `term` | objeto | sí | `code` del periodo. Solo informativo para el motor. |
| `modality` | objeto | sí | `code` de la modalidad de la carga. Solo informativo. |
| `campuses[]` | lista | sí | Un objeto por campus de la corrida: `code`, `block_minutes`, `day_start`, `day_end` (`HH:MM`), `days` (lista de 1 a 6) y `uses_rooms`. Cada campus tiene su rejilla de bloques, y `block_minutes` debe dividir la jornada de `day_start` a `day_end` en bloques completos (si no, la entrada se rechaza en `day_end`). |
| `time_limit_seconds` | entero | sí | Tiempo máximo de resolución. El motor devuelve la mejor solución encontrada al vencer. |
| `scope` | objeto | sí | `kind` (`department`: la carga del departamento en la modalidad; `career`: exploración del coordinador) e `id`. Para logs y para que la pantalla sepa qué se programó. |
| `travel_minutes` | entero | sí | Minutos mínimos para que un docente cambie de campus entre dos sesiones seguidas (`staffing.min_campus_travel_minutes`, SC-09). |
| `weekly_hours_tolerance` | entero | no (0) | Tolerancia de HC-12, en minutos: la suma de las sesiones de un grupo puede diferir de `weekly_hours` por esa cantidad (MS17). `BuildSolverInput` la toma del perfil de la modalidad (`weekly_hours_tolerance`) y, si no la tiene, de `scheduling.weekly_hours_tolerance`. Ausente vale 0, así que las entradas anteriores siguen siendo válidas en la versión 1. |
| `groups[]` | lista | sí | Grupos a programar. Por grupo: `id`, `campus_code`, `subject_code`, `department_id`, `owner_career_id`, `study_plan_id`, `semester` (reticular), `weekly_hours` (teoría más práctica), `practice_hours`, `needs_lab`, `capacity`, `teacher_id` (opcional), `cohorts[]` (claves de cohorte: conjuntos de grupos que un mismo alumno toma juntos y que no se empalman; ver HC-03), `fixed_sessions[]` (sesiones que no se mueven), `candidate_room_ids[]` (opcional, para acotar el dominio), `movable` (si es `false` el grupo entra solo como ocupación), `current_sessions[]` (opcional; las sesiones que un grupo movible ya tiene, con la forma de `fixed_sessions[]`, para que el motor marque `changed` en la salida; vacía si no tenía horario). |
| `teachers[]` | lista | sí | Docentes referidos por los grupos. Por docente: `id`, `remaining_hours`, `honorarios` (si es de honorarios, para SC-08), `unavailable[]` (día, inicio, fin), `preferred[]` (día, inicio, fin), `busy[]` (sesiones ya asignadas fuera de la corrida, con su `campus_code`, como ocupación fija). |
| `rooms[]` | lista | sí si `campus.uses_rooms` | Por salón: `id`, `campus_code`, `type`, `capacity`, `building_id`, `owner_department_id` (opcional), `blocks[]` (día, inicio, fin bloqueados), `busy[]` (sesiones de grupos fuera del alcance). |
| `agreements[]` | lista | no | Préstamos aceptados: `room_id`, `to_department_id`, `day`, `starts_at`, `ends_at`. Sin acuerdo, un salón con dueño solo lo usa su departamento (HC-10). |
| `subject_room_defaults[]` | lista | no | `subject_code`, `campus_code`, `room_id`, `priority`. Alimenta SC-03. |
| `hard_rules[]` | lista de cadenas | sí | Identificadores HC activos (por defecto HC-01 a HC-13). Permite desactivar una regla para una corrida exploratoria; la salida la reporta como desactivada. |
| `soft_weights` | objeto | sí | Peso por SC-01 a SC-09. Cero desactiva la regla blanda. Salen del parámetro `solver.soft_weights` (campos `sc_01`…`sc_09`, que `BuildSolverInput` pasa a `SC-01`…), que la Subdirección edita en «Parámetros»; sin valor válido, los de la tabla SC. |
| `options` | objeto | no | `threads`, `seed`, `suggest_teachers` (si el motor puede sugerir docente para grupos sin él), `return_intermediate` (si reporta soluciones intermedias). |

**Cohortes.** Una cohorte es un conjunto de grupos que un mismo alumno toma juntos; sus sesiones no se empalman (HC-03). Quien arma la entrada pone las claves en cada grupo:

- `plan:<study_plan_id>:<semestre>:<campus_code>` en los grupos de una materia que es el único grupo de su materia para la carrera en ese plan, semestre y campus (el alumno tipo de HC-03);
- `package:<package_id>:<campus_code>:<número>` en cada grupo de la instancia *n* de un paquete de primer semestre (DM-09; `groups.package_id` y `groups.package_number`, tarea 5.6).

El motor no sabe de dónde viene cada cohorte: solo garantiza que los grupos con una misma clave no se empalmen.

### Salida

| Campo | Tipo | Obligatorio | Significado |
|---|---|---|---|
| `version` | entero | sí | Misma versión del contrato que la entrada. |
| `run_id` | cadena | sí | Eco del identificador. |
| `status` | cadena | sí | `optimal`, `feasible` (sin violaciones duras pero sin prueba de óptimo), `partial` (hay grupos sin programar o violaciones residuales), `infeasible`, `error`. |
| `sessions[]` | lista | sí | Por sesión propuesta: `group_id`, `day`, `starts_at`, `ends_at`, `room_id` (nulo si la modalidad no usa salones), `kind` (`teoria`, `practica`, `virtual`), `changed` (si difiere de lo que había en la entrada). |
| `teacher_suggestions[]` | lista | no | `group_id`, `teacher_id`, `reason`. Solo si `options.suggest_teachers`. Es sugerencia; la asignación sigue el ranking de la fase 3. |
| `unscheduled[]` | lista | sí | Grupos que no se pudieron colocar: `group_id`, `reasons[]` (identificadores HC y texto). |
| `hard_violations[]` | lista | sí | Violaciones duras residuales en la solución devuelta: `rule`, `group_ids[]`, `teacher_id`, `room_id`, `message`. Debe estar vacía para `optimal` y `feasible`. |
| `soft_score` | objeto | sí | `total` y desglose por SC-01 a SC-09 (penalización por regla). Menor es mejor. |
| `relaxed_rules[]` | lista | no | Reglas que el motor relajó para encontrar solución en una instancia infactible, con los grupos afectados. |
| `metrics` | objeto | sí | `elapsed_seconds`, `engine`, `engine_version`, `variables`, `constraints`, `iterations` o `branches`, `intermediate_solutions`. |
| `log` | lista de cadenas | no | Mensajes informativos para la pantalla de corridas. |

### Versionado

- `version` se incrementa solo cuando cambia la forma o el significado de un campo. Agregar un campo opcional no cambia la versión.
- Cada motor declara qué versiones soporta; una entrada de versión no soportada devuelve `status = error` con mensaje claro.
- Las corridas guardadas conservan su versión; la pantalla de corridas sabe mostrar cualquier versión que haya existido.
- Los fixtures se guardan por versión en `tests/Fixtures/solver/v1/`.

## Mapeo de reglas a cada motor

| Regla | Heurística en PHP | CP-SAT |
|---|---|---|
| HC-01 docente sin empalme | Al colocar, el evaluador rechaza bloques ocupados por el docente (incluye `busy` de otros campus) | Suma de variables del docente por bloque ≤ 1, con `busy` como constantes |
| HC-02 salón sin empalme | Rechaza salones ocupados en el bloque (incluye `busy` y bloqueos) | Suma de variables del salón por bloque ≤ 1 |
| HC-03 alumno tipo sin empalme | Rechaza bloques ocupados por otro grupo de la misma cohorte (`cohorts[]`) | Suma por (cohorte, bloque) ≤ 1 |
| HC-04 ventana y bloques | El dominio de bloques se construye solo dentro de la ventana y alineado | Solo se crean variables dentro de la ventana y alineadas |
| HC-05 capacidad del salón | Filtro de salones candidatos por capacidad | Solo se crean variables con `room.capacity >= group.capacity` |
| HC-06 laboratorio | Filtro de tipo de salón para sesiones prácticas | Solo se crean variables de salones del tipo requerido para horas prácticas |
| HC-07 suma de cupos | No aplica al motor (se valida al editar cupos); se verifica en la entrada | Igual: verificación previa, no restricción del modelo |
| HC-08 horas del docente | Verificación previa con `remaining_hours`; el motor no cambia docentes salvo sugerencia | Igual; si `suggest_teachers`, suma de horas sugeridas ≤ `remaining_hours` |
| HC-09 disponibilidad | Rechaza bloques `unavailable` del docente | Variables en bloques `unavailable` fijadas en cero |
| HC-10 préstamo de salón | Salón con dueño ajeno solo si hay acuerdo que cubra el bloque | Variables de salón ajeno existen solo en bloques cubiertos por un acuerdo |
| HC-11 bloqueos de salón | Rechaza bloques en `blocks` | Variables en bloques bloqueados fijadas en cero |
| HC-12 horas semanales | El constructor coloca exactamente `weekly_hours` horas por grupo | Suma de horas de las sesiones del grupo = `weekly_hours` |
| HC-13 grupos válidos | Verificación previa al armar la entrada | Igual |
| SC-01 horas seguidas del alumno tipo | Penaliza huecos por (plan, semestre, día) en la búsqueda local | Variables auxiliares de primer y último bloque por (plan, semestre, día); penaliza su diferencia menos las horas ocupadas |
| SC-02 reparto en días | Penaliza más de N horas del mismo grupo en un día | Penalización por grupo y día cuando excede el umbral |
| SC-03 salón por defecto | Bonifica el salón de mayor prioridad del `subject_room_defaults` | Término negativo por usar un salón por defecto |
| SC-04 bloques preferidos del docente | Bonifica sesiones en `preferred` | Término negativo por sesión en bloque preferido |
| SC-05 compacidad del docente | Penaliza huecos por docente y día | Primer y último bloque por docente y día, como SC-01 |
| SC-06 cambio de edificio | Penaliza edificios distintos en horas consecutivas del docente | Penalización por par de sesiones consecutivas con edificio distinto |
| SC-07 semestres consecutivos | Penaliza empalmes entre semestre N y N+1 de la misma carrera | Penalización por (plan, semestre, semestre+1, bloque) ocupados a la vez |
| SC-08 turnos para honorarios | Penaliza sesiones fuera de la disponibilidad declarada de docentes de honorarios | Penalización por sesión fuera de `preferred` cuando el docente es de honorarios |
| SC-09 traslado entre campus | Penaliza dos sesiones seguidas del docente en campus distintos con menos de `travel_minutes` entre ellas | Penalización por par de sesiones del docente en campus distintos demasiado cerca |

HC-14 a HC-18 son reglas del flujo (validación de catálogos, cierre, motivo de asignación, visibilidad, bitácora) y no entran al motor.

## Descomposición del problema

La unidad de corrida es la carga: un departamento en una modalidad, con sus grupos de todos sus campus (decisión del usuario, 17 de noviembre de 2026). Lo que no es de la carga entra fijo: los grupos de otras cargas que comparten docentes, salones o cohortes, como `busy` o como grupos con `movable = false`, y las sesiones ya aceptadas, como `fixed_sessions`.

1. **Por departamento.** El jefe lanza la corrida de su carga y la acepta. Es la corrida normal.
2. **Por carrera.** El coordinador lanza corridas de los grupos de su carrera dentro de las cargas que toca, para explorar y comparar. No las acepta.
3. **Tronco común primero, sugerido.** Las materias compartidas entre carreras (Cálculo, Álgebra, Ética…) cruzan más restricciones, así que conviene programarlas primero. No se exige: cada departamento corre cuando quiere, y la pantalla avisa cuando los grupos con los que comparte cohorte todavía no tienen horario aceptado. `PlanRun` (fase 6) propone el orden y deja la decisión al jefe.

Una parte aceptada se vuelve fija para las siguientes corridas. Si una parte queda `partial`, se puede relanzar con más tiempo o con otra regla relajada.

## Límites de tiempo y soluciones parciales

- Toda corrida tiene `time_limit_seconds`; por defecto 60 por carrera y 300 por departamento (parámetros en `settings`).
- El motor devuelve siempre la mejor solución encontrada, con `status` honesto: `feasible` si no violó nada pero no probó óptimo, `partial` si dejó grupos sin colocar.
- Las soluciones intermedias se reportan para progreso; la pantalla muestra el mejor puntaje hasta el momento y permite cancelar y quedarse con lo que hay.
- Una instancia `infeasible` no se devuelve vacía: el motor relaja reglas en un orden fijo (SC primero, después HC-09 preferencias convertidas, nunca HC-01, HC-02 ni HC-05) y reporta en `relaxed_rules` qué relajó y qué grupos lo necesitaron, para que la persona decida.

## Límites del contrato v1

- **Cohortes HC-03 solo de materias de un grupo.** El contrato lleva únicamente las cohortes de HC-03 de las materias con un solo grupo activo (ver `Cohorts`). Si materias con varios grupos dejan al alumno tipo sin ninguna combinación posible, ni el evaluador ni el motor lo ven; el validador lo marca después de aplicar la salida.
- **HC-07 y HC-13 fuera del alcance del motor.** El motor no cambia cupos ni materias: HC-07 (cupo contra capacidad del salón) y HC-13 (materias, planes y carreras activos u ofrecidos) dependen de datos que no mueve. `BuildSolverInput` pasa los grupos tal como están y el validador los marca.

## Fixtures compartidos y pruebas

- `tests/Fixtures/solver/v1/<nombre>/` trae `input.json` (instancia del contrato, válida para `SolverInput::fromArray`) y `expected.json`, que describe lo que logra un motor correcto y no una salida exacta: `feasible` (`true`, `false` o `null` si no se sabe), `must_schedule` (grupos que deben quedar completos, con sus horas semanales), `may_leave_unscheduled`, `min_unscheduled` (en las infactibles), `max_soft_score` (`null` hasta tener línea base) y `notes`.
  - `tiny`: 3 grupos, 2 campus, un laboratorio ajeno con préstamo; `output.json` es una solución de referencia.
  - `small`: una carrera, un semestre, un campus; 6 grupos de una cohorte, 2 docentes, 3 salones (uno de laboratorio). Factible.
  - `medium`: 12 grupos movibles y uno fijo, 2 campus, 2 carreras con tronco común (claves de cohorte compartidas), laboratorio con préstamo aceptado, paquete (`package:<id>:<campus>:1`), docente en dos campus con traslado y salón ajeno sin préstamo. Factible.
  - `infeasible`: un docente cuyos bloques `unavailable` dejan menos horas libres que las de sus grupos (HC-09); al menos dos de sus tres grupos deben salir en `unscheduled`.
  - `department-multicampus`: la carga de Ciencias Económico-Administrativas (1 139 grupos movibles, 2 708 con los fijos, 657 docentes, 426 salones, C1 y C2) en PRES, Ene-Jun 2027, generada de la demo masiva y anonimizada (fichas `g001`, `t001`, `r001`…). No lleva resultado esperado; se mide en el benchmark. Grupo `solver-slow` en la suite.
- Para regenerar una instancia real: `docker compose exec -e DB_DATABASE=<base demo> app php artisan solver:export-fixture <periodo> <modalidad> <clave de departamento> <nombre> [--career=<clave>]` (por ejemplo `20271 PRES CEA department-multicampus`). Escribe solo `input.json`; `expected.json` se escribe a mano.
- `tests/Feature/Solver/SolverFixturesTest.php` corre cada motor registrado sobre cada fixture. A todos les exige una salida válida del contrato, el `run_id` de la entrada, el tiempo límite y sesiones de grupos de la entrada, dentro de la rejilla de su campus, alineadas a bloques y en un salón de ese campus. Solo a los motores que declaran `checksHardRules()` les exige además lo de `expected.json`: cero `hard_violations` y todo programado donde hay solución, `unscheduled` correcto donde no, y las horas semanales de `must_schedule` (HC-12). El motor de prueba (`FixedEngine`) corre solo las revisiones estructurales.
- El servicio Python lee los mismos archivos y corre la misma verificación con pytest.
- Los fixtures grandes corren fuera del CI de PR (job nocturno o manual; el CI de PR los salta con `--exclude-group=solver-slow`); `tiny`, `small`, `medium` e `infeasible` corren en cada PR.
- El comando de benchmark reporta tiempo, violaciones y puntaje por motor y fixture; los resultados se registran aquí al cierre de las fases 6 y 7.

### Línea base (se llena al cerrar cada fase)

`php artisan solver:benchmark [--engine=*] [--fixture=*] [--markdown]` corre cada motor registrado (o los de `--engine`) sobre cada fixture (o los de `--fixture`) y mide el tiempo con `hrtime`. La tabla de consola trae motor, fixture, grupos movibles, sesiones propuestas, sin programar, violaciones duras, puntaje blando, estado, segundos y «Cumple» (✓/✗ contra `expected.json` en los motores que declaran `checksHardRules()`; «—» en los demás). Con `--markdown` imprime la tabla con las columnas de abajo para pegarla aquí. Un motor o fixture desconocido termina con error.

Fila de referencia del arnés (6.12): el motor de prueba (`fixed`) no revisa reglas duras ni calcula puntaje, así que sus ceros no miden nada y solo muestran que el comando corre. Las filas de la heurística llegan con la 6.7.

| Fixture | Motor | Tiempo | Violaciones duras | Puntaje blando | Sin programar |
|---|---|---|---|---|---|
| tiny | fixed | 0.00 s | 0 | 0 | 0 |
| small | fixed | 0.00 s | 0 | 0 | 0 |
| medium | fixed | 0.00 s | 0 | 0 | 0 |
| infeasible | fixed | 0.00 s | 0 | 0 | 0 |
| department-multicampus | fixed | 0.01 s | 0 | 0 | 0 |

## Despliegue del servicio Python

| Aspecto | Decisión |
|---|---|
| Ubicación | `services/solver/` con su propio `Dockerfile`, `pyproject` y pruebas; imagen de Python 3.12 fijada por versión y digest como el resto del stack |
| Compose | Servicio `solver` en `compose.yaml` en la red `backend`, sin `ports` públicos; en `compose.override.yaml` el código montado como volumen y recarga automática |
| Acceso | Solo desde `queue` (y `app` para la consulta de salud) por nombre de servicio; token compartido en variable de entorno (`SOLVER_TOKEN`) verificado en cada petición |
| Recursos | Límite de CPU y memoria en compose; número de hilos de CP-SAT por configuración; límite de corridas simultáneas en el servicio |
| Estado | Sin estado persistente: las corridas en curso viven en memoria; el registro duradero es `solver_runs`. Al reiniciar, lo que estaba corriendo se marca `failed` desde PHP por tiempo de espera y se relanza |
| Salud | Endpoint de salud usado por el healthcheck de Docker; `queue` depende de que el servicio esté sano solo cuando el motor predeterminado es CP-SAT |
| Versionado | El servicio expone su versión y las versiones de contrato que soporta; `CpSatEngine` lo comprueba antes de enviar |

## Observabilidad

- Logs estructurados en JSON a stdout en ambos lados con `run_id`, motor, alcance, tamaño de la instancia, tiempo, estado y, en CP-SAT, número de variables, restricciones y soluciones intermedias.
- `solver_runs.metrics` guarda lo mismo para consultarlo desde Nexus sin leer logs.
- Tablero mínimo en la pantalla de corridas: corridas por estado, tiempo promedio por alcance, porcentaje `partial` o `failed`.
- Alertas: corrida en `running` más del doble de su límite de tiempo se marca `failed` y notifica al autor.

## Qué queda fuera

- Asignar docentes de forma automática como decisión final: el motor sugiere; el ranking y el jefe deciden.
- Programar modalidades sin bloques fijos (EnLínea): entran en la fase 10 con su propio perfil; el contrato ya admite campus sin salones.
- Optimizar todo el Tec a la vez: cada corrida es la carga de un departamento en una modalidad (todos sus campus); lo de otras cargas entra como `busy` o como grupos fijos.
- Un motor comercial o en la nube: todo corre en el stack de Docker de la institución.
