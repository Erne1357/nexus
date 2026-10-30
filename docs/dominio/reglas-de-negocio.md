# Reglas de negocio

Reglas que Nexus hace cumplir. Cada regla tiene un identificador para referirla desde issues, pruebas y código. Las **duras** nunca se violan y bloquean transiciones; las **blandas** se puntúan y el motor (R2) las optimiza. Los parámetros marcados como configurables viven en `settings` o en `campuses.settings`.

## Restricciones duras (HC)

| Id | Regla | Ámbito | Desde |
|---|---|---|---|
| HC-01 | Un docente no puede tener dos sesiones que se empalmen en el mismo día y periodo, en ningún campus. | Scheduling, Staffing | R1 |
| HC-02 | Un salón no puede tener dos sesiones que se empalmen en el mismo día y periodo. | Scheduling | R1 |
| HC-03 | Dos grupos de materias del **mismo semestre reticular del mismo plan y campus** no deben empalmarse si son los únicos grupos de esas materias para esa carrera (el "alumno tipo" debe tener al menos una combinación sin empalme). | Scheduling | R1 |
| HC-04 | Las sesiones deben caer dentro de la ventana del campus (`day_start`, `day_end`, `days`) y alinearse a `block_minutes`. | Scheduling | R1 |
| HC-05 | La capacidad del grupo no puede exceder la capacidad del salón de ninguna de sus sesiones. | Scheduling, Facilities | R1 |
| HC-06 | Una materia con `needs_lab` solo puede tener sesiones prácticas en salones de tipo laboratorio, taller o sala de cómputo. | Scheduling | R1 |
| HC-07 | La suma de `max_seats` de los cupos por carrera de un grupo no puede exceder su capacidad. | Scheduling | R1 |
| HC-08 | Las horas asignadas a un docente en el periodo (todos los campus) más sus horas externas no pueden exceder sus horas base más la tolerancia configurable (`staffing.extra_hours_tolerance`, por defecto 0). Honorarios: tope configurable (`staffing.honorarios_max_hours`). | Staffing | R1 |
| HC-09 | Un docente no puede ser asignado en bloques marcados `unavailable` en su disponibilidad del periodo. | Staffing | R1 |
| HC-10 | Un salón con `department_id` dueño solo puede usarse por otro departamento si existe un acuerdo de préstamo `accepted` que cubra el día y la hora. | Scheduling, AcademicLoad | R1 |
| HC-11 | Un salón no puede usarse en bloques con `room_blocks` vigentes. | Scheduling | R1 |
| HC-12 | Las horas semanales de sesiones de un grupo deben ser iguales a `theory_hours + practice_hours` de la materia (tolerancia configurable por campus para modalidades mixtas). | Scheduling | R1 |
| HC-13 | Solo se pueden crear grupos de materias activas de planes activos, en carreras ofrecidas en el campus. | AcademicLoad | R1 |
| HC-14 | La carga de un departamento no sale de `draft` hasta que sus `catalog_reviews` del periodo y campus estén `validated`. | CatalogValidation, AcademicLoad | R1 |
| HC-15 | Para cerrar (`closed`): cero choques duros, todos los grupos `confirmed` con docente y, si el campus usa salones, con salón en todas sus sesiones. Un grupo sin docente o salón solo pasa si se marca `cancelled` o con justificación registrada. | AcademicLoad | R1 |
| HC-16 | Asignar un docente que no ocupa el primer lugar del ranking del grupo exige un motivo escrito; se guarda con la posición que tenía. | Staffing | R1 |
| HC-17 | Toda la oferta (grupos) de un departamento es visible para todos los docentes activos del departamento desde que la carga entra a `staffing`. No existe forma de ocultar un grupo. | Staffing | R1 |
| HC-18 | Ningún registro de bitácora, transición o snapshot se edita ni se borra. | Core | R1 |
| HC-19 | (R3) Un alumno solo puede inscribir una materia si aprobó su seriación, no la tiene aprobada, hay cupo en su carrera para ese grupo y no se empalma con sus otros grupos. | Enrollment | R3 |

## Restricciones blandas (SC)

| Id | Preferencia | Peso por defecto | Desde |
|---|---|---|---|
| SC-01 | Las materias de un mismo semestre reticular quedan en horas consecutivas (sin huecos) para el alumno tipo. | 10 | R1 (reporte), R2 (optimización) |
| SC-02 | Las sesiones de un grupo se reparten en días distintos (no 5 horas el mismo día). | 6 | R2 |
| SC-03 | La materia se imparte en su salón por defecto o sugerido. | 5 | R1 (sugerencia), R2 |
| SC-04 | El docente imparte en sus bloques `preferred`. | 4 | R2 |
| SC-05 | Un docente tiene sus sesiones compactas (pocos huecos por día). | 3 | R2 |
| SC-06 | Un docente no cambia de edificio entre horas consecutivas. | 2 | R2 |
| SC-07 | Materias de semestres consecutivos de una carrera no se empalman entre sí (para alumnos irregulares). | 3 | R2 |
| SC-08 | Un grupo con docente de honorarios se programa en los turnos donde hay disponibilidad declarada. | 2 | R2 |

Los pesos son configurables por campus (`settings`, clave `solver.weights`).

## Ranking docente (desempate)

Para cada grupo, se ordenan los docentes activos del departamento que imparte la materia (y los de otros departamentos con perfil `preferente` en esa materia) por:

1. **Horas base vigentes** en el periodo, de mayor a menor (regla institucional).
2. **Historial y perfil** en la materia: puntos por cada vez que la impartió en los últimos N periodos (`staffing.history_terms`, por defecto 6) más puntos por perfil `preferente` o `apto` declarado por el jefe.
3. **Evaluación docente**: promedio de las evaluaciones de los últimos M periodos (`staffing.evaluation_terms`, por defecto 2).
4. Docentes con **horas restantes insuficientes** para el grupo bajan al final con la marca "sin horas", y los que tendrían choque duro con la marca "choque".

El puntaje y su desglose se guardan en `staffing_rankings` y se muestran completos. Los pesos de 2 y 3 son configurables (`staffing.weights`). Si dos docentes empatan en todo, se conserva el orden del periodo anterior para la misma materia y, en última instancia, el orden alfabético, para que el resultado sea determinista y reproducible.

## Cupos por carrera

- Todo grupo tiene al menos un cupo: el de su carrera dueña (`is_owner`).
- Al aceptar un acuerdo de grupo compartido se crea el cupo de la otra carrera con el máximo acordado.
- El coordinador de cada carrera ve el grupo en la retícula de su carrera con el cupo que le corresponde.
- R3: la inscripción descuenta del cupo de la carrera del alumno; si se agota, lista de espera, aunque el grupo tenga lugares de otra carrera.

## Elegibilidad y demanda

- Un alumno es **elegible** para una materia de su plan si aprobó todas sus seriaciones y no la ha aprobado. Solo el resultado `aprobada` aprueba: quien está cursando la materia o su seriación todavía no la tiene aprobada (la proyección de quienes están cursando es de R2). Los correquisitos no bloquean; las seriaciones de otro plan no cuentan (confirmado el 29 de octubre de 2026).
- La especialidad de un alumno sale de su kardex: si aprobó o está cursando materias de una especialidad de su plan, las materias de las otras especialidades ya no le faltan ni cuentan para sus elegibles. Mientras no toma ninguna, cuentan las de todas, porque puede elegir cualquiera (confirmado el 29 de octubre de 2026).
- Una materia de la retícula del alumno cuenta aunque esté inactiva en el catálogo: el plan se ata al alumno al inscribirse. Por eso no se desactiva una materia que sigue en un plan con alumnos que la necesitan (lo revisa la tarea 1.8; confirmado el 29 de octubre de 2026).
- Una materia no se borra, se desactiva, y no se desactiva mientras alguien **la necesita**: un alumno no borrado, activo, con estatus `activo` o `baja_temporal`, cuyo plan la tiene en su retícula y que no la ha acreditado. No cuentan los egresados, las bajas definitivas, los alumnos sin estatus ni los desactivados. Se cuenta con el kárdex del momento, no con `student_progress`, para no esperar al recálculo, y el rechazo dice cuántos alumnos son y de qué planes (confirmado el 29 de octubre de 2026). Respeta la especialidad igual que el avance: si el alumno ya eligió otra especialidad, una materia de especialidad ajena no la necesita (confirmado el 29 de octubre de 2026).
- Un alumno puede cursar materias de su semestre y de hasta dos semestres más, y siempre las de semestres anteriores que le faltan. Su semestre es el que trae el SII (`students.current_semester`); sin semestre (un egresado) no hay límite. Cuenta para la retícula y para los elegibles (confirmado el 29 de octubre de 2026).
- Servicio Social pide el 70 % de los créditos del plan y Residencia Profesional el 80 % y el servicio social acreditado, como marcan los lineamientos del TecNM. Se reconocen por su tipo (`subjects.kind`), que la exportación del SII no trae: mientras no se capture, no piden créditos. El porcentaje es de `study_plans.total_credits`; si no está capturado, de la suma de la retícula con la especialidad del alumno o, si todavía no elige, con la de más créditos (confirmado el 29 de octubre de 2026).
- Solo cuentan como elegibles los alumnos con estatus `activo`: no cuentan los de baja temporal o definitiva, los egresados, los que quedaron sin estatus porque el SII trajo uno que Nexus no conoce, ni un alumno desactivado (confirmado el 29 de octubre de 2026).
- R1: `eligible_students` por materia, carrera y campus se cuenta al leerlo desde `student_progress` (`EligibleStudentsQuery`), que se recalcula al importar alumnos o kardex. El estatus del alumno cuenta desde que se importa; un cambio de plan, desde el recálculo.
- R2: `expected_students = eligible_students x (1 - tasa_de_no_inscripción) + repetidores_esperados`, con tasas históricas por materia y campus (`demand.history_terms`, por defecto 4). `suggested_groups = ceil(expected_students / tamaño_objetivo)`, con tamaño objetivo configurable por tipo de materia (`demand.target_group_size`, por defecto 35; laboratorios 25).

## Retícula del alumno

La ficha del alumno muestra cada materia de su retícula con el estado que usa el SII (confirmado el 29 de octubre de 2026). Sale de sus filas de kardex de esa materia y de su avance ("Elegibilidad y demanda"):

| Estado | Cuándo |
|---|---|
| Acreditada | La aprobó, en cualquier oportunidad. |
| Cursando | La cursa en el periodo en curso de su campus o después. |
| Sin calificar | La cursó en un periodo anterior al en curso y el kardex no trae su calificación. |
| Reprobada · prioridad | La reprobó en la oportunidad 1 (ordinaria): tiene prioridad para seleccionarla. |
| A curso especial | La reprobó en la oportunidad 2 (repetición): solo puede cursarla en curso especial. |
| Curso especial reprobado | La reprobó en la oportunidad 3 (curso especial): el alumno se da de baja en automático. |
| Puede seleccionarla | Ya puede cursarla según su avance. |
| No permitida aún | Le falta seriación, créditos o avance. |

- Si no la acreditó, decide la fila más reciente del kardex, por periodo y oportunidad. La oportunidad es la de `kardex_records.attempt`: 1 ordinaria, 2 repetición, 3 curso especial. Una baja no gasta oportunidad.
- El periodo en curso es el más reciente del kardex de los alumnos de su campus, sin contar verano, que solo cursan algunos. El periodo de captura (`terms.is_current`) no sirve: es el que se planea. Se recalcula con cada importación de kardex (confirmado el 29 de octubre de 2026).
- Una materia reprobada conserva su estado aunque hoy no pueda cursarla, y la retícula dice qué le falta. Cuenta para «Puede seleccionar» solo si su avance la deja cursar; la del curso especial reprobado ya no cuenta, porque el alumno se da de baja. A veces se cambian de carrera o a EAD antes de que se refleje (confirmado el 29 de octubre de 2026).
- «Seleccionada solamente», que también muestra el SII, llega con la inscripción (R3).
- Una vez elegida la especialidad, la retícula ya no muestra las materias de las otras.
- La ficha calcula el avance al leerla, desde el kardex del alumno, para no esperar al siguiente recálculo de `student_progress`.

## Aprobaciones y permisos

| Acción | Quién | Condición |
|---|---|---|
| Abrir periodo, calendario, perfil de campus | `admin`, `subdireccion` | `subdireccion`, en cualquier campus activo y solo el perfil de flujo; crear un campus o cambiar su código, nombre, modalidad o estado es solo de `admin` |
| Administrar edificios y salones | `admin`, `subdireccion` | `subdireccion`, en cualquier campus activo |
| Importar catálogos | `admin` | |
| Administrar catálogos de Academic (departamentos, carreras, materias, planes y su retícula) | `admin` | En la fase 2 se abren a los jefes para validar (confirmado el 29 de octubre de 2026) |
| Administrar docentes (listado, datos, adscripción, plazas, disponibilidad, perfil y horas fuera del ITCJ) | `admin`, `subdireccion`, `jefe_departamento`, `jefe_proyecto_docente` | `admin` y `subdireccion`, a todos los docentes, también a los que no tienen departamento; `jefe_departamento` y `jefe_proyecto_docente`, a los adscritos a su departamento, aunque su adscripción principal sea otra; el perfil, solo en las materias que imparte su departamento (confirmado el 30 de octubre de 2026). El jefe de proyecto docente administra a los docentes de su departamento aunque su alcance sea de un campus, porque el docente no es de un campus (supuesto por confirmar) |
| Dar de baja o reactivar a un docente (desactiva su usuario y termina sus jefaturas) | `admin`, `subdireccion` | Los jefes y los jefes de proyecto docente no dan de baja: solo quitan al docente de su departamento (confirmado el 30 de octubre de 2026). Nadie se da de baja a sí mismo (supuesto por confirmar) |
| Dar de alta usuarios de docentes, reiniciar contraseñas y nombrar jefes | `admin` | Supuesto por confirmar (nombrar jefes es S7) |
| Consultar alumnos (listado y ficha) | `admin`, `subdireccion`, `jefe_departamento`, `coordinador` | `subdireccion`, los de cualquier campus; `jefe_departamento`, los de las carreras de su departamento en cualquier campus; `coordinador`, los de su carrera en su campus. Solo lectura: vienen del SII (confirmado el 29 de octubre de 2026) |
| Validar catálogos del departamento | `jefe_departamento` del departamento | |
| Enviar propuesta de carrera | `coordinador` de la carrera y campus | catálogos de los departamentos involucrados validados (aviso, no bloqueo) |
| Crear grupos y editar sesiones | `coordinador` (de su carrera dueña), `jefe_departamento` (de su departamento) | carga en `proposed`, `staffing` o `adjusting` |
| Definir cupos por carrera | `jefe_departamento` del departamento que imparte | acuerdo registrado si involucra otra carrera de otro departamento |
| Asignar docente | `jefe_departamento` del departamento, `jefe_proyecto_docente` del departamento y campus | HC-08, HC-09, HC-16 |
| Solicitar cambio | `docente` asignado al grupo, `coordinador` | carga en `staffing` o posterior |
| Aprobar o rechazar cambio | `jefe_departamento` | |
| Asignar salón | `jefe_departamento` | HC-02, HC-05, HC-06, HC-10, HC-11 |
| Proponer o aceptar préstamo de salón | `jefe_departamento` de ambos departamentos | |
| Cerrar carga | `jefe_departamento` | HC-15 |
| Visto bueno | `subdireccion` | solo si `requires_subdireccion_approval` |
| Publicar | `subdireccion`, o `jefe_departamento` si el campus no exige visto bueno | carga `closed` o `approved` |
| Re-análisis | `coordinador`, `jefe_departamento` | nueva importación de kardex (R2) |

Alcance de cada rol: la Subdirección Académica es una para todo el Tec y el jefe de departamento es uno por departamento para todo el Tec, así que su alcance no lleva campus y vale en cada campus activo (confirmado el 30 de octubre de 2026). El coordinador lo es de una carrera en un campus, porque sus alumnos son de un campus (confirmado el 30 de octubre de 2026). Los docentes no están atados a un campus: dan clase en cualquier campus o modalidad (confirmado el 30 de octubre de 2026); por eso un alcance de `docente` sin campus vale en todos los campus activos.

El jefe de proyecto docente lo es de un departamento en un campus, lo más restrictivo mientras no se decida si también es de todo el Tec (supuesto por confirmar).

Adscripciones de docentes: qué hace «Quitar de mi departamento» y qué principal queda está en "Reglas de datos". Nadie se quita a sí mismo de un departamento (supuesto por confirmar). Quien cubre al docente puede cambiar cuál adscripción es la principal (supuesto por confirmar). Si al quitar una adscripción el jefe deja de cubrir al docente, la ficha lo regresa a la lista.

Dar de alta un docente con un número de empleado que ya existe no crea otro: la pantalla ofrece adscribirlo al departamento de quien lo da de alta, sin cambiar sus datos; si esa adscripción se había quitado, la restaura, y si el docente no tiene principal, esa queda como principal (S9, supuesto por confirmar).

Un alcance (`role_scopes`) deja de contar cuando su carrera o su departamento se borra. Si solo se desactiva, sigue contando, porque una carrera inactiva puede tener todavía alumnos y grupos que atender, igual que un plan inactivo (confirmado el 29 de octubre de 2026). Core no depende de Academic, así que lo resuelve la tarea 1.8 al borrar.

Borrar departamentos (confirmado el 29 de octubre de 2026): un departamento solo se borra, de forma lógica, si no tiene carreras ni materias sin borrar; si no, se desactiva. Al borrarlo se cierran los alcances de sus jefes: los vigentes que empezaron antes de hoy terminan ayer y los que empiezan hoy o después se eliminan, porque `valid_to` no puede ser anterior a `valid_from`; cada cambio queda en la bitácora sobre el usuario (confirmado el 29 de octubre de 2026).

Borrar departamentos con salones (S10, supuesto por confirmar): un departamento que es dueño de salones no borrados, activos o no, no se borra; Facilities lo veta con «Es dueño de N salones.» mediante `DepartmentDeleting`. Se desactiva, o se reasignan sus salones.

Importar salones (S11, supuesto por confirmar): la plantilla `rooms` es de un campus, el del lote, y trae edificio, clave, nombre, tipo, capacidad y departamento dueño; «desactivar faltantes» solo mira los salones activos de ese campus. El edificio que el campus no tiene se crea con su clave como nombre y avisa, como las especialidades al importar materias; uno inactivo avisa y uno borrado es error. El tipo se reconoce por nombre, sin acentos ni mayúsculas (`AULA`, `LABORATORIO`, `TALLER`, `SALA DE COMPUTO` o `CENTRO DE COMPUTO`, `AUDITORIO`); otro es error. La capacidad es un entero mayor que cero y acepta separadores de miles. Un departamento dueño que no existe es error; uno inactivo, advertencia. Como todo lo importado, no reactiva ni restaura: un salón inactivo se actualiza y sigue inactivo, con advertencia, y uno borrado es error.

Desactivar un edificio con salones activos (supuesto por confirmar): un edificio se puede desactivar aunque tenga salones activos; los salones no cambian.

Edificio inactivo en un salón (supuesto por confirmar): un edificio desactivado sigue disponible para elegirse como edificio de un salón y aparece marcado «(inactivo)».

Características de un salón (supuesto por confirmar): son una lista libre de etiquetas que se escriben separadas por comas; se recortan, y se quitan las vacías y las repetidas sin distinguir mayúsculas.

Bloqueos que se repiten o empalman (supuesto por confirmar): un bloqueo de un salón puede repetir o empalmarse con otro bloqueo del mismo salón, porque no tiene clave natural.

Borrar carreras (confirmado el 29 de octubre de 2026): una carrera solo se borra, de forma lógica, si no tiene planes de estudio, ni siquiera borrados; si no, se desactiva. Al borrarla dejan de contar los alcances de sus coordinadores (igual que con los departamentos; confirmado el 29 de octubre de 2026).

Oferta por campus: la oferta de una carrera inactiva nace inactiva (confirmado el 29 de octubre de 2026, la misma regla de la importación). Ofrecer crea, restaura o activa la fila de `career_campus` del campus y dejar de ofrecer la desactiva; ofrecer nunca desactiva una oferta activa (supuesto por confirmar).

Especialidades: quitar una especialidad de un plan no pasa en la práctica: sería otro plan; un plan suele tener 2 o 3 y el alumno elige una (confirmado el 29 de octubre de 2026). Por eso solo se quita, de forma lógica, una especialidad sin materias en la retícula, que se capturó por error; desactivarla se permite siempre, tenga o no materias (confirmado el 29 de octubre de 2026). Su clave es única dentro del plan, también contra las quitadas. Darla de alta, renombrarla, desactivarla o quitarla no cambia el avance de ningún alumno.

Retícula del plan: una materia está una sola vez en la retícula de un plan, y volver a poner una que se quitó restaura su fila con el lugar nuevo, como pide el modelo de datos (modelo-de-datos.md, `study_plan_subjects`); su seriación no vuelve, porque se quitó con ella. Quitar una materia de la retícula quita en la misma operación toda su seriación en ese plan, la que pide y la que la pide a ella; la materia sigue en el catálogo y en los otros planes. El semestre va de 1 a los semestres del plan; si el plan no los tiene capturados, de 1 a 12, y el editor ofrece los que ya tienen materias y uno más (confirmado el 29 de octubre de 2026). Solo se pone una materia activa y sin borrar del catálogo, y solo se asigna una especialidad activa del mismo plan; una materia conserva su especialidad aunque esta se desactive (confirmado el 29 de octubre de 2026). Poner, mover o quitar una materia cambia el avance de los alumnos del plan, y cada cambio queda en la bitácora con el lugar anterior y el nuevo y, al quitarla, cuántas seriaciones se fueron.

Seriación del plan: en el panel de una materia de la retícula se le agrega un requisito (seriación o correquisito), se cambia su tipo o se quita. Valen las reglas de la seriación importada (ver "Reglas de datos", confirmadas el 29 de octubre de 2026): las dos materias están en la retícula del plan, una materia no se pide a sí misma, una seriación que cierra un ciclo se rechaza y los correquisitos no forman ciclos, así que agregar un correquisito o volver correquisito una seriación no revisa el ciclo y volver seriación un correquisito sí; en el editor, el rechazo dice la cadena que cierra el ciclo. Una seriación está una vez por plan, materia y requisito (confirmado el 29 de octubre de 2026, "Reglas de datos"), y agregar una que se quitó restaura su fila con el tipo nuevo, como pide el modelo de datos (modelo-de-datos.md, Academic: volver a poner una fila quitada la restaura). Pedir de nuevo un requisito que la materia ya pide es error aunque sea con otro tipo: el tipo se cambia en su renglón (confirmado el 29 de octubre de 2026). Quitar un requisito es borrado lógico (confirmado el 29 de octubre de 2026, "Reglas de datos"). Agregar, cambiar o quitar un requisito cambia el avance de los alumnos del plan, y cada cambio queda en la bitácora con el tipo anterior y el nuevo.

## Parámetros por campus (perfil de flujo)

| Clave | Presencial (C1, C2) | Notas para otras modalidades |
|---|---|---|
| `modality` | `presencial` | `mixta` (EAD), `en_linea`, `virtual_tecnm` |
| `uses_rooms` | `true` | EAD: `true` solo viernes y sábado; EnLínea y Virtual: `false` |
| `uses_time_blocks` | `true` | EAD: `true`; EnLínea: por definir |
| `staffing_mode` | `department` | otras modalidades: `central` |
| `block_minutes` | 60 | |
| `day_start` / `day_end` | 07:00 / 21:00 | |
| `days` | lunes a viernes | EAD: lunes a sábado |
| `requires_subdireccion_approval` | `false` por defecto; configurable | |
| `allow_external_teachers` | `false` | TecNM Virtual: `true` |

## Reglas de datos

- Claves naturales únicas: `subjects.code`, `careers.code`, `study_plans.code`, `teachers.employee_number`, `students.control_number`, `rooms.code` por campus, `groups.code` por periodo y campus. El kardex, por alumno, materia y periodo. También `departments.code`, una carrera por campus en `career_campus`, `specialties.code` dentro de su plan, una materia por plan en `study_plan_subjects` y una seriación por plan, materia y requisito en `subject_prerequisites` (confirmado el 29 de octubre de 2026).
- En Academic nada se borra físicamente: departamentos, jefes, carreras, carrera-campus, planes, materias, especialidades, retícula y seriación llevan borrado lógico (confirmado el 29 de octubre de 2026).
- Un departamento tiene un solo jefe vigente para todo el Tec, sin campus (confirmado el 30 de octubre de 2026). De jefe de proyecto docente puede haber varios a la vez, y la misma persona no repite el cargo con fechas que se traslapan (confirmado el 29 de octubre de 2026). Cada jefe de proyecto docente lo es de un departamento en un campus (supuesto por confirmar).
- Plazas y adscripciones de Faculty: un docente puede tener varias plazas vigentes a la vez, y estar adscrito a varios departamentos, con una principal (confirmado el 30 de octubre de 2026). La adscripción es solo a un departamento, sin campus, porque un docente da clase en cualquier campus o modalidad (confirmado el 30 de octubre de 2026). Las plazas, y con ellas las horas base, son del docente y no de un departamento (confirmado el 30 de octubre de 2026). Soft deletes y claves naturales únicas también contra borrados: `teachers.employee_number`, una adscripción por docente y departamento.
- Horas base de un docente en un periodo (supuestos por confirmar): una plaza cuenta si está vigente el primer día del periodo, `terms.starts_on` (S1); las horas base son la suma de las plazas vigentes, sin tope en la suma, aunque cada plaza tenga máximo 40 (S2); horas por tipo: tiempo completo 40, tres cuartos 30, medio tiempo 20, honorarios 0 y asignatura de 1 a 40 (S3). `Faculty\Rules\BaseHours` y `TeacherBaseHoursQuery` lo calculan.
- Evaluación docente: escala de 1 a 5 del TecNM; un puntaje fuera de 0 a 5 es error (confirmado el 30 de octubre de 2026). Una por docente, periodo (código del SII) y tipo.
- Historial docente: una fila por grupo impartido, de modo que dos grupos de la misma materia en el mismo periodo cuentan dos veces (confirmado el 30 de octubre de 2026).
- Disponibilidad docente: dos bloques del mismo docente, periodo y día no se traslapan; los contiguos sí se aceptan. Horas externas (S16, supuesto por confirmar): de 1 a 40, una fila por docente, periodo y origen.
- Borrar un departamento se veta si tiene docentes adscritos sin borrar, con «Tiene N docentes adscritos.» (S10, supuesto por confirmar), a través de `DepartmentDeleting`.
- Importar nunca borra ni reactiva: si un registro no viene en el archivo, se marca `is_active = false` solo cuando el admin elige explícitamente "desactivar faltantes" para ese lote.
  - Faltante es un registro activo y sin borrar del catálogo de la plantilla, del campus del lote si el catálogo es por campus, que ninguna fila del archivo menciona: una fila aplicada protege el registro que escribió, y cualquier otra protege el de su clave natural. Una fila con error también cuenta, porque dice que el registro sigue en el SII; una fila sin clave no protege a ninguno. Los registros ya inactivos o borrados no se tocan (confirmado el 29 de octubre de 2026).
  - La opción existe en los catálogos con `is_active`; el kardex, el historial docente y las evaluaciones no la tienen. Tampoco los alumnos ni los catálogos de Academic (departamentos, carreras, planes, materias y seriación): son globales, la exportación puede ser de un solo campus, y quitar algo de una retícula cambia el avance de los alumnos, así que se hace a mano en el CRUD (confirmado el 29 de octubre de 2026). Antes de aplicar, el admin ve cuántos de los activos se desactivarían y cuáles, y confirma con esa cifra. Al aplicar nunca se desactivan más de los confirmados: si el catálogo cambió y ahora son más, no se desactiva ninguno y el lote queda fallido con el motivo (las filas ya escritas se quedan). No hay un umbral que bloquee la opción (confirmado el 29 de octubre de 2026).
  - Se desactiva al final, solo si todas las filas se escribieron. Cuáles se desactivaron queda en `import_deactivations`; reactivarlos es una edición a mano en el catálogo.
  - Un registro existente inactivo se actualiza con los datos del archivo, pero sigue inactivo; la fila lleva una advertencia.
  - Un registro borrado con la misma clave natural no se restaura: la fila es un error.
- Los catálogos de Academic se importan en orden, porque cada uno busca por clave lo que trae el anterior: departamentos, carreras, planes, materias por plan y seriación; después, alumnos y kardex. La exportación de carreras trae un renglón por cada campus donde se ofrece la carrera: cada renglón da de alta la carrera si falta y su oferta en ese campus (`career_campus`), y dos renglones de la misma carrera en campus distintos no son filas repetidas. Esos renglones deben traer el mismo departamento: si uno posterior trae otro, es error y no se aplica; si solo cambia el nombre, avisa y se queda el del primero (confirmado el 29 de octubre de 2026). La oferta de una carrera inactiva nace inactiva, y un departamento o campus inactivo solo avisa (confirmado el 29 de octubre de 2026).
- «Materias por plan» trae un renglón por cada plan en que está una materia: cada renglón da de alta o actualiza la materia (global, por su clave) y su lugar en la retícula del plan, con su semestre y su especialidad. Esos renglones deben coincidir en créditos, horas y departamento: si uno posterior no coincide, es error y no se aplica; si solo cambia el nombre, avisa y se queda el del primero. La especialidad que el plan no tiene se crea con su clave, y con su nombre si el archivo lo trae; la de un plan inactivo nace inactiva. Una materia que tenía especialidad y el SII ya no se la trae la conserva, con advertencia, porque quitarla cambia el avance de los alumnos y es una edición a mano (confirmado el 29 de octubre de 2026). Como el SII puede dar claves distintas a la «misma» materia, una materia con el mismo nombre que otra de otra clave, en la base o en el mismo archivo, solo avisa: no se unifican. Una materia que se quitó de una retícula no vuelve a ella por importación. Al importar materias o seriación se recalcula el avance de los alumnos.
- La seriación importada es del plan: materia, materia requisito y un tipo opcional. Sin tipo es seriación; con la columna, acepta seriación o correquisito. Es error si alguna de las dos materias no está en la retícula del plan, si una materia se pide a sí misma, o si una seriación cierra un ciclo con la que ya tiene el plan o con renglones anteriores del archivo (confirmado el 29 de octubre de 2026); un ciclo dentro del archivo ya es error al validar, y un renglón que vuelve correquisito una seriación cuenta para los que siguen. Los correquisitos no forman ciclos, porque no bloquean.
- Un plan importado sin nombre se llama como su clave hasta que se capture. Uno que el SII no marca vigente nace inactivo; uno activo que el SII deja de marcar vigente sigue activo, con advertencia, porque importar no desactiva (confirmado el 29 de octubre de 2026). El plan de una carrera inactiva nace inactivo, y un plan no cambia de carrera por importación, porque la llave compuesta de `students` cambiaría de carrera a sus alumnos: eso es una edición a mano (confirmado el 29 de octubre de 2026).
- Un plan solo cambia de carrera, a mano, si ningún alumno no borrado lo tiene (cualquiera sea su estatus): la llave compuesta `(study_plan_id, career_id)` de `students` movería la carrera de cada uno. `UpdateStudyPlan` pregunta con `PlanCareerChanging` y Students lo veta con «Tiene N alumnos inscritos en este plan.». Cambiar la carrera o los créditos totales de un plan avisa `CurriculumChanged` para recalcular el avance (confirmado el 29 de octubre de 2026).
- Dentro de un mismo archivo, una fila que repite la clave natural de otra fila anterior es error ("ya viene en la fila N") y solo se aplica la primera. Una fila que ya tenía error no cuenta como la primera. Supuesto por confirmar: el más restrictivo, para no aplicar dos versiones del mismo registro en un lote.
- Al aplicar un lote se escriben las filas válidas y las que traen advertencia; las que traen error se saltan. El admin descarga los errores, corrige el archivo y lo vuelve a subir: como se aplica por clave natural, repetir la importación no duplica nada (confirmado el 27 de octubre de 2026).
- Una importación deja **una entrada de bitácora por lote aplicado** (quién, plantilla, altas, cambios y, si se eligió "desactivar faltantes", cuántos desactivó); el detalle por registro queda en `import_rows` y `import_deactivations` (fila original, normalizada y registro destino). No hay una entrada por registro importado (confirmado el 27 de octubre de 2026).
- Los usuarios nuevos de personal nacen activos con la contraseña por defecto, que sale de la variable `NEXUS_DEFAULT_PASSWORD` del `.env` (nunca del repositorio). Quien entra con ella queda obligado a cambiarla, no puede volver a elegirla como contraseña nueva, y el admin puede reiniciar la de un usuario a la de defecto (confirmado el 30 de octubre de 2026). Sin la variable no se crean usuarios.
- Acceso de docentes. Todo docente activo tiene usuario, con username = número de empleado, la contraseña por defecto y el rol `docente`; los que son solo registro para RH también (confirmado el 30 de octubre de 2026). Al nombrar a un docente jefe o jefe de proyecto docente de un departamento, con fechas, recibe el rol y su alcance con esas mismas fechas, y al terminar la jefatura termina también el alcance (confirmado el 30 de octubre de 2026). El alcance del jefe no lleva campus, porque es de todo el Tec (confirmado el 30 de octubre de 2026). El del jefe de proyecto docente lleva el campus de su jefatura (supuesto por confirmar). Como `valid_to` no puede ser anterior a `valid_from`, una jefatura que termina antes de empezar se borra con su alcance, con la misma regla que cierra los alcances de un departamento borrado (confirmado el 29 de octubre de 2026). Terminar una jefatura nunca la alarga: si ya termina antes de la fecha que se da, se queda como está (supuesto por confirmar). Un usuario inactivo no se nombra jefe ni jefe de proyecto docente (supuesto por confirmar).
- Baja de un docente: un docente inactivo desactiva su usuario y terminan sus jefaturas vigentes; si se reactiva, su usuario vuelve. Un admin nunca se desactiva por esto (confirmado el 30 de octubre de 2026). Un docente borrado cuenta como dado de baja, y la provisión de usuarios se la aplica si no se aplicó al borrarlo (supuesto por confirmar). La baja también termina las jefaturas que todavía no empiezan, que se borran, para que no vuelvan al reactivarlo (supuesto por confirmar).
- Quién da de baja a un docente: solo `admin` y la Subdirección; los jefes de departamento y de proyecto docente solo lo quitan de su departamento (confirmado el 30 de octubre de 2026). Nadie se da de baja a sí mismo (supuesto por confirmar).
- «Quitar de mi departamento» quita, con borrado lógico, la adscripción del docente a ese departamento. Si era la principal, la principal pasa a la adscripción más antigua que le quede; cuando exista la carga de la fase 3, pasará a la del departamento donde tenga más horas asignadas (confirmado el 30 de octubre de 2026). Si no le queda ninguna, el docente sigue activo y sin departamento (confirmado el 30 de octubre de 2026). La más antigua es la de alta más antigua y, a igual fecha, la de id menor; quitar una que no es la principal no cambia la principal (supuesto por confirmar).
- Alcance del rol `docente`. Los docentes no están atados a un campus (confirmado el 30 de octubre de 2026). De ahí se sigue que el rol `docente` lleve un solo alcance, sin campus, mientras el docente esté activo, tenga o no departamento, y que al sincronizar su acceso se cierren los alcances de `docente` por campus que quedaron de antes. Un alcance de `docente` sin campus que todavía no empieza cuenta como el suyo, así que no se abre otro (supuesto por confirmar).
- La provisión de usuarios reutiliza un usuario existente con username = número de empleado si no está ligado a otro docente, sin tocar su contraseña; si está ligado a otro, lo reporta y no lo toca (S15, supuesto por confirmar). Ese usuario existente solo se liga si está activo y no tiene más rol que `docente`; si no, también es un conflicto que se reporta sin tocarlo y lo resuelve el admin, para que dar de alta un docente no reactive ni, con su baja, desactive una cuenta de personal (supuesto por confirmar). El usuario nuevo de un docente lleva su correo solo si ningún otro usuario lo usa; si no, se crea sin correo y el docente conserva el suyo (supuesto por confirmar).
- Fechas y horas en zona `America/Ciudad_Juarez`; en base de datos `time` sin zona para sesiones y `timestamptz` para eventos.
