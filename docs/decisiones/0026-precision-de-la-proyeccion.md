# ADR-0026: Precisión de la proyección y uso de la precarga

**Estado:** Aceptada · **Fecha:** 2026-11-17 · Tareas 5.9 y 5.10 (criterio 3 de la fase 5)

## Contexto

La fase 5 calcula, antes de que el coordinador arme su propuesta, cuántos alumnos van a llevar cada materia y cuántos grupos conviene abrir (`demand_forecasts`, DM-01 a DM-10). La regla de R1 usaba los **elegibles** como alumnos esperados. El criterio 3 de la fase pide medir el error de la proyección contra lo que pasó de verdad y decidir si la precarga se muestra como sugerencia fuerte o solo como referencia.

Decisiones del usuario del 16 de noviembre de 2026:
- **C2:** con error medio por materia de 15 % o menos, la proyección es sugerencia fuerte; arriba, referencia.
- **C3:** el reporte lo ven Subdirección y el admin.
- **C4:** la medición con datos reales se hace sobre una copia local de la base del SII que no sale de la máquina.

## Cómo se mide

- **«Como si»** (5.9): Demand calcula la proyección de un periodo ya cursado con lo que se sabía antes de él (`RefreshDemandForecasts::compute(asIf: true)`). Los elegibles salen del kárdex hasta el periodo ordinario anterior, con la seriación en curso contada como aprobada, y las tasas, de los periodos anteriores del mismo tipo.
- **Real:** los alumnos que tienen la materia en ese periodo, aprobada, reprobada o cursando (DM-02, `TermOccupancyQuery`).
- **Alcance:** solo lo que Demand proyecta: carreras con oferta activa, sus campus de oferta y las materias de la propuesta (DM-27). Lo demás se cuenta aparte, como «fuera del alcance», y no es error de la fórmula.
- **Métrica:** error ponderado por alumnos, Σ|proyectado − real| / Σ real, por carrera, campus, departamento y global (DM-20 a DM-23). La base de comparación es la regla de R1 (proyectado = elegibles), medida igual.
- **Dónde:** la pantalla «Precisión de la proyección» (#551) y el comando `academic-load:evaluate-forecast` (#546). Cada evaluación guarda la versión de la fórmula (`method`).

## Resultado con datos reales (Campus II, presencial, «como si» Ene-Jun 2026)

Copia local de la base del SII migrada (2 043 435 renglones de kárdex; 24 251 grupos históricos de 2022 a 2026 importados con `academic-load:import-sii-groups`). Solo cifras agregadas. Las dos versiones se corrieron con el comando de la 5.9 y quedaron lado a lado, cada una con su `method`.

| | R1 (elegibles) | Fórmula v1 | Fórmula v2 |
|---|---|---|---|
| Error ponderado global de C2 | 277 % (con los elegibles de v1); 210 % (con los de v2) | **84.7 %** | **66.2 %** |
| Proyectado contra real (alumnos-materia) | 21 395 contra 3 711 | 4 778 contra 3 711 | 4 069 contra 3 711 |
| Primer semestre | — | 148.5 % (678 contra 363) | 74.4 % (357 contra 363) |
| Materias comunes | — | 75.6 % | 59.2 % |
| Materias de especialidad | — | 223.7 % | 174.1 % |
| Repetidores (reales 122) | — | 723 | 323 |

- **v1 → v2** (#552, aprobada por el usuario el 17 de noviembre de 2026):
  - los repetidores se multiplican por (1 − no inscripción);
  - el nuevo ingreso cae solo en el plan vigente;
  - la no inscripción se calcula por materia y por distancia de semestre (69 % de los elegibles de su semestre la toman, 11 % de uno adelante y 6 % de dos adelante);
  - las materias de especialidad piden el 60 % de los créditos del plan (supuesto por confirmar).
  Esto encontró la medición de la 5.9: la proyección ya estaba bien en el total, pero repartía mal entre materias.
- **Ago-Dic 2026** (el periodo en curso; lo «real» son las materias cursando): v1 134.1 %, **v2 74.4 %** (R1 185 %).
- **Por carrera con v2** (Ene-Jun 2026, C2): la mejor es COPU con 29.0 %, y las demás van de 60 % a 80 %. Ninguna llega a la meta del 15 %. IMEC sale con 1100 %: es una carrera casi sin alumnos en C2, con un real mínimo. IELE, IEME e ITIC salen «Sin medir» porque no tuvieron alumnos reales en las materias que se proyectan.
- **Fuera del alcance:** en la copia, Campus I no tiene ninguna oferta activa (28 ofertas inactivas, 15 165 alumnos activos), así que no se proyecta ni se evalúa: 4 650 alumnos en Ene-Jun 2026. Puede ser un hueco de la migración del SII (1.16) o una decisión del piloto de Campus II; se reporta al usuario y no se cambia aquí.
- **Demo masiva:** 23 a 36 % de error por carrera con v1, R1 entre 135 y 200 %. Son datos sintéticos y sirven solo para probar la pantalla.

## Decisión

1. **La precarga de la propuesta se muestra como «Referencia»** en todas las carreras mientras su error medido sea mayor que 15 %. Es el caso de las doce carreras de C2 con v1 y con v2. El coordinador parte de los alumnos posibles y los grupos sugeridos, pero ve que la cifra es orientativa. La regla DM-22 y `ForecastConfidenceQuery` deciden el rótulo por carrera con la última evaluación lista, así que **no hay que cambiar código** para pasar una carrera a «Sugerencia fuerte»: basta con que una evaluación la mida en 15 % o menos.
2. **Se vuelve a medir** al cerrar Ene-Jun 2027 con su kárdex reimportado (respuesta del 10 de noviembre de 2026) y cada vez que cambie la versión de la fórmula. La pantalla deja las evaluaciones lado a lado por versión.
3. **La meta del 15 % no se relaja** para que la precarga parezca mejor de lo que es. Si después de medir 20271 alguna carrera baja de la meta, su rótulo cambia solo.

## Consecuencias

- La precarga ya ahorra captura (el coordinador corrige en lugar de partir de cero), y el rótulo «Referencia» evita que se tome como cifra firme.
- La parte que el kárdex no ve, la volatilidad de la oferta de C2 (materias que un semestre se abren y otro no), la sigue decidiendo el coordinador.
- La copia local de los datos reales se borra al terminar la 5.10; para medir de nuevo se vuelve a copiar.
- Pendientes que no bloquean:
  - los grupos realmente abiertos (`actual_groups`) se llenan cuando `historical_groups` (Riel B, #525) esté en develop;
  - confirmar la oferta de Campus I en la migración del SII.

## Alternativas descartadas

- **Medir con la media de los errores porcentuales por materia.** Se dispara con las materias de pocos alumnos (2 reales contra 5 proyectados es 150 %) y castiga igual una materia de 3 alumnos que una de 200. Se usa el error ponderado por alumnos, que mide alumnos mal proyectados.
- **Contar como error lo que la proyección no cubre** (carreras o campus sin oferta, materias fuera de la propuesta). No es error de la fórmula, sino del alcance: se reporta aparte.
- **Un solo veredicto global.** Unas carreras pueden ser fiables y otras no; el rótulo es por carrera (DM-22).

