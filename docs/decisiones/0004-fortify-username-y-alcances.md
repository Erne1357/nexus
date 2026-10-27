# ADR-0004 — Fortify con username; roles de spatie más alcances propios

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
Los usuarios vienen del SII identificados por número de empleado o de control; no hay SSO disponible. Un coordinador lo es de una carrera en un campus; un jefe, de un departamento en un campus.

## Decisión
Laravel Fortify con login por username, sin registro público, cambio de contraseña obligatorio en primer acceso. Roles y permisos con spatie/laravel-permission; el alcance (campus, departamento, carrera) en una tabla propia `role_scopes` consultada por todas las policies a través de `ScopedContext`.

## Consecuencias
La provisión masiva de usuarios se hace desde el catálogo de docentes. Si en el futuro hay SSO, se agrega como método adicional sin cambiar roles ni alcances.

## Alternativas descartadas
SSO institucional desde el inicio (no hay credenciales OAuth). Equipos de spatie para el alcance (no modela tres dimensiones a la vez).
