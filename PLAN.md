# Nexus — Plan maestro

> Archivo de estado. Se actualiza en cada PR que cierre o abra tareas de una fase. Contexto en [PROJECT.md](PROJECT.md); reglas de trabajo en [CLAUDE.md](CLAUDE.md).

**Última actualización:** 2026-11-08 · **Fase activa:** 1 (1.16 en curso: migración repetible del SII, parte 1 en #257 a #271; la parte 2 espera el kárdex, la seriación, los periodos y la evaluación docente); la fase 2 se cerró el 2 de noviembre (2.1 a 2.9); en la fase 3, con tres rieles en paralelo, 3.1 a 3.12 ya están en `develop` y falta la integración 3.13 · **Entrega en curso:** R1 · **Tablero:** https://github.com/users/Erne1357/projects/3

> El prerrequisito de la fase 3 ya está en `develop`: las tareas 3.P1 a 3.P4 (permisos por puesto y perfil activo, [ADR-0018](docs/decisiones/0018-permisos-por-puesto-y-perfil-activo.md); #216, #217, #220 y #221, 3 de noviembre). Las pantallas nuevas de la fase 3 usan `allows()` y `constrain()` con permisos declarados por su módulo, nunca listas de roles. Las 3.P5 a 3.P9 migraron los módulos actuales y cerraron la transición el 4 de noviembre (#223 a #253): ya no queda la API vieja de `ScopedContext`; toda Policy usa `AuthorizesActor`. Detalle en [fase-3-captura-de-carga.md](docs/plan/fase-3-captura-de-carga.md).

## Estados

| Símbolo | Significado |
|---|---|
| ⬜ | Pendiente, detallada (lista para ejecutar) |
| 🟨 | En curso |
| ✅ | Terminada y en `develop` |
| 🟦 | Vaga: necesita sesión de planeación detallada antes de iniciar |
| ⛔ | Bloqueada (ver nota) |

## Fases

| # | Fase | Entrega | Ventana | Modo | Estado | Detalle |
|---|---|---|---|---|---|---|
| 0 | Fundación | R1 | 27 oct – 2 nov 2026 | Individual | ✅ | [fase-0-fundacion.md](docs/plan/fase-0-fundacion.md) |
| 1 | Catálogos e importación | R1 | 3 – 14 nov 2026 | 3 en paralelo | 🟨 | [fase-1-catalogos-importacion.md](docs/plan/fase-1-catalogos-importacion.md) |
| 2 | Validación de catálogos y periodo | R1 | 10 – 19 nov 2026 | 3 en paralelo | ✅ | [fase-2-validacion-y-periodo.md](docs/plan/fase-2-validacion-y-periodo.md) |
| 3 | Captura de carga: grupos, horarios, docentes, salones | R1 | 17 nov – 4 dic 2026 | 3 en paralelo | 🟨 | [fase-3-captura-de-carga.md](docs/plan/fase-3-captura-de-carga.md) |
| 4 | Cierre, publicación y piloto Campus II | R1 | 30 nov – 18 dic 2026 | 3 en paralelo | ⬜ | [fase-4-cierre-publicacion-piloto.md](docs/plan/fase-4-cierre-publicacion-piloto.md) |
| 5 | Proyección de demanda | R2 | feb 2027 | Paralelo | ⬜ | [fase-5-demanda.md](docs/plan/fase-5-demanda.md) |
| 6 | Heurística de horarios y contrato del motor | R2 | feb – mar 2027 | Paralelo | ⬜ | [fase-6-heuristica-y-contrato.md](docs/plan/fase-6-heuristica-y-contrato.md) |
| 7 | Solver CP-SAT (Python + OR-Tools) | R2 | mar – abr 2027 | Paralelo | ⬜ | [fase-7-solver-cpsat.md](docs/plan/fase-7-solver-cpsat.md) |
| 8 | Re-análisis, impacto de cambios y junta digital | R2 | abr 2027 | Paralelo | ⬜ | [fase-8-reanalisis.md](docs/plan/fase-8-reanalisis.md) |
| 9 | Inscripción Campus II | R3 | may – jul 2027 | Paralelo | 🟦 | [fase-9-inscripcion-campus-ii.md](docs/plan/fase-9-inscripcion-campus-ii.md) |
| 10 | Otras modalidades: EAD, EnLínea, TecNM Virtual | R4 | por definir | — | 🟦 | [fase-10-otras-modalidades.md](docs/plan/fase-10-otras-modalidades.md) |

Fechas de R1 cerradas con el equipo el 26 de octubre de 2026: producto usable el **4 de diciembre** y carga de Campus II publicada a mediados de diciembre. Las fases de R1 se traslapan a propósito: cuando una persona termina su parte de la fase N, arranca la fase N+1 sin esperar a las demás.

## Hitos de R1

| Hito | Fecha | Criterio |
|---|---|---|
| H1 Fundación lista ✅ (27 oct) | 2 nov | Login por username, roles y alcances, layout, módulo de referencia con pruebas unit/feature/browser, CI verde en PR a `develop` |
| H2 Catálogos reales cargados | 14 nov | Exportaciones del SII de Campus II importadas sin errores bloqueantes; `student_progress` calculado |
| H3 Catálogos validados por jefes | 19 nov | Jefes de departamento de Campus II validan sus catálogos en Nexus; periodo `20271` abierto |
| H4 Carga capturada | 4 dic | Grupos, horarios, cupos, docentes y salones de Campus II sin choques duros; ranking visible |
| H5 Carga publicada | 18 dic | Cierre por departamento, (visto bueno), snapshot, consulta pública y exportes PDF/Excel; exportación al SII |

## Módulos y su estado

| Módulo | Fase que lo crea | Estado |
|---|---|---|
| Core | 0 | ✅ (base de R1; periodos y calendario de etapas #178; apertura por campus #179; notificaciones y campana #183) |
| Academic | 1 | 🟨 (1.7 a 1.10 hechas: modelos #116 y #117; CRUD y retícula #135, #136, #137, #139, #142, #144, #145; seeder demo #138, #140, #141; faltan 1.11 a 1.16) |
| Facilities | 1 | ✅ (1.15 hecha: modelos #158; pantallas de edificios y salones #161; plantilla `rooms` #162; seeders demo y masivo #163) |
| Faculty | 1 | ✅ (modelos #159 y #160; usuarios, roles y jefaturas #164; seeders #165; adscripción sin campus #168; pantalla de docentes #169; ficha #170; perfil y horas fuera del ITCJ #173; jefaturas en la ficha #174; editor de disponibilidad #175; perfil y disponibilidad desde la validación, 2.6, #190. Sus plantillas de 1.13 están en Imports: #171 y #172) |
| Students | 1 | 🟨 (1.4 hecha: modelos, progreso y plantillas #118 a #120) |
| Imports | 1 | 🟨 (1.1, 1.2 y 1.3 hechas: motor #94 a #98, asistente #99 a #103, desactivar faltantes #114 y #115; plantillas `students` y `kardex` en #120; migración del SII `imports:migrate-sii`, 1.16 parte 1, #257 a #271) |
| CatalogValidation | 2 | ✅ (base y revisiones al abrir el periodo #179; validación del jefe, 2.4, #184 y #185; tablero de avance con recordatorios, 2.5, #191; captura docente desde la validación, 2.6, #190) |
| AcademicLoad | 2 (base), 4 (cierre), 8 (re-análisis) | 🟨 (cargas al abrir el periodo #179; máquina de estados con HC-14 #180; propuesta de carrera #187; bandeja del departamento con HC-20 y paralelos #188; acuerdos entre departamentos, préstamos de salón y grupos compartidos, #286 y #292) |
| Scheduling | 3 (manual + validador), 6 (heurística) | 🟨 (3.1 a 3.7 hechas: modelos y exclusiones #287; validador HC-01 a HC-13 y SC-09 #288 a #290, #312 y #320; grupos desde propuestas #291; tablero de choques y avance #294, #319 y #325; editor de horarios #300, #301, #311 y #327; cupos por carrera y grupos compartidos #298, #315 y #326; salones y préstamos #286, #292 y #316; datos demo y masivos #310 y #317. Falta la integración 3.13; la heurística es de la fase 6) |
| Staffing | 3 | 🟨 (3.8 a 3.12 hechas: ranking docente #293, #295, #297 y #299; asignación #296, #302, #306, #318 y #321; oferta y Mi carga #309 y #314; solicitudes de cambio #304 y #314; carga por docente #308; datos demo y masivos #324. Falta la integración 3.13) |
| Demand | 5 | ⬜ |
| Solver | 6 (contrato y cliente), 7 (servicio Python) | ⬜ |
| Enrollment | 9 | 🟦 |

## Cómo se actualiza este archivo

1. Al abrir una fase: cambiar su estado a 🟨 y poner la fecha en "Última actualización".
2. Al cerrar una fase: estado ✅, y en el archivo de la fase marcar cada tarea con su PR.
3. Si una fase 🟦 va a iniciar: primero una sesión de planeación (brainstorming + writing-plans) que la convierta en ⬜ con tareas, pruebas y criterios de aceptación; después se ejecuta.
4. Cualquier cambio de alcance o fecha se anota en `docs/decisiones/` con fecha y motivo, y se refleja aquí.

## Registro de cambios del plan

| Fecha | Cambio |
|---|---|
| 2026-10-26 | Plan inicial. Fecha de R1 fijada para la carga de Campus II Ene-Jun 2027. Motor de sugerencia movido a R2. |
| 2026-10-27 | Fase 0 cerrada (PRs #72 a #85). Pest 5 en lugar de Pest 4 ([ADR-0011](docs/decisiones/0011-pest-5.md)); despliegue on-premise en el ITCJ con dominio público ([ADR-0013](docs/decisiones/0013-despliegue-on-premise-itcj.md)). Pendientes al cierre en [fase-0-fundacion.md](docs/plan/fase-0-fundacion.md#pendientes-al-cierre). |
| 2026-10-27 | Supuestos de la fase 0 confirmados. Antes de la fase 1: las plantillas de importación viven en Imports (tareas 1.1 y 1.4), edificios y salones son de `admin` y `subdireccion` de su campus (1.15) y los catálogos con `campus_id` usan el campus activo. |
| 2026-10-27 | Tarea 0.15 agregada al cierre de la fase 0: UUIDv7 como llave primaria en todo modelo, editando por única vez las migraciones de la fase 0 ([ADR-0014](docs/decisiones/0014-uuidv7-como-llave-primaria.md)). |
| 2026-10-27 | Despliegue al servidor del ITCJ postergado hasta nuevo aviso: la integración de la tarea 1.16 se hace en local mientras tanto. Si sigue postergado al iniciar la fase 4 (30 de noviembre), la tarea 4.7 y el hito H5 quedan en riesgo. El CI corre en todo PR, también en los encadenados sobre otra rama `feature/*`. |
| 2026-10-27 | Fase 1 abierta: A empieza antes, el 27 de octubre, con la tarea 1.1, para que el contrato de plantilla esté publicado cuando B y C arranquen el 3 de noviembre; la ventana de la fase no cambia. En CI, la cobertura mínima de 80 % por módulo pasa a bloquear ([testing.md](docs/arquitectura/testing.md#cobertura-1)). |
| 2026-10-27 | Fase 1: la tarea 1.7 (modelos de Academic, B) va primero y se mezcla a más tardar el día 2, porque la 1.4 (A) y la 1.11, la 1.14 y la 1.15 (C) tienen llaves foráneas a sus tablas. Mientras, A hace la 1.3 y C los edificios de la 1.15 ([fase-1](docs/plan/fase-1-catalogos-importacion.md#reparto)). |
| 2026-10-29 | Identidad visual definida y `DESIGN.md` como norma para toda pantalla ([ADR-0015](docs/decisiones/0015-identidad-visual.md)): credencial con áreas, menú que se transforma, paleta institucional y la liebre como marca. Construir el shell en la app queda en el issue #108. |
| 2026-10-30 | Alcance general contra alcance por campus: carreras, materias, planes de estudio y retículas, docentes, jefes de departamento (uno por departamento) y la Subdirección (una para todo el Tec) son de toda la institución; se atan a un campus los alumnos, los coordinadores (por carrera y campus), la oferta de carreras, los salones y las cargas. El jefe de proyecto docente queda por departamento y campus mientras se confirma (supuesto). Cambios en Core, Academic y Faculty en #166, #167 y #168; detalle en [PROJECT.md](PROJECT.md) y [reglas de negocio](docs/dominio/reglas-de-negocio.md). |
| 2026-11-01 | Fase 2 abierta por A (tareas 2.1 a 2.3). Decisiones del usuario: el periodo se abre por campus (hoy solo C2); docentes, materias, planes y carreras se validan una vez por periodo para todo el Tec y los salones por campus (HC-14 reescrita); abrir un periodo no cierra el anterior; el calendario de etapas solo informa. Mapa de módulos: `CatalogValidation --> AcademicLoad` ([ADR-0017](docs/decisiones/0017-catalog-validation-antes-de-academic-load.md), #179). |
| 2026-11-02 | Fase 2 cerrada en un día, con los tres carriles en sesiones paralelas: 2.1 a 2.3 (#178 a #180), 2.4 (#184, #185), 2.5 (#191), 2.6 (#190), 2.7 (#187), 2.8 (#188) y 2.9 (#183). Decisiones del usuario en la validación de catálogos (CV1 a CV6 en [reglas de negocio](docs/dominio/reglas-de-negocio.md)): el jefe corrige lo suyo mientras no valida; los salones, si el perfil del campus lo permite (`heads_edit_rooms`, confirmado en C1, por confirmar en C2 y EAD); altas y bajas contra la foto de la validación anterior; las observaciones y los docentes sin disponibilidad o perfil no bloquean; se reabre mientras la carga siga en borrador. La propuesta y la bandeja suman HC-20. CI: la suite completa corre una vez y en paralelo en cada PR (#182, #186). Vista previa por worktree con `.github/scripts/worktree-preview.sh` (#181). |
| 2026-11-02 | Después del cierre de la fase 2, datos de prueba y pulido con volumen. Los seeders demo y masivo ya traen todo lo de la fase 2: periodos desde 2014, calendarios y aperturas de 20263 y 20271 en C1 y C2 (A, #192); validación de catálogos con la foto de 20263, estados fijos por departamento en 20271, observaciones, recordatorios y huecos realistas en la captura docente (B, #195); propuestas, bandejas y avisos (C, #196). Cada pantalla de la fase se revisó con Impeccable sobre el masivo y se corrigió. B: pestañas de catálogo en la validación del jefe, con altas y bajas como filtro y drawer (#201), y en la matriz de avance Salones en una columna con una insignia por campus (#200). Las dos estructuras las eligió el usuario. C: propuesta y bandeja con volumen (#198, bandeja compacta con paralelos en chip y regresar en drawer; #199, propuesta con 9 semestres desde 1366, mosaicos por estado de la parte y bandas de especialidad), y campana con la página «Todas las notificaciones» (#197). Reglas confirmadas: los departamentos siguen decidiendo aunque otro regrese la propuesta, y las materias sin horas no entran (#193). Las anomalías de la propuesta se marcan sin bloquear (#194, `demand.min_group_size`, 10 por omisión). Usuarios y pantallas para revisar con el masivo: [testing.md](docs/arquitectura/testing.md#revisión-visual-con-volumen). |
| 2026-11-02 | Segunda revisión con volumen del carril C (Impeccable, 25/36), con los cuatro grupos de correcciones que eligió el usuario. Bandeja (#203): una carrera que otro departamento regresó se ve «Pendiente» con «en corrección por» y el departamento (decisión del usuario), filtros de anomalías (`?anomalias=1`) y de texto (`?filtro=`), tabla que se acomoda al ancho de su panel y «Validar catálogos» en las acciones. Banda y avisos (#204): la banda queda sobre lo fijo de la página (DESIGN.md, «Capas») y «Todas las notificaciones» agrupa por día con filtro por tipo (`?tipo=`). Propuesta (#205): «Ver» recorre las anomalías («1 de 4 ›»), la fila de la tabla abre el detalle, los totales van junto al selector de vista y cada celda avisa al guardarse. #203 también corrige una falla intermitente de los browser tests: su servidor conservaba la sesión entre peticiones, y una petición tardía de la prueba anterior mandaba el login a otra pantalla ([testing.md](docs/arquitectura/testing.md#browser-tests-en-docker)). Pendiente: con barras de desplazamiento clásicas los drawers dejan una franja de unos 15 px (el `overflow` de `.drawer-side` de daisyUI); se corrige en todos los módulos a la vez. |
