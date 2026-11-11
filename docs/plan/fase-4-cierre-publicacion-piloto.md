# Fase 4 — Cierre, publicación y piloto Campus II

**Entrega:** R1 · **Ventana:** 30 nov – 18 dic 2026 · **Modo:** 3 en paralelo · **Estado:** ⬜ · **Milestone:** `Fase 4 — Cierre, publicación y piloto`

## Objetivo

Cerrar la carga de cada departamento de Campus II con todas sus condiciones verificadas, obtener el visto bueno si aplica, publicar una versión consultable y exportable, y operar el piloto con usuarios reales hasta el inicio del semestre. Al terminar, hito H5.

## Dependencias

- Fase 3: carga capturada sin choques duros.
- Servidor de despliegue listo (tarea 0.14).
- Formato de importación del SII para cargas (pedir a sistemas del ITCJ en noviembre).

## Punto de partida (9 de noviembre de 2026)

Esta sección se escribió al cerrar la sesión de los tres rieles de la fase 3, para retomar la fase 4 en otra sesión. **Antes de ejecutar** hace falta una sesión corta de planeación (brainstorming y después writing-plans). Esa sesión resuelve con el usuario las preguntas de abajo, ajusta las tareas 4.1 a 4.6 a lo que dice esta sección y reparte los rieles. Las tareas siguen ⬜ porque están detalladas, pero se escribieron antes de que el mapa de módulos y la fase 3 quedaran como están.

### Estado al empezar

- **Fase 3:** las tareas 3.1 a 3.12 están en `develop`. La 3.13 está pendiente por decisión del usuario del 8 de noviembre: es capturar la carga real de Campus II con coordinadores y jefes (hito H4).
  - Las tareas 4.1 a 4.6 y la 4.8 se construyen y se prueban con la demo.
  - La 4.9 y la 4.10 necesitan la 3.13.
  - La 4.7 necesita el servidor.
- **Demo por puntos, hasta `cierre`:** la corrida es `php artisan demo:seed --hasta=cierre --volumen=masivo --force`.
  - Deja en `staffing` cada carga con grupos en su campus, y cada grupo activo con horario, docente y salón en toda sesión presencial, con cero choques duros. Desde ahí arranca la 4.1.
  - Un departamento sin partes en un campus deja su carga en `draft`; por ejemplo, EE y MM en Campus II.
  - Con el volumen demo tarda unos 26 s y con el masivo unos 9 min.
  - Detalle en [testing.md](../arquitectura/testing.md), «Demo por puntos del proceso».
- **PR abiertos:** no queda ninguno de la fase 3. Siguen abiertos los de la 1.16 (#284 y #305, de otra sesión; no se tocan) y sus issues #273 a #282.
- **Issues de la fase:** #54 a #64 (4.1 a 4.11), en el tablero.

### Qué ya existe y se reutiliza

- **Máquina de estados:**
  - `LoadStatus` (AcademicLoad) ya tiene `closed`, `approved` y `published`.
  - `transitions(CampusProfile)` decide si el campus pasa por `approved` (`requires_subdireccion_approval`, `false` por omisión, [ADR-0008](../decisiones/0008-cierre-por-departamento.md)). Ya permite `approved → adjusting`, pero **no** `closed → adjusting`, que es la reapertura de la 4.1.
  - `TransitionLoad` es la única puerta. Lanza `LoadTransitioning`, con el que otro módulo puede vetar dando un motivo, y después `LoadTransitioned`.
  - Las Actions de `Actions/Transitions/` (`ProposeLoad`, `StartStaffing`, `StartAdjusting`, `ReturnToStaffing`) son el modelo para `CloseLoad`, `ApproveLoad` y `PublishLoad`, que todavía no existen.
- **Lo que le falta a un grupo para cerrar (HC-15):**
  - `ConflictBoardQuery` y `GroupBoardStatus` (Scheduling) ya cuentan, por grupo activo, cuatro faltantes: un choque duro, las sesiones, el docente o el salón de alguna sesión presencial. Respetan el perfil del campus (`uses_time_blocks`, `uses_rooms`).
  - Los choques duros los leen de `schedule_conflicts`, una tabla derivada que el job en cola recalcula después, así que puede ir atrasada.
  - El tablero «Choques y avance» (`/scheduling/conflicts`) los muestra por departamento y carrera.
  - `BlockStaffingWithHardConflicts` (Scheduling) es el modelo de guarda: escucha `LoadTransitioning` y veta con un motivo por cada cosa que falta.
- **Edición congelada al cerrar:** `LoadEditability` (Scheduling) solo deja editar con la carga en `proposed`, `staffing` o `adjusting`, según la carga del departamento del grupo.
  - En `closed`, `approved` o `published` ya se bloquean sesiones, salones, cupos y aceptar un grupo compartido. También se bloquea rechazar o cancelar un préstamo, porque quitaría el salón de las sesiones.
  - Asignar o quitar docente y pedir cambios también se niegan fuera de `staffing` o `adjusting` (Staffing).
- **Docente vigente:** es `groups.teacher_id` y solo lo cambia `SetGroupTeacher` (Scheduling; Staffing lo llama, [ADR-0020](../decisiones/0020-staffing-a-la-derecha-de-scheduling.md)).
- **Historial docente:** `teacher_subject_history` (Faculty) ya existe, y `source = nexus` es para lo que escriba la publicación.
- **Excel y PDF:** `openspout/openspout` ya está instalado (lo usa Imports). DomPDF no lo está, y agregarlo lleva un ADR (CLAUDE.md, regla 6).
- **Calendario:** `Stage::Closing` (7, «Cierre y aprobación») y `Stage::Publication` (8) ya existen.
- **Falta:**
  - la migración de `load_snapshots`, que ya está en el modelo de datos;
  - `docs/operacion.md`;
  - el servidor (tarea 0.14, postergada desde el 27 de octubre);
  - el layout del SII para la 4.6.

### Ajustes a las tareas (mapa de módulos y lo aprendido en la fase 3)

El mapa es `AcademicLoad → Scheduling → Staffing`, así que AcademicLoad no puede leer grupos, sesiones, docentes ni salones (CLAUDE.md, arquitectura, punto 5). La tabla de tareas se escribió antes y pone todo en AcademicLoad. Al planear hay que repartirlo así, con lo que los rieles aprendieron en la fase 3:

- **4.1:**
  - `CloseLoad` (AcademicLoad) solo hace la transición `staffing → closed`, que es la única forma de cerrar.
  - Las guardas de HC-15 van en un listener de Scheduling sobre `LoadTransitioning`, como `BlockStaffingWithHardConflicts`, y reutilizan del tablero los faltantes de sesiones, docente y salón. Los choques duros no los toman de `schedule_conflicts`, que puede ir atrasada: los calcula el validador en ese momento, como hace hoy `BlockStaffingWithHardConflicts`. El docente y el salón también son de Scheduling: `groups.teacher_id` y `group_sessions.room_id`.
  - El listener valida el campus completo con `ScheduleValidator::validateLoad`, porque HC-01 cruza departamentos y campus. Con 400 grupos tarda menos de 5 s.
  - El validador es `scoped` y guarda la foto que leyó. El listener y la Query del resumen llaman `forget()` antes de validar, como `AssignTeacher` y `StaffingBlockersQuery` (#313). Si no, en el mismo request o job validan una foto vieja, por ejemplo justo después de asignar el último docente o salón.
  - Un préstamo de salón pendiente ya es choque duro (HC-10), así que también bloquea el cierre.
  - El resumen de bloqueos antes de cerrar sigue el patrón de `StaffingBlockersQuery` (Staffing): una Query hace la misma cuenta que el veto, y la pantalla deshabilita el botón con su motivo. El veto sigue decidiendo.
  - La justificación de HC-15 no tiene tabla ni campo. La 4.1 la crea y actualiza `docs/dominio/modelo-de-datos.md` en el mismo PR.
  - La reapertura se agrega al diagrama de `docs/proceso/proceso-propuesto.md` y a `LoadStatus::transitions()`.
- **4.2:** `approved → adjusting` ya existe. Hoy una carga sale de `adjusting` solo en automático, cuando se aplica la última solicitud de cambio aprobada (`ReturnToStaffing`); ninguna pantalla lo hace a mano. Una carga que Subdirección regresa sin solicitudes se quedaría en `adjusting` sin salida. La 4.2 necesita un «Volver a asignación» para el jefe, que pasa por el veto de choques duros. Ese botón ya hace falta hoy:
  - Si la vuelta automática al aplicar la última solicitud la veta Scheduling por choques duros, la solicitud queda aplicada y la carga se queda en `adjusting` (`MarkChangeRequestApplied`, supuesto por confirmar). Al corregir los choques no hay salida, porque `ReturnToStaffing` solo lo llama esa Action.
  - El botón exige lo mismo que la vuelta automática: ninguna solicitud aprobada sin aplicar y pasar el veto.
- **4.3:**
  - El contenido del snapshot (grupos, sesiones, docentes, salones y cupos) lo arma Scheduling **dentro de la transacción de `TransitionLoad`**, igual que `teacher_subject_history`, para que no haya cargas `published` sin snapshot. Hay dos vías:
    - un listener de Scheduling sobre `LoadTransitioning` con `to = published`, que es síncrono y puede vetar con motivo si algo falla;
    - un contrato declarado en AcademicLoad que Scheduling implementa y `PublishLoad` llama.
  - No sirve un listener de `LoadTransitioned`: implementa `ShouldDispatchAfterCommit`, así que correría después del commit, y si fallara la carga quedaría publicada sin snapshot.
  - `load_snapshots` puede quedarse en AcademicLoad, con un `payload` jsonb versionado de forma documentada.
  - Si el snapshot usa `teacher_term_loads` (Staffing), hay que tener en cuenta que es derivada y se refresca en cola (`RefreshTeacherLoads`). Se recalcula en `sync` antes de armar el snapshot, o se cuenta desde los grupos.
- **4.4 y 4.5:** la consulta pública y los exportes leen solo el `payload` del snapshot, así que pueden vivir en AcademicLoad sin importar Scheduling. La forma del payload es el contrato entre la 4.3 y las 4.4 y 4.5: se publica primero, como el validador en la fase 3.
  - Para pintar la semana, `WeekGrid` es de Scheduling y es puro. Hay dos opciones: moverlo a `Core/Support`, junto a `WeekTime`, o llevar las páginas publicadas a Scheduling cambiando la ruta del plan.
  - Sin `uses_rooms` en el perfil del campus no hay consulta ni PDF por salón. Sin `uses_time_blocks` los grupos van sin sesiones.
- **Qué congela el snapshot:** lo que hoy se calcula y cambiaría después:
  - la colocación de cada carrera con cupo (carrera, retícula y semestre), que hoy se calcula desde la retícula activa;
  - la clave final del grupo, ya con la letra de su hora de inicio;
  - los nombres de materia, docente, salón y edificio;
  - los cupos por carrera y el tipo de cada sesión.
  - Los grupos cancelados quedan fuera (supuesto).
  - Los préstamos no hacen falta, porque el salón de la sesión ya lo dice; en el PDF por departamento conviene distinguir el departamento del grupo del dueño del salón.
- **Volumen para medir exportes:** el masivo en `cierre` tiene unas 9 740 sesiones y 856 préstamos aceptados. Los PDF se generan en cola, un archivo por unidad.

### Preguntas de negocio para la sesión de planeación

1. **Grupos `confirmed`:** HC-15 pide todos los grupos `confirmed`, pero hoy ninguna pantalla ni Action confirma uno. Nacen `planned` (`CreateGroupsFromProposal`) y el tablero no mira ese estado. Solo la factory y `SchedulingDemoSeeder` (en `todo`) crean grupos confirmados. Tal como está escrita, HC-15 no deja cerrar ninguna carga. Opciones:
   - un paso explícito del jefe (`ConfirmGroup`);
   - que el cierre confirme los activos;
   - leer HC-15 como «no cancelado».
2. **Justificación:** HC-15 deja pasar un grupo sin docente o sin salón «con justificación registrada», pero no hay dónde registrarla. ¿Quién la escribe? ¿La ve Subdirección? ¿Va en el snapshot y en la consulta pública?
3. **Reapertura:** según la 4.1 la hacen Subdirección o admin, con motivo. ¿Regresa la carga a `adjusting` o a `staffing`? ¿Se puede reabrir una carga ya `approved` o `published`, o eso es solo re-análisis (R2)?
4. **Visto bueno en Campus II:** `requires_subdireccion_approval` es `false` por omisión. ¿Campus II lo exige? La 4.2 se construye igual, pero la respuesta cambia la demo y el piloto.
5. **Solicitudes de cambio al cerrar:** con la carga en `staffing` solo puede haber solicitudes `pending`. `ApproveChangeRequest` y `RejectChangeRequest` no revisan el estado de la carga, así que se puede aprobar una con la carga ya cerrada, aprobada o publicada, y nadie podría aplicarla. Opciones, propuestas por el riel C:
   - que Staffing vete el cierre con «N solicitudes de cambio pendientes»;
   - que el cierre las rechace con un comentario automático.

   En cualquiera de las dos, aprobar exigiría la carga en `staffing` o `adjusting`. El veto sería un listener de Staffing sobre `LoadTransitioning`, porque `change_requests` es de Staffing. Así, las guardas del cierre quedan en dos listeners: Scheduling para HC-15 y Staffing para las solicitudes.
6. **Grupo compartido propuesto al cerrar:** un acuerdo de grupo compartido que sigue `proposed` no es choque para el validador; solo `QuotaRules` lo mira. ¿Bloquea el cierre, se cancela solo al cerrar o se queda?
7. **Alcance de lo publicado (4.3 y 4.4):** la carga es por departamento, pero la consulta por carrera y semestre, por docente y por salón cruza departamentos. Un semestre mezcla grupos de Ciencias Básicas y del departamento de la carrera, un salón se presta, y un docente da clase en varios departamentos y campus. Hay dos preguntas:
   - ¿La consulta pública muestra lo que cada departamento ya publicó, con un aviso de que el semestre está incompleto, o espera a que publique el campus completo?
   - ¿Las versiones son por carga, y la consulta toma la última de cada una?
8. **Lo que ve el docente después de publicar:** «Mi carga» (`MyLoadQuery`) y la oferta (`TeacherOfferQuery`, visible desde `staffing` y en cualquier estado posterior) son de Staffing y leen las tablas vivas, no el snapshot. ¿El docente ve lo publicado o lo vivo?
9. **Cargas sin grupos:** un departamento sin partes en un campus tiene su carga en `draft`, y `draft` solo pasa a `proposed`. ¿Se cierra y se publica vacía, se queda fuera del cierre, o no cuenta para el campus?
10. **Docente dado de baja:** si a un docente lo dan de baja después de asignarlo, su grupo conserva `teacher_id`. Ni el validador ni el tablero revisan que el docente siga activo; `ScheduleStateQuery` lo hace con la materia y el salón, pero no con el docente. ¿Bloquea el cierre, o la baja debe quitarlo de sus grupos?
11. **Exportación al SII (4.6):** sigue esperando el layout de sistemas del ITCJ.
12. **Servidor (4.7):** sin él no hay piloto ni H5. Si sigue sin fecha, conviene decidir con el usuario un plan B para el piloto, como el túnel que se usó para la demo de la 1.16.

### Respuestas de la planeación (10 de noviembre de 2026)

El usuario contestó las doce preguntas en la sesión de planeación de los tres rieles. El detalle y los supuestos que quedan están en [reglas de negocio](../dominio/reglas-de-negocio.md), en "Cierre y reapertura de la carga" y en las secciones de cada riel.

1. **Grupos `confirmed`:** el cierre confirma todos los grupos activos del departamento, y la confirmación se lo avisa al jefe.
2. **Justificación:** la escribe el jefe por grupo al cerrar y la lee Subdirección. La consulta pública solo dice «Por asignar». Se guarda en `groups.close_justification`, con quién y cuándo.
3. **Reapertura:** Subdirección o el admin reabren, con motivo, una carga cerrada, aprobada o publicada, y vuelve a ajustes. Republicar crea una versión nueva y conserva la anterior.
4. **Visto bueno:** Campus II no lo exige; el jefe cierra y publica.
5. **Solicitudes de cambio:** las pendientes bloquean el cierre (veto de Staffing), y aprobar una exige la carga en asignación o ajustes.
6. **Grupo compartido propuesto:** bloquea el cierre del departamento que lo imparte.
7. **Alcance de lo publicado:** la consulta pública muestra lo que cada departamento ya publicó, sin avisar que el semestre está incompleto («los alumnos no se enteran de las cuestiones internas»); lo pendiente solo lo ve el personal con sesión. Las versiones son por carga: la consulta toma la última de cada una y dice «Actualizado el …».
8. **Lo que ve el docente:** con la carga publicada, «Mi carga» y la oferta muestran lo publicado; antes, lo vivo.
9. **Cargas sin grupos:** se quedan en borrador, no se cierran ni se publican y aparecen como «Sin grupos».
10. **Docente dado de baja:** su grupo bloquea el cierre y el tablero lo marca; la baja no toca los grupos.
11. **Exportación al SII:** dos archivos con la forma de las tablas `grupos` y `horarios` del SII que conocemos por la 1.16, ajustables cuando llegue el layout oficial.
12. **Servidor:** el despliegue se ensaya completo en local, con stack de producción, HTTPS interno y respaldo con restauración probada. La prueba de concepto se muestra en local o con túnel con clave. Sin fecha de producción; la meta llega hasta la fase 8.

Además: **publicar vuelve a revisar HC-15**, y se veta si algo cambió después del cierre. Los PDF por carrera y semestre, por docente y por salón son públicos; el PDF por departamento y el Excel completo, solo con sesión.

### Demo por puntos en la fase 4

La fase extiende `demo:seed` como dice [testing.md](../arquitectura/testing.md): el punto va en `DemoCheckpoint` antes de `todo`, y sus pasos y revisiones van en el provider del módulo que hace cada cosa.

- ✅ **Punto 8, `publicacion` («Empezando la publicación»)** (rama `feature/54-demo-publicacion`):
  - Al llegar, toda carga con grupos en su campus está `closed`; el visto bueno está apagado en todos los campus (decisión del usuario, 10 de noviembre de 2026), así que ninguna queda `approved`. Las cargas sin grupos se quedan en `draft`.
  - Los pasos son de Staffing (`LoadClosingDemoSeeder`): terreno limpio (solicitudes pendientes rechazadas, acuerdos propuestos respondidos), grupos sin docente o sin salón con la justificación del jefe, y el cierre de cada carga con su jefe, que confirma sus grupos.
  - Sus revisiones confirman que cada carga cerrada pasa HC-15 (`ClosingBlockersQuery`), que todo grupo activo está `confirmed`, que hay grupos justificados y que las cargas sin grupos siguen en `draft`.
  - En `todo`, el modo `mixed` cierra unas cargas con justificaciones y reabre una (con el volumen demo solo CEA se puede cerrar; SC no, porque tiene solicitudes aprobadas sin aplicar). Detalle en [testing.md](../arquitectura/testing.md).
- ✅ **Punto 9, `reanalisis` («Empezando el re-análisis»)** (rama `feature/56-demo-publication`):
  - Al llegar, toda carga que salió de borrador está `published` con su versión 1, publicada por su jefe (`PublicationDemoSeeder`, AcademicLoad); las cargas sin grupos siguen en `draft` (P9).
  - Sus revisiones confirman que cada foto cumple la forma del snapshot (`SnapshotPayload`), que el historial docente la sigue y que cada foto tiene los grupos no cancelados de su carga.
- **La demo mezclada (`todo`):** con el cierre de la 4.1 (cargas cerradas, una reabierta) y una carga publicada en varias versiones (`PublicationShowcaseDemoSeeder`, Scheduling): con el volumen demo, CEA de Campus II en tres versiones; con el masivo, CB de Campus II en dos versiones y reabierta al final, y II de Campus I cerrada sin publicar. Cada versión nueva pasa por el proceso real (Subdirección reabre, el jefe regresa a asignación, cambia salones, cierra y publica). Así la 4.2, la 4.4 y la 4.5 tienen pantallas llenas. No hay cargas `approved`: el visto bueno está apagado en todos los campus.
- `DemoCheckpointsTest` (hasta el último punto con el volumen demo) debe seguir debajo de un minuto.

### Reparto sugerido

- **Rieles:** el de la tabla. A hace la 4.3, la 4.6 y la 4.7; B la 4.4 y la 4.5; C la 4.1, la 4.2 y la 4.8; los tres juntos la 4.9 a la 4.11.
- **Orden:**
  1. C empieza con la 4.1, porque abre el punto `publicacion` de la demo.
  2. A publica primero la forma del payload del snapshot. Con eso B arranca la 4.4 y la 4.5 sobre un snapshot de prueba, sin esperar la 4.3 completa.
  3. La 4.7 avanza en cuanto haya servidor.
- **Coordinación como en la fase 3:**
  - Una sesión por riel.
  - Los contratos se publican primero.
  - Antes de mezclar a `develop` se avisa a los otros rieles: la rama debe estar al día.
  - Los subagentes corren solo sus pruebas; la regresión completa es el CI del PR.
  - Los PR que entran a `develop` no cierran su issue: se cierra a mano y se mueve a «Hecho» en el tablero.

### Notas de los rieles de la fase 3

**Riel B (editor, cupos y salones).**

- **Dónde vive cada cosa:**
  - `academic_loads` (AcademicLoad) tiene una fila por periodo, campus y departamento, y su bitácora de transiciones en `LoadTransition`.
  - `groups` (Scheduling): `department_id` es el de la materia. `owner_career_id`, `study_plan_id` y `semester` colocan el grupo en su carrera dueña.
  - `group_sessions`: `day`, `starts_at` y `ends_at` (`time`), `room_id` y `kind` (la virtual va sin salón). Los EXCLUDE de HC-01 y HC-02 son diferibles.
  - `group_quotas`: solo son filas los cupos aceptados, y la suma de cupos no pasa de `capacity` (HC-07, diferible). Un grupo compartido propuesto es un acuerdo vivo que aparta sus lugares.
  - `interdepartmental_agreements` (AcademicLoad) guarda préstamos y grupos compartidos, y nunca se borra. Las consultas de préstamos están en `RoomLoansQuery`, y los préstamos siguen solos a las sesiones (`SyncSessionLoans`).
- **Deuda:** después de cerrar o publicar, Facilities todavía puede bloquear (HC-11) o desactivar un salón, y aparecen choques vivos en una carga publicada. El snapshot protege la consulta; el resto es del re-análisis (R2).
- **Supuestos:** los de B siguen «por confirmar» en `docs/dominio/reglas-de-negocio.md` («Editor de horarios», «Cupos» y «Acuerdos entre departamentos»). Algunos: un préstamo cubre las sesiones dentro de su rango y los contiguos se suman; un grupo compartido con una carrera del mismo departamento no necesita acuerdo; rechazar exige motivo; el puntaje SC-03 de sugerencia de salón.

**Riel C (Staffing).**

- **Docente asignado:** un grupo lo tiene cuando `groups.teacher_id` no es nulo, y la guarda y el tablero miran solo eso. La fila vigente de `group_teacher_assignments` (`replaced_at` nulo) es su historial (HC-16), y la escribe la misma transacción: `AssignTeacher` y `UnassignTeacher` llaman a `SetGroupTeacher`. Solo la demo `todo` escribe `teacher_id` directo (`SchedulingDemoSeeder`). El docente no acepta su carga: con HC-17 ve la oferta y «Mi carga» en solo lectura, y solo puede pedir cambios de horario o de salón.
- **Después del cierre:** `AssignTeacher`, `UnassignTeacher` y `RequestChange` ya se niegan, porque exigen la carga en `staffing` o `adjusting`, y las pantallas muestran el motivo.
- **Para la 4.8:**
  - Pantallas de Staffing: `/staffing/assign`, `/carga-por-docente`, `/staffing/change-requests`, `/mi-espacio/oferta` y `/mi-espacio/mi-carga`.
  - Ya tienen pruebas negativas de alcance: `AssignPageScopeTest`, `StaffingPoliciesTest`, `TeacherOfferPageTest`, `TeacherLoadsPageTest`, `ChangeRequestPolicyTest` y `ChangeRequestsPageTest`.
  - La oferta y «Mi carga» son personales (`Target::ownedBy`) y cruzan campus a propósito (supuesto por confirmar): el docente ve los grupos de sus departamentos en todos los campus. El checklist debe decirlo para que no se lea como una fuga.
  - El ranking muestra el lugar y el desglose del propio docente; de los demás, solo cuántos hay.
- **Deudas:**
  - `UnassignTeacher` tiene Action y prueba, pero ningún botón.
  - El ranking del punto 5 del masivo tarda unos 154 s. `RankGroups` podría reutilizar las marcas entre grupos de la misma materia y horario.
- **Supuestos:** los de Staffing siguen «por confirmar» en `docs/dominio/reglas-de-negocio.md`. Algunos: reasignar al mismo docente no deja fila; HC-01, HC-08 y HC-09 no se saltan con motivo; SC-09 marca pero no baja en el ranking; desempates; quién ve las solicitudes. El piloto (4.9) es el momento de confirmarlos.

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
| 4.1 ✅ [#357](https://github.com/Erne1357/nexus/pull/357), [#361](https://github.com/Erne1357/nexus/pull/361), [#360](https://github.com/Erne1357/nexus/pull/360), [#383](https://github.com/Erne1357/nexus/pull/383), [#382](https://github.com/Erne1357/nexus/pull/382) | Cierre por departamento: Action `CloseLoad` con guardas HC-15 (cero choques duros, docente y salón en todos los grupos confirmados o justificación), resumen de bloqueos antes de cerrar, reapertura por Subdirección o admin con motivo | AcademicLoad | `AcademicLoad/Actions/Transitions/CloseLoad`. La pantalla solo muestra lo que falta; la regla vive en la Action. | Unit: cada guarda; feature: cierre y reapertura registrados | C |
| 4.2 ✅ [#362](https://github.com/Erne1357/nexus/pull/362), [#383](https://github.com/Erne1357/nexus/pull/383) | Visto bueno de Subdirección: tablero del campus con cargas cerradas, aprobar o regresar a `adjusting` con comentarios; se activa solo si el campus tiene `requires_subdireccion_approval` | AcademicLoad | Perfil de flujo de `campuses.settings`. Queda listo aunque Campus II no lo exija. | Feature: con y sin el parámetro | C |
| 4.3 ✅ [#356](https://github.com/Erne1357/nexus/pull/356), [#359](https://github.com/Erne1357/nexus/pull/359), [#377](https://github.com/Erne1357/nexus/pull/377), [#386](https://github.com/Erne1357/nexus/pull/386) | Publicación: Action `PublishLoad` que genera `load_snapshots` (grupos, sesiones, docentes, salones, cupos) con versión, cambia a `published` y registra en `teacher_subject_history` las materias impartidas | AcademicLoad, Faculty | El snapshot es lo que se consulta y exporta; la tabla viva sigue editable solo vía re-análisis (R2). | Feature: snapshot completo y versionado; historial docente alimentado | A |
| 4.4 ✅ [#355](https://github.com/Erne1357/nexus/pull/355), [#364](https://github.com/Erne1357/nexus/pull/364) y [#367](https://github.com/Erne1357/nexus/pull/367) | Consulta publicada: páginas de solo lectura por carrera y semestre, por docente y por salón, leyendo del snapshot; acceso público por campus y periodo (sin login) para alumnos, con búsqueda por carrera | AcademicLoad | `AcademicLoad/Livewire/pages/published/*`. En R1 los alumnos no tienen cuenta; la consulta pública reemplaza la publicación en el SII. | Feature: solo cargas publicadas son visibles; browser: consultar horario de un semestre | B |
| 4.5 | Exportes PDF (por carrera y semestre, por docente, por salón, por departamento) y Excel (lista completa de grupos con sesiones) desde el snapshot, generados en cola y descargables | AcademicLoad | `AcademicLoad/Exports/`. PDF con una librería basada en HTML (DomPDF) para no depender de Chromium en producción. | Feature: archivos generados con el contenido esperado | B |
| 4.6 | Exportación al SII: archivo con el layout que el SII acepte para cargar grupos y horarios (o, si no existe, el Excel que Servicios Escolares captura) | AcademicLoad | Depende de lo que sistemas del ITCJ confirme. Es el respaldo para que la inscripción de Ene-Jun 2027 siga en el SII con datos de Nexus. | Feature: layout validado con un archivo de ejemplo del SII | A |
| 4.7 | Despliegue: imagen `prod` en el servidor, HTTPS, variables, migraciones, `pg_dump` diario con retención, restauración probada; `main` recibe el primer PR desde `develop` | — | README de operación en `docs/operacion.md`. Sin restauración probada no hay piloto. | Restauración de un respaldo en el servidor de pruebas | A |
| 4.8 ✅ [#363](https://github.com/Erne1357/nexus/pull/363) (más la revisión de seguridad de las rutas públicas de la 4.4 y la 4.5 en [#367](https://github.com/Erne1357/nexus/pull/367), [#368](https://github.com/Erne1357/nexus/pull/368) y [#369](https://github.com/Erne1357/nexus/pull/369)) | Endurecimiento: límites de intentos de login, cabeceras de seguridad, políticas revisadas en todas las pantallas (`/security-review`), revisión de que ningún dato de otro campus se filtre | Core | Checklist en `CONTRIBUTING.md`. | Feature: pruebas negativas de alcance por rol en cada módulo | C |
| 4.9 | Piloto: sesión de arranque con jefes y coordinadores de Campus II, guía de una página por rol, canal de soporte, triage diario de incidencias en issues con etiqueta `piloto`, corrección en PRs pequeños | — | Las incidencias del piloto se atienden antes que cualquier tarea de R2. | Incidencias críticas en cero antes de publicar | A, B, C |
| 4.10 | Publicar la carga de Campus II `20271` desde Nexus (hito H5) y entregar el exporte al SII | — | | Carga publicada, consultable y exportada | A, B, C |
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
