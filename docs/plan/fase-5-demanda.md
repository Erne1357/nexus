# Fase 5 — Proyección de demanda

**Entrega:** R2 · **Ventana:** feb 2027 · **Modo:** paralelo · **Estado:** ⬜ · **Milestone:** `Fase 5 — Proyección de demanda`

## Objetivo

Que Nexus calcule, antes de que el coordinador abra su propuesta, cuántos alumnos van a necesitar cada materia en el siguiente periodo y cuántos grupos conviene abrir, usando el avance reticular y el comportamiento histórico de cada materia en cada campus. El coordinador pasa de capturar desde cero a corregir una propuesta ya calculada. La proyección se mide contra la carga real de `20271` para saber cuánto confiar en ella.

## Dependencias

- Fase 1: `student_progress` y `EligibleStudentsQuery` en `Students`; kardex de varios periodos importado (al menos los `demand.history_terms` anteriores).
- Fase 2: `career_proposals` y `proposal_items` en `AcademicLoad`; acción `OpenTerm`.
- Fase 4: snapshot publicado de `20271` (grupos abiertos, cupos y ocupación real) como referencia para medir el error.
- Retrospectiva de R1 (tarea 4.11) con los ajustes que los coordinadores hicieron a mano sobre los elegibles.

## Alcance

### Incluye

- Módulo `Demand` completo: tabla `demand_forecasts`, cálculo, job, pantallas y parámetros.
- Precarga de `proposal_items` con la proyección.
- Tablero de demanda agregada por materia para el departamento.
- Medición del error de la proyección contra datos reales.

### No incluye

- Generación de horarios (fase 6) ni asignación automática de docentes.
- Proyección para modalidades no presenciales (fase 10).
- Cambios a las reglas de elegibilidad (ya definidas en `reglas-de-negocio.md`).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 5.1 | Modelo y migración de `demand_forecasts` (periodo, campus, carrera, materia, elegibles, tasa histórica, esperados, grupos sugeridos, método, fecha de cálculo) con unicidad por periodo, campus, carrera y materia | Demand | Modelo de datos. Una fila por combinación para que la precarga y la comparación sean consultas directas; `method` registra con qué versión de la fórmula se calculó. | Unit: relaciones y unicidad | A |
| 5.2 | Tasas históricas por materia y campus: cálculo de tasa de no inscripción (elegibles que no cursaron) y tasa de reprobación (cursaron y no aprobaron) sobre los últimos `demand.history_terms` periodos a partir de `kardex_records` y `student_progress` históricos | Demand | `Demand/Queries/HistoricalRatesQuery`. Separar la consulta del cálculo permite probar las tasas con fixtures de kardex sin tocar la fórmula. Materias sin historial usan la tasa promedio de su departamento, y se marca `method` = `fallback`. | Unit con kardex sintético de 4 periodos: tasas conocidas, materia nueva sin historial, materia con un solo periodo | A |
| 5.3 | Fórmula de proyección: `expected_students` = elegibles × (1 − tasa de no inscripción) + repetidores esperados (alumnos cursando ahora × tasa de reprobación); `suggested_groups` = techo de esperados entre `demand.target_group_size` según `subjects.kind` y `needs_lab` | Demand | `Demand/Rules/DemandForecaster` como clase pura, sin acceso a base de datos. Es la regla de `reglas-de-negocio.md`; vive aislada para poder cambiarla y versionarla sin tocar jobs ni pantallas. | Unit: casos con tamaño objetivo por tipo, redondeos, cero elegibles, materias de especialidad | A |
| 5.4 | Job `ComputeDemandForecasts` por periodo y campus, encolado al abrir periodo (evento `TermOpened`) y al aplicar una importación de kardex (evento `BatchApplied` de plantilla `kardex`); recalcula solo las carreras del campus afectado | Demand | `Demand/Jobs/`. Respeta la dirección de dependencias: `Demand` escucha eventos de `Core` e `Imports`, nunca al revés. Idempotente: reemplaza las filas del periodo y campus. | Feature: ambos eventos encolan el job; segunda corrida no duplica filas | A |
| 5.5 | Precarga de `proposal_items`: al crear o abrir una `career_proposal` en `draft`, `AcademicLoad` lee `demand_forecasts` y rellena elegibles, esperados y grupos solicitados; los valores precargados se marcan como sugeridos hasta que el coordinador los confirma o edita | AcademicLoad, Demand | Action `PrefillProposalFromDemand` en `AcademicLoad` (que sí puede depender de `Demand`). Guarda el valor sugerido junto al capturado para medir después cuánto corrige la gente. | Feature: propuesta nueva nace precargada; una propuesta ya `submitted` no se toca | B |
| 5.6 | Pantalla del coordinador: en la propuesta de carrera, por materia, columnas de elegibles, esperados por Nexus, esperados capturados, grupos sugeridos y grupos pedidos, más el histórico de los últimos periodos (grupos abiertos, cupo y ocupación real desde los snapshots); resaltar diferencias grandes entre sugerido y capturado | AcademicLoad | Extiende `career-proposal` de la fase 2. El coordinador necesita ver la evidencia para confiar o corregir, no solo un número. La ocupación histórica sale de `load_snapshots` de R1 (y de la importación de grupos del SII si se decide cargarla). | Feature: columnas correctas por alcance; browser: corregir un esperado y pedir un grupo más | B |
| 5.7 | Tablero de demanda del departamento: por materia que imparte el departamento, demanda agregada de todas las carreras y campus (elegibles, esperados, grupos sugeridos vs. pedidos), para dimensionar docentes y laboratorios antes de que lleguen las propuestas | Demand | `Demand/Livewire/pages/department-demand`. Hoy el jefe se entera materia por materia cuando cada coordinador manda su Excel; aquí ve el total de una vez. | Feature: agregación por materia respeta alcance de departamento y campus | B |
| 5.8 | Parámetros editables por campus en `settings`: `demand.history_terms`, `demand.target_group_size` por tipo de materia, umbral de diferencia para resaltar; pantalla de parámetros en `Core` | Core, Demand | Reutiliza la tabla `settings` de la fase 0. Que Subdirección pueda ajustar el tamaño objetivo sin despliegue. | Feature: cambiar el parámetro altera `suggested_groups` en la siguiente corrida | C |
| 5.9 | Medición del error: comando y reporte que compara la proyección calculada "como si" se hubiera corrido antes de `20271` contra los grupos realmente abiertos y la ocupación del snapshot publicado; error absoluto medio por materia y por carrera | Demand | `Demand/Console/EvaluateForecast`. Sin esta medición no hay forma de saber si la fórmula sirve; el resultado decide si R2 confía en la precarga o la muestra solo como referencia. | Feature con fixture del snapshot de R1: el reporte produce las métricas esperadas | C |
| 5.10 | Pruebas con datos sintéticos y con el historial real: seeder de kardex histórico de varios periodos para el `DemoSeeder`; corrida completa sobre los datos reales de Campus II en el servidor de pruebas | Demand, Students | Los datos reales son la única prueba de que las tasas tienen sentido. | Reporte de 5.9 generado con datos reales y revisado con un coordinador | A, C |

## Pruebas de la fase

- **Unit:** `DemandForecaster` (fórmula pura), `HistoricalRatesQuery` con kardex sintético, redondeos y tamaños objetivo.
- **Feature:** job por eventos, precarga de propuestas, tablero del departamento, parámetros.
- **Browser:** coordinador revisa una propuesta precargada, corrige y envía.
- **Datos reales:** comando de evaluación contra la carga publicada de `20271`.
- **Arquitectura:** `Demand` depende solo de `Core`, `Academic` y `Students`; `AcademicLoad` depende de `Demand`, nunca al revés.

## Criterios de aceptación

1. Al abrir un periodo, cada carrera de cada campus presencial tiene su `demand_forecasts` calculado y su propuesta nace precargada.
2. El coordinador ve, por materia, elegibles, esperados, grupos sugeridos e histórico, y puede corregir cualquier valor; lo sugerido y lo capturado se guardan por separado.
3. El error de la proyección medido contra `20271` queda documentado en `docs/decisiones/` con la decisión de si se muestra como sugerencia fuerte o como referencia.
4. Cambiar un parámetro de demanda en `settings` altera la siguiente corrida sin despliegue.
5. CI verde con cobertura mínima de 80 % en `Demand`.

## Reparto

| Persona | Módulos | Orden sugerido |
|---|---|---|
| A | Demand (núcleo) | 5.1 → 5.2 → 5.3 → 5.4; publica `DemandForecaster` y el job la primera semana |
| B | AcademicLoad (precarga y pantallas) | 5.5 → 5.6 → 5.7 usando fixtures de `demand_forecasts` mientras A termina |
| C | Core (parámetros) y evaluación | 5.8 → 5.9 → 5.10 |

## Riesgos y notas

- Si el kardex histórico importado cubre menos periodos que `demand.history_terms`, las tasas se calculan con lo que haya y se marca el método; no bloquear.
- Los alumnos de nuevo ingreso no tienen kardex: la demanda del primer semestre se toma de un parámetro por carrera y campus (`demand.new_students`) que captura Servicios Escolares, no de la fórmula.
- Las materias de especialidad dependen de qué especialidad elige cada alumno; la proyección las trata con la tasa del departamento y las marca para revisión manual.
- La proyección es una sugerencia: nunca crea grupos por sí sola ni cambia una propuesta ya enviada.
