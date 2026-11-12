# ADR-0025 — Modalidad como catálogo y unidad de la carga

**Estado:** Aceptada; supuestos por confirmar (MS1 a MS3) · **Fecha:** 2026-11-12 · Reemplaza a [ADR-0009](0009-modalidad-como-perfil-de-campus.md) y a las decisiones de modelado 1 y 4 de `docs/dominio/modelo-de-datos.md`

## Contexto
ADR-0009 hizo de cada modalidad un campus con su perfil de flujo en `campuses.settings`: EAD, EnLínea y TecNM Virtual eran filas de `campuses`, y la carga se cerraba por departamento en un campus (decisión de modelado 4, ADR-0008). Al planear la carga por modalidad (#418), el usuario confirmó el 12 de noviembre de 2026:

- **M1.** La unidad de la carga es el departamento en una modalidad: Sistemas presencial es una sola carga con los grupos de C1 y C2, y el jefe la cierra y la publica una vez. Cada grupo conserva su campus (dónde se da) para los alumnos.
- **M2.** EAD, En línea y TecNM Virtual son modalidades, no campus. Campus es un lugar físico (C1, C2); los grupos de EAD con sesión presencial usan salones de C1 o C2, como en el SII, donde el alumno trae campus y modalidad por separado.
- **M3.** Hay cuatro modalidades: Presencial; EAD, que reúne lo que el SII llama «a distancia» y «mixta»; En línea, la única 100 % en línea; y TecNM Virtual, virtual y sin clases (cursos ya creados que cada quien avanza a su ritmo).
- **M4.** El periodo se abre y se calendariza por modalidad: Presencial se abre una vez para C1 y C2 con un solo calendario.

Con la modalidad como atributo del campus no hay dónde poner una carga de Presencial que junte a C1 y C2, ni un perfil de flujo que no sea de un lugar.

## Decisión
1. **La modalidad es un catálogo de Core** (MS1, supuesto por confirmar): `modalities` con `code` (`PRES`, `EAD`, `ENL`, `TNMV`; único aun entre borrados), `name`, `settings` (jsonb con el perfil de flujo, `Modality::profile` lo expone como `ModalityProfile`), `is_active` y borrado lógico. `ModalityProfile` es el `CampusProfile` de hoy sin `heads_edit_rooms`, que es de la validación de salones y no de la modalidad. Pantallas, validadores y transiciones consultan el perfil, nunca la clave (`if ($modality->code === 'PRES')` está prohibido). El catálogo reemplaza a `campuses.modality`, `campuses.settings` y a los enums `Core\Enums\Modality` y `Students\Enums\StudentModality`.
2. **Siembra** (MS2, supuesto por confirmar): `PRES` Presencial, activa, con el perfil de los campus presenciales activos sin `heads_edit_rooms` (si dos difieren en otra clave, la migración se detiene y lista las diferencias); `EAD`, `ENL` En línea y `TNMV` TecNM Virtual, inactivas y solo con lo documentado: las tres con asignación central; EAD de lunes a sábado con salones; En línea sin salones; TecNM Virtual sin salones, sin bloques de tiempo y con docentes externos. El resto de sus perfiles se define en la fase 10.
3. **Campus = lugar** (MS3, supuesto por confirmar): `campuses` queda con `code`, `name` e `is_active`, sin modalidad ni perfil. Las filas `EAD`, `ENL` y `TNMV` se desactivan y se borran lógicamente, nunca físicamente; la migración se detiene y lista lo que las referencie si algo lo hace.
4. **Toda tabla de la carga lleva `term_id` y `modality_id`**; `campus_id` solo donde algo es físico (dónde se da el grupo, el salón, el alumno).
5. **Migración en tres pasos**, para que cada PR quede verde y los módulos avancen en paralelo:
   1. *Ampliar:* `modalities` sembrada y `modality_id` agregado y llenado junto a cada `campus_id` (C1 y C2 → `PRES`; la fila de campus `EAD` → `EAD`, etc.). Mientras dura, `campuses.modality_id` es un puente temporal que llenan `CreateCampus`, `UpdateCampus`, `CampusSeeder` y `CampusFactory` (`Core\Enums\Modality::catalogCode()`). Las Actions escriben `campus_id` y `modality_id`; ningún lector cambia.
   2. *Cambiar lectores por módulo:* cada módulo pasa sus consultas, permisos y pantallas al alcance por modalidad con el campus como filtro.
   3. *Contraer:* se funden las cargas y propuestas de C1 y C2, las llaves únicas pasan a modalidad y se quitan el puente, `campuses.modality`, `campuses.settings`, `CampusProfile` y los enums viejos.

## Consecuencias
- La carga, la propuesta, la apertura del periodo y su calendario son por modalidad; el campus queda como filtro dentro de la página y como dato de cada grupo, salón y alumno.
- La unidad de cierre de [ADR-0008](0008-cierre-por-departamento.md) pasa de departamento en un campus a departamento en una modalidad; el visto bueno configurable de Subdirección se queda, ahora en el perfil de la modalidad.
- Activar otra modalidad es activar su fila y ajustar su perfil (fase 10), sin filas nuevas en `campuses`.
- Durante el paso 1 conviven `campuses.modality` y `campuses.modality_id`; quien escriba un campus sin las Actions, el sembrador o la factory lo deja sin puente (columna nula) hasta que una de ellas lo guarde.
- R1 y R2 siguen implementando solo Presencial.

## Alternativas descartadas
- **Seguir con la modalidad como perfil del campus (ADR-0009):** obliga a que la carga de Presencial sean dos cargas (C1 y C2) y a tratar EAD, En línea y TecNM Virtual como lugares que no son.
- **Tabla campus × modalidad** (MS4): ningún caso documentado la necesita; si la fase 10 tiene que limitar qué campus usa EAD, se agrega entonces.
- **Migrar todo en un solo paso:** toca cerca de 300 archivos de AcademicLoad, Scheduling y Staffing en un PR imposible de revisar y bloquea a los demás rieles.
