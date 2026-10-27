# Nexus — Proyecto

> Documento de contexto: qué es Nexus, para quién, qué incluye cada entrega y bajo qué supuestos. El estado de avance vive en [PLAN.md](PLAN.md); las reglas para trabajar en el código, en [CLAUDE.md](CLAUDE.md).

## Visión

Nexus reemplaza por etapas al SII (Sistema Integral de Información) del Instituto Tecnológico de Ciudad Juárez como **nueva fuente de verdad** académica: menos datos acumulados sin uso, procesos explícitos y verificables, y tecnología actual (Laravel 13, Livewire 4, PostgreSQL 18, Valkey, Docker).

La primera gran meta es el proceso que arranca cada semestre: **carga académica** (qué materias se ofertan, con qué horarios, docentes y salones) y después la **inscripción**.

## Contexto institucional

- Campus y modalidades: **Campus I** y **Campus II** (presencial), **EAD** (mixta: virtual lunes a jueves, presencial viernes y sábado, docentes propios, mismas carreras y retículas), **EnLínea** y **TecNM Virtual** (docentes del TecNM, algunos del ITCJ). La modalidad cambia el flujo; Nexus la modela desde el inicio y solo implementa presencial en R1 y R2.
- Oferta presencial (folleto "Oferta Educativa"): 12 carreras en Campus I (Administración, Contador Público, Eléctrica, Gestión Empresarial, Industrial, Logística, Sistemas Computacionales, Mecánica, Electromecánica, Mecatrónica, Electrónica, TIC) y 6 en Campus II (Administración, Contador Público, Sistemas Computacionales, Industrial, Logística, Gestión Empresarial). Muchas materias son de tronco común entre carreras.
- Planta docente de unos 550 profesores con plazas de 40, 30, 20 horas, por asignatura y honorarios.
- Fuente inicial de datos: **exportaciones del SII** (Excel, CSV o SQL). No hay acceso directo a su base de datos.

## Entregas

| Entrega | Meta | Fecha objetivo | Alcance |
|---|---|---|---|
| **R1 — Carga capturada en Nexus** | La carga académica de **Campus II para Ene-Jun 2027** se construye, valida y publica desde Nexus | Usable: **4 de diciembre de 2026**. Carga publicada: **mediados de diciembre de 2026** | Catálogos importados y validados por jefes; propuesta por carrera con elegibles calculados; editor de horarios con validador de choques; cupos por carrera; ranking docente visible y asignación auditada; salones sugeridos y préstamos registrados; solicitudes de cambio; cierre, visto bueno opcional, publicación y exportes. Lógica para presencial completa (Campus I y II); piloto solo en Campus II. |
| **R2 — Motor y re-análisis** | La carga de **Ago-Dic 2027** se genera con sugerencias del sistema y se re-analiza con calificaciones | Abril de 2027 | Proyección de demanda, heurística de horarios en PHP, solver OR-Tools CP-SAT en Python bajo el mismo contrato, impacto de solicitudes de cambio, re-análisis versionado, junta interdepartamental apoyada en datos. |
| **R3 — Inscripción Campus II** | Los alumnos de Campus II se inscriben en Nexus para Ago-Dic 2027 | Julio de 2027 | Orden de inscripción, citas, auto-inscripción con seriación y cupos por carrera, lista de espera, sincronización con el SII donde haga falta. |
| **R4 — Otras modalidades** | EAD, EnLínea y TecNM Virtual | Por definir | Requiere levantar sus procesos. |

## Fuera de alcance (por ahora)

Calificaciones y actas, pagos, servicio social y residencias, titulación, control escolar completo, API pública, aplicación móvil. Nexus crece módulo a módulo cuando cada proceso esté levantado.

## Actores y roles

| Rol en Nexus | Persona | Alcance |
|---|---|---|
| `admin` | Equipo de desarrollo / sistemas | Todo |
| `subdireccion` | Subdirección Académica | Campus |
| `jefe_departamento` | Jefe de departamento académico | Departamento en un campus |
| `jefe_proyecto_docente` | Jefe de proyecto docente | Departamento en un campus |
| `coordinador` | Coordinador de carrera | Carrera en un campus |
| `docente` | Profesor | Su propia carga |
| `alumno` (R3) | Estudiante | Su propia inscripción |
| `servicios_escolares` (R3) | Servicios Escolares / División | Campus |

Detalles en `docs/dominio/glosario.md` y `docs/dominio/reglas-de-negocio.md`.

## Decisiones clave

| Decisión | Elección | Razón |
|---|---|---|
| Arquitectura | Monolito modular (`app/Modules/*`), sin hexagonal ni DDD | Equipo de 3, poco tiempo, módulos repartibles sin ceremonia. Ver `docs/arquitectura/arquitectura.md` |
| UI | Livewire 4 MFC + MaryUI (daisyUI) + Tailwind 4 | Un solo lenguaje, componentes listos, sin SPA |
| Auth | Fortify con **username** y contraseña; roles con spatie/laravel-permission y alcances propios | Usuarios vienen del SII con username; sin SSO disponible hoy |
| Pruebas | TDD con Pest 5: unit, feature y browser; CI en GitHub Actions contra PostgreSQL | Atrapar errores de código generado con IA antes de integrar |
| Datos | PostgreSQL 18 única fuente; importación por archivos con staging y validación; sin borrado físico; bitácora inmutable | Transparencia y reproducibilidad |
| Motor | R1 sin motor (validador + edición manual); R2 heurística PHP y luego OR-Tools CP-SAT en Python bajo un contrato JSON | Fecha de R1 muy corta; el algoritmo complejo sí es objetivo |
| Paralelos | Se reemplazan por cupos por carrera dentro del grupo | Elimina el sobrecupo y simplifica la inscripción |
| Desempate docente | Base de horas, luego historial y perfil en la materia, luego evaluación docente; ranking visible y asignación fuera de ranking con motivo | Lo acordado como aceptable institucionalmente |
| Aprobación final | Jefe de departamento; visto bueno de Subdirección configurable por campus | No está confirmado si Subdirección participa |
| Git | `feature/*` → `develop` por PR; `develop` → `main` por PR cuando la integración está probada | Regla del equipo |

El registro completo está en `docs/decisiones/`.

## Supuestos

1. Las exportaciones del SII contienen al menos: claves y nombres de carreras, planes y materias con semestre, créditos y seriación; docentes con número de empleado, departamento y plaza; salones con capacidad y tipo; alumnos con número de control, carrera, plan y semestre; kardex con materia, periodo, calificación y resultado. Si falta algo, la plantilla de importación lo marca como faltante y el jefe lo completa en la validación.
2. Los usernames de Nexus son los mismos que en el SII (número de empleado o número de control). Las contraseñas se generan al crear el usuario y se cambian en el primer acceso.
3. El proceso de carga de Ene-Jun 2027 ya está en marcha en Excel para ambos campus. El piloto de Campus II capturará en Nexus lo que ya se decidió y terminará el proceso dentro de Nexus; si R1 no llega a tiempo, Nexus al menos conserva los catálogos validados y la carga capturada, y la publicación se hace exportando a Excel.
4. El bloque horario presencial es de 1 hora, de 7:00 a 21:00, lunes a viernes, igual en ambos campus.
5. Hay un servidor donde desplegar con Docker antes del piloto (por confirmar: on-premise del ITCJ o un VPS).

## Riesgos

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| La fecha de R1 (4 de diciembre) no alcanza para todo el alcance | Alta | Orden de construcción por valor: catálogos → validación → grupos y horarios con validador → ranking y asignación → cierre y exportes. Lo que no llegue se publica desde Excel con los datos de Nexus. Revisión de alcance cada viernes. |
| Las exportaciones del SII llegan tarde o incompletas | Alta | Pedirlas la primera semana; plantillas tolerantes a columnas faltantes; datos de prueba realistas generados con factories mientras llegan. |
| Resistencia de jefes a la transparencia del ranking | Media | El ranking es información, la decisión sigue siendo del jefe; solo exige motivo. Presentarlo así. |
| El equipo escribe código con IA sin pruebas suficientes | Media | TDD obligatorio, CI bloqueante en PR, revisión cruzada, checklist en `CONTRIBUTING.md`. |
| Octane y estado compartido entre peticiones | Media | Sin singletons con estado mutable; pruebas en modo Octane antes del piloto. |
| Tres personas tocando el mismo módulo | Media | Ownership por módulo por fase (ver `docs/plan/trabajo-en-equipo.md`) y PRs pequeños. |

## Equipo

Tres desarrolladores. En GitHub están `Erne1357` (owner) y `Deig0l`; falta agregar al tercer integrante como colaborador. Reparto por módulo y fase en `docs/plan/trabajo-en-equipo.md`.

## Mapa de documentos

| Archivo | Para qué |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Cómo trabajar en este repo (humanos e IA) |
| [PLAN.md](PLAN.md) | Roadmap y estado de cada fase |
| [DESIGN.md](DESIGN.md) | Sistema de diseño (en borrador) |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Flujo de git, PRs, definición de hecho |
| `docs/proceso/` | Proceso actual, deficiencias y proceso propuesto |
| `docs/dominio/` | Glosario, modelo de datos, reglas de negocio |
| `docs/arquitectura/` | Arquitectura, pruebas, solver |
| `docs/plan/` | Una fase por archivo y el plan de trabajo en equipo |
| `docs/herramientas/` | Skills, MCP y plugins para trabajar con IA |
| `docs/decisiones/` | Registro de decisiones |
