# ADR-0006 — Cupos por carrera en lugar de paralelos

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
En el SII un "paralelo" deja entrar a un grupo alumnos de otra carrera sin límite; el sobrecupo es frecuente.

## Decisión
Cada grupo reparte su capacidad entre carreras mediante `group_quotas` con máximo por carrera (HC-07). El grupo tiene una carrera dueña; las demás entran por acuerdo registrado.

## Consecuencias
La junta interdepartamental produce acuerdos verificables; la inscripción (R3) descuenta del cupo de la carrera del alumno. Las exportaciones al SII deben traducir cupos a su modelo de paralelos mientras coexistan.

## Alternativas descartadas
Replicar paralelos (mantiene el problema). Un grupo por carrera (duplica docentes y salones).
