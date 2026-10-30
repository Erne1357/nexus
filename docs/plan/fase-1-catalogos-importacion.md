# Fase 1 — Catálogos e importación

**Entrega:** R1 · **Ventana:** 3 – 14 nov 2026 · **Modo:** 3 en paralelo · **Estado:** 🟨 · **Milestone:** `Fase 1 — Catálogos e importación`

## Objetivo

Tener en Nexus todos los catálogos del proceso presencial cargados desde exportaciones del SII, con pantallas de consulta y edición para administradores, y el avance reticular de los alumnos calculado. Al terminar, el hito H2: las exportaciones reales de Campus II importadas sin errores bloqueantes.

## Dependencias

- Fase 0 terminada (módulo de referencia, `ScopedContext`, CI).
- **Exportaciones del SII** solicitadas el 27 de octubre (ver "Datos que hay que pedir").

## Alcance

### Incluye

- Módulos `Academic`, `Facilities`, `Faculty`, `Students` con modelos, migraciones, factories, seeders realistas y CRUD de administrador.
- Módulo `Imports`: motor genérico de importación por archivos y una plantilla por catálogo.
- Cálculo de `student_progress` y consulta de alumnos elegibles por materia.
- Alta de usuarios de docentes y jefes a partir del catálogo de docentes.

### No incluye

- Validación por jefes (fase 2), pantallas de coordinador (fase 2), grupos (fase 3).

## Datos que hay que pedir al SII (semana del 27 de octubre)

| Exportación | Campos mínimos | Plantilla |
|---|---|---|
| Departamentos | clave, nombre | `departments` |
| Carreras | clave, nombre, departamento, campus donde se ofrece (un renglón por campus) | `careers` |
| Planes de estudio | clave de plan, carrera, versión, vigente | `study_plans` |
| Materias por plan | clave de plan, clave de materia, nombre, semestre, horas teoría, horas práctica, créditos, departamento que imparte, especialidad | `subjects` y `study_plans` |
| Seriación | clave de plan, materia, materia requisito | `prerequisites` |
| Docentes | número de empleado, nombre, departamento, campus, correo, grado, activo, fecha de ingreso | `teachers` |
| Plazas | número de empleado, tipo de plaza, horas base, vigencia | `positions` |
| Historial docente | número de empleado, clave de materia, periodo, campus | `teacher_history` |
| Evaluación docente | número de empleado, periodo, tipo, puntaje | `evaluations` |
| Salones | campus, edificio, clave, nombre, tipo, capacidad, departamento dueño | `rooms` |
| Alumnos | número de control, nombre, carrera, plan, campus, semestre, estatus | `students` |
| Kardex | número de control, clave de materia, periodo, calificación, resultado, oportunidad | `kardex` |

Mientras llegan, cada módulo trabaja con factories y un `DemoSeeder` que reproduce las 6 carreras de Campus II con materias de tronco común compartidas.

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 1.1 ✅ [#94](https://github.com/Erne1357/nexus/pull/94), [#95](https://github.com/Erne1357/nexus/pull/95), [#96](https://github.com/Erne1357/nexus/pull/96), [#97](https://github.com/Erne1357/nexus/pull/97), [#98](https://github.com/Erne1357/nexus/pull/98) | Motor de importación: modelos `ImportBatch`, `ImportRow`, `ImportMapping`; contrato de plantilla (columnas esperadas, reglas por fila, clave natural, aplicación por upsert); registro de plantillas; lectura por streaming con openspout | Imports | `Imports/Templates/` con una clase abstracta y un registro. El motor es genérico para que cada catálogo solo escriba su plantilla. Todas las plantillas viven en `Imports/Templates/`, también las de las tareas 1.4, 1.9, 1.13 y 1.15: Imports depende de los catálogos y no al revés. | Unit: contrato de plantilla con una plantilla de prueba; feature: subir, validar y aplicar un CSV | A |
| 1.2 ✅ [#99](https://github.com/Erne1357/nexus/pull/99), [#100](https://github.com/Erne1357/nexus/pull/100), [#101](https://github.com/Erne1357/nexus/pull/101), [#102](https://github.com/Erne1357/nexus/pull/102), [#103](https://github.com/Erne1357/nexus/pull/103) | Asistente de importación: subir archivo, elegir o crear mapeo de columnas, ver validación por fila (errores y advertencias), descargar errores, aplicar; todo en jobs de cola con progreso | Imports | `Imports/Livewire/pages/import-wizard`. Validar y aplicar son jobs porque un kardex puede tener cientos de miles de filas. | Feature: estados del lote; browser: asistente completo con archivo pequeño | A |
| 1.3 ✅ [#114](https://github.com/Erne1357/nexus/pull/114), [#115](https://github.com/Erne1357/nexus/pull/115) | Opción "desactivar faltantes" al aplicar (marca `is_active = false` lo que no venga en el archivo) con confirmación y bitácora | Imports | Regla de datos de `reglas-de-negocio.md`. Es la forma controlada de reflejar bajas del SII. | Feature: faltantes se desactivan solo con la opción activa | A |
| 1.4 ✅ [#118](https://github.com/Erne1357/nexus/pull/118), [#119](https://github.com/Erne1357/nexus/pull/119), [#120](https://github.com/Erne1357/nexus/pull/120) | `Students`: modelos y migraciones de `students`, `kardex_records`, `student_progress`; plantillas `students` y `kardex`; job de recálculo de progreso que esas plantillas despachan al aplicar un lote | Students | `Students/Jobs/RecomputeStudentProgress`. El progreso se materializa para que contar elegibles sea una consulta simple. Students no escucha eventos de Imports, porque el mapa de dependencias lo prohíbe: la plantilla de Imports despacha el job. | Unit: cálculo de progreso con seriación; feature: aplicar un lote de kardex despacha el job | A |
| 1.5 ✅ [#121](https://github.com/Erne1357/nexus/pull/121) | Consulta de elegibles: alumnos elegibles por materia, carrera y campus (aprobaron seriación, no aprobaron la materia) | Students | `Students/Queries/EligibleStudentsQuery`. La usará la propuesta del coordinador (fase 2) y Demand (R2). | Unit con factories: casos con y sin seriación, repetidores | A |
| 1.6 ✅ [#125](https://github.com/Erne1357/nexus/pull/125), [#126](https://github.com/Erne1357/nexus/pull/126), [#127](https://github.com/Erne1357/nexus/pull/127), [#128](https://github.com/Erne1357/nexus/pull/128), [#129](https://github.com/Erne1357/nexus/pull/129), [#130](https://github.com/Erne1357/nexus/pull/130) | Pantallas de alumnos: listado con filtros por carrera, plan, semestre y estatus; ficha con retícula coloreada por aprobada, cursando, pendiente | Students | Solo lectura. La vista de retícula se reutiliza en fase 2 para el coordinador. | Feature: filtros y alcance por campus; browser: abrir ficha | A |
| 1.7 ✅ [#116](https://github.com/Erne1357/nexus/pull/116), [#117](https://github.com/Erne1357/nexus/pull/117) | `Academic`: modelos y migraciones de departamentos, jefes, carreras, carrera-campus, planes, materias, materias por plan, seriación, especialidades | Academic | Modelo de datos. Claves naturales únicas para upsert. | Unit: relaciones y scopes; feature: integridad (una materia en dos planes, seriación por plan) | A (en lugar de B, acordado en #22) |
| 1.8 ✅ [#135](https://github.com/Erne1357/nexus/pull/135), [#136](https://github.com/Erne1357/nexus/pull/136), [#137](https://github.com/Erne1357/nexus/pull/137), [#139](https://github.com/Erne1357/nexus/pull/139), [#142](https://github.com/Erne1357/nexus/pull/142), [#143](https://github.com/Erne1357/nexus/pull/143), [#144](https://github.com/Erne1357/nexus/pull/144), [#145](https://github.com/Erne1357/nexus/pull/145) | CRUD de administrador para departamentos, carreras, planes y materias, con edición de la retícula (materias por semestre y seriación) | Academic | Páginas MFC copiadas del módulo de referencia. La edición de retícula es una cuadrícula por semestre. Lo que ya se decidió para esta tarea: no se desactiva una materia que sigue en un plan con alumnos que la necesitan; quitar una materia o una especialidad de la retícula resuelve su seriación y sus ubicaciones en la misma transacción; se rechaza una seriación que forme un ciclo; un cambio de retícula avisa con un evento para que Students recalcule el avance (`RecomputeStudentProgress`), porque Academic no depende de Students; y borrar una carrera o un departamento hace que sus alcances dejen de contar, desactivarlo no (reglas-de-negocio.md). | Feature por pantalla; browser: editar retícula | B |
| 1.9 ✅ [#131](https://github.com/Erne1357/nexus/pull/131), [#132](https://github.com/Erne1357/nexus/pull/132), [#133](https://github.com/Erne1357/nexus/pull/133), [#134](https://github.com/Erne1357/nexus/pull/134) | Plantillas `departments`, `careers`, `study_plans`, `subjects`, `prerequisites` | Academic | Cada plantilla valida claves cruzadas (la materia existe, el plan existe). `departments` se agregó porque carreras y materias traen la clave de su departamento y nada más los da de alta. | Unit por plantilla con fixtures; feature: aplicar en orden | A (en lugar de B, acordado en #24) |
| 1.10 ✅ [#138](https://github.com/Erne1357/nexus/pull/138), [#140](https://github.com/Erne1357/nexus/pull/140), [#141](https://github.com/Erne1357/nexus/pull/141) | Seeder de demostración: 6 carreras de Campus II, planes vigentes, tronco común compartido, seriación de ejemplo | Academic | Quedó en `Database\Seeders\DemoSeeder` (base + Academic + Students) con `AcademicDemoSeeder`, `DemoStaffSeeder` y `StudentsDemoSeeder`, más `MassiveSeeder` y el comando `imports:export-demo`. Necesario para desarrollo y browser tests mientras llegan datos reales. | Feature: seeder corre y deja datos consistentes | B |
| 1.11 ✅ [#159](https://github.com/Erne1357/nexus/pull/159), [#PR4](https://github.com/Erne1357/nexus/pull/PR4) | `Faculty`: modelos y migraciones de docentes, plazas, disponibilidad, horas externas, historial, perfil y evaluaciones | Faculty | Modelo de datos. `base_hours` vigente se obtiene por periodo. | Unit: horas base vigentes por fecha; relaciones | C |
| 1.12 | CRUD de docentes y plazas; editor de disponibilidad (cuadrícula día x hora con bloques no disponibles y preferidos); perfil por materia (apto, preferente) | Faculty | Páginas MFC. La disponibilidad y el perfil los captura el jefe en la validación (fase 2) pero las pantallas nacen aquí. | Feature por pantalla; browser: marcar disponibilidad | C |
| 1.13 | Plantillas `teachers`, `positions`, `teacher_history`, `evaluations` | Faculty | Historial y evaluaciones alimentan el ranking de fase 3. | Unit por plantilla; feature: aplicar | C |
| 1.14 | Alta de usuarios desde docentes: crear `users` con username = número de empleado, contraseña generada, `must_change_password`; asignar rol `docente`; asignar `jefe_departamento` y alcances desde `department_heads` | Faculty, Core | Action `ProvisionTeacherUsers`. Es la única forma de dar acceso a cientos de docentes sin capturarlos a mano. | Feature: provisión idempotente; usuarios inactivos no se crean | C |
| 1.15 | `Facilities`: modelos y migraciones de edificios, salones, bloqueos, salones por defecto; CRUD; plantilla `rooms` | Facilities | Modelo de datos. `capacity` y `type` son los campos críticos para el validador. El CRUD es de `admin` y de `subdireccion` en su campus, y toma el campus del contexto activo. | Unit, feature, plantilla | C |
| 1.16 | Integración: importar las exportaciones reales de Campus II de principio a fin en el servidor de pruebas; registrar incidencias | Todos | Hito H2. Se hace juntos los días 13 y 14. Mientras el despliegue siga postergado (ver pendientes de la fase 0), se hace en el entorno local con `docker compose`. | Lista de incidencias cerrada o convertida en issues | A, B, C |

## Pruebas de la fase

- **Unit:** plantillas (cada regla de validación), cálculo de progreso, elegibles, horas base vigentes.
- **Feature:** CRUD con políticas y alcance, jobs de importación, provisión de usuarios, seeders.
- **Browser:** asistente de importación, edición de retícula, disponibilidad docente.
- **Arquitectura:** `Imports` no depende de `CatalogValidation`; ningún módulo de catálogo depende de `Scheduling`.

## Criterios de aceptación

1. Cada catálogo se puede importar desde un archivo del SII con un mapeo guardado, ver errores por fila y aplicar sin duplicar registros al repetir la importación.
2. Los administradores pueden consultar y editar todos los catálogos con bitácora de cambios.
3. `student_progress` y elegibles por materia son correctos en un conjunto de prueba con seriación.
4. Todos los docentes y jefes activos de Campus II tienen usuario y pueden entrar.
5. CI verde con cobertura mínima de 80 % en los módulos de la fase.

## Reparto

| Persona | Módulos | Carga |
|---|---|---|
| A | Imports, Students | Motor primero (1.1 y 1.2, ya en `develop`), luego 1.3 y alumnos cuando esté la 1.7 |
| B | Academic | Modelos primero (1.7, los necesitan A y C), luego CRUD y retícula; es el módulo con más pantallas |
| C | Faculty, Facilities | Edificios mientras llega la 1.7, luego docentes (los necesita fase 3), salones al final |

Dependencias entre personas:

- **Contrato de plantilla (1.1, A).** B y C lo necesitan para sus plantillas (1.9, 1.13 y 1.15). Ya está en `develop`; la plantilla de ejemplo para copiar es `tests/Fixtures/ImportTemplates/CampusesTemplate.php`.
- **Modelos de Academic (1.7, B).** Cuatro tareas no pueden escribir sus migraciones sin las tablas de Academic, porque llevan llaves foráneas a ellas:

  | Tarea | Persona | Necesita |
  |---|---|---|
  | 1.4 | A | `students.career_id` y `study_plan_id`; `kardex_records.subject_id` |
  | 1.11 | C | `teachers.department_id`; `subject_id` en el historial y el perfil docente |
  | 1.14 | C | `department_heads` para dar el rol y el alcance de los jefes |
  | 1.15 | C | `rooms.department_id` (dueño); `subject_room_defaults.subject_id` |

  B hace la 1.7 antes que el CRUD y la mezcla a más tardar el día 2. Basta con las migraciones, los modelos y las factories, para que las pruebas de A y C creen filas reales. Mientras, A trabaja en la 1.3 y C en `buildings` y su CRUD (parte de la 1.15), que solo dependen de `campuses`.

## Riesgos y notas

- Si las exportaciones llegan sin seriación o sin historial docente, se avanza con datos vacíos y el jefe los completa en fase 2. No bloquear.
- Kardex de todos los alumnos puede ser grande; validar en chunks y nunca cargar el archivo completo en memoria.
- El SII puede usar claves de materia distintas entre planes para la "misma" materia; la plantilla debe reportarlo como advertencia, no unificar por nombre.
