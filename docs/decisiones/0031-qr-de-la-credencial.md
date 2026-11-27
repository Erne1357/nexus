# ADR-0031 — Código QR de la credencial con bacon/bacon-qr-code

**Estado:** Aceptada · **Fecha:** 2026-11-20 · Tarea 1.25 (#744) · Rama padre `riel/ajustes-1-24`

## Contexto
El usuario decidió el 20 de noviembre de 2026 que la credencial del alumno («Mis datos», pestaña «Credencial», AC-15) lleva un código QR con su número de control. `bacon/bacon-qr-code` ya está instalado como dependencia transitiva de Fortify (v3.1.1); usarlo directo la vuelve dependencia directa.

## Decisión
- `bacon/bacon-qr-code` `^3.1` se declara en `composer.json`; la versión instalada no cambia (solo el `content-hash` del lock).
- `StudentCredentialQuery::qr()` genera en el servidor un SVG en línea con el número de control y nada más (sin URL ni datos personales). La vista lo incrusta con un texto accesible («Código QR con tu número de control»).
- Se usa el backend SVG, que no necesita la extensión Imagick.

## Consecuencias
- Sin CDN, sin `data:` ni recursos de terceros; el QR sale del mismo servidor.
- Si Fortify deja de requerir la librería, Nexus la conserva por declaración propia.

## Alternativas descartadas
- **QR en JavaScript en el navegador:** agrega una dependencia del front y lógica fuera de las Queries.
- **Imagen PNG con Imagick:** exige una extensión y pierde nitidez al escalar.

## Adenda — 20 de noviembre de 2026 (tarea 1.27, AC-20)
El usuario decidió que el QR va en el reverso de la credencial, cambia cada 30 s y, al escanearlo, abre una página pública de verificación. Por eso el QR ya no lleva el número de control: lleva la URL `/credencial/verificar/<token>`, donde el token cifra y autentica con AES-256-GCM (llave derivada de `APP_KEY`, sin librería nueva: `openssl` de PHP) el id del alumno y la ventana de 30 s (`Students\Support\CredentialToken`). La URL no deja ver el id ni el número de control, no se puede adivinar ni recorrer, y el token vence (vale en su ventana y la siguiente). La librería del QR sigue siendo `bacon/bacon-qr-code` con el backend SVG; el QR lo pinta `students::components.credential-qr`, que solo se vuelve a pintar a sí mismo al cambiar.
