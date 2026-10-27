# Fase 10 — Otras modalidades: EAD, EnLínea, TecNM Virtual

**Entrega:** R4 · **Ventana:** por definir · **Modo:** por definir · **Estado:** 🟦 (vaga) · **Milestone:** `Fase 10 — Otras modalidades`

> Fase vaga. El flujo de carga académica descrito en `docs/proceso/proceso-propuesto.md` es el de modalidad presencial (Campus I y II). Las otras tres modalidades tienen procesos distintos que todavía no se han levantado. Nada de esta fase se implementa hasta terminar la sesión de descubrimiento y convertirla en ⬜.

## Objetivo

Que Nexus construya, valide y publique la carga académica (y después la inscripción) de los campus EAD, EnLínea y TecNM Virtual, reutilizando los módulos de R1 y R2 y activando por **perfil de flujo** solo las diferencias reales de cada modalidad, sin bifurcar el código por campus.

## Lo que ya sabemos

| Modalidad | Campus | Lo confirmado | Lo no confirmado |
|---|---|---|---|
| Mixta | **EAD** | Clases virtuales de lunes a jueves y presenciales viernes y sábado. Docentes propios, distintos a los de presencial. Mismas carreras y mismas retículas que presencial. | Si las sesiones virtuales tienen horario fijo o son asíncronas; quién propone y quién asigna; si los salones del viernes y sábado se toman del catálogo de Campus I o II. |
| En línea | **EnLínea** | Existe como oferta. | Todo lo demás: carreras, retículas, docentes, horarios, asignación, publicación. |
| Virtual | **TecNM Virtual** | Los docentes son del TecNM, no del ITCJ. Algunos docentes del ITCJ dan clases ahí y esas horas cuentan como **horas externas** en su carga presencial (`teacher_external_loads`). | Si el ITCJ construye alguna carga para esta modalidad o solo registra las horas de sus docentes; si hay retículas o materias distintas. |
| Común a las tres | — | La asignación docente es **centralizada**, no por jefe de departamento. Puede haber retículas o materias distintas a las presenciales. | Quién es la instancia central y con qué criterios asigna. |

## Lo que el modelo ya prevé

El diseño de R1 dejó listos los puntos de extensión para no tocar el esquema:

- **Perfil de flujo** en `campuses.settings`: `modality`, `uses_rooms`, `uses_time_blocks`, `staffing_mode` (`department` / `central`), `days`, `block_minutes`, `day_start`, `day_end`, `allow_external_teachers`, `requires_subdireccion_approval`. Las pantallas y el validador consultan el perfil, no el código del campus (ver `docs/dominio/reglas-de-negocio.md`, "Parámetros por campus").
- **Sesiones de tipo `virtual`** en `group_sessions`: un grupo de EAD puede tener sesiones virtuales lunes a jueves y presenciales con salón viernes y sábado. Cuando `uses_time_blocks` es falso, el grupo puede no tener sesiones.
- **Horas externas** (`teacher_external_loads`) por docente y periodo, que ya descuentan disponibilidad en HC-08.
- **Docentes externos**: `allow_external_teachers` permite registrar docentes que no pertenecen a un departamento del ITCJ, con `home_campus_id` en el campus virtual.
- **Campus como atributo**: EAD, EnLínea y TecNM Virtual ya existen como filas de `campuses` sembradas en fase 0, inactivas para el flujo hasta esta fase.
- **Materias globales y retículas por plan**: si una modalidad usa un plan distinto, se registra como otro `study_plan` de la misma carrera; si usa materias distintas, se dan de alta en `subjects` con su clave.

## Preguntas de descubrimiento por modalidad

Para cada una de las tres modalidades hay que responder:

1. **Propuesta**: ¿quién decide qué materias y cuántos grupos se abren? ¿Existe un coordinador por carrera o una coordinación de modalidad?
2. **Asignación docente**: ¿quién es la instancia central, con qué criterio ordena a los docentes y aplica la prioridad por base de horas? ¿Los docentes de EAD tienen plazas y evaluación como los presenciales?
3. **Horarios**: ¿hay bloques fijos (y con qué ventana y días) o es asíncrono? En EAD, ¿las sesiones virtuales tienen hora?
4. **Salones**: ¿EAD usa salones de Campus I o II los viernes y sábados? ¿Entran al mismo validador de empalmes de salón que presencial?
5. **Plataformas virtuales**: ¿hay un "aula virtual" que equivalga a salón (con capacidad) o no hay recurso que asignar?
6. **Publicación**: ¿cómo y dónde se publica hoy la carga de cada modalidad? ¿Qué formato esperan docentes y alumnos?
7. **Inscripción**: ¿cómo se inscriben hoy; comparten orden y citas con presencial o tienen proceso propio?
8. **Datos**: ¿las exportaciones del SII separan por modalidad o vienen mezcladas con presencial? ¿Las claves de materia coinciden?
9. **TecNM Virtual**: ¿el ITCJ solo registra horas de sus docentes o también opera grupos? ¿De dónde sale la lista de docentes del TecNM?

## Plan de la sesión de descubrimiento

| Paso | Qué | Con quién | Duración |
|---|---|---|---|
| 1 | Entrevista por modalidad siguiendo las nueve preguntas; dibujar el flujo actual al estilo de `docs/proceso/proceso-actual.md` | Responsable de EAD; responsable de EnLínea; enlace del ITCJ con TecNM Virtual; Subdirección Académica | Una sesión de 90 minutos por modalidad |
| 2 | Pedir documentos y exportaciones: carga del periodo anterior de cada modalidad, lista de docentes y plazas, retículas si difieren, calendario de su proceso | Los mismos responsables y sistemas del ITCJ | Una semana de espera |
| 3 | Redactar `docs/proceso/proceso-actual-<modalidad>.md` con flujo y deficiencias, y la tabla de diferencias contra presencial | Equipo | Dos días |
| 4 | Sesión de brainstorming con el equipo para decidir perfil de flujo por campus y cambios por módulo; registrar ADR | Equipo | Medio día |
| 5 | Convertir este archivo en fase detallada (tareas, pruebas, criterios, reparto) con writing-plans | Equipo | Un día |

Duración total estimada del descubrimiento: tres semanas de calendario, una de trabajo efectivo.

## Cambios previsibles por módulo (sin detallar)

| Módulo | Qué podría cambiar |
|---|---|
| `Core` | Activar los perfiles de flujo de EAD, EnLínea y TecNM Virtual con valores reales; etapas de periodo propias por campus en `term_stages`; posible rol nuevo para la instancia central de asignación. |
| `Academic` | Planes o materias propios de una modalidad, si los hay, como filas nuevas (sin cambio de esquema). |
| `Faculty` | Docentes externos del TecNM; plazas y evaluación de docentes de EAD si existen; horas externas alimentadas también desde la carga de TecNM Virtual cuando Nexus la conozca. |
| `Facilities` | Salones compartidos entre un campus presencial y EAD en viernes y sábado; "aula virtual" como tipo de salón sin ubicación si el descubrimiento lo justifica. |
| `Scheduling` | Reglas HC-04 y HC-12 parametrizadas por perfil (días y tolerancia de horas para mixta); validador que ignore salón en sesiones virtuales; editor de horarios con sesiones virtuales y sábados. |
| `Staffing` | Modo `central`: el ranking y la asignación los opera la instancia central sobre todos los departamentos; misma regla de desempate salvo que el descubrimiento diga otra cosa. |
| `AcademicLoad` | Unidad de cierre distinta si no hay departamentos (por ejemplo, por campus o por carrera); transiciones y aprobaciones según quién publica en cada modalidad. |
| `Enrollment` | Orden, citas y auto-inscripción por modalidad si el proceso difiere del presencial. |
| `Imports` | Plantillas o columnas adicionales si las exportaciones del SII separan modalidad. |

## Criterios para pasar a detallada

1. Existe un `docs/proceso/proceso-actual-<modalidad>.md` por modalidad con flujo, actores, deficiencias y tabla de diferencias contra presencial.
2. Las nueve preguntas de descubrimiento tienen respuesta escrita con fecha y fuente, por modalidad.
3. El perfil de flujo de cada campus está definido valor por valor en `docs/dominio/reglas-de-negocio.md` ("Parámetros por campus").
4. Las reglas nuevas o modificadas tienen identificador HC/SC propio.
5. Hay una ADR en `docs/decisiones/` con el orden en que se abordan las modalidades y por qué.
6. Este archivo tiene tabla de tareas con el formato de las fases 1 a 4, reparto y fechas, y `PLAN.md` refleja la ventana.
