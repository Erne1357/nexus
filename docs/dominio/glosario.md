# Glosario del dominio

Términos del proceso académico del ITCJ tal como los usa Nexus. Cuando el código necesite un nombre, usa la columna **Identificador en código** (inglés) y en la interfaz el término en español.

| Término | Identificador en código | Definición |
|---|---|---|
| **SII** | — | Sistema Integral de Información. Sistema actual del ITCJ que Nexus reemplaza por etapas. Es la fuente de las exportaciones iniciales. |
| **Campus** | `Campus` | Unidad donde se imparte la oferta: Campus I, Campus II, EAD, EnLínea, TecNM Virtual. Cada campus tiene una **modalidad** y un perfil de flujo. |
| **Modalidad** | `Modality` (enum) | Forma de impartición: `presencial` (Campus I y II), `mixta` (EAD: virtual lunes a jueves, presencial viernes y sábado), `en_linea` (EnLínea), `virtual_tecnm` (TecNM Virtual, docentes del TecNM). Cambia qué pasos del flujo aplican. |
| **Periodo escolar / semestre** | `Term` | Ciclo identificado con el código del SII: año y número, sin guión. `20271` es Ene-Jun, `20272` verano y `20273` Ago-Dic de 2027. "El siguiente periodo" es el siguiente semestre (Ene-Jun o Ago-Dic): el verano no cuenta (confirmado el 29 de octubre de 2026). Toda carga académica pertenece a un periodo. |
| **Etapa del periodo** | `TermStage` (enum `Stage`) | Ventana de fechas de cada paso del proceso dentro de un periodo (validación de catálogos, propuesta, asignación docente, cierre, publicación, re-análisis). |
| **Departamento académico** | `Department` | Unidad que agrupa docentes y materias (Sistemas y Computación, Ciencias Básicas, Industrial, Eléctrica-Electrónica, Metal-Mecánica, Económico-Administrativas, etc.). Su **jefe** asigna docentes y salones. |
| **Jefe de departamento académico** | rol `jefe_departamento` | Responsable de asignar docentes y salones a los grupos de las materias de su departamento, de validar sus catálogos y de cerrar su carga. |
| **Jefe de proyecto docente** | rol `jefe_proyecto_docente` | Co-responsable, junto al jefe de departamento, de la asignación docente según prioridad de base. |
| **Coordinador de carrera** | rol `coordinador` | Analiza el avance de los alumnos de su carrera en un campus, propone qué materias y cuántos grupos abrir y arma la propuesta de horarios por semestre reticular. |
| **Subdirección Académica** | rol `subdireccion` | Instancia superior que, si el campus lo configura, da el visto bueno final antes de publicar la carga. |
| **Carrera / programa educativo** | `Career` | Licenciatura o ingeniería (ISC, IGE, Industrial, etc.). Se ofrece en uno o varios campus. |
| **Plan de estudios / retícula** | `StudyPlan` | Versión del plan de una carrera: lista de materias por semestre con créditos y seriación. Una carrera puede tener varios planes (vigentes e inactivos). |
| **Materia / asignatura** | `Subject` | Unidad curricular con clave (ej. `ACF-0901`), horas teoría/práctica y créditos. La imparte un departamento y puede aparecer en varias retículas (tronco común). |
| **Seriación / prerrequisito** | `SubjectPrerequisite` | Regla de la retícula: para cursar una materia hay que haber aprobado otra. |
| **Especialidad** | `Specialty` | Módulo de materias que el alumno elige en los últimos semestres de su plan. |
| **Semestre reticular** | `semester` en `StudyPlanSubject` | Posición sugerida de la materia en la retícula (1 a 9). El "alumno tipo" de un semestre cursa las materias de ese semestre, por eso no deben empalmarse. |
| **Avance reticular** | `StudentProgress` | Qué materias ha aprobado, cursa o le faltan a un alumno respecto a su plan. Base de la proyección de demanda. |
| **Kardex** | `KardexRecord` | Historial de materias cursadas por un alumno con calificación y resultado. |
| **Docente / profesor** | `Teacher` | Persona que imparte grupos. Pertenece a un departamento y puede tener carga en varios campus. |
| **Plaza / base** | `TeacherPosition` | Nombramiento del docente que define sus **horas base**: tiempo completo (40 h), tres cuartos (30 h), medio tiempo (20 h), asignatura (N h) u honorarios (0 h base). La base máxima es 40 h. |
| **Prioridad de base** | — | Regla de asignación: primero se asignan materias a los docentes con más horas base; al final a los de honorarios. |
| **Desempate docente** | `StaffingRanking` | Criterio para ordenar docentes con la misma base: historial de haber impartido la materia y perfil, luego evaluación docente. Nexus lo calcula y lo muestra. |
| **Horas externas** | `TeacherExternalLoad` | Horas que un docente del ITCJ imparte fuera del campus (por ejemplo para TecNM Virtual). Reducen su disponibilidad. |
| **Edificio / salón** | `Building` / `Room` | Espacio físico con capacidad y tipo (aula, laboratorio, taller, sala de cómputo). Un salón puede tener un departamento "dueño". |
| **Salón por defecto** | `SubjectRoomDefault` | Salón donde una materia se imparte siempre o preferentemente. Nexus lo sugiere al asignar. |
| **Préstamo de salón** | `InterdepartmentalAgreement` (kind `room_loan`) | Acuerdo de la junta interdepartamental para que un departamento use un salón de otro en ciertos bloques. |
| **Grupo** | `Group` | Oferta concreta de una materia en un periodo y campus: tiene docente, sesiones (horario), salón, capacidad y una **carrera dueña**. |
| **Sesión / bloque** | `GroupSession` | Franja de un grupo en un día, con hora de inicio y fin (bloques de 1 hora, 7:00 a 21:00, lunes a viernes en presencial) y salón. |
| **Paralelo** (término SII) | `GroupQuota` | En el SII, un "paralelo" permitía que alumnos de otra carrera entraran a un grupo, sin límite. En Nexus se reemplaza por **cupos por carrera**: cada grupo reparte su capacidad entre carreras con un máximo por carrera. |
| **Cupo por carrera** | `GroupQuota` | Número máximo de lugares de un grupo reservados para una carrera. La suma de cupos no puede exceder la capacidad del grupo. |
| **Carga académica** | `AcademicLoad` | Conjunto de grupos de un departamento en un campus y periodo, con su estado en el flujo (borrador → propuesta → asignación → ajustes → cerrada → aprobada → publicada → re-análisis). |
| **Propuesta de carrera** | `CareerProposal` | Lista de materias y número de grupos que el coordinador pide abrir para su carrera en un campus y periodo, con los horarios tentativos. |
| **Junta interdepartamental** | — | Reunión por periodo donde los departamentos acuerdan grupos compartidos, cupos por carrera y préstamos de salones. En Nexus queda registrada como acuerdos. |
| **Solicitud de cambio** | `ChangeRequest` | Petición de un docente (o coordinador) para cambiar horario, salón o docente de un grupo ya asignado. Pasa por aprobación. |
| **Re-análisis** | etapa `reanalysis` | Revisión de la carga ya publicada cuando llegan las calificaciones finales: la demanda real cambia por reprobación y hay que abrir, cerrar o ampliar grupos. |
| **Choque / empalme** | `ScheduleConflict` | Violación de una restricción dura: mismo docente, salón o semestre reticular en dos sesiones al mismo tiempo, capacidad excedida, horas base excedidas, etc. |
| **Restricción dura / blanda** | — | Dura: nunca se viola (empalmes, capacidad, disponibilidad). Blanda: se intenta cumplir y se puntúa (horas seguidas, salón por defecto, preferencias). |
| **Motor / solver** | `SolverRun` | Componente que sugiere horarios y asignaciones: primero una heurística en PHP, después un servicio Python con OR-Tools CP-SAT bajo el mismo contrato. |
| **Orden de inscripción** | `EnrollmentOrder` (R3) | Prioridad con la que cada alumno puede inscribirse (semestre, promedio, créditos faltantes). |
| **Cita de inscripción** | `EnrollmentAppointment` (R3) | Ventana de fecha y hora en la que el alumno puede entrar a inscribirse. |
| **Catálogo** | — | Datos maestros: campus, departamentos, carreras, planes, materias, docentes, salones, alumnos. Se cargan por importación y los jefes los validan cada periodo. |
| **Importación / lote** | `ImportBatch` | Archivo exportado del SII (Excel/CSV) cargado a Nexus, validado fila por fila y aplicado a un catálogo. |
| **Validación de catálogos** | `CatalogReview` | Paso del flujo en el que cada jefe revisa, corrige y marca como validados los catálogos de su departamento antes de que inicie la carga. |
| **Bitácora** | `activity_log` | Registro inmutable de quién cambió qué y cuándo. Nunca se borra. |
