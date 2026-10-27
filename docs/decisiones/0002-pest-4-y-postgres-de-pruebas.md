# ADR-0002 — Pest 4 en tres niveles y PostgreSQL para pruebas

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
Gran parte del código lo escribirá la IA; se requiere TDD y pruebas E2E sin mantener dos ecosistemas.

## Decisión
Pest 4 para unit, feature, browser (Playwright integrado) y arquitectura, todo en `php artisan test`. Las pruebas corren contra una base PostgreSQL `nexus_test`, no SQLite, para no divergir del dialecto de producción (jsonb, restricciones de exclusión).

## Consecuencias
La imagen `dev` incluye Node y dependencias de Chromium; CI levanta PostgreSQL y Valkey. Cobertura mínima 80 % por módulo desde la fase 1.

## Alternativas descartadas
PHPUnit + Dusk (más pesado en Docker y CI). PHPUnit + Playwright en Node (dos lenguajes de pruebas).
