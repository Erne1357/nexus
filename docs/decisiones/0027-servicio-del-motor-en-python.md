# ADR-0027 — El servicio del motor de horarios en Python (FastAPI y OR-Tools)

**Estado:** Aceptada · **Fecha:** 2026-11-17 · Tareas 7.1 a 7.3 de la fase 7 (#557) · Relacionada con [ADR-0013](0013-despliegue-on-premise-itcj.md) y `docs/arquitectura/solver.md`

## Contexto
La fase 7 sustituye la heurística en PHP por un modelo de optimización con CP-SAT cuando la heurística se queda corta (cargas grandes y muy restringidas, varios campus a la vez). OR-Tools CP-SAT se usa desde Python (su API de PHP no existe), así que el motor es el único componente de Nexus fuera de PHP. Este ADR registra las dependencias nuevas (CLAUDE.md §8, regla 6).

## Decisión
1. **Un servicio aparte en `services/solver/`**, con su Dockerfile, sus pruebas y su ciclo propio, en Python 3.12. Nexus lo llama por HTTP desde el contenedor `queue`, por la red interna `backend`, sin puertos públicos y con un token compartido (`SOLVER_TOKEN`).
2. **Imagen base `python:3.12.16-slim` fijada por versión y digest**, como el resto del stack. Se usa `slim` (glibc) y no Alpine: las ruedas de OR-Tools son `manylinux` y no corren sobre musl.
3. **Dependencias de ejecución, con versión exacta** (verificadas en PyPI el 17 de noviembre de 2026):

   | Paquete | Versión | Para qué |
   |---|---|---|
   | `ortools` | 9.15.6755 | CP-SAT, el motor de optimización (modelo en 7.4 a 7.7) |
   | `fastapi` | 0.163.2 | La API asíncrona de corridas (`/solve`, `/runs/{id}`, `/health`) |
   | `uvicorn` | 0.54.0 | El servidor ASGI |
   | `pydantic` | 2.14.3 | Los modelos del contrato JSON v1, con las mismas reglas que los DTOs de PHP |

   Solo de desarrollo y pruebas (etapa `dev` de la imagen): `pytest` 9.2.0, `httpx2` 2.16.2 (cliente de `TestClient`; Starlette deja de recomendar `httpx`) y `ruff` 0.17.5 (lint y formato).
4. **La API es de verdad asíncrona**: `POST /solve` valida, encola en un hilo de trabajo del servicio y responde `202`; el estado se consulta con `GET /runs/{id}`. Se usan hilos y no procesos porque CP-SAT suelta el GIL mientras resuelve. Las corridas viven en memoria con un límite de simultáneas (`SOLVER_MAX_CONCURRENT_RUNS`); el registro duradero sigue siendo `solver_runs`.
5. **El contrato se implementa dos veces, una por lenguaje**, y los dos lados se prueban con los mismos fixtures de `tests/Fixtures/solver/v1/` (cada uno vuelve a su JSON exacto). Un cambio de contrato se versiona (`docs/arquitectura/solver.md`, «Versionado»).
6. **El motor de prueba del servicio** (`app/engine/trivial.py`, equivalente a `FixedEngine`) permite entregar la API completa antes que el modelo CP-SAT; el modelo (7.4 a 7.7) se registra después como otro motor.

## Consecuencias
- Un segundo lenguaje, con su propio lint (`ruff`), sus pruebas (`pytest`) y su imagen; el CI debe correrlos (tarea 7.12) y la documentación de despliegue lo cubre (`docs/operacion`).
- Las dependencias transitivas (numpy, pandas, protobuf, etc., que trae OR-Tools) no se fijan por separado; si hace falta reproducibilidad total se agrega un archivo de bloqueo en una tarea aparte.
- La imagen pesa varios cientos de MB por OR-Tools y sus dependencias; es un contenedor más con CPU y memoria acotadas en compose (2 CPU y 2 GB por defecto).
- Un reinicio del servicio pierde las corridas en curso: PHP las marca `failed` por tiempo de espera y se relanzan.

## Alternativas descartadas
- **Resolver desde PHP** (un binario de OR-Tools por línea de comandos o una extensión): sin soporte oficial; ata el motor al ciclo de PHP y complica las pruebas.
- **Motor propio sobre otro solucionador (por ejemplo, un MIP de código abierto):** CP-SAT modela mejor los horarios (restricciones lógicas, intervalos, objetivo lineal) y es el estado del arte en este problema.
- **Un motor comercial o en la nube:** todo corre en el stack de Docker de la institución (`docs/arquitectura/solver.md`, «Qué queda fuera»).
- **Flask o Django:** la API es pequeña y asíncrona; FastAPI da la validación con pydantic (la misma que modela el contrato) y la documentación OpenAPI sin código adicional.
