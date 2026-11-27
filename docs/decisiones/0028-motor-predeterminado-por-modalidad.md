# ADR-0028 — Motor de horarios predeterminado por modalidad

**Estado:** Aceptada (decisión del usuario, 19 de noviembre de 2026) · **Fecha:** 2026-11-19 · Tarea 7.11 de la fase 7 (#665) · Relacionada con [ADR-0025](0025-modalidad-como-catalogo.md), [ADR-0027](0027-servicio-del-motor-en-python.md) y `docs/arquitectura/solver.md`, «Benchmark de la fase 7»

## Contexto
Nexus tiene dos motores de horarios bajo el mismo contrato: la heurística en PHP (fase 6, clave `heuristic`) y CP-SAT en el servicio de Python (fase 7, clave `cpsat`, al que `CpSatEngine` le manda la solución de la heurística como pista). La 7.11 pide decidir con números, no por preferencia, cuál propone «Lanzar una corrida» en cada modalidad (`solver.default_engine`, por modalidad según ADR-0025). Hasta la 7.11 el predeterminado era el último motor registrado, que es la heurística.

La medición está en `docs/arquitectura/solver.md`, «Benchmark de la fase 7», en dos tandas (hora de Ciudad Juárez): el 18 de noviembre de 2026, los dos motores, cuando el servicio todavía ignoraba `hint_sessions`; y el 19 de noviembre de 2026, CP-SAT con el servicio de la 7.10 (#670, Riel B), que ya usa la pista. Cero violaciones duras en todas las corridas de los dos motores, también con el evaluador en PHP:

| Instancia | Heurística | CP-SAT con pista, 60 s | CP-SAT con pista, 300 s | CP-SAT previo a la pista (60 s / 300 s) |
|---|---|---|---|---|
| `tiny`, `small`, `medium` | 44, 79 y 64 (0.02 s) | 36, 42 y 49 (1 s) | igual que con 60 s | igual |
| `infeasible` | 2 sin programar, 12 | — | — | 2 sin programar, 12 |
| CEA con docentes (`department-multicampus`, 1 139 movibles) | 23 608 (23–28 s) | **23 019** (61 s), 2.5 % mejor | **22 834** (302 s), 3.3 % mejor | 32 935 / 23 066 |
| Ciencias Básicas de la demo masiva, sin docentes (356 movibles) | 4 350 (1 s) | — | — | 4 040 (56 s) / 4 040 (291 s), 7.1 % mejor |

Sin la pista, CP-SAT arrancaba sin la solución de la heurística y la carga grande con 60 s quedaba peor que ella; con la pista la mejora ya con 60 s (B había medido 23 091 a 23 171 en la 7.10). La carga de la demo masiva no se volvió a medir con la pista. No hubo instancia real de Campus II: Nexus todavía no tiene el horario del SII como grupos (solo crudo en `sii_archivo`), así que la de tamaño real es la de la demo masiva.

## Decisión
Decisión del usuario, 19 de noviembre de 2026:

1. **Presencial propone CP-SAT** por omisión, con la pista de la heurística. Con la pista, CP-SAT ya mejora a la heurística con 60 s en la carga grande (23 019 contra 23 608) y en las chicas, sin violaciones duras.
2. **El servicio corre siempre.** El microservicio del motor vive en su contenedor de Docker (`solver`, `restart: unless-stopped`), arriba junto con `app` y `queue`, y producción exige `SOLVER_URL` y `SOLVER_TOKEN` (decisión del usuario, 19 de noviembre de 2026). No es un servicio opcional que se active después.
3. **Las demás modalidades (EAD, En línea, TecNM Virtual) proponen la heurística.** No hay instancias suyas que medir (fase 10).
4. **La heurística solo como respaldo de configuración.** Si el servicio no está configurado (`CpSatClient::configured()` falso: sin `SOLVER_URL` o `SOLVER_TOKEN`, como en las pruebas), `cpsat` no se registra y `EngineRegistry::default()` propone la heurística aunque el parámetro diga `cpsat`. No es el estado normal. Si el servicio está configurado pero caído, la corrida de CP-SAT falla y avisa (SV-08, confirmado el 17 de noviembre de 2026): no cambia sola a la heurística.
5. **El parámetro.** `solver.default_engine` es una tabla de opciones en «Parámetros» (Motor de horarios): un campo por modalidad del catálogo (`pres`, `ead`, `enl`, `tnmv`) y una opción por motor elegible (`heuristic`, `cpsat`; el de prueba no). El valor de fábrica es `{"pres": "cpsat", "ead": "heuristic", "enl": "heuristic", "tnmv": "heuristic"}`. Lo cambia quien cambia los demás parámetros del motor (`solver.setting.update`, Subdirección). Quien lanza puede elegir otro motor en el drawer.

## Consecuencias
- El catálogo de parámetros (CF-S7) gana un tipo, la tabla de opciones (`SettingType::ChoiceTable`, con `options` en la definición y un `x-select` por campo en la pantalla). Sirve para cualquier parámetro futuro que elija entre valores con nombre.
- Los campos son las modalidades sembradas por ADR-0025; una modalidad nueva necesita su campo para elegirle otro motor y, mientras, usa la heurística (supuesto por confirmar).
- Una corrida de Presencial tarda lo que su límite (`solver.time_limits`) y no lo que tarda la heurística (unos 25 s en la carga grande), y depende del contenedor `solver`: si cae, las corridas de CP-SAT fallan con «El servicio del motor no está disponible.» (SV-08) y quien lanza puede volver a lanzar con la heurística.
- Conviene medir otra vez con la carga real de Campus II cuando Nexus tenga su horario.

## Alternativas descartadas
- **La heurística en todas las modalidades** (el valor de fábrica del parámetro antes de la decisión, mientras el servicio ignoraba la pista): con la pista, CP-SAT ya no queda peor que ella con 60 s.
- **Por campus:** la carga es por modalidad (ADR-0025) y una corrida abarca todos los campus de la carga; el plan original de la 7.11 decía por campus.
- **Un parámetro sin pantalla:** CF-S7 pide que todo parámetro que lee un módulo se vea y se edite en «Parámetros».
- **Una tabla de enteros (0 = heurística, 1 = CP-SAT):** cabía en los tipos que había, pero la pantalla mostraría números en lugar de nombres de motor.
