# ADR-0007 — Motor de horarios: heurística PHP y luego OR-Tools CP-SAT en Python

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
El equipo quiere el algoritmo complejo, pero R1 tiene fecha muy corta y aún no se conoce el volumen real de datos.

## Decisión
R1 no tiene motor: validador de restricciones y edición manual. R2 define un contrato JSON y una interfaz `SolverEngine` con dos implementaciones: heurística en PHP (rápida de construir, reutiliza las reglas del validador) y servicio Python con OR-Tools CP-SAT (óptimo, descompuesto por departamento). Ambas se prueban con los mismos fixtures.

## Consecuencias
Un servicio Docker adicional en R2 (`services/solver/`), red interna, sin puerto público. La heurística queda como respaldo si el servicio no está disponible.

## Alternativas descartadas
CP-SAT desde R1 (no cabe en la fecha). Solo heurística (no cumple el objetivo de calidad).
