# Fase 8 — Re-análisis, impacto de cambios y junta digital

**Entrega:** R2 · **Ventana:** abr 2027 · **Modo:** paralelo · **Estado:** ⬜ · **Milestone:** `Fase 8 — Re-análisis, impacto y junta digital`

## Objetivo

Cerrar las tres brechas que R1 dejó a propósito: que una carga publicada se pueda **re-analizar** cuando llegan las calificaciones finales y republicar como nueva versión; que cada **solicitud de cambio** muestre su impacto antes de aprobarse; y que la **junta interdepartamental** llegue con los datos en pantalla y salga con un acta generada. Con esto el flujo propuesto queda completo para presencial.

## Dependencias

- Fase 4: etapa `published`, `load_snapshots`, exportes.
- Fase 5: `demand_forecasts` recalculable al importar kardex.
- Fase 6: evaluador en memoria y `SolverEngine` para simular y proponer alternativas.
- Fase 3: `change_requests`, `interdepartmental_agreements`, `group_quotas`, tablero de choques.

## Punto de partida (17 de noviembre de 2026)

Se escribió con la fase 5 y el contrato del motor ya en `develop` (#567, #568), para que la fase 8 arranque con lo que hoy existe y con las decisiones del usuario. El plan de abajo se escribió cuando la carga era por campus y antes de que el mapa de módulos quedara como está. Desde la ronda 2 «carga por modalidad» (#418, #464; [ADR-0025](../decisiones/0025-modalidad-como-catalogo.md)) la carga es del **departamento en una modalidad**, con grupos en varios campus. Donde choquen, mandan estos ajustes. La heurística del motor (6.5 a 6.7) todavía no está en `develop`; lo que depende de ella se dice en cada tarea.

### Qué ya existe y se reutiliza

- **Estado `reanalysis`:** ya está en `LoadStatus` (`AcademicLoad/Enums/LoadStatus.php`), con etiqueta, tono de advertencia y las transiciones `published → reanalysis` y `reanalysis → published`.
  - Ninguna Action, permiso ni pantalla lo usa todavía. Solo lo leen los tableros de Staffing (`ClosingBoardQuery`, `LoadClosingQuery`, `LoadProgress`) y la demo: `demo:seed --hasta=reanalisis` deja las cargas cerradas publicadas con su versión 1 (`PublicationDemoSeeder`), que es el punto de arranque de la 8.1.
  - `Stage::Reanalysis` ya existe en el calendario del periodo (por modalidad, M4).
- **Transiciones:** `TransitionLoad` es la única puerta (`AcademicLoad/Actions/Transitions/`).
  - Valida el diagrama, junta los motivos de la guarda de quien llama y del veto de `LoadTransitioning` (módulos a la derecha), escribe `load_transitions` (HC-18) y avisa con `LoadTransitioned` después del commit. Una carga publicada de una modalidad inactiva no sale de `published` (MS36).
  - `CloseLoad`, `ApproveLoad` y `PublishLoad` son las del cierre de la fase 4 (4.1 #357, #361; 4.2 #362, #383; 4.3 #356, #359). `ReopenLoad` reabre una carga cerrada, aprobada o publicada **a `adjusting`**, con motivo (P3, confirmado el 10 de noviembre de 2026; permiso `academic_load.load.reopen`, de Subdirección y del admin).
  - `StartReanalysis` y el permiso para iniciarlo no existen. `AcademicLoadPermission` no tiene nada de re-análisis; la matriz de «Aprobaciones y permisos» dice «`coordinador`, `jefe_departamento` · nueva importación de kardex (R2)».
- **Versiones del snapshot:** `load_snapshots` (`Models/LoadSnapshot.php`) es inmutable por trigger (HC-18) y `PublishLoad` ya crea `current_version + 1` y conserva la anterior.
  - `Rules/SnapshotPayload.php` fija la forma v2 (`SCHEMA_VERSION = 2`) y `normalize()` lleva las fotos v1 a la actual (MS13). El grupo trae `campus`, `capacity`, `teacher`, `placements[]` (carrera, `seats`, `is_owner`) y `sessions[]` con `room.owner_department_id`: con eso solo se pueden calcular qué cambió por docente, por carrera y por préstamo de salón, sin leer Scheduling.
  - El scope `LoadSnapshot::latestVersion()` da la última versión de cada carga; es lo que leen la consulta pública, los exportes y el docente.
  - `PublishLoad` ya lee la versión anterior normalizada para llevar el historial docente (`SyncPublishedTeaching`) y escribe la bitácora «published». `RecordGroupHistoryOnPublication` pone al día `historical_groups` en cada publicación.
  - **Un diff parcial ya existe:** `Queries/LoadVersionsQuery::changes()` cuenta grupos que entraron, salieron, con otro docente y con otro salón entre una versión y la anterior, y la línea de versiones del componente `publish-load` lo muestra. No guarda nada, y no ve cambios de horario ni de cupos.
  - El «motivo» de una versión hoy es solo el comentario de la transición a publicada (`versions()`).
- **Consulta pública y exportes (4.4 #355, #364; 4.5 #365, #368):** leen solo el `payload` y su llave de caché lleva los ids de las versiones, así que una versión nueva invalida sola lo anterior (`PublishedScheduleQuery`, `PublishedExport`). La consulta ya dice «Actualizado el …» (decisión 7 de la fase 4).
- **Avisos:** `NotifyLoadClosing` y `LoadReopened`, `LoadApproved` y `LoadAwaitingApproval` (`AcademicLoad/Notifications`) mandan por la campana (canal `database`; el correo «queda para después») a los titulares de un permiso con `RecipientsQuery::holders()` y `NotifyHolders`, y el listener `NotifyOnLoadClosingTransition` los manda en cola. Los docentes y las carreras afectadas no tienen aviso todavía.
- **Demanda (fase 5):**
  - `Demand/Actions/RefreshDemandForecasts.php` (fórmula `v2`) guarda `demand_forecasts` por periodo, modalidad, **campus**, carrera y materia, con `eligible_students`, `pending_prerequisite_students`, `expected_students`, `suggested_groups` y `computed_at`. DM-01 proyecta como elegible a quien cursa hoy la seriación como si la aprobara; con las calificaciones finales esa suposición se corrige, que es justo el cambio que busca el re-análisis.
  - Se recalcula en cola (`ComputeDemandForecasts`, único por periodo y modalidad) cuando llega `Students\Events\StudentProgressRefreshed`, y solo para los periodos abiertos que no son anteriores al actual (`ForecastTargetsQuery`). Al terminar despacha `Demand\Events\DemandForecastsComputed` (periodo, modalidad, campus y carreras), que AcademicLoad ya escucha para las propuestas (`SyncProposalsOnForecastsComputed`).
  - `Demand/Rules/DemandSettings.php`: `demand.highlight_threshold` (20 por omisión, editable por Subdirección y el admin, DM-26) y `isLargeDifference()`, que es simétrica y no dice si sobra o falta. `TermCode::previousRegular()` da el periodo ordinario anterior.
  - `Demand/Queries/TermOccupancyQuery.php` cuenta la ocupación real de un periodo desde el kárdex (DM-02). La evaluación de la 5.9 (`AcademicLoad/Actions/EvaluateForecast`) ya compara proyección contra realidad dentro de AcademicLoad: es el precedente de forma para la 8.2.
- **Kárdex:** la plantilla `kardex` (`Imports/Templates/KardexTemplate.php`) hace upsert por número de control, materia y periodo, así que importar las calificaciones del semestre actual cambia filas `cursando` a `aprobada` o `reprobada`. Al terminar el lote despacha `RecomputeStudentProgress`, y `ApplyImportBatch` avisa con `Imports\Events\BatchApplied` (lleva el lote, con `template`, `status` y `applied_at`, y los conteos; no dice qué periodos tocó). La cadena en cola es: `BatchApplied` → avance de alumnos → `StudentProgressRefreshed` → `ComputeDemandForecasts` → `DemandForecastsComputed`.
- **Mapa de módulos** (`docs/dominio/modelo-de-datos.md`, `tests/Arch/ModulesTest.php`): AcademicLoad puede depender de Academic, CatalogValidation y Demand y, transitivamente, de **Imports, Students, Faculty, Facilities y Core**. No puede depender de Scheduling, Solver ni Staffing, que están a su derecha. Staffing y Solver cuelgan los dos de Scheduling y **ninguno puede depender del otro**.
- **Motor (fase 6, `develop`):** `Solver/Engines/SolverEngine`, `FixedEngine` (motor de prueba), `Solver/Queries/BuildSolverInput` y el contrato v1 (`Solver/Contract/`). `GroupInput.movable` ya distingue los grupos que se mueven de los fijos, `Scope` es `department` o `career` (`RunScope`), y las corridas se guardan en `solver_runs` (6.2). Falta el evaluador en memoria (6.5), la heurística (6.6 y 6.7), la corrida en cola (6.8) y el cliente de CP-SAT (7.9). El servicio Python tiene su esqueleto (7.1 a 7.3, #560).
- **Scheduling para sugerencias e impacto:** `ScheduleValidator::checkSession()` y `checkSessions()` validan una sesión candidata contra el estado vivo sin escribir; `RoomSuggestionQuery` ordena salones por SC-03; HC-05 y HC-07 están en `Rules/Constraints/` y `QuotaRules`.
- **Solicitudes de cambio (3.11, #304, #314):** `change_requests` ya trae la columna `impact` (jsonb, nula) y vive en Staffing (`Staffing/Actions/ChangeRequests/`, pantalla `Staffing/Livewire/pages/change-requests`). El validador de la fase 3 hoy no la llena.
- **Acuerdos (3.6, 3.7):** `interdepartmental_agreements` y sus Actions de proponer, aceptar y rechazar viven en AcademicLoad, pero el préstamo de salón se acepta y se valida con Scheduling, y la pantalla de acuerdos es `Scheduling/Livewire/pages/agreements`.
- **PDF:** DomPDF ya está instalado ([ADR-0021](../decisiones/0021-dompdf-para-los-pdf-publicados.md)) y los PDF publicados se generan en cola desde `AcademicLoad/Exports/`.

### Respuestas del usuario (17 de noviembre de 2026)

1. **Quién inicia y qué lo dispara (confirmado).** El **jefe de departamento** inicia el re-análisis de su carga. Subdirección la ve y puede devolverla, como en el cierre. Lo que lo dispara es importar las **calificaciones del semestre actual**, no un kárdex completo: «más bien se importan las calificaciones del semestre actual, la mayoría del kárdex ya está».
2. **Visto bueno otra vez (confirmado).** Republicar una carga re-analizada en una modalidad que exige el visto bueno de Subdirección (el usuario dijo «Presencial») lo vuelve a pedir, igual que con la versión 1. Ver la pregunta 1 de abajo: las reglas dicen que Presencial no lo exige.
3. **Umbral (confirmado).** Una materia se clasifica como **sobrante**, **faltante** o **ajustada** con el mismo `demand.highlight_threshold` (20 %), que Subdirección edita en «Parámetros».
4. **Avisos al republicar (confirmado).** Nexus avisa a: los docentes afectados (con exactamente lo que cambió para cada uno), los coordinadores de las carreras afectadas y los jefes de otros departamentos cuando se tocan grupos compartidos o préstamos de salón. A Servicios Escolares no.

### Ajustes a las tareas

- **Estado de la carga (8.1 y 8.4).** El plan dice «la máquina de estados ya lo prevé» y que la republicación «regresa a `published`». Con la respuesta 2 el diagrama de hoy está mal, porque `reanalysis → published` salta el visto bueno.
  - Propuesta, por confirmar: `published → reanalysis` (el jefe) → `closed` → `approved` (solo si el perfil de la modalidad lo exige) → `published` con la versión n + 1. `LoadStatus::transitions()` cambia `Reanalysis => [Closed]`.
  - Subdirección devuelve con motivo desde `closed` o `approved`, como en el cierre (`ReopenLoad`). Hoy esa reapertura va a `adjusting`; para un ciclo de re-análisis debería volver a `reanalysis`. La máquina lo distinguiría por la última transición de `load_transitions` hacia `closed` (viene de `reanalysis` o de `staffing`). Decisión por confirmar con el usuario o en la planeación (pregunta 5).
  - Se actualiza el diagrama de `docs/proceso/proceso-propuesto.md` y la regla en «Cierre y reapertura de la carga», en el mismo PR de la 8.1.
  - Además, hoy tres piezas suponen los estados de un ciclo normal y hay que ajustarlas para un cierre desde `reanalysis`: `Scheduling/Listeners/BlockClosingWithPendingGroups` (`from === Staffing` para HC-15, y `closed|approved → published` para volver a revisarlo; falta `reanalysis → closed`), `Scheduling/Rules/LoadEditability::EDITABLE` (solo `proposed`, `staffing` y `adjusting`: en `reanalysis` no se edita ni una sesión, un cupo ni un salón) y `Staffing/Listeners/QueueTeacherLoadRefresh` (solo refresca las cargas en `proposed`, `staffing` y `adjusting`). El texto de `reanalysis` en `Staffing/Queries/LoadClosing.php` dice «Al terminar se publica otra versión» y habrá que ajustarlo.
- **8.1, `StartReanalysis`:** Action de AcademicLoad con permiso nuevo (`academic_load.load.start_reanalysis`, del jefe de su departamento y su modalidad; Subdirección ve y devuelve). Reemplaza «el coordinador o jefe la inicia» del plan y la fila «Re-análisis» de la matriz de permisos (el papel del coordinador queda en la pregunta 4).
  - **Qué evento.** La respuesta 1 pide el contenido, calificaciones del semestre actual, y ni `BatchApplied` ni `StudentProgressRefreshed` lo dicen: el primero lleva el lote de la plantilla `kardex` sin sus periodos, y el segundo también se dispara al importar alumnos, materias o seriaciones y al cambiar una retícula.
  - AcademicLoad **sí puede escuchar `Imports\BatchApplied`** (Imports está a su izquierda: Imports → CatalogValidation → AcademicLoad). La nota del punto de partida de la fase 6, «8.1 no puede escuchar `BatchApplied` de Imports, que está a la derecha de AcademicLoad», está equivocada; solo Demand no puede (por eso la 5.4 usa `StudentProgressRefreshed`).
  - Lo que mejor encaja con «calificaciones del semestre actual» es que el evento solo **avise** al jefe y que la guarda sea una consulta, no una bandera guardada. `BatchApplied` con `template = kardex` es la señal que dice «kárdex». Pero sale antes de que se recalcule el avance y la proyección (la cadena de arriba es asíncrona), así que habilitar el botón ahí dejaría al jefe comparando contra una proyección vieja. La guarda debería comprobar tres cosas: que un lote de kárdex se aplicó después de `published_at` de la versión vigente; que el periodo de las calificaciones, `TermCode::previousRegular()` del periodo de la carga (DM-01), tiene filas ya no `cursando` en la modalidad; y que `demand_forecasts.computed_at` del periodo y modalidad es posterior a ese lote. El aviso «ya puedes re-analizar» puede salir de `DemandForecastsComputed`, que AcademicLoad ya escucha. La decisión de cuál escuchar es de la 8.1; esta es la propuesta.
  - Es la carga del **periodo siguiente** la que se re-analiza (la 8.11: `20273` con las calificaciones de `20271`); la del periodo en curso no.
  - Sin tocar el snapshot publicado: público y docentes siguen viendo la última versión (regla de «Reapertura» y P8).
  - **Hecho (#600):** cancelar el re-análisis sin cambios (RA-10). `LoadStatus` agrega `reanalysis → published`; `CancelReanalysis` (policy `cancelReanalysis`, mismo permiso que iniciar) lo toma solo si `ReanalysisChangesQuery` (grupos de ahora, por `LoadSnapshotRequested`, contra la última versión con una comparación estricta: tipo de sesión y justificación también cuentan) no encuentra cambios, sin versión nueva ni avisos de republicación; `PublishLoad` rechaza publicar desde `reanalysis`. La pantalla viene después.
- **8.2, `ReanalysisDiffQuery`:** en AcademicLoad, lee `demand_forecasts` de Demand (a su izquierda) y el `payload` de la última versión publicada.
  - La llave es la de la demanda: periodo, modalidad, **campus**, carrera y materia (no «por grupo y materia»). La oferta de cada fila sale de los renglones `placements[]` de los grupos del campus: la suma de `seats` de esa carrera y esa materia y el número de grupos. La demanda es `expected_students` y `suggested_groups`.
  - Clasificación con `DemandSettings::highlightThreshold`: **faltante** si lo esperado supera la oferta en el umbral o más; **sobrante** si la oferta supera lo esperado en el umbral o más; **ajustada** en otro caso. Si no se espera a nadie y hay cupo, sobrante; si se espera y no hay oferta, faltante. `isLargeDifference()` no sirve tal cual porque no da el signo; se agrega a `DemandSettings` un método con signo que use el mismo umbral, para no duplicarlo.
  - Se calcula al leer, sobre la proyección vigente y la última versión; no se guarda. Así, si llega otra importación a mitad del re-análisis, el diff se pone al día solo y lo ya aplicado se queda.
  - Una materia con `needs_review` o sin historia suficiente (`history_terms_used`) se marca, como en la propuesta de carrera. La rapidez pedida (menos de 3 s para una carga de Campus II) se mide aquí.
  - **Hecho (tarea 8.2, #584):** `AcademicLoad/Queries/ReanalysisDiffQuery::forLoad(AcademicLoad $load, ?array $careerIds = null): ReanalysisDiff`.
    - Fila (`ReanalysisRow`), una por campus × carrera × materia de las materias del departamento de la carga en su periodo y modalidad: `campusId/Code/Name` (nulos sin salones), `careerId/Code/Name`, `subjectId/Code/Name`, `semester` (el del plan vigente, o el del renglón del snapshot), `forecasted` (falso: oferta sin demanda), `expectedStudents`, `suggestedGroups`, `newStudents`, `offeredSeats` y `offeredGroups` (suma de `seats` de la carrera y número de grupos de la materia en el campus, de la última versión), `isFirstSemester`, `balance` (`Demand\Enums\DemandBalance`: `Shortfall`, `Surplus`, `Adjusted`; nulo en primer semestre, RA-09), `needsReview`, `insufficientHistory` (método `department_fallback` o `no_history`) y `computedAt`. `gap()` da esperados menos ofrecidos.
    - `ReanalysisDiff`: `rows` (ordenadas por campus, carrera y materia), `totals` (`shortfall`, `surplus`, `adjusted`, `unclassified`), `snapshotVersion` y `forecastComputedAt`. `$careerIds` filtra la proyección y la oferta (RA-07); quien llama decide quién ve qué.
    - Clasificación: `DemandSettings::balanceOf(int $expected, int $offered): DemandBalance`, con la misma base y el mismo umbral que `isLargeDifference()` (lo esperado es la base: la diferencia es el umbral o más de lo esperado, RA-04). Nadie esperado y con oferta sobra; esperados y sin oferta falta.
    - Cuatro consultas fijas (proyección, última versión, semestre en el plan vigente y parámetros), sin importar el número de filas; la prueba cuenta consultas con 360 filas.
- **Pantalla (#602, opción B «Por materia» de `.impeccable/mocks/reanalisis/`, elegida por el usuario el 17 de noviembre de 2026):** `scheduling::pages.reanalysis` (`/scheduling/reanalysis`, «Re-análisis de la carga» en Cierre y publicación). Inicia (StartReanalysis) y cancela (CancelReanalysis, RA-10) el re-análisis, muestra el diff de la 8.2 por materia y campus (`Scheduling/Queries/ReanalysisBoardQuery`, con lo ya cambiado de `ReanalysisChangesQuery`) y lleva a «Cierre de la carga» para cerrarlo. El coordinador ve solo sus carreras, sin acciones (RA-07). El porcentaje de la diferencia es de lo esperado, la base de RA-04.
  - **Alcance por carga y consultas fijas (#620):** el alcance se decide por carga, no por usuario (`ReanalysisLoadsQuery::careerScopeOf()`): quien ve completa la carga de un departamento (`load.view`) y además ve la propuesta de carreras que se imparten en otra (`proposal.view`, por ejemplo Subdirección nombrada en una unidad) ve la primera completa y la segunda filtrada a esas carreras, sin acciones ni sugerencias, y el select «Carga» lista las dos; el coordinador de una carrera que no se imparte en la carga recibe 403. `ReanalysisDiff::blockGroups` da los grupos de cada bloque sin volver a leer la foto, y `ReanalysisBoardQuery::data()` arma lo que no depende de los filtros (diff, umbral y cambios) una vez por petición; `of()` aplica los filtros sobre eso. Una prueba cuenta consultas con más materias, campus y carreras.
  - **Lugar para la 8.3 (Riel B):** cada bloque abre el drawer `scheduling::components.reanalysis-suggestions` con el evento `open-reanalysis-suggestions` (`subjectId`, `campusId`, nulo sin campus). Hoy pinta la demanda por carrera del bloque (`ReanalysisBoardQuery::block()`) y la vista `scheduling::reanalysis.suggestions` vacía. La 8.3 llena `suggestions()` del componente, pinta cada sugerencia en esa vista y agrega al componente sus métodos de aplicar y descartar, que vuelven a autorizar (hoy lo abre quien ve toda la carga, `view`) y llaman las Actions de la fase 3.
- **8.3, sugerencias:** `AcademicLoad/Rules/ReanalysisAdvisor` **no puede** usar `RoomSuggestionQuery`, el validador ni el motor: son de Scheduling y de Solver, a la derecha de AcademicLoad. Dos formas dentro del mapa, por decidir en la planeación:
  - AcademicLoad declara el contrato (un evento al estilo de `LoadSnapshotRequested`, o una interfaz) y Scheduling lo contesta con las sugerencias que calcula con el validador y `RoomSuggestionQuery`; o
  - la pantalla y el asesor viven en Scheduling, que ya lee AcademicLoad y Demand, como la pantalla de acuerdos.
  - Cerrar un grupo, ampliar un cupo (HC-05, HC-07) y cambiar un salón por capacidad se pueden construir ya, con el validador y `RoomSuggestionQuery`. **Abrir un grupo con horario y salón sugeridos depende del motor** (6.5 y la construcción de la 6.6). Conviene partir la tarea en esos dos pedazos.
  - Aplicar una sugerencia pasa por las Actions de la fase 3 y exige la carga en `reanalysis` (ajuste de `LoadEditability`, arriba).
  - **8.3a en revisión (#619, issue #604; sin abrir grupo):** se eligió la segunda forma: todo vive en Scheduling. `Rules/ReanalysisAdvisor` (puro) propone ampliar un cupo (HC-05, HC-07), cambiar de salón por capacidad, cerrar un grupo sobrante reubicando sus lugares y, si nada alcanza, avisa «Hace falta abrir un grupo» sin acción (8.3b, con el motor). `Queries/ReanalysisSuggestionsQuery` lee los grupos vivos del bloque y los salones más grandes libres a la hora de cada sesión con su propia lógica de salones (no `RoomSuggestionQuery`, que es por sesión y costaría consultas por cada una) en un número fijo de consultas. `Actions/ApplyReanalysisSuggestion` aplica en una transacción con las Actions de la fase 3 y vuelve a autorizar (el jefe, `startReanalysis`, y GroupPolicy por grupo). La fase 3 no tenía Actions para cancelar un grupo ni para cambiar su capacidad: `CancelGroup` es la del #612 (8.8), copiada sin cambios para que las dos ramas no choquen (borra las sesiones, cancela los acuerdos vivos y no cancela un grupo con docente: la sugerencia de cerrarlo se ve con su motivo hasta quitárselo), y se agregó `SetGroupCapacity` (no sube más que el salón más chico, HC-05, ni baja de lo repartido y apartado, HC-07). El drawer `scheduling::components.reanalysis-suggestions` llena `suggestions()` y agrega aplicar, editar los lugares y descartar (solo de la pantalla). Las reglas están en reglas-de-negocio.md, «Sugerencias (tarea 8.3a)», y el diseño en DESIGN.md, «Sugerencias del re-análisis». Pendiente: aplicar varias en lote (la casilla y «Aplicar las N elegidas» de la maqueta).
  - **8.3b en revisión (PR #PR605, issue #605; abrir un grupo):** Scheduling no puede depender de Solver, así que el drawer pide el horario y el salón con un **evento con respuesta**, como `LoadSnapshotRequested`: `Scheduling/Events/NewGroupPlacementRequested` (la carga y `NewGroupSpec`) lo despacha `Scheduling/Queries/NewGroupPlacementQuery`, y lo contesta `Solver/Listeners/ProvideNewGroupPlacement` con `Solver/Queries/NewGroupSlotQuery`: arma la entrada con `BuildSolverInput::forDepartment()`, deja todos los grupos fijos, agrega el grupo nuevo virtual (`new:<materia>:<campus>`, armado aparte en `virtualGroup()` para pasar a `forGroups()` de la 8.7 cuando exista) y corre solo la construcción de `PhpHeuristicEngine` (`search: null`). Responde con `NewGroupPlacement` (sesiones con salón, puntaje blando que agrega y por regla, evidencia y motivos si no cabe). El asesor da una sugerencia «Abrir un grupo» por carrera faltante (`open_group:<carrera>`) con el grupo nuevo (`NewGroupSpec`: capacidad de `Rules/GroupCapacity`, que ahora comparte `CreateGroupsFromProposal`; el plan vigente y el semestre de la carrera; el cupo, lo que falta hasta la capacidad). **Tiempo:** 0.72–0.86 s con la demo masiva (carga de CEA en re-análisis: 1 317 grupos en la entrada y 426 salones; armar la entrada se lleva unos 0.65 s y colocar el grupo unos 0.1 s; 26 a 28 consultas; el faltante real de la demo, ACA-0909 en C2 para IGEM, en 0.79 s), así que va en la petición al buscar en la tarjeta («Buscando lugar…»), nunca al pintar, sin cola (CLAUDE.md §4.7). `Actions/OpenReanalysisGroup` lo crea con su cupo y sus sesiones (`ReplaceGroupSessions`) en una transacción, revisa con los candados que nada cambió y deja la asignación del docente a Staffing. Reglas en reglas-de-negocio.md, «Abrir un grupo».
- **8.4, `RepublishLoad`:** ya no es una Action nueva. Republicar es cerrar, el visto bueno si la modalidad lo exige y `PublishLoad`, que ya sube la versión, revisa HC-15 y escribe el historial docente; el plan lo decía casi así («reutiliza `PublishLoad`»). Lo que se agrega:
  - el **diff guardado contra la versión n**: una pieza pura `AcademicLoad/Rules/SnapshotDiff` que compara dos payloads normalizados (grupos nuevos, cancelados, docente, salón, horario y cupos por carrera). Se guarda en una columna `diff` (jsonb, nula en la versión 1 y en las anteriores) de `load_snapshots`, escrita al crear la fila, para no tocar la inmutabilidad; migración nueva y `modelo-de-datos.md` en el mismo PR. `LoadVersionsQuery::changes()` pasa a leerlo;
  - el **motivo de la versión** (`published`, `reanalysis` o `correction`; enum `SnapshotReason`), en la misma migración, para la 8.10: hoy solo hay el comentario de la transición. Sale de la ruta (si se publicó después de un ciclo de `reanalysis` o después de `adjusting`);
  - los **avisos**, según la respuesta 4, en un listener de `LoadTransitioned` a `published` con `version > 1`, en cola, como `NotifyOnLoadClosingTransition`:
    - docentes: quien está en la versión n o en la n + 1 de un grupo que cambió (también el que salió de un grupo), cada uno con su lista de cambios; el docente se resuelve con `teachers.user_id` (Faculty, a la izquierda);
    - coordinadores: los de cada carrera con renglón en `placements[]` de un grupo que cambió, por permiso, modalidad y carrera (`RecipientsQuery`);
    - jefes de otros departamentos: los dueños de salón (`room.owner_department_id` distinto del departamento del grupo) de una sesión prestada que cambió, y los de la carrera de un grupo compartido cuyo cupo cambió. Todo sale del payload; el departamento de una carrera se lee de Academic.
  - No hay aviso a Servicios Escolares (respuesta 4).
  - **Hecho (tarea 8.4):** sin Action nueva; `PublishLoad` guarda `diff` y `reason` en la misma transacción (migración `2026_11_17_194410_add_diff_and_reason_to_load_snapshots`), `ReanalysisCycleQuery` da el motivo (la comparte con `ReopenLoad`), `NotifyAffectedOnRepublication` (listener en cola de `LoadTransitioned`) llama a `NotifyRepublication`, que avisa con `LoadRepublished` (campana y correo, `MailKinds`), y `LoadVersionsQuery` expone `reason`, `summary` y `diff()`. Los supuestos de los avisos están en reglas-de-negocio.md, RA-06 «Cómo se avisa». El contrato del diff para la 8.5 y la 8.10 está abajo, en «Contrato del diff (v1)».
- **8.5, versión en la consulta y los exportes:** la consulta pública ya dice «Actualizado el …»; falta la versión y el resumen de «qué cambió», que sale del `diff` guardado. Los PDF (4.5) ya dicen «Actualizado el …» en su pie; falta agregar la versión, también al Excel. Lo que ve el público es lo que ve un alumno (grupos, horarios y salones), nunca la justificación ni los préstamos internos.
- **8.6, `AssessChangeImpact` (Staffing):** el caso simple (empalmes que deja el cambio, grupos y docentes afectados) se puede hacer ya con `ScheduleValidator::checkSession()`, que está en Scheduling y Staffing puede importar. La «cadena de movimientos» y cualquier búsqueda dependen del evaluador en memoria (6.5) y de los movimientos de la búsqueda local (6.7). `impact` ya existe en `change_requests`. El plan dice «Reutiliza el evaluador de la fase 6»: si el evaluador queda en Solver, Staffing no lo puede importar (ver 8.7).
- **8.7, alternativas del motor:** `Staffing/Livewire/pages/change-request` (el nombre real es `change-requests`) **no puede llamar a `SolverEngine`**: Staffing y Solver son hermanos del mapa. Hay que decidir (arquitectura, no negocio; pregunta 6):
  - agregar la arista `Solver --> Staffing` al mapa (Staffing pasa a depender de Solver; el motor sigue sin leer Staffing, que es lo que quería la fase 6) con su ADR, o
  - dejar la Action en Solver y que Staffing la llame por un contrato declarado en Scheduling.
  - «Alcance mínimo, lo demás fijo» **no pide cambiar el contrato v1**: `GroupInput.movable` ya existe. Falta un armador `BuildSolverInput::forGroups()` que marque movibles solo los grupos afectados y fije el resto, y decidir si esa corrida se guarda en `solver_runs` (`RunScope` solo tiene `department` y `career`). `solver.md` se actualiza si se agrega un alcance.
  - Depende del motor (6.5 y 6.6; 6.7 mejora las alternativas) y, para el tiempo de respuesta, del `CpSatEngine` (7.9). Hasta entonces solo está el motor de prueba, que no revisa reglas duras (`checksHardRules()` es falso) y no sirve para ofrecer alternativas sin choques.
- **8.8 y 8.9, junta y acta:** el plan los pone en `AcademicLoad/Livewire/pages/interdepartmental-meeting` y `AcademicLoad/Exports/MeetingMinutes`, pero necesitan grupos, salones y préstamos, que son de Scheduling. Como la pantalla de acuerdos, van en **Scheduling** (que lee AcademicLoad y Demand). La demanda por carrera sale de `demand_forecasts` con la llave completa. Archivar el acta «en la carga» necesita una tabla o un archivo propio del acta; se define al planear. El PDF reutiliza DomPDF.
- **8.10, tablero de versiones:** extiende `LoadVersionsQuery` y el componente `publish-load`. Con el `diff` y el `motivo` guardados (8.4), solo lee.
  - **Hecho (tarea 8.10, #586):** `academic-load::pages.versions` (`/academic-load/versions`, entrada «Versiones publicadas» en «Cierre y publicación», después de las del cierre y antes de «Horarios publicados»), maqueta C «Línea y detalle» (DESIGN.md «Versiones publicadas»). La ve quien ve la carga (`academic_load.load.view`). `LoadVersionsQuery::board()` da cada versión con su motivo (deducido en las de antes de la 8.4: la 1, publicación; las que siguen a una salida de publicada hacia `reanalysis`, re-análisis; las demás, corrección; el diff calculado de esas se guarda en caché por el id de su foto), el resumen (calculado con `diff()` en esas), la reapertura que la originó y sus docentes; `loadsWithVersions()` las cargas del selector. `Rules\VersionChanges` lee el diff por tipo y por docente o carrera (con las líneas de `SnapshotDiffText`, lo que dijeron los avisos). El punto de la línea es un componente compartido con `publish-load` (`x-academic-load::version-dot`).
  - **Archivos por versión: no existen, a propósito (confirmado el 17 de noviembre de 2026).** Los exportes (PDF del departamento y Excel de la modalidad) se arman con la última versión de cada carga (`PublishedScheduleQuery::snapshotIds()`), así que el tablero los enlaza solo en la versión vigente y en las anteriores dice que no se ofrecen. El público ve «Actualizado el …» y una marca «Cambió» sin número de versión; las versiones son solo para el personal con sesión, que descarga únicamente la vigente. La 8.5 no agrega la versión a los exportes.
- **8.11, ensayo:** corre con la carga de `20273` y las calificaciones de `20271`, como dice el plan. Necesita la publicación real de R1 (4.10) o, en la demo, `--hasta=reanalisis`.

### Respuestas del usuario (17 de noviembre de 2026, segunda ronda)

1. **Visto bueno al republicar:** la respuesta «como la primera vez» se aplica según el perfil de la modalidad (RA-03). La pregunta mencionó Presencial por error del Riel A: hoy Presencial no exige visto bueno (`requires_subdireccion_approval = false`, reglas del 10 de noviembre), así que su versión nueva se republica como la 1.
2. **Avisos:** por la campana y también por correo institucional, sobre todo a los docentes, que se tienen que enterar aunque no entren a Nexus (confirmado).
3. **Ventana del calendario:** el calendario solo informa y nada se rechaza por fecha (regla del 1 de noviembre), así que iniciar el re-análisis no exige estar en la ventana de la etapa.
4. **El coordinador solo ve el diff de su carrera.** Los ajustes (abrir, cerrar o mover grupos, cupos y salones) los hace el jefe del departamento dueño de la carga (confirmado).
5. **Devolver una carga re-analizada la regresa a `reanalysis`.** Mientras tanto sigue vigente la versión publicada anterior (confirmado).
6. **Primer semestre:** sus materias se muestran con el nuevo ingreso capturado, pero no se clasifican como sobrante, faltante o ajustada, porque el kárdex nuevo no dice nada de los que van a entrar (confirmado).

### Respuestas del usuario sobre la junta (17 de noviembre de 2026, vía el Riel B)

1. **La junta interdepartamental desaparece.** En palabras del usuario: «Mi intención es que esa junta ya no se realice, ya que todo lo que se acuerda ahí ya se puede hacer en el sistema. Si varias carreras piden un grupo para una materia de cálculo, por ejemplo, el jefe de básicas las ve y crea los paralelos considerando la demanda de cada dpto y los junta en menos grupos». El trabajo es asíncrono, en Nexus (confirmado).
2. **No hay acta:** la 8.9 se cae (confirmado).
3. **Los cupos de los grupos compartidos son proporcionales a la demanda:** Nexus los sugiere con los alumnos esperados de cada carrera, el jefe los ajusta y la suma nunca pasa la capacidad (HC-07) (confirmado).

Con eso, la **8.8 se redefine** (Riel B): desde la bandeja del departamento, el jefe dueño de la materia ve lo que piden todas las carreras y arma los paralelos de verdad: junta las peticiones en menos grupos, con cupos por carrera proporcionales a la demanda. Hoy la bandeja solo muestra la sugerencia (`ParallelGroups`) y los grupos mixtos se arman a mano con `ProposeSharedGroup` y `AcceptSharedGroup` (fase 3). Toca `CreateGroupsFromProposal` (Scheduling), que crea los grupos por fila al aceptar las partes; se coordina con el Riel A al planear.

### Pregunta abierta (arquitectura)

- **Dónde viven las alternativas del motor (8.6 y 8.7).** Staffing y Solver son hermanos en el mapa y no pueden depender uno del otro. Hay que decidir si se agrega la arista `Solver --> Staffing`, o si Staffing pide las alternativas con un evento o un contrato de Scheduling. Se decide al empezar la 8.6 (Riel C) y se registra en `docs/decisiones/`.

### Reparto propuesto

El del plan (A: 8.1, 8.2, 8.4 y 8.10; B: 8.3, 8.5, 8.8 y 8.9; C: 8.6 y 8.7), con estas dependencias:

- **A** abre la fase con la máquina de estados (8.1) y el `SnapshotDiff` con su migración (8.4), porque B (8.3 y 8.5) lee el estado `reanalysis` y el diff. Conviene publicar primero el contrato de `SnapshotDiff` y de la columna `diff`, como se hizo con el payload del snapshot en la fase 4.
- **B** puede empezar la 8.8 redefinida (paralelos en la bandeja; la 8.9 se cayó) sin esperar al motor; la 8.3 parte en dos: cerrar, ampliar y cambiar salón dependen de la 8.1 y la 8.2, y abrir grupo espera el motor (6.5, 6.6). La 8.5 espera el `diff` de A.
- **C** hace la 8.6 simple ya; la cadena de movimientos y la 8.7 esperan 6.5 a 6.7 y la decisión de arquitectura (pregunta 6). C no depende de A ni de B.
- **Se terminan antes de la 8.11:** 6.5 a 6.7 (otro riel) y, para el tiempo, 7.9. Si la heurística llega tarde, la 8.3 sin «abrir grupo» y la 8.6 simple entregan lo esencial.

### Contrato del diff (v1) (tarea 8.4)

Para la 8.5 (Riel B) y la 8.10. `load_snapshots.diff` (jsonb, nulo en la versión 1) lo escribe `PublishLoad` con `AcademicLoad\Rules\SnapshotDiff::between()` al publicar la versión n + 1, contra la n, con los dos payloads normalizados. La fuente es el docblock de `SnapshotDiff`; esto es un resumen:

```json
{
  "version": 1,
  "groups": {
    "added": [Grupo],
    "cancelled": [Grupo],
    "changed": [{
      "group": Grupo,
      "changes": {
        "schedule": {"from": [{"day": 1, "starts_at": "07:00", "ends_at": "09:00"}], "to": [{"day": 3, "starts_at": "07:00", "ends_at": "09:00"}]},
        "teacher": {"from": {"id": "…", "name": "Ana Ruiz"}, "to": null},
        "room": {"from": [{"day": 1, "starts_at": "07:00", "ends_at": "09:00", "room": "A-101"}], "to": [{"day": 1, "starts_at": "07:00", "ends_at": "09:00", "room": "A-102"}]},
        "quota": {"from": 30, "to": 35},
        "career_quotas": {"from": [{"id": "…", "code": "ISC", "seats": 30}], "to": [{"id": "…", "code": "ISC", "seats": 25}, {"id": "…", "code": "IND", "seats": 10}]}
      }
    }]
  },
  "summary": {"added": 0, "cancelled": 0, "changed": 1, "teachers_affected": 1, "careers_affected": 2, "departments_affected": 1}
}
```

Grupo: `{"id", "code", "subject": {"id", "code", "name"}, "campus": "C2" | null, "teacher": {"id", "name"} | null, "careers": [{"id", "code", "department_id" | null, "is_owner"}], "sessions": [{"day", "starts_at", "ends_at", "room": clave | null}], "other_departments": [uuid]}`.

- La identidad de un grupo entre versiones es `groups[].id` del payload (el `groups.id` de Scheduling). El Grupo de un nuevo o un cambiado es como quedó; el de un cancelado, lo que tenía. `careers` son las carreras con lugares en alguna de las dos versiones, la dueña primero.
- En `changes` solo van las llaves que cambiaron. `schedule` compara días y horas; `room`, el salón de cada sesión con el mismo horario (si el horario cambió, el conjunto de salones), y trae las sesiones con su salón; `quota` es la capacidad del grupo; `career_quotas`, los lugares por carrera, una vez por carrera. El tipo de sesión, la justificación y los nombres no cuentan.
- `other_departments` es interno (préstamos de salón y grupos compartidos, RA-05): la consulta pública no lo muestra, igual que los préstamos. Lo público de un cambio son grupos, horarios y salones; nunca cupos, lugares ni justificaciones (reglas «Consulta publicada»).
- Para leerlo en español, `Rules\SnapshotDiffText` (una línea por grupo); para los afectados, `SnapshotDiff::teachers()`, `careers()` y `departments()`. `LoadVersionsQuery::versions()` trae `reason` y `summary`, y `diff()` el diff completo de una versión (o calculado al leer si la versión es de antes de la 8.4).
- Para la vista pública (8.5), `SnapshotDiffText::publicEntries($diff)` da las mismas líneas sin lo interno: sin cupo total ni lugares por carrera (el público nunca ve cupos ni lugares, «Consulta publicada»), departamentos ni llaves; conserva grupos nuevos y cancelados, horario, salón y docente.
- PostgreSQL no conserva el orden de las llaves de un objeto `jsonb` (sí el de las listas): no dependas de él. Una forma nueva sube `version`.

## Alcance

### Incluye

- Etapa `reanalysis` en `AcademicLoad` con sugerencias, aplicación validada, nueva versión del snapshot, diff visible y republicación.
- Impacto calculado en `change_requests` con alternativas del motor.
- Pantalla de junta interdepartamental digital con acta en PDF.
- Tablero de versiones publicadas.

### No incluye

- Inscripción (fase 9): el re-análisis prepara la carga para las citas, no las ejecuta.
- Cambios en la fórmula de demanda (fase 5) ni en los motores (fases 6 y 7).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 8.1 | Transición `published` → `reanalysis`: Action que se habilita cuando se aplica una importación de kardex del periodo que termina (evento `BatchApplied` de plantilla `kardex`) y el coordinador o jefe la inicia; en `reanalysis` la carga vuelve a ser editable con el validador, sin tocar el snapshot publicado | AcademicLoad | `AcademicLoad/Actions/Transitions/StartReanalysis`. La máquina de estados ya lo prevé; aquí se implementan guardas y permisos. | Unit: guardas; feature: solo tras importación nueva, registra `load_transitions` | A |
| 8.2 | Diff de demanda: por grupo y materia, comparar la demanda recalculada por `Demand` (elegibles y esperados con el kardex nuevo) contra la capacidad y cupos del snapshot publicado; clasificar como sobrante, faltante o ajustado dentro de un umbral configurable | AcademicLoad, Demand | `AcademicLoad/Queries/ReanalysisDiffQuery`. Es la pregunta concreta del re-análisis: dónde cambió la demanda respecto a lo publicado. | Unit con snapshot y pronóstico de fixture: clasificación por umbral | A |
| 8.3 | Sugerencias de ajuste: a partir del diff, propuestas de abrir grupo (con horario y salón sugeridos por el motor), cerrar grupo (con reubicación de cupos), ampliar cupo (si el salón lo permite, HC-05 y HC-07) o cambiar salón por capacidad (con `RoomSuggestionQuery`); cada sugerencia se acepta, edita o descarta | AcademicLoad, Scheduling, Solver | `AcademicLoad/Rules/ReanalysisAdvisor`. Las sugerencias se presentan con la evidencia (demanda, ocupación, salones libres); aplicar cualquiera pasa por las Actions de la fase 3 y el validador. | Feature: cada tipo de sugerencia aplicada deja la carga sin choques duros o se rechaza con mensaje | B |
| 8.4 | Nueva versión y republicación: Action `RepublishLoad` que exige cero choques duros, genera `load_snapshots` con la versión siguiente, calcula el diff contra la versión anterior (grupos nuevos, cancelados, cambios de horario, docente, salón y cupos) y regresa a `published`; notifica a docentes afectados | AcademicLoad | Reutiliza `PublishLoad` de la fase 4 con versión incremental. El diff guardado es lo que ven docentes y alumnos como "qué cambió". | Feature: versión incrementa, diff correcto, notificaciones solo a afectados | A |
| 8.5 | Vista pública y exportes con versión: la consulta publicada muestra la versión vigente y un resumen de cambios respecto a la anterior; los PDF y Excel llevan versión y fecha | AcademicLoad | Extiende las páginas y exportes de la fase 4. | Feature: cambios listados; browser: consultar "qué cambió" | B |
| 8.6 | Impacto de solicitudes de cambio: al crear o revisar una `change_request`, simular el cambio propuesto con el evaluador en memoria y listar empalmes resultantes, grupos y docentes afectados y la cadena de movimientos necesaria para dejarlo sin choques; guardar en `impact` | Staffing, Scheduling, Solver | `Staffing/Actions/AssessChangeImpact`. Reutiliza el evaluador de la fase 6; no escribe nada. Hoy el jefe aprueba a ciegas (D5). | Unit: cambio sin impacto, con un empalme, con cadena de dos movimientos | C |
| 8.7 | Alternativas del motor: para una solicitud con impacto, pedir a `SolverEngine` (alcance mínimo: los grupos afectados, el resto fijo) dos o tres acomodos alternativos sin choques; el jefe elige uno, lo rechaza o aprueba el original sabiendo el impacto; la aplicación sigue el flujo `adjusting` de la fase 3 con las sesiones propuestas precargadas en el editor | Staffing, Solver | `Staffing/Livewire/pages/change-request` extendida. Convierte la solicitud en una decisión informada en una sola pantalla. | Feature: alternativas sin choques; elegir una precarga el editor; browser: aprobar con alternativa | C |
| 8.8 (redefinida el 17 de noviembre de 2026: paralelos en la bandeja del departamento, sin junta; ver el punto de partida) | Junta interdepartamental digital: pantalla por periodo y campus con las materias compartidas entre carreras y departamentos, demanda por carrera (de `Demand`), grupos propuestos, cupos por carrera sugeridos (proporcionales a la demanda, ajustables), préstamos de salón pendientes y aceptados; desde ahí se crean y aceptan `interdepartmental_agreements` | AcademicLoad, Demand, Scheduling | `AcademicLoad/Livewire/pages/interdepartmental-meeting`. La junta deja de ser verbal (D7): cada acuerdo nace registrado y el validador lo respeta (HC-07, HC-10). | Feature: cupos sugeridos suman la capacidad; acuerdos creados por alcance; browser: aceptar un préstamo | B |
| ~~8.9~~ (se cae: el usuario no quiere acta, 17 de noviembre de 2026) | Acta de la junta: PDF generado desde los acuerdos `accepted` del periodo y campus, con asistentes (usuarios que aceptaron), fecha y detalle de cupos y préstamos; archivado en la carga y descargable | AcademicLoad | `AcademicLoad/Exports/MeetingMinutes`. Reutiliza la generación de PDF de la fase 4. | Feature: el acta contiene exactamente los acuerdos aceptados | B |
| 8.10 | Tablero de versiones: por carga, lista de versiones publicadas con fecha, autor, motivo (publicación, re-análisis, corrección), resumen del diff y descarga de los exportes de cada versión | AcademicLoad | `AcademicLoad/Livewire/pages/versions`. Trazabilidad completa de lo que vieron docentes y alumnos en cada momento. | Feature: versiones ordenadas, diff accesible | A |
| 8.11 | Ensayo con datos reales: re-analizar la carga publicada de `20273` de Campus II con el kardex de cierre de `20271`, aplicar sugerencias con coordinadores y jefes, republicar y generar el acta de la junta del periodo | Todos | Es la primera vez que el proceso completo corre en Nexus de principio a fin. | Lista de incidencias cerrada; versión 2 publicada | A, B, C |

## Pruebas de la fase

- **Unit:** guardas de re-análisis, clasificación del diff, impacto de cambios, cupos sugeridos.
- **Feature:** sugerencias aplicadas con validador, republicación con versión y diff, alternativas del motor, acuerdos y acta.
- **Browser:** re-analizar y republicar; aprobar una solicitud eligiendo una alternativa; aceptar un préstamo desde la junta.
- **Rendimiento:** diff de demanda y simulación de impacto en menos de 3 segundos para una carga de Campus II.

## Criterios de aceptación

1. Tras importar el kardex de cierre, un coordinador ve dónde cambió la demanda respecto a lo publicado y aplica ajustes sugeridos sin producir choques duros.
2. La republicación genera una nueva versión con diff legible, y la consulta pública y los exportes la reflejan.
3. Toda solicitud de cambio muestra su impacto y al menos una alternativa sin choques antes de que el jefe decida.
4. La junta del periodo se realiza con la pantalla de Nexus y termina con acuerdos registrados y un acta en PDF.
5. CI verde con cobertura mínima de 80 % en lo nuevo de `AcademicLoad` y `Staffing`.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | AcademicLoad (re-análisis, versiones) | 8.1 → 8.2 → 8.4 → 8.10 |
| B | AcademicLoad (sugerencias, junta, acta, vista pública) | 8.3 → 8.8 → 8.9 → 8.5 |
| C | Staffing y Solver (impacto y alternativas) | 8.6 → 8.7 |

## Riesgos y notas

- El re-análisis ocurre en la ventana más corta del calendario (entre calificaciones finales y citas); las pantallas deben permitir aplicar varias sugerencias en lote, no una por una.
- Republicar cambia horarios ya vistos por docentes: la notificación debe decir exactamente qué cambió para cada uno.
- Los cupos sugeridos en la junta son una propuesta; la decisión sigue siendo de los jefes y queda registrada con quién la aceptó.
- Si la fase 7 no está lista, las alternativas del motor usan la heurística de la fase 6; el contrato es el mismo.
