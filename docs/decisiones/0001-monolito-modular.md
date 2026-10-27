# ADR-0001 — Monolito modular sin hexagonal ni DDD

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
Equipo de tres personas, fecha corta para R1, aplicación que crecerá por módulos (catálogos, carga, horarios, inscripción). Se necesita repartir trabajo sin ceremonia y mantener fronteras claras.

## Decisión
Un solo proyecto Laravel con `app/Modules/<Modulo>/` y una convención fija por tipo de clase (Models, Actions, Queries, Rules, Livewire, Policies, Jobs, Routes, Database). Sin capas Domain/Infrastructure, sin repositorios ni interfaces por defecto. Dependencias entre módulos en una sola dirección, verificadas con pruebas de arquitectura.

## Consecuencias
Menos archivos por funcionalidad y curva corta para quien llega. La disciplina recae en las convenciones y en las pruebas `arch()`, no en abstracciones. Si un módulo necesita aislarse más (el motor), se hace con interfaces puntuales, no con capas globales.

## Alternativas descartadas
Hexagonal/DDD completo (demasiada ceremonia para el tamaño del equipo y el plazo). Laravel estándar sin módulos (difícil de repartir entre tres personas y de mantener al crecer).
