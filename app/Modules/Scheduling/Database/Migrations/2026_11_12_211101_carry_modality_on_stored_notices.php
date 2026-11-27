<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contraer la carga por modalidad (ADR-0025), antes de quitar el puente `campuses.modality_id` (2026_11_12_211102): los
 * avisos guardados de la propuesta y de los acuerdos entre departamentos que llevan solo el campus (de antes de la carga
 * por modalidad) pasan a llevar la modalidad de ese campus por el puente, y su campus se quita: la propuesta es de la
 * modalidad (M6) y la buscan por ella (LocatesProposal); en un préstamo de salón el campus va en el filtro de la
 * pantalla (`parameters.campus`, AgreementText::parameters), como en los avisos nuevos (MS14, supuesto por confirmar).
 * Un aviso de un campus sin puente queda igual. Los demás avisos con campus (catálogos, cambios, cierre) no se tocan: su
 * campus no depende del puente y su pantalla lo sigue aceptando.
 *
 * Vive en Scheduling, el módulo de más a la derecha de los avisos que reescribe (los de la propuesta son de
 * AcademicLoad). down() no los regresa: la forma nueva también se lee con el puente.
 */
return new class extends Migration
{
    /**
     * Los avisos de la propuesta, que la buscan por su modalidad (LocatesProposal).
     *
     * @var list<string>
     */
    private const array PROPOSAL_NOTICES = [
        'App\\Modules\\AcademicLoad\\Notifications\\ProposalSubmitted',
        'App\\Modules\\AcademicLoad\\Notifications\\ProposalReturned',
    ];

    /**
     * Los avisos de los acuerdos entre departamentos.
     *
     * @var list<string>
     */
    private const array AGREEMENT_NOTICES = [
        'App\\Modules\\Scheduling\\Notifications\\AgreementReceived',
        'App\\Modules\\Scheduling\\Notifications\\AgreementAnswered',
        'App\\Modules\\Scheduling\\Notifications\\LoanCancelled',
    ];

    public function up(): void
    {
        $agreements = $this->placeholders(self::AGREEMENT_NOTICES);
        $all = $this->placeholders([...self::PROPOSAL_NOTICES, ...self::AGREEMENT_NOTICES]);

        DB::update(<<<SQL
            update notifications
            set data = jsonb_set(
                notifications.data || jsonb_build_object('modality_id', campuses.modality_id::text, 'campus_id', null),
                '{parameters,campus}', to_jsonb(campuses.code)
            )
            from campuses, interdepartmental_agreements
            where notifications.type in ({$agreements})
                and notifications.data ->> 'modality_id' is null
                and campuses.id::text = notifications.data ->> 'campus_id' and campuses.modality_id is not null
                and jsonb_typeof(notifications.data -> 'parameters') = 'object'
                and not jsonb_exists(notifications.data -> 'parameters', 'campus')
                and interdepartmental_agreements.id::text = notifications.data -> 'parameters' ->> 'acuerdo'
                and interdepartmental_agreements.kind = 'room_loan'
            SQL, self::AGREEMENT_NOTICES);

        DB::update(<<<SQL
            update notifications
            set data = notifications.data || jsonb_build_object('modality_id', campuses.modality_id::text, 'campus_id', null)
            from campuses
            where notifications.type in ({$all})
                and notifications.data ->> 'modality_id' is null
                and campuses.id::text = notifications.data ->> 'campus_id' and campuses.modality_id is not null
            SQL, [...self::PROPOSAL_NOTICES, ...self::AGREEMENT_NOTICES]);
    }

    public function down(): void
    {
        // Sin reversa (ver el docblock).
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
};
