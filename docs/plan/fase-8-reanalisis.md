# Fase 8 — Re-análisis, impacto de cambios y junta digital

**Entrega:** R2 · **Ventana:** abr 2027 · **Modo:** paralelo · **Estado:** ⬜ · **Milestone:** `Fase 8 — Re-análisis, impacto y junta digital`

## Objetivo

Cerrar las tres brechas que R1 dejó a propósito: que una carga publicada se pueda **re-analizar** cuando llegan las calificaciones finales y republicar como nueva versión; que cada **solicitud de cambio** muestre su impacto antes de aprobarse; y que la **junta interdepartamental** llegue con los datos en pantalla y salga con un acta generada. Con esto el flujo propuesto queda completo para presencial.

## Dependencias

- Fase 4: etapa `published`, `load_snapshots`, exportes.
- Fase 5: `demand_forecasts` recalculable al importar kardex.
- Fase 6: evaluador en memoria y `SolverEngine` para simular y proponer alternativas.
- Fase 3: `change_requests`, `interdepartmental_agreements`, `group_quotas`, tablero de choques.

## Alcance

### Incluye

- Etapa `reanalysis` en `AcademicLoad` con sugerencias, aplicación validada, nueva versión del snapshot, diff visible y republicación.
- Impacto calculado en `change_requests` con alternativas del motor.
- Pantalla de junta interdepartamental digital con acta en PDF.
- Tablero de versiones publicadas.

### No incluye

- Inscripción (fase 9): el re-análisis prepara la carga para las citas, no las ejecuta.
- Cambios en la fórmula de demanda (fase 5) ni en los motores (fases 6 y 7).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 8.1 | Transición `published` → `reanalysis`: Action que se habilita cuando se aplica una importación de kardex del periodo que termina (evento `BatchApplied` de plantilla `kardex`) y el coordinador o jefe la inicia; en `reanalysis` la carga vuelve a ser editable con el validador, sin tocar el snapshot publicado | AcademicLoad | `AcademicLoad/Actions/Transitions/StartReanalysis`. La máquina de estados ya lo prevé; aquí se implementan guardas y permisos. | Unit: guardas; feature: solo tras importación nueva, registra `load_transitions` | A |
| 8.2 | Diff de demanda: por grupo y materia, comparar la demanda recalculada por `Demand` (elegibles y esperados con el kardex nuevo) contra la capacidad y cupos del snapshot publicado; clasificar como sobrante, faltante o ajustado dentro de un umbral configurable | AcademicLoad, Demand | `AcademicLoad/Queries/ReanalysisDiffQuery`. Es la pregunta concreta del re-análisis: dónde cambió la demanda respecto a lo publicado. | Unit con snapshot y pronóstico de fixture: clasificación por umbral | A |
| 8.3 | Sugerencias de ajuste: a partir del diff, propuestas de abrir grupo (con horario y salón sugeridos por el motor), cerrar grupo (con reubicación de cupos), ampliar cupo (si el salón lo permite, HC-05 y HC-07) o cambiar salón por capacidad (con `RoomSuggestionQuery`); cada sugerencia se acepta, edita o descarta | AcademicLoad, Scheduling, Solver | `AcademicLoad/Rules/ReanalysisAdvisor`. Las sugerencias se presentan con la evidencia (demanda, ocupación, salones libres); aplicar cualquiera pasa por las Actions de la fase 3 y el validador. | Feature: cada tipo de sugerencia aplicada deja la carga sin choques duros o se rechaza con mensaje | B |
| 8.4 | Nueva versión y republicación: Action `RepublishLoad` que exige cero choques duros, genera `load_snapshots` con la versión siguiente, calcula el diff contra la versión anterior (grupos nuevos, cancelados, cambios de horario, docente, salón y cupos) y regresa a `published`; notifica a docentes afectados | AcademicLoad | Reutiliza `PublishLoad` de la fase 4 con versión incremental. El diff guardado es lo que ven docentes y alumnos como "qué cambió". | Feature: versión incrementa, diff correcto, notificaciones solo a afectados | A |
| 8.5 | Vista pública y exportes con versión: la consulta publicada muestra la versión vigente y un resumen de cambios respecto a la anterior; los PDF y Excel llevan versión y fecha | AcademicLoad | Extiende las páginas y exportes de la fase 4. | Feature: cambios listados; browser: consultar "qué cambió" | B |
| 8.6 | Impacto de solicitudes de cambio: al crear o revisar una `change_request`, simular el cambio propuesto con el evaluador en memoria y listar empalmes resultantes, grupos y docentes afectados y la cadena de movimientos necesaria para dejarlo sin choques; guardar en `impact` | Staffing, Scheduling, Solver | `Staffing/Actions/AssessChangeImpact`. Reutiliza el evaluador de la fase 6; no escribe nada. Hoy el jefe aprueba a ciegas (D5). | Unit: cambio sin impacto, con un empalme, con cadena de dos movimientos | C |
| 8.7 | Alternativas del motor: para una solicitud con impacto, pedir a `SolverEngine` (alcance mínimo: los grupos afectados, el resto fijo) dos o tres acomodos alternativos sin choques; el jefe elige uno, lo rechaza o aprueba el original sabiendo el impacto; la aplicación sigue el flujo `adjusting` de la fase 3 con las sesiones propuestas precargadas en el editor | Staffing, Solver | `Staffing/Livewire/pages/change-request` extendida. Convierte la solicitud en una decisión informada en una sola pantalla. | Feature: alternativas sin choques; elegir una precarga el editor; browser: aprobar con alternativa | C |
| 8.8 | Junta interdepartamental digital: pantalla por periodo y campus con las materias compartidas entre carreras y departamentos, demanda por carrera (de `Demand`), grupos propuestos, cupos por carrera sugeridos (proporcionales a la demanda, ajustables), préstamos de salón pendientes y aceptados; desde ahí se crean y aceptan `interdepartmental_agreements` | AcademicLoad, Demand, Scheduling | `AcademicLoad/Livewire/pages/interdepartmental-meeting`. La junta deja de ser verbal (D7): cada acuerdo nace registrado y el validador lo respeta (HC-07, HC-10). | Feature: cupos sugeridos suman la capacidad; acuerdos creados por alcance; browser: aceptar un préstamo | B |
| 8.9 | Acta de la junta: PDF generado desde los acuerdos `accepted` del periodo y campus, con asistentes (usuarios que aceptaron), fecha y detalle de cupos y préstamos; archivado en la carga y descargable | AcademicLoad | `AcademicLoad/Exports/MeetingMinutes`. Reutiliza la generación de PDF de la fase 4. | Feature: el acta contiene exactamente los acuerdos aceptados | B |
| 8.10 | Tablero de versiones: por carga, lista de versiones publicadas con fecha, autor, motivo (publicación, re-análisis, corrección), resumen del diff y descarga de los exportes de cada versión | AcademicLoad | `AcademicLoad/Livewire/pages/versions`. Trazabilidad completa de lo que vieron docentes y alumnos en cada momento. | Feature: versiones ordenadas, diff accesible | A |
| 8.11 | Ensayo con datos reales: re-analizar la carga publicada de `2027-2` de Campus II con el kardex de cierre de `2027-1`, aplicar sugerencias con coordinadores y jefes, republicar y generar el acta de la junta del periodo | Todos | Es la primera vez que el proceso completo corre en Nexus de principio a fin. | Lista de incidencias cerrada; versión 2 publicada | A, B, C |

## Pruebas de la fase

- **Unit:** guardas de re-análisis, clasificación del diff, impacto de cambios, cupos sugeridos.
- **Feature:** sugerencias aplicadas con validador, republicación con versión y diff, alternativas del motor, acuerdos y acta.
- **Browser:** re-analizar y republicar; aprobar una solicitud eligiendo una alternativa; aceptar un préstamo desde la junta.
- **Rendimiento:** diff de demanda y simulación de impacto en menos de 3 segundos para una carga de Campus II.

## Criterios de aceptación

1. Tras importar el kardex de cierre, un coordinador ve dónde cambió la demanda respecto a lo publicado y aplica ajustes sugeridos sin producir choques duros.
2. La republicación genera una nueva versión con diff legible, y la consulta pública y los exportes la reflejan.
3. Toda solicitud de cambio muestra su impacto y al menos una alternativa sin choques antes de que el jefe decida.
4. La junta del periodo se realiza con la pantalla de Nexus y termina con acuerdos registrados y un acta en PDF.
5. CI verde con cobertura mínima de 80 % en lo nuevo de `AcademicLoad` y `Staffing`.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | AcademicLoad (re-análisis, versiones) | 8.1 → 8.2 → 8.4 → 8.10 |
| B | AcademicLoad (sugerencias, junta, acta, vista pública) | 8.3 → 8.8 → 8.9 → 8.5 |
| C | Staffing y Solver (impacto y alternativas) | 8.6 → 8.7 |

## Riesgos y notas

- El re-análisis ocurre en la ventana más corta del calendario (entre calificaciones finales y citas); las pantallas deben permitir aplicar varias sugerencias en lote, no una por una.
- Republicar cambia horarios ya vistos por docentes: la notificación debe decir exactamente qué cambió para cada uno.
- Los cupos sugeridos en la junta son una propuesta; la decisión sigue siendo de los jefes y queda registrada con quién la aceptó.
- Si la fase 7 no está lista, las alternativas del motor usan la heurística de la fase 6; el contrato es el mismo.
