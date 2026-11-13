<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los avisos guardados de una solicitud de cambio llevaban el campus del grupo como alcance (`campus_id`): al abrirlos,
 * la campana rechazaba a quien no trabaja en ese campus, aunque la pantalla le muestre la solicitud. Desde la carga por
 * modalidad el alcance es la modalidad (`modality_id`, que ya llevan) y el campus va solo como filtro de la pantalla
 * (`parameters.campus`, ChangeRequestNotification, MS14), así que un grupo que cambió de campus (M8) no deja fuera a
 * quien pidió el cambio desde el campus de antes. Esta migración les da esa forma a los guardados: su campus pasa, con
 * su código, al filtro (si el aviso ya lo trae, se queda el suyo) y `campus_id` queda nulo. Un aviso cuyo campus ya no
 * existe queda igual. Repetirla no cambia nada.
 *
 * down() regresa el campus del filtro a `campus_id` y lo quita de los parámetros.
 */
return new class extends Migration
{
    /**
     * Los avisos de una solicitud de cambio (las clases de ChangeRequestNotification).
     *
     * @var list<string>
     */
    private const array NOTICES = [
        'App\\Modules\\Staffing\\Notifications\\ChangeRequested',
        'App\\Modules\\Staffing\\Notifications\\ChangeRequestApproved',
        'App\\Modules\\Staffing\\Notifications\\ChangeRequestRejected',
        'App\\Modules\\Staffing\\Notifications\\ChangeRequestApplied',
        'App\\Modules\\Staffing\\Notifications\\ChangeRequestPendingApplication',
    ];

    public function up(): void
    {
        $types = implode(', ', array_fill(0, count(self::NOTICES), '?'));

        DB::update(<<<SQL
            update notifications
            set data = case
                when jsonb_exists(notifications.data -> 'parameters', 'campus') then notifications.data || jsonb_build_object('campus_id', null)
                else jsonb_set(notifications.data || jsonb_build_object('campus_id', null), '{parameters,campus}', to_jsonb(campuses.code))
            end
            from campuses
            where notifications.type in ({$types})
                and campuses.id::text = notifications.data ->> 'campus_id'
                and jsonb_typeof(notifications.data -> 'parameters') = 'object'
            SQL, self::NOTICES);
    }

    public function down(): void
    {
        $types = implode(', ', array_fill(0, count(self::NOTICES), '?'));

        DB::update(<<<SQL
            update notifications
            set data = jsonb_set(notifications.data, '{campus_id}', to_jsonb(campuses.id::text)) #- '{parameters,campus}'
            from campuses
            where notifications.type in ({$types})
                and notifications.data ->> 'campus_id' is null
                and campuses.code = notifications.data -> 'parameters' ->> 'campus'
            SQL, self::NOTICES);
    }
};
