<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Carbon;

/**
 * El aviso de privacidad del Tecnológico Nacional de México que muestra Nexus (LG-03, tarea 1.65): el simplificado y el
 * integral de 2026, copiados tal cual en `core::privacy-notice.simplified` y `core::privacy-notice.integral`. Se llega a
 * ellos desde cdjuarez.tecnm.mx/alumnos › «Protección de Datos Personales», que lleva a la página del TecNM con los PDF.
 *
 * Cuando el TecNM publique otro aviso, se copian los textos nuevos y cambian estas constantes en el mismo PR: con otra
 * `VERSION`, cada alumno vuelve a aceptarlo (AC-22).
 */
final class PrivacyNotice
{
    /** La fecha de realización del aviso vigente («FECHA DE REALIZACIÓN: 03/MARZO/2026.»), en ISO. */
    public const string DATE = '2026-03-03';

    /**
     * La versión vigente del aviso, la que el alumno acepta (AC-22) y se guarda en `users.privacy_notice_version`: su
     * fecha de realización. Vive aquí y no en una migración.
     */
    public const string VERSION = self::DATE;

    /** La sección de la página del ITCJ para alumnos que lleva al aviso. */
    public const string SOURCE_URL = 'https://cdjuarez.tecnm.mx/alumnos';

    /** La página «Protección de Datos Personales» del TecNM, a la que lleva la del ITCJ. */
    public const string TECNM_PAGE_URL = 'https://www.tecnm.mx/?vista=Proteccion_Datos_Personales';

    /** El PDF oficial del aviso simplificado. */
    public const string SIMPLIFIED_PDF_URL = 'https://www.tecnm.mx/menu/proteccion_datos_personales/AVISO_DE_PRIVACIDAD_SIMPLIFICADO_2026.pdf?doc=1';

    /** El PDF oficial del aviso integral. */
    public const string INTEGRAL_PDF_URL = 'https://www.tecnm.mx/menu/proteccion_datos_personales/AVISO_DE_PRIVACIDAD_INTEGRAL_2026.pdf?doc=1';

    /**
     * La fecha de realización para leerla: «3 de marzo de 2026».
     */
    public static function dateLabel(): string
    {
        return Carbon::parse(self::DATE)->locale('es')->translatedFormat('j \\d\\e F \\d\\e Y');
    }
}
