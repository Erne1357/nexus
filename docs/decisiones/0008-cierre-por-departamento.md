# ADR-0008 — Unidad de cierre = departamento en un campus; visto bueno de Subdirección configurable

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
El jefe de departamento asigna docentes y salones y hoy es quien da por terminada la carga. No está confirmado si Subdirección Académica debe dar un visto bueno antes de publicar.

## Decisión
`academic_loads` se cierra por departamento y campus. El campus tiene el parámetro `requires_subdireccion_approval`; si está activo, la carga pasa por `approved` antes de `published`.

## Consecuencias
Campus II arranca sin visto bueno obligatorio; se activa con un cambio de configuración, sin código nuevo. La propuesta del coordinador sigue siendo por carrera y alimenta a varios departamentos.

## Alternativas descartadas
Cierre por carrera (quien cierra no es quien asigna). Visto bueno obligatorio (no confirmado; frenaría el piloto).
