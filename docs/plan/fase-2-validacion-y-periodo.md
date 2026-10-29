# Fase 2 — Validación de catálogos y periodo

**Entrega:** R1 · **Ventana:** 10 – 19 nov 2026 (se traslapa con fase 1) · **Modo:** 3 en paralelo · **Estado:** ⬜ · **Milestone:** `Fase 2 — Validación y periodo`

## Objetivo

Que cada jefe de departamento de Campus II revise, corrija y valide sus catálogos dentro de Nexus, que exista el periodo `20271` con su calendario, y que cada coordinador pueda enviar su propuesta de materias y grupos con los elegibles calculados. Al terminar, hito H3.

## Dependencias

- Fase 1: catálogos cargados, elegibles, usuarios de jefes y coordinadores.
- `ScopedContext` (fase 0) para filtrar por departamento, carrera y campus.

## Alcance

### Incluye

- Módulo `CatalogValidation`.
- Pantallas de periodo y calendario de etapas en `Core`; acción de abrir periodo.
- Base del módulo `AcademicLoad`: cargas por departamento, máquina de estados, propuestas de carrera.

### No incluye

- Grupos, horarios y asignación (fase 3). Cierre y publicación (fase 4).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 2.1 | Periodos: CRUD de `terms`, calendario de `term_stages` por campus, selector de periodo activo en la topbar | Core | Páginas MFC en `Core`. El periodo activo entra en `ScopedContext` y en todas las claves de caché. | Feature: solo un periodo actual; browser: crear periodo y etapas | A |
| 2.2 | Acción `OpenTerm`: al abrir un periodo crea una `academic_load` en `draft` por departamento y campus activo, y una `catalog_review` en `pending` por catálogo y departamento; notifica a los jefes | Core, AcademicLoad, CatalogValidation | Action en `AcademicLoad` escuchando el evento `TermOpened` de `Core` (respeta la dirección de dependencias). | Feature: abrir periodo genera exactamente las filas esperadas; idempotente | A |
| 2.3 | Máquina de estados de `academic_loads`: una Action por transición con sus guardas (HC-14 para salir de `draft`; el resto llega en fases 3 y 4), `load_transitions`, política por rol | AcademicLoad | `AcademicLoad/Actions/Transitions/`. Centralizar guardas aquí evita que las pantallas decidan estados. | Unit por transición: permitida, bloqueada, registra historial | A |
| 2.4 | Validación de catálogos: página del jefe con un bloque por catálogo (docentes, salones, materias, planes, carreras) mostrando conteos, altas y bajas respecto al periodo anterior, lista con edición inline reutilizando los CRUD de fase 1, observaciones (`flags`) y botón "validar" | CatalogValidation | `CatalogValidation/Livewire/pages/review`. Reutiliza componentes de fase 1 en modo de alcance por departamento. | Feature: solo su departamento y campus; validar cambia estado y queda en bitácora; browser: flujo completo | B |
| 2.5 | Tablero de avance de validación por campus y departamento para `subdireccion` y `admin`, con recordatorios por notificación | CatalogValidation | `CatalogValidation/Livewire/pages/dashboard`. Da visibilidad a quien hoy no la tiene. | Feature: conteos correctos | B |
| 2.6 | Captura por el jefe de perfil docente (apto, preferente) y disponibilidad durante la validación; horas externas por periodo | Faculty | Enlaces desde la validación a las pantallas de fase 1 con el periodo activo. Los jefes conocen a su planta mejor que el SII. | Feature: cambios con bitácora | B |
| 2.7 | Propuesta de carrera: página del coordinador con la retícula de su carrera y campus; por materia muestra elegibles (`EligibleStudentsQuery`), permite capturar alumnos esperados y grupos solicitados, y enviar la propuesta | AcademicLoad | `AcademicLoad/Livewire/pages/career-proposal` reutilizando la vista de retícula de `Students`/`Academic`. R2 precargará `expected_students` desde `Demand`. | Feature: solo su carrera; enviar cambia a `submitted`; browser: capturar y enviar | C |
| 2.8 | Bandeja del departamento: materias solicitadas por cada carrera agrupadas por materia, con total de grupos pedidos; aceptar o regresar con comentario; al aceptar la carga pasa a `proposed` (si catálogos validados) | AcademicLoad | `AcademicLoad/Livewire/pages/department-inbox`. Es el punto donde el departamento ve la demanda de todas las carreras sobre sus materias. | Feature: aceptar bloqueado sin validación (HC-14) | C |
| 2.9 | Notificaciones en base de datos para: catálogos pendientes, propuesta enviada, propuesta regresada; campana en la topbar | Core | `Core/Notifications`. Canal de correo queda para después. | Feature: cada evento genera su notificación al rol correcto | C |

## Pruebas de la fase

- **Unit:** transiciones de estado, guardas, conteos de validación.
- **Feature:** alcance por rol en todas las pantallas; `OpenTerm`; propuesta y bandeja.
- **Browser:** validar catálogos como jefe; enviar propuesta como coordinador; aceptar como jefe.

## Criterios de aceptación

1. Con el periodo `20271` abierto, cada jefe de Campus II ve solo sus catálogos, puede corregir y validar, y el tablero refleja el avance.
2. Un coordinador de Campus II envía su propuesta con elegibles reales y el departamento la acepta; la carga del departamento queda en `proposed`.
3. Ninguna transición es posible desde una pantalla sin pasar por su Action y quedar en `load_transitions`.

## Reparto

| Persona | Entra cuando termina | Módulos |
|---|---|---|
| A | Imports y Students (fase 1) | Core (periodos), AcademicLoad (estados) |
| B | Academic (fase 1) | CatalogValidation, Faculty (perfil y disponibilidad) |
| C | Faculty y Facilities (fase 1) | AcademicLoad (propuestas y bandeja), Core (notificaciones) |

## Riesgos y notas

- Los jefes reales entran a Nexus por primera vez en esta fase: la pantalla de validación debe ser obvia y rápida. Hacer una prueba con un jefe amigo antes del 17 de noviembre.
- Si el calendario real del proceso va adelantado, la validación puede hacerse "en retrospectiva" (validar lo que ya se usó en Excel) sin bloquear el piloto.
