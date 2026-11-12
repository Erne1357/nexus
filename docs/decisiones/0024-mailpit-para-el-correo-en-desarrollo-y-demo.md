# ADR-0024 — Mailpit atrapa el correo en desarrollo y en la demo

**Estado:** Aceptada · **Fecha:** 2026-11-11

## Contexto
Hasta la tarea 1.16 Nexus no mandaba correos: los avisos van por la campana (canal `database`) y `MAIL_MAILER` era `log`. Con los accesos sin claves del SII (reglas AC-06 a AC-08), cada docente recibe en su correo institucional una invitación con un enlace firmado, su usuario y la contraseña por defecto. Esa invitación hay que poder verla y probarla de punta a punta en desarrollo y en la demo, y ahí no puede salir ningún correo real: la demo trae docentes del SII con sus correos reales.

Supuesto por confirmar (11 de noviembre de 2026): la demo y el desarrollo nunca mandan correos reales.

## Decisión
1. **Mailpit** (`axllent/mailpit`, imagen fijada con su digest) es un servicio de `compose.override.yaml`, el archivo de desarrollo: recibe por SMTP en el puerto 1025 dentro de la red `backend` y guarda todo lo que llega, sin reenviarlo. Su bandeja web queda en `127.0.0.1:8025` (`MAILPIT_PORT`), solo en la máquina.
2. `.env.example` apunta el correo a Mailpit: `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`. La demo usa el mismo servicio.
3. Las pruebas no lo usan: `phpunit.xml` deja `MAIL_MAILER=array` y las pruebas de la invitación usan `Notification::fake()`.
4. **Con `MAIL_MAILER=log` o `array` el correo de la invitación no lleva la contraseña por defecto:** el transporte `log` escribe el correo completo en `storage/logs/laravel.log`, y la contraseña quedaría en un archivo de texto. En su lugar, el correo dice que se la da su jefe de departamento o la administración. Con Mailpit (`smtp`) el correo va completo, igual que en producción.
5. No es una dependencia de la aplicación: no entra a `composer.json` ni a `package.json`, ni a `compose.yaml` ni a `compose.prod.yaml`. Producción configura su propio SMTP institucional en su `.env`.

## Consecuencias
- Para ver un correo en desarrollo basta abrir `http://127.0.0.1:8025`. La cola (`queue`) es la que manda: la invitación sale de la cola después del commit.
- Si un `.env` de desarrollo o de la demo apuntara a un SMTP real, saldrían correos de verdad. Por eso `.env.example` trae Mailpit, y el `.env` de la demo se revisa antes de sembrar.
- Producción necesita un SMTP del Tec (o de TecNM) que pueda mandar a `@cdjuarez.tecnm.mx`. Esa configuración es del despliegue ([ADR-0013](0013-despliegue-on-premise-itcj.md)) y queda pendiente.

## Alternativas descartadas
- **`MAIL_MAILER=log`:** no manda nada, pero el correo queda en el log como texto: no se ve como lo verá el docente ni se puede pulsar el enlace.
- **Mailtrap u otro servicio en la nube:** saca los correos, con datos personales de la demo, fuera de la máquina.
- **MailHog:** hace lo mismo, pero ya no se mantiene.
