# ADR-0040 — Aviso de privacidad y cambio obligatorio del NIP

**Estado:** Aceptada (decisión del usuario, 26 de noviembre de 2026; lo marcado «supuesto por confirmar» sigue abierto) · **Fecha:** 2026-11-26 · Tarea 1.64 ([#889](https://github.com/Erne1357/nexus/issues/889)) · Rama padre `riel/alumnos`

## Contexto
Desde la tarea 1.16 el alumno entra a Nexus con su número de control y su NIP del SII (AC-10). El NIP solo tiene 10,000 combinaciones y el SII lo guardaba en texto plano, y cambiarlo por una contraseña propia era opcional (AC-12). El 26 de noviembre de 2026 el usuario pidió: «Que todos los alumnos al entrar con su NIP del SII estén obligados a cambiar su contraseña ahí mismo. Y al cambiarla tengan que aceptar el acuerdo de privacidad de los datos». Respondió además que se acepta el aviso **simplificado** del TecNM de 2026, con enlace al integral.

El aviso es del Tecnológico Nacional de México (cdjuarez.tecnm.mx/alumnos › «Protección de Datos Personales»); Nexus lo copia tal cual en la página pública `/aviso-de-privacidad` (AC-23, tarea 1.65), en componentes Blade reutilizables.

## Decisión
- **Cambio obligatorio sin migrar datos.** Quien tiene `password_origin = sii_nip` debe cambiar su contraseña antes de usar Nexus, igual que quien tiene `must_change_password` (`AccessRequirements::mustChangePassword()`, que leen `EnsurePasswordIsChanged`, la pantalla de cambio, la respuesta del login y `PasswordUpdateResponse`). No se marca a nadie en la base: el origen ya lo dice, y una nueva corrida de la migración del SII sigue dejando `must_change_password = false`.
- **La aceptación al cambiar el NIP.** La pantalla de cambio de contraseña (primer acceso, en la escena del login) muestra al alumno el aviso simplificado en un recuadro con desplazamiento, el enlace al integral y la casilla obligatoria «He leído el aviso de privacidad y acepto el tratamiento de mis datos personales». `UpdateUserPassword` la valida (`accepted`) y, con ella, `AcceptPrivacyNotice` guarda la aceptación en la misma transacción que la contraseña.
- **Dónde se guarda.** `users.privacy_notice_accepted_at` (timestamptz) y `users.privacy_notice_version` (texto), nulas. La versión vigente es la fecha de realización del aviso, `2026-03-03`, en `Core\Support\PrivacyNotice::VERSION` (código, no migración ni seeder). Cada aceptación deja una entrada `privacy-notice-accepted` en la bitácora con la versión.
- **Quién lo acepta (supuesto por confirmar).** Solo el usuario de alumno: el que solo tiene el puesto `alumno` (`Actor::isStudentOnly()`). El personal no lo acepta por ahora, tampoco quien además de alumno tiene un puesto del personal (como en CF-S4, se maneja como personal).
- **El alumno que ya cambió su NIP (supuesto por confirmar).** `EnsurePrivacyNoticeAccepted`, en el grupo `web` después de `EnsurePasswordIsChanged`, manda a la pantalla «Aviso de privacidad» a todo alumno sin la aceptación de la versión vigente: el mismo texto, «Acepto» y «Salir» (cerrar sesión). Mientras tanto solo puede ver esa pantalla, la página pública del aviso y la de cambio de contraseña (que también lo pide), o salir; una petición JSON o de Livewire recibe 403. Una versión nueva del aviso (otra `VERSION`) pide aceptarlo de nuevo.

## Consecuencias
- Todo alumno que hoy entra con su NIP tendrá que elegir una contraseña propia en su siguiente acceso; los que olviden la nueva la restablecen por su correo institucional (AC-16).
- La pantalla «Mis datos» ya no dice que cambiar el NIP «no es obligatorio».
- La respuesta JSON del login no lleva la credencial a la banda cuando la siguiente página es el cambio o el aviso.
- La factory de usuarios nace con el aviso vigente aceptado (como nace con la contraseña ya cambiada); las pruebas del aviso usan `withoutPrivacyNotice()`.
- Cambiar el aviso es copiar los textos nuevos del TecNM en los componentes y cambiar `PrivacyNotice` en el mismo PR.

## Alternativas descartadas
- **Marcar `must_change_password = true` a los alumnos con NIP** (comando de una sola vez): datos que mantener, y la migración del SII tendría que volver a marcarlo en cada alumno nuevo; el origen de la contraseña ya basta.
- **Una casilla en la pantalla de acceso:** obligaría a aceptar a todos, también al personal, antes de saber quién entra.
- **Guardar la versión en `settings` o en una migración:** la versión es la del texto que está en el código; si cambia el texto, cambia la versión en el mismo PR.
- **Pedir la aceptación solo al cambiar el NIP:** los alumnos que ya lo habían cambiado nunca la darían (por eso el supuesto de la pantalla «Aviso de privacidad»).
