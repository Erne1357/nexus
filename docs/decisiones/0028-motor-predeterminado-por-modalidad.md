# ADR-0028 — Motor de horarios predeterminado por modalidad

**Estado:** Propuesta, por confirmar con el usuario · **Fecha:** 2026-11-18 · Tarea 7.11 de la fase 7 (#665) · Relacionada con [ADR-0025](0025-modalidad-como-catalogo.md), [ADR-0027](0027-servicio-del-motor-en-python.md) y `docs/arquitectura/solver.md`, «Benchmark de la fase 7»

## Contexto
Nexus tiene dos motores de horarios bajo el mismo contrato: la heurística en PHP (fase 6, clave `heuristic`) y CP-SAT en el servicio de Python (fase 7, clave `cpsat`, que usa la heurística como pista). La 7.11 pide decidir con números, no por preferencia, cuál propone «Lanzar una corrida» en cada modalidad (`solver.default_engine`, por modalidad según ADR-0025). Hasta hoy el predeterminado era el último motor registrado, que es la heurística.

La medición está en `docs/arquitectura/solver.md`, «Benchmark de la fase 7» (18 de noviembre de 2026, hora de Ciudad Juárez).

Lo que importa para decidir (cero violaciones duras en todas las corridas de los dos motores, también con el evaluador en PHP):

| Instancia | Heurística | CP-SAT con 60 s | CP-SAT con 300 s |
|---|---|---|---|
| `tiny`, `small`, `medium` | 44, 79 y 64 (0.02 s) | 36, 42 y 49 (1 s) | igual que con 60 s |
| `infeasible` | 2 sin programar, 12 | 2 sin programar, 12 | igual |
| CEA con docentes (`department-multicampus`, 1 139 movibles) | 23 608 (23–28 s) | 32 935 (61 s) | **23 066** (302 s), 2.3 % mejor |
| Ciencias Básicas de la demo masiva, sin docentes (356 movibles) | 4 350 (1 s) | **4 040** (56 s), 7.1 % mejor | 4 040 (291 s) |

No hubo instancia real de Campus II: Nexus todavía no tiene el horario del SII como grupos (solo crudo en `sii_archivo`), así que la de tamaño real es la de la demo masiva. El servicio ignora `hint_sessions`, así que la pista de la heurística que arma `CpSatEngine` (hasta el 15 % del límite) hoy le quita tiempo a CP-SAT sin ayudarle.

## Decisión
1. **El parámetro existe y se edita en «Parámetros»** (Motor de horarios): `solver.default_engine`, una tabla de opciones con un campo por modalidad del catálogo (`pres`, `ead`, `enl`, `tnmv`) y una opción por motor elegible (`heuristic`, `cpsat`; el de prueba no). Lo cambia quien cambia los demás parámetros del motor (`solver.setting.update`, Subdirección). `EngineRegistry::default($modalidad)` lo lee; si el elegido no está registrado (CP-SAT sin servicio configurado), propone la heurística. Quien lanza puede elegir otro motor en el drawer.
2. **Valor de fábrica: la heurística en todas las modalidades.** No cambia nada mientras el usuario no decida.
3. **Propuesta, por confirmar con el usuario:** `pres` = `cpsat` y las demás modalidades con la heurística (`{"pres": "cpsat", "ead": "heuristic", "enl": "heuristic", "tnmv": "heuristic"}`), **cuando el servicio `solver` esté desplegado en el servidor** con sus 2 CPU (la decisión de infraestructura de la fase 7 sigue pendiente) y con el límite por departamento en 300 s, el de fábrica. Mientras no esté, la heurística.
   - *A favor:* con 300 s, CP-SAT no deja violaciones duras y mejora el puntaje en todas las instancias: 2.3 % y 7.1 % en las de tamaño real, de 18 % a 47 % en las chicas. Cumple el criterio 3 de la fase en las cargas de tamaño real que hay.
   - *En contra, y por eso es una propuesta:* en la carga grande tarda unas 12 veces más (5 min contra 25 s) por una mejora chica; con 60 s, el límite de fábrica de una exploración por carrera, puede quedar peor que la heurística en una carga grande (32 935 contra 23 608); y depende de un contenedor más.
   - *Lo que cambiaría la cuenta:* que el servicio use `hint_sessions` como pista (CP-SAT arrancaría de la solución de la heurística y no quedaría peor que ella con poco tiempo), y medir otra vez con la carga real de Campus II cuando Nexus tenga su horario.
   - EAD, En línea y TecNM Virtual se quedan con la heurística: no hay instancias suyas que medir (fase 10).

## Consecuencias
- El catálogo de parámetros (CF-S7) gana un tipo, la tabla de opciones (`SettingType::ChoiceTable`, con `options` en la definición y un `x-select` por campo en la pantalla). Sirve para cualquier parámetro futuro que elija entre valores con nombre.
- Los campos son las modalidades sembradas por ADR-0025; una modalidad nueva necesita su campo para elegirle otro motor y, mientras, usa la heurística (supuesto por confirmar).
- Elegir CP-SAT en una modalidad exige el servicio `solver` sano en el servidor: si cae, las corridas de CP-SAT fallan con «El servicio del motor no está disponible.» (SV-08), y el registro sin servicio vuelve a proponer la heurística.

## Alternativas descartadas
- **Por campus:** la carga es por modalidad (ADR-0025) y una corrida abarca todos los campus de la carga; el plan original de la 7.11 decía por campus.
- **Un parámetro sin pantalla:** CF-S7 pide que todo parámetro que lee un módulo se vea y se edite en «Parámetros».
- **Una tabla de enteros (0 = heurística, 1 = CP-SAT):** cabía en los tipos que había, pero la pantalla mostraría números en lugar de nombres de motor.
