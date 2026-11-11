# ADR-0021 — DomPDF para los PDF de lo publicado

**Estado:** Aceptada · **Fecha:** 2026-11-10

## Contexto
La tarea 4.5 entrega los PDF de la carga publicada: por carrera y semestre, por docente, por salón y por departamento. El usuario eligió el formato el 10 de noviembre de 2026. El de semestre y el de departamento son una tabla tipo SII (grupo, docente y una columna por día con horas y salón) en carta horizontal. El de docente y el de salón son una semana: días por horas, con bloques. Se generan en la cola (`GeneratePublishedExport`), una vez por versión publicada, y se descargan; los de semestre, docente y salón son públicos.

El servidor del ITCJ es on-premise ([ADR-0013](0013-despliegue-on-premise-itcj.md)) y la imagen de producción no lleva navegador. El plan de la fase 4 ya pedía una librería basada en HTML que no dependa de Chromium.

## Decisión
1. `dompdf/dompdf` ^3.1, directo y sin el envoltorio de Laravel (`barryvdh/laravel-dompdf`): una clase de AcademicLoad le pasa el HTML de una vista Blade y recibe los bytes. Trae `dompdf/php-font-lib`, `dompdf/php-svg-lib`, `masterminds/html5` y `sabberworm/php-css-parser`, sin extensiones nuevas (usa `dom` y `mbstring`, que ya están en la imagen).
2. Las plantillas usan tablas y CSS 2.1, que es lo que DomPDF maneja bien: la semana es una tabla con `rowspan`, no la cuadrícula CSS de la pantalla. Los colores salen de los tokens de DESIGN.md escritos en la plantilla, porque el PDF no carga `app.css`.
3. Fuente: DejaVu Sans, que viene con DomPDF y trae acentos y la «ñ». Atkinson Hyperlegible no se incrusta por ahora; si se pide, es otra decisión, por el peso de cada PDF.
4. Sin acceso remoto: `isRemoteEnabled` apagado, así que una plantilla no puede pedir nada por la red. Las imágenes, como la liebre, van como data URI.

## Consecuencias
- La licencia es LGPL-2.1: se usa como librería sin modificarla, lo que la licencia permite en una aplicación propia.
- DomPDF es lento con tablas grandes: el PDF de un departamento con cientos de grupos se mide con la demo masiva. Si pasa del `timeout` del job, se parte por carrera.
- Cambiar de librería solo toca la clase que arma el PDF y las plantillas: los exportes, la cola y las descargas no dependen de DomPDF.

## Alternativas descartadas
- **Chromium (Browsershot o Puppeteer):** da el CSS de la pantalla tal cual, pero mete un navegador y Node en la imagen de producción y en la cola.
- **mPDF:** maneja bien las tablas, pero pesa más y su API es más amplia de lo que hace falta.
- **El envoltorio `barryvdh/laravel-dompdf`:** solo agrega una fachada y la configuración, y no se necesitan.
