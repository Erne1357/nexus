{{--
    El aviso de privacidad SIMPLIFICADO del TecNM (AC-23, tarea 1.65), tal cual el PDF oficial de 2026
    (Core\Support\PrivacyNotice::SIMPLIFIED_PDF_URL), sin el encabezado ni el pie que se repiten en cada página. No se
    corrige ni se resume: si el TecNM publica otro, se copia completo. Los tamaños van en `em`, así que toma el tamaño de
    letra de donde se pone: la página pública (core::privacy-notice.show) y el recuadro de la pantalla de cambio de
    contraseña. Sus atributos van en el `<article>`.
--}}
<article {{ $attributes->class('flex flex-col gap-[0.85em] text-pretty') }}>
    <h2 class="text-[1.3em] leading-tight font-bold tracking-[-0.01em]">AVISO DE PRIVACIDAD SIMPLIFICADO</h2>

    <p>El <strong>Tecnológico Nacional de México</strong> (<strong>TecNM</strong>), a través de sus oficinas centrales adscritas a la Dirección General, con domicilio en Av. Universidad 1200, Quinto Piso, Colonia Xoco, Alcaldía Benito Juárez, código postal 03330, Ciudad de México, es responsable de recabar sus datos personales, del tratamiento de los mismos, de su protección y resguardo, con fundamento en los artículos 3º, fracción III, 10,11,12,15,16,17,19,20, 21, 22 y 59 de la Ley General de Protección de Datos Personales en Posesión de Sujetos Obligados, publicada en el Diario Oficial de la Federación el 20 de marzo de 2025.</p>

    <h3 class="mt-[0.4em] text-[1.06em] font-bold">Finalidades para recabar sus datos personales son las siguientes:</h3>

    <p>La recolección de los datos personales por parte de este Órgano Administrativo Desconcentrado de la Secretaría de Educación se efectuará en el cumplimento de las siguientes finalidades:</p>

    <ul class="flex list-disc flex-col gap-[0.55em] ps-[1.4em] marker:text-neutral">
        <li>Eliminación de datos personales contenidos en las documentales que dan respuesta a las solicitudes de acceso a la información, recursos de revisión, y recursos de inconformidad emitidos por la Autoridad Garante.</li>
        <li>Llevar a cabo las gestiones necesarias para el otorgamiento de becas, estímulos y otros medios de apoyo para los estudiantes del Tecnológico Nacional de México.</li>
        <li>Llevar a cabo las actividades relacionadas con la expedición y registro de certificados, títulos y grados académicos del TecNM.</li>
        <li>Realizar los trámites relacionados con la expedición de las cédulas de los egresados de los institutos, unidades y centros ante la Dirección General de Profesiones de la Secretaría de Educación Pública</li>
        <li>Suscripción de convenios de vinculación con los sectores social, público y privado con el Tecnológico Nacional de México.</li>
        <li>Inscripción para participar en eventos, concursos, congresos, simposios, seminarios y reuniones de carácter académico, científica e innovación en el Tecnológico Nacional de México, para desarrollar la investigación aplicada, científica y tecnológica.</li>
        <li>Inscripción para participar en eventos de cultura, arte, educación cívica, participación comunitaria, educación para la salud y deportes del Tecnológico Nacional de México.</li>
        <li>Coordinar el proceso de promoción para el personal docente de los Institutos Tecnológicos, unidades y centros.</li>
        <li>Elaborar y evaluar los Sistema de Gestión de Calidad, ambiental, de la energía y de la seguridad y salud en el trabajo.</li>
        <li>Trámites de prestaciones y movimientos de personal, como altas, bajas, promociones, licencias, modificaciones de datos personales y demás incidencias del personal adscrito al Tecnológico Nacional de México.</li>
        <li>Realizar los procesos licitatorios para la adquisición de mobiliario y equipo.</li>
        <li>Elaboración de una identificación vigente con fotografía, toda vez que, es indispensable, para ingresar al Instituto Tecnológico correspondiente, y así optimizar los protocolos de vigilancia y protección civil dentro de nuestras instalaciones.</li>
    </ul>

    <h3 class="mt-[0.4em] text-[1.06em] font-bold">Transferencia de datos personales</h3>

    <p>Los datos personales no serán objeto de transferencia, difusión ni distribución a terceros, salvo los supuestos de excepción previstos en los artículos 16, 22 y 64 de la Ley General de Protección de Datos Personales en Posesión de Sujetos Obligados (LGPDPPSO).</p>

    <p>El Tecnológico Nacional de México (TECNM) únicamente realizará las transferencias que resulten indispensables para el ejercicio de sus atribuciones y funciones, de conformidad con su Manual de Organización General. De igual forma, la información podrá ser remitida a otras autoridades competentes y sujetos obligados de la LGPDPPSO cuando exista un requerimiento debidamente fundado y motivado, o bien, mediante mandato de orden judicial emitido por órgano jurisdiccional.</p>

    <p>En caso de manifestar su negativa de tratamiento será necesario elaborar un escrito antes de conceder sus datos personales, exponiendo los motivos de su impedimento a esta Unidad Administrativa y remitirla al correo: <a href="mailto:unidaddeenlace@nube.sep.gob.mx" class="link break-all underline-offset-3">unidaddeenlace@nube.sep.gob.mx</a>.</p>

    <p>Usted puede conocer nuestro aviso de privacidad integral en la página electrónica del Tecnológico Nacional de México. <a href="https://www.tecnm.mx/" target="_blank" rel="noopener" class="link break-all underline-offset-3">https://www.tecnm.mx/<span class="sr-only"> (se abre en otra pestaña)</span></a></p>

    <p class="font-bold">FECHA DE REALIZACIÓN: 03/MARZO/2026.</p>
</article>
