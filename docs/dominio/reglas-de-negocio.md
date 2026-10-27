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

- Un alumno es **elegible** para una materia de su plan si aprobó todas sus seriaciones y no la ha aprobado.
- R1: `eligible_students` por materia, carrera y campus se calcula desde `student_progress` al importar kardex.
- R2: `expected_students = eligible_students x (1 - tasa_de_no_inscripción) + repetidores_esperados`, con tasas históricas por materia y campus (`demand.history_terms`, por defecto 4). `suggested_groups = ceil(expected_students / tamaño_objetivo)`, con tamaño objetivo configurable por tipo de materia (`demand.target_group_size`, por defecto 35; laboratorios 25).

## Aprobaciones y permisos

| Acción | Quién | Condición |
|---|---|---|
| Abrir periodo, calendario, perfil de campus | `admin`, `subdireccion` | `subdireccion`, solo en sus campus y solo el perfil de flujo; crear un campus o cambiar su código, nombre, modalidad o estado es solo de `admin` |
| Administrar edificios y salones | `admin`, `subdireccion` de su campus | |
| Importar catálogos | `admin` | |
| Validar catálogos del departamento | `jefe_departamento` del departamento y campus | |
| Enviar propuesta de carrera | `coordinador` de la carrera y campus | catálogos de los departamentos involucrados validados (aviso, no bloqueo) |
| Crear grupos y editar sesiones | `coordinador` (de su carrera dueña), `jefe_departamento` (de su departamento) | carga en `proposed`, `staffing` o `adjusting` |
| Definir cupos por carrera | `jefe_departamento` del departamento que imparte | acuerdo registrado si involucra otra carrera de otro departamento |
| Asignar docente | `jefe_departamento`, `jefe_proyecto_docente` del departamento y campus | HC-08, HC-09, HC-16 |
| Solicitar cambio | `docente` asignado al grupo, `coordinador` | carga en `staffing` o posterior |
| Aprobar o rechazar cambio | `jefe_departamento` | |
| Asignar salón | `jefe_departamento` | HC-02, HC-05, HC-06, HC-10, HC-11 |
| Proponer o aceptar préstamo de salón | `jefe_departamento` de ambos departamentos | |
| Cerrar carga | `jefe_departamento` | HC-15 |
| Visto bueno | `subdireccion` | solo si `requires_subdireccion_approval` |
| Publicar | `subdireccion`, o `jefe_departamento` si el campus no exige visto bueno | carga `closed` o `approved` |
| Re-análisis | `coordinador`, `jefe_departamento` | nueva importación de kardex (R2) |

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

- Claves naturales únicas: `subjects.code`, `careers.code`, `study_plans.code`, `teachers.employee_number`, `students.control_number`, `rooms.code` por campus, `groups.code` por periodo y campus.
- Importar nunca borra ni reactiva: si un registro no viene en el archivo, se marca `is_active = false` solo cuando el admin elige explícitamente "desactivar faltantes" para ese lote.
  - Un registro existente inactivo se actualiza con los datos del archivo, pero sigue inactivo; la fila lleva una advertencia.
  - Un registro borrado con la misma clave natural no se restaura: la fila es un error.
- Dentro de un mismo archivo, una fila que repite la clave natural de otra fila anterior es error ("ya viene en la fila N") y solo se aplica la primera. Una fila que ya tenía error no cuenta como la primera. Supuesto por confirmar: el más restrictivo, para no aplicar dos versiones del mismo registro en un lote.
- Al aplicar un lote se escriben las filas válidas y las que traen advertencia; las que traen error se saltan. El admin descarga los errores, corrige el archivo y lo vuelve a subir: como se aplica por clave natural, repetir la importación no duplica nada (confirmado el 27 de octubre de 2026).
- Una importación deja **una entrada de bitácora por lote aplicado** (quién, plantilla, altas y cambios); el detalle por registro queda en `import_rows` (fila original, normalizada y registro destino). No hay una entrada por registro importado (confirmado el 27 de octubre de 2026).
- Fechas y horas en zona `America/Ciudad_Juarez`; en base de datos `time` sin zona para sesiones y `timestamptz` para eventos.
