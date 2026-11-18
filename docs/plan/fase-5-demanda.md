# Fase 5 — Proyección de demanda

**Entrega:** R2 · **Ventana:** feb 2027 · **Modo:** paralelo · **Estado:** 🟨 (abierta el 16 de noviembre de 2026) · **Milestone:** `Fase 5 — Proyección de demanda`

## Objetivo

Que Nexus calcule, antes de que el coordinador abra su propuesta, cuántos alumnos van a necesitar cada materia en el siguiente periodo y cuántos grupos conviene abrir, usando el avance reticular y el comportamiento histórico de cada materia en cada campus. El coordinador pasa de capturar desde cero a corregir una propuesta ya calculada. La proyección se mide contra la carga real de `20271` para saber cuánto confiar en ella.

## Dependencias

- Fase 1: `student_progress` y `EligibleStudentsQuery` en `Students`; kardex de varios periodos importado (al menos los `demand.history_terms` anteriores).
- Fase 2: `career_proposals` y `proposal_items` en `AcademicLoad`; acción `OpenTerm`.
- Fase 4: snapshot publicado de `20271` (grupos abiertos, cupos y ocupación real) como referencia para medir el error.
- Retrospectiva de R1 (tarea 4.11) con los ajustes que los coordinadores hicieron a mano sobre los elegibles.

## Punto de partida (10 de noviembre de 2026)

Se escribió durante la fase 4, para que la fase 5 arranque con lo que hoy existe y con las decisiones del usuario. **Por ahora es solo planeación** (decisión del usuario, 10 de noviembre de 2026): ningún riel implementa nada de la fase 5 hasta que el usuario abra la fase. Los tres siguen en la fase 4.

### Qué ya existe y se reutiliza

- **Elegibles:** `Students\Queries\EligibleStudentsQuery`.
  - `countsFor(Career, Campus)` da los elegibles por materia y `distinctFor()` los alumnos distintos. Lee `student_progress`: una fila por alumno con `subjects_available`, sin periodo, que se recalcula al importar alumnos o kárdex (`RefreshStudentProgress`).
  - Es el estado de hoy, **sin historia**: para las tasas hay que reconstruir quién era elegible al inicio de cada periodo pasado a partir del kárdex (ver «Ajustes»).
- **Grupos sugeridos:** `AcademicLoad\Rules\SuggestedGroups`.
  - Calcula `ceil(esperados / tamaño)` con `demand.target_group_size` (`{"default": 35, "lab": 25}`) en `settings` con alcance global.
  - Solo distingue normal y laboratorio. `ProposalAnomalies` lee `demand.min_group_size`, 10 por omisión.
  - Hoy ninguna pantalla edita `settings`; solo los seeders.
- **Kárdex:** `kardex_records` con `term_code`, `grade`, `result` (`aprobada`, `reprobada`, `cursando` o `baja`) y `attempt`.
  - La migración del SII (tarea 1.16, #353 y #354) trae la historia desde 1964: unos 2 millones de renglones, con la oportunidad de `tipo_evaluacion`.
  - Los periodos que no existen en Nexus pasan sin `term_code`.
  - De `seleccion_materias` solo entran, como `cursando`, los periodos que el SII todavía no pasó a la historia: no hay inscripciones históricas aparte.
  - La demo y el masivo ya siembran kárdex de varios periodos (`TrajectorySimulator`, ingresos de 2022 a 2026 y egresados desde 2018).
- **Propuesta de carrera:** `proposal_items` tiene `eligible_students`, `expected_students` y `requested_groups`.
  - `StartCareerProposal` los nace con los elegibles y los grupos sugeridos.
  - **No hay columnas de lo sugerido aparte de lo capturado**: el coordinador sobrescribe.
  - «Alumnos posibles» queda congelado en `career_proposals.eligible_students`.
- **Precedente de cálculo por departamento:** `StaffingSupplyDemoSeeder::demand()` ya hace la cadena elegibles → grupos sugeridos → horas por departamento para la demo.
- **Lo publicado:** `load_snapshots`, payload v1, trae los grupos abiertos por materia con su `capacity` y sus `placements[].seats`, pero **no la ocupación**: en R1 la inscripción sigue en el SII.
  - «La última versión de cada carga» ya existe: el scope `LoadSnapshot::query()->latestVersion()` y, con #366, `AcademicLoad\Exports\Sii\SiiLoadExport::latestSnapshots(Term, Campus)`. Los dos son de AcademicLoad.
- **Mapa de módulos:** el diagrama ya tiene `Students --> Demand --> AcademicLoad`. El módulo `Demand` no existe todavía. Por el ADR-0022, sus tablas van en el esquema `demand`.

### Respuestas del usuario (10 de noviembre de 2026)

1. **Ocupación real:** sale del **kárdex** de cada periodo: quien tiene la materia ese periodo, aprobada, reprobada o cursando, la tomó. Antes de usarlo con datos reales se vuelve a importar la información más actual del SII. Así se mide el error de la 5.9 y se muestra el historial de la 5.6, sin otra exportación.
2. **Recálculo:** si la proyección se recalcula después de que el coordinador corrigió, **se conserva lo que capturó y se avisa** («la proyección cambió: ahora sugiere N»), para que decida él.
3. **Historial y tasas:** se comparan con **el mismo tipo de periodo**, Ene-Jun con los Ene-Jun anteriores y Ago-Dic con los Ago-Dic, porque cada uno abre materias de semestres distintos. Por omisión, los últimos 4 (`demand.history_terms`).
4. **Tablero de demanda del departamento (5.7):** es de **todo el Tec**, con una insignia por campus. En palabras del usuario: «el campus no es tan decisivo como parece en Nexus»; el jefe de departamento es uno para todo el Tec.
5. **Parámetros (5.8):** los ajustan **Subdirección y admin**, para todo el Tec, sin despliegue.
6. **Primer semestre:** no sale de la fórmula. Con los alumnos de nuevo ingreso ya inscritos, **el coordinador arma «paquetes»**: conjuntos de materias de primer semestre, y a veces de segundo, que se asignan cada uno a varios alumnos. La proyección de primer semestre toma lo que el coordinador capture; el concepto de paquete es nuevo en Nexus (ver «Preguntas abiertas»).
7. **Coordinadores:** en Campus I hay un coordinador por carrera; **en Campus II uno solo para todas las carreras**. Para el piloto de la fase 4 basta con darle a esa persona un nombramiento de coordinador por cada carrera de Campus II. Si conviene un puesto «coordinador de campus» que cubra todas sus carreras se decide al planear.
   - **Pendiente antes del piloto de Campus II: «apertura por campus»** (M17, decisión del usuario del 13 de noviembre de 2026; todavía no se construye ni está en las reglas). Al abrir el periodo para una modalidad, el admin marca en qué campus se captura; los campus no marcados no tienen columna en la propuesta, grupos, catálogos por validar ni choques, y se pueden agregar campus después. Hoy abrir Presencial cubre todos los campus activos, así que sin esto cada propuesta presencial nace con una columna de C1 llena con los grupos sugeridos que el coordinador de C2 no puede cambiar.

### Ajustes a las tareas

- **Regla del mapa para toda la fase: Demand calcula; lo que compara con las propuestas o con lo publicado vive en AcademicLoad.** Demand está a la izquierda de AcademicLoad (`Students --> Demand --> AcademicLoad`), así que no lee `proposal_items`, `load_snapshots`, `LoadSnapshot` ni `SiiLoadExport`. Es lo mismo que pasa con `SuggestedGroups` en la 5.3.
- **5.2, tasas históricas:** `student_progress` no guarda historia, así que la tasa de no inscripción de un periodo pasado necesita los elegibles **al inicio de ese periodo**. Hay que reconstruirlos del kárdex: lo aprobado antes del periodo, la seriación y el plan del alumno en ese momento.
  - Es la parte más cara y más delicada de la fase.
  - Conviene una consulta por periodo y campus que se pueda probar con un kárdex sintético, separada de la fórmula, como ya dice la tarea.
  - Los renglones sin `term_code` no entran.
- **5.3, la fórmula** (reglas de negocio, «Elegibilidad y demanda», R2): `SuggestedGroups` es de AcademicLoad, pero Demand está a su izquierda en el mapa y no puede usarlo.
  - Muévelo a `Demand/Rules`, que AcademicLoad sí puede importar, o duplica la lectura del parámetro.
  - Los tamaños por tipo de materia siguen siendo dos, normal 35 y laboratorio 25, hasta que se decida otra cosa.
- **5.5, precarga:** `proposal_items` necesita columnas para lo sugerido (esperados y grupos) aparte de lo capturado. Va en el mismo PR que `modelo-de-datos.md`. El aviso «la proyección cambió» compara lo sugerido nuevo contra lo sugerido guardado, nunca contra lo capturado.
- **5.6, historial:** la pantalla es la de la propuesta, en AcademicLoad. Desde ahí lee la ocupación por periodo, que da Demand con el kárdex de Students, y los grupos abiertos, que salen de `load_snapshots` desde R1; antes de R1 no hay. Demand nunca lee la foto. Historial de grupos (5.6a, #514, sin marcar): tabla `historical_groups` de AcademicLoad, con lo migrado del SII antes de 20271 y lo publicado desde ahí; `SubjectGroupHistoryQuery::sameTypeTermsBefore` es el mismo cálculo de periodos del mismo tipo que tendrá Demand: unificarlos después.
- **5.7, tablero:** de todo el Tec, con la forma de los tableros de cierre de la fase 4: un renglón por materia e insignias por campus. Como compara lo proyectado contra lo pedido (`proposal_items`), vive en AcademicLoad y lee lo que calcula Demand.
- **5.8, parámetros:** es la primera pantalla que edita `settings`. Va en Core, con permiso de Subdirección y admin y bitácora de cada cambio. Las claves son `demand.history_terms`, `demand.target_group_size`, `demand.min_group_size` y el umbral para resaltar diferencias.
- **5.9, evaluación:** compara lo que la fórmula habría dicho «como si» se hubiera corrido antes de `20271` contra los grupos publicados de `20271` y el kárdex de `20271` reimportado al terminar el semestre.
  - Lo publicado es **la última versión de cada carga** (`latestVersion`): una carga reabierta y republicada tiene varias fotos, y no cuentan las que el público ya no ve.
  - Como compara contra lo publicado, el comando vive en AcademicLoad y llama a la fórmula de Demand.
  - Hasta tener ese kárdex solo se puede medir contra periodos anteriores.
- **Demo por puntos:** la proyección se calcula al abrir el periodo, así que el punto `propuesta` es el que ya trae las propuestas en borrador precargadas. La demo tiene kárdex de varios periodos para las tasas.

### Preguntas abiertas para cuando se abra la fase

- **Paquetes de primer semestre:** ¿cuántos por carrera, cómo se arman (todas las materias del semestre en el mismo horario) y quién asigna a cada alumno a su paquete? Toca a la fase 6 (horarios de cada paquete sin choques) y a la 9 (inscripción).
- **Tamaño objetivo por tipo:** ¿bastan normal y laboratorio, o hay más tipos (especialidad, inglés, complementarias)?
- **Verano:** ¿la proyección también calcula cursos de verano, o solo Ene-Jun y Ago-Dic? (supuesto: solo los semestres).
- **Coordinador de campus:** ¿se modela como un puesto que cubre todas las carreras del campus?

### Reparto cuando se abra la fase

Como en la tabla «Reparto»: A el núcleo de Demand (5.1–5.4), B la precarga y las pantallas (5.5–5.7) y C los parámetros, la evaluación y los datos (5.8–5.10). Acordado por los tres rieles el 10 de noviembre de 2026.

## Arranque (16 de noviembre de 2026)

El usuario abrió la fase con tres rieles en sesiones paralelas, con el reparto de abajo. Cada riel trabaja en su rama padre, que sale de `develop`, y en su propio puerto y base, para no chocar al ver pantallas o correr pruebas:

| Riel | Tareas | Rama padre | Puertos | Bases |
|---|---|---|---|---|
| A | 5.1 → 5.4, núcleo de Demand | `riel/demanda-a` | 8091, 8092 | `nexus_demo_a`, `nexus_massive_a`, `nexus_test_a*` |
| B | 5.5 → 5.7, precarga y pantallas | `riel/demanda-b` | 8093, 8094 | `nexus_demo_b`, `nexus_massive_b`, `nexus_test_b*` |
| C | 5.8 → 5.10, parámetros, evaluación y datos | `riel/demanda-c` | 8097, 8098 | `nexus_demo_c`, `nexus_massive_c`, `nexus_test_c*` |

Los puertos 8095 y 8096 son de la tarea 1.16, que sigue en otra sesión.

### Ronda 0: contratos entre rieles

Antes que todo, A mezcla a `develop` un PR chico con lo que los tres necesitan (5.1). Así B y C salen de `develop` con las mismas firmas:

- **Módulo `Demand`** con su provider, entre CatalogValidation y AcademicLoad en `bootstrap/providers.php`.
- **`demand_forecasts`**, con sus columnas en el [modelo de datos](../dominio/modelo-de-datos.md#demand-r2). La llave es periodo, modalidad, campus, carrera y materia; desde #464 la propuesta es por carrera y modalidad, con filas por campus.
- **`Demand\Rules\DemandSettings`**:
  - lee los cuatro parámetros `demand.*`: `history_terms` 4, `target_group_size` 35 y 25, `min_group_size` 10 y `highlight_threshold` 20 %;
  - declara sus definiciones para la pantalla «Parámetros» (CF-S7). Antes las declaraba AcademicLoad;
  - con `isLargeDifference()` decide qué diferencia se resalta en la 5.6 y la 5.7.
- **`SuggestedGroups`** se mueve de AcademicLoad a `Demand\Rules`, como pedía la 5.3. AcademicLoad, Scheduling y Staffing lo importan desde ahí. `ProposalAnomalies` se queda en AcademicLoad y lee su clave de `DemandSettings`.
- **`DemandForecaster`**, la fórmula pura (versión `v1`). Recibe un `ForecastInput` con las tasas de `SubjectRates` y da un `ForecastResult`.
- **Lecturas para B y C**:
  - `DemandForecastsQuery::forCareer(Term, Modality, Career)`: por materia y, dentro, por campus;
  - `DemandForecastsQuery::forDepartment(Term, Modality, Department)`: las materias del departamento con las filas de todas las carreras y campus;
  - `TermOccupancyQuery::forTerm(string $termCode, Modality, ?Campus)`: la ocupación real de un periodo por carrera, campus y materia;
  - `TermOccupancyQuery::history(string $targetTermCode, Modality, array $subjectIds, int $terms)`: los periodos del mismo tipo anteriores a uno, por materia, periodo y campus.
- **Evento `DemandForecastsComputed`** (periodo, modalidad, campus o todos, carreras), después del commit. Lo despacha el job de la 5.4. AcademicLoad lo escucha para poner al día las propuestas en borrador (5.5).

### Cómo quedó el núcleo de Demand (A, 5.1 a 5.4)

- **Reconstrucción de elegibles** (`Demand\Rules\StandingCalculator`, pura): lo que un alumno podía cursar al inicio de un periodo, desde su kárdex y la retícula de su plan (`PlanGrid::progressFor`, la misma regla de R1).
  - Lo que cursa cuenta como aprobado (DM-01).
  - Su semestre es uno más los semestres ordinarios con kárdex (DM-10).
  - Tiene un modo «como si» para la 5.9.
- **Tasas** (`HistoricalRatesQuery`): por materia y campus, sumando la ventana del mismo tipo (DM-03). Sin historia, la del departamento (DM-04); además, el nuevo ingreso histórico (DM-05).
- **Cálculo** (`Demand\Actions\RefreshDemandForecasts`):
  - `compute()` no escribe y tiene el modo `asIf`;
  - `handle()` reemplaza las filas del periodo y la modalidad y avisa con `DemandForecastsComputed`;
  - el alcance (`ForecastScopeQuery`) es la misma selección que la propuesta de carrera.
- **Cuándo se recalcula:** `Demand\Jobs\ComputeDemandForecasts` es único por periodo, modalidad y campus.
  - Lo encola la apertura del periodo (`TermOpened`, después del commit).
  - También lo encola el recálculo del avance de los alumnos, con el evento nuevo de Students `StudentProgressRefreshed`. Demand no puede escuchar a Imports, porque está a su derecha en el mapa, así que esta es la señal de que llegó kárdex nuevo.
  - Recalcula los periodos abiertos del actual en adelante (`ForecastTargetsQuery`).
- **Demo:** `demo:seed` corre con la cola síncrona, así que la proyección nace al abrir el periodo. Desde el punto `propuesta`, una revisión exige proyección en cada modalidad abierta.
  - Con el masivo (12 006 alumnos y 354 918 filas de kárdex), abrir Ene-Jun 2027 con la proyección tarda 12.5 s, y llegar a `propuesta`, 78 s.
  - Con la copia real del SII (16 700 alumnos activos y 2 043 435 filas de kárdex; base local del Riel C, que se borra al terminar), la proyección de 20271 en Presencial tarda 20.1 s y usa 85 MB como máximo (2 176 filas). En modo «como si», 13.2 s.
- **Casos raros en la demo** (`DemandRareCasesDemoSeeder`, #541, paso que lleva a `validacion`, también en `DemoSeeder` y `MassiveSeeder` tras abrir el periodo): materia nueva sin historia (`department_fallback`), un solo periodo de historia, especialidad por revisar y primer semestre con nuevo ingreso ya salen de la simulación de alumnos en los dos volúmenes (medido el 17 de noviembre de 2026); lo único que agrega es una carrera ofrecida por primera vez en un campus sin alumnos (demo: COPU en Campus I; masivo: IELC en Campus II, 54 filas con cero elegibles y cero de nuevo ingreso) y recalcula la proyección. Cinco revisiones de Demand, desde `propuesta`, exigen cada caso.

### Respuestas del usuario (16 de noviembre de 2026)

1. **Seriación en curso:** al proyectar, quien cursa hoy la seriación de una materia cuenta como si la aprobara («el escenario ideal»). Más adelante se quiere ver cómo mitigar y prevenir considerando a los que no la pasan. Por eso cuántos dependen de eso se guarda aparte (`pending_prerequisite_students`).
2. **Nuevo ingreso:** para la parte de nuevo ingreso de primer semestre, Nexus precarga el ingreso histórico: el promedio de alumnos que ingresaron a la carrera en ese campus en los últimos periodos del mismo tipo, más los rezagados que da la fórmula. El coordinador lo corrige.
3. **Paquetes de primer semestre:** en la propuesta, el coordinador captura cuántos paquetes abre por campus, y cada paquete abre un grupo de cada materia del paquete. Los horarios sin choque entre las materias de un paquete son de la fase 6 y asignar alumnos a cada paquete, de la 9.
4. **Tamaños objetivo:** bastan los dos, normal y con laboratorio.

#### Respuestas del usuario al Riel B (16 de noviembre de 2026)

1. **Recálculo:** las filas de la propuesta que el coordinador no ha tocado siguen solas a la nueva proyección; las que corrigió conservan lo capturado y se avisa «la proyección cambió: ahora sugiere N» para que él decida (implementado en 5.5).
2. **Historial previo a Nexus:** se toma de `sii_archivo.grupos`.
3. **Quién ve el tablero:** lo ven el jefe, el JPD, Subdirección y el admin, y los coordinadores (sus materias, solo consulta).
4. **Diferencia grande:** solo se resalta; no pide motivo ni bloquea el envío.

Queda como supuesto por confirmar que la proyección no calcula cursos de verano. «Coordinador de campus» ya no es pregunta: lo resolvió M5 (#464), con coordinador = carreras + modalidad, campus opcional y varias carreras por persona.

## Alcance

### Incluye

- Módulo `Demand` completo: tabla `demand_forecasts`, cálculo, job, pantallas y parámetros.
- Precarga de `proposal_items` con la proyección.
- Tablero de demanda agregada por materia para el departamento.
- Medición del error de la proyección contra datos reales.

### No incluye

- Generación de horarios (fase 6) ni asignación automática de docentes.
- Proyección para modalidades no presenciales (fase 10).
- Cambios a las reglas de elegibilidad (ya definidas en `reglas-de-negocio.md`).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 5.1 ✅ [#511](https://github.com/Erne1357/nexus/pull/511) | Modelo y migración de `demand_forecasts` (periodo, campus, carrera, materia, elegibles, tasa histórica, esperados, grupos sugeridos, método, fecha de cálculo) con unicidad por periodo, campus, carrera y materia | Demand | Modelo de datos. Una fila por combinación para que la precarga y la comparación sean consultas directas; `method` registra con qué versión de la fórmula se calculó. | Unit: relaciones y unicidad | A |
| 5.2 ✅ [#521](https://github.com/Erne1357/nexus/pull/521) | Tasas históricas por materia y campus: cálculo de tasa de no inscripción (elegibles que no cursaron) y tasa de reprobación (cursaron y no aprobaron) sobre los últimos `demand.history_terms` periodos a partir de `kardex_records` y `student_progress` históricos | Demand | `Demand/Queries/HistoricalRatesQuery`. Separar la consulta del cálculo permite probar las tasas con fixtures de kardex sin tocar la fórmula. Materias sin historial usan la tasa promedio de su departamento, y se marca `method` = `fallback`. | Unit con kardex sintético de 4 periodos: tasas conocidas, materia nueva sin historial, materia con un solo periodo | A |
| 5.3 ✅ [#524](https://github.com/Erne1357/nexus/pull/524), v2 [#552](https://github.com/Erne1357/nexus/pull/552) | Fórmula de proyección: `expected_students` = elegibles × (1 − tasa de no inscripción) + repetidores esperados (alumnos cursando ahora × tasa de reprobación); `suggested_groups` = techo de esperados entre `demand.target_group_size` según `subjects.kind` y `needs_lab` | Demand | `Demand/Rules/DemandForecaster` como clase pura, sin acceso a base de datos. Es la regla de `reglas-de-negocio.md`; vive aislada para poder cambiarla y versionarla sin tocar jobs ni pantallas. | Unit: casos con tamaño objetivo por tipo, redondeos, cero elegibles, materias de especialidad | A |
| 5.4 ✅ [#526](https://github.com/Erne1357/nexus/pull/526) | Job `ComputeDemandForecasts` por periodo y campus, encolado al abrir periodo (evento `TermOpened`) y al aplicar una importación de kardex (evento `BatchApplied` de plantilla `kardex`); recalcula solo las carreras del campus afectado | Demand | `Demand/Jobs/`. Respeta la dirección de dependencias: `Demand` escucha eventos de `Core` e `Imports`, nunca al revés. Idempotente: reemplaza las filas del periodo y campus. | Feature: ambos eventos encolan el job; segunda corrida no duplica filas | A |
| 5.5 | Precarga de `proposal_items`: al crear o abrir una `career_proposal` en `draft`, `AcademicLoad` lee `demand_forecasts` y rellena elegibles, esperados y grupos solicitados; los valores precargados se marcan como sugeridos hasta que el coordinador los confirma o edita | AcademicLoad, Demand | Action `PrefillProposalFromDemand` en `AcademicLoad` (que sí puede depender de `Demand`). Guarda el valor sugerido junto al capturado para medir después cuánto corrige la gente. | Feature: propuesta nueva nace precargada; una propuesta ya `submitted` no se toca | B |
| 5.6 | Pantalla del coordinador: en la propuesta de carrera, por materia, columnas de elegibles, esperados por Nexus, esperados capturados, grupos sugeridos y grupos pedidos, más el histórico de los últimos periodos (grupos abiertos, cupo y ocupación real desde los snapshots); resaltar diferencias grandes entre sugerido y capturado | AcademicLoad | Extiende `career-proposal` de la fase 2. El coordinador necesita ver la evidencia para confiar o corregir, no solo un número. La ocupación histórica sale de `load_snapshots` de R1 (y de la importación de grupos del SII si se decide cargarla). | Feature: columnas correctas por alcance; browser: corregir un esperado y pedir un grupo más | B |
| 5.6b | Paquetes de primer semestre y nuevo ingreso, solo backend ([#518](https://github.com/Erne1357/nexus/issues/518)): tablas `proposal_packages`, `proposal_package_subjects`, `proposal_package_campuses` y `proposal_intakes`, `proposal_items.package_groups`, `groups.package_id` y `package_number`; Actions de paquetes, cuentas por campus y nuevo ingreso; `CreateGroupsFromProposal` abre un grupo por materia de cada paquete. La pantalla va en B3 | AcademicLoad, Scheduling | Composición por carrera, cuenta por campus (DM-09). Ver «Propuesta de carrera». | Feature de cada Action, sugeridos, grupos y restricciones | B |
| 5.7 | Tablero de demanda del departamento: por materia que imparte el departamento, demanda agregada de todas las carreras y campus (elegibles, esperados, grupos sugeridos vs. pedidos), para dimensionar docentes y laboratorios antes de que lleguen las propuestas | Demand | `Demand/Livewire/pages/department-demand`. Hoy el jefe se entera materia por materia cuando cada coordinador manda su Excel; aquí ve el total de una vez. | Feature: agregación por materia respeta alcance de departamento y campus | B |
| 5.8 | Parámetros editables por campus en `settings`: `demand.history_terms`, `demand.target_group_size` por tipo de materia, umbral de diferencia para resaltar; pantalla de parámetros en `Core` | Core, Demand | Reutiliza la tabla `settings` de la fase 0. Que Subdirección pueda ajustar el tamaño objetivo sin despliegue. | Feature: cambiar el parámetro altera `suggested_groups` en la siguiente corrida | C |
| 5.9 | Medición del error: comando y reporte que compara la proyección calculada "como si" se hubiera corrido antes de `20271` contra los grupos realmente abiertos y la ocupación del snapshot publicado; error absoluto medio por materia y por carrera | Demand | `Demand/Console/EvaluateForecast`. Sin esta medición no hay forma de saber si la fórmula sirve; el resultado decide si R2 confía en la precarga o la muestra solo como referencia. | Feature con fixture del snapshot de R1: el reporte produce las métricas esperadas | C |
| 5.10 | Pruebas con datos sintéticos y con el historial real: seeder de kardex histórico de varios periodos para el `DemoSeeder`; corrida completa sobre los datos reales de Campus II en el servidor de pruebas | Demand, Students | Los datos reales son la única prueba de que las tasas tienen sentido. | Reporte de 5.9 generado con datos reales y revisado con un coordinador | A, C |

## Pruebas de la fase

- **Unit:** `DemandForecaster` (fórmula pura), `HistoricalRatesQuery` con kardex sintético, redondeos y tamaños objetivo.
- **Feature:** job por eventos, precarga de propuestas, tablero del departamento, parámetros.
- **Browser:** coordinador revisa una propuesta precargada, corrige y envía.
- **Datos reales:** comando de evaluación contra la carga publicada de `20271`.
- **Arquitectura:** `Demand` depende solo de `Core`, `Academic` y `Students`; `AcademicLoad` depende de `Demand`, nunca al revés.

## Criterios de aceptación

1. Al abrir un periodo, cada carrera de cada campus presencial tiene su `demand_forecasts` calculado y su propuesta nace precargada.
2. El coordinador ve, por materia, elegibles, esperados, grupos sugeridos e histórico, y puede corregir cualquier valor; lo sugerido y lo capturado se guardan por separado.
3. El error de la proyección medido contra `20271` queda documentado en `docs/decisiones/` con la decisión de si se muestra como sugerencia fuerte o como referencia.
4. Cambiar un parámetro de demanda en `settings` altera la siguiente corrida sin despliegue.
5. CI verde con cobertura mínima de 80 % en `Demand`.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | Demand (núcleo) | 5.1 → 5.2 → 5.3 → 5.4; publica `DemandForecaster` y el job la primera semana |
| B | AcademicLoad (precarga y pantallas) | 5.5 → 5.6 → 5.7 usando fixtures de `demand_forecasts` mientras A termina |
| C | Core (parámetros) y evaluación | 5.8 → 5.9 → 5.10 |

## Riesgos y notas

- Si el kardex histórico importado cubre menos periodos que `demand.history_terms`, las tasas se calculan con lo que haya y se marca el método; no bloquear.
- Los alumnos de nuevo ingreso no tienen kardex: la demanda del primer semestre se toma de un parámetro por carrera y campus (`demand.new_students`) que captura Servicios Escolares, no de la fórmula.
- Las materias de especialidad dependen de qué especialidad elige cada alumno; la proyección las trata con la tasa del departamento y las marca para revisión manual.
- La proyección es una sugerencia: nunca crea grupos por sí sola ni cambia una propuesta ya enviada.
