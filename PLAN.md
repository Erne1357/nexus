# Nexus — Plan maestro

> Archivo de estado. Se actualiza en cada PR que cierre o abra tareas de una fase. Contexto en [PROJECT.md](PROJECT.md); reglas de trabajo en [CLAUDE.md](CLAUDE.md).

**Última actualización:** 2026-10-29 · **Fase activa:** 1 (A arrancó el 27 de octubre; B y C arrancan el 3 de noviembre) · **Entrega en curso:** R1 · **Tablero:** https://github.com/users/Erne1357/projects/3

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
| 2 | Validación de catálogos y periodo | R1 | 10 – 19 nov 2026 | 3 en paralelo | ⬜ | [fase-2-validacion-y-periodo.md](docs/plan/fase-2-validacion-y-periodo.md) |
| 3 | Captura de carga: grupos, horarios, docentes, salones | R1 | 17 nov – 4 dic 2026 | 3 en paralelo | ⬜ | [fase-3-captura-de-carga.md](docs/plan/fase-3-captura-de-carga.md) |
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
| H3 Catálogos validados por jefes | 19 nov | Jefes de departamento de Campus II validan sus catálogos en Nexus; periodo `2027-1` abierto |
| H4 Carga capturada | 4 dic | Grupos, horarios, cupos, docentes y salones de Campus II sin choques duros; ranking visible |
| H5 Carga publicada | 18 dic | Cierre por departamento, (visto bueno), snapshot, consulta pública y exportes PDF/Excel; exportación al SII |

## Módulos y su estado

| Módulo | Fase que lo crea | Estado |
|---|---|---|
| Core | 0 | ✅ (base de R1; periodos y notificaciones en fase 2) |
| Academic | 1 | 🟨 (1.7 hecha por A, acordado en #22: modelos #116 y #117; CRUD, plantillas y seeder siguen con B) |
| Facilities | 1 | ⬜ |
| Faculty | 1 | ⬜ |
| Students | 1 | ⬜ |
| Imports | 1 | 🟨 (1.1, 1.2 y 1.3 hechas: motor #94 a #98, asistente #99 a #103, desactivar faltantes #114 y #115) |
| CatalogValidation | 2 | ⬜ |
| AcademicLoad | 2 (base), 4 (cierre), 8 (re-análisis) | ⬜ |
| Scheduling | 3 (manual + validador), 6 (heurística) | ⬜ |
| Staffing | 3 | ⬜ |
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
