<?php

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use App\Modules\Students\Queries\SubjectDemandQuery;

/*
| SubjectDemandQuery: cuántos alumnos necesitan todavía una materia y de qué planes, para el veto al
| desactivarla (reglas-de-negocio.md, "Elegibilidad y demanda"). La necesita un alumno no borrado,
| activo, con estatus `activo` o `baja_temporal`, cuyo plan la tiene en su retícula y que no la ha
| acreditado; si ya eligió otra especialidad, una materia de especialidad ajena no la necesita (S3).
| Se cuenta con el kárdex del momento, no con `student_progress`.
*/

beforeEach(function () {
    $this->isc = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    $this->iia = StudyPlan::factory()->create(['code' => 'IIND-2010-227']);
    $this->calculo = Subject::factory()->create(['code' => 'ACF-0901']);
    placeInPlan($this->isc, $this->calculo);
    placeInPlan($this->iia, $this->calculo);
});

function demandStudent(StudyPlan $plan, array $attributes = []): Student
{
    return Student::factory()->for($plan)->create(['career_id' => $plan->career_id, ...$attributes]);
}

function demandKardex(Student $student, Subject $subject, KardexResult $result = KardexResult::Aprobada): KardexRecord
{
    return KardexRecord::factory()->for($student)->for($subject)->create([
        'result' => $result,
        'grade' => $result === KardexResult::Aprobada ? 85 : null,
    ]);
}

function demandFor(Subject $subject): array
{
    return app(SubjectDemandQuery::class)->needing($subject->id);
}

it('cuenta al alumno activo y al de baja temporal que no la han acreditado, con las claves de sus planes en orden', function () {
    demandStudent($this->isc);
    demandStudent($this->isc, ['status' => StudentStatus::BajaTemporal]);
    demandStudent($this->iia);

    expect(demandFor($this->calculo))->toBe(['total' => 3, 'plans' => ['IIND-2010-227', 'ISIC-2010-224']]);
});

it('no cuenta egresados, bajas definitivas, alumnos sin estatus, desactivados ni borrados', function () {
    demandStudent($this->isc, ['status' => StudentStatus::Egresado]);
    demandStudent($this->isc, ['status' => StudentStatus::BajaDefinitiva]);
    demandStudent($this->isc, ['status' => null]);
    demandStudent($this->isc, ['is_active' => false]);
    demandStudent($this->isc)->delete();

    expect(demandFor($this->calculo))->toBe(['total' => 0, 'plans' => []]);
});

it('no cuenta a quien la acreditó; sí a quien la reprobó, la dejó o la está cursando', function () {
    demandKardex(demandStudent($this->isc), $this->calculo);
    demandKardex(demandStudent($this->isc), $this->calculo, KardexResult::Reprobada);
    demandKardex(demandStudent($this->isc), $this->calculo, KardexResult::Baja);
    demandKardex(demandStudent($this->iia), $this->calculo, KardexResult::Cursando);

    expect(demandFor($this->calculo))->toBe(['total' => 3, 'plans' => ['IIND-2010-227', 'ISIC-2010-224']]);
});

it('una acreditación borrada del kárdex no cuenta como acreditada', function () {
    demandKardex(demandStudent($this->isc), $this->calculo)->delete();

    expect(demandFor($this->calculo)['total'])->toBe(1);
});

it('no cuenta a los alumnos de un plan que la quitó de su retícula ni a los de un plan que nunca la tuvo', function () {
    $old = StudyPlan::factory()->create(['code' => 'ISIC-2004-296']);
    placeInPlan($old, $this->calculo)->delete();
    demandStudent($old);
    demandStudent(StudyPlan::factory()->create());

    expect(demandFor($this->calculo))->toBe(['total' => 0, 'plans' => []]);
});

it('usa el kárdex actual aunque student_progress diga otra cosa (Review Focus 4)', function () {
    // Acaba de importarse su acreditación; el avance viejo todavía la tiene pendiente.
    $justApproved = demandStudent($this->isc);
    StudentProgress::factory()->for($justApproved)->create(['subjects_pending' => [$this->calculo->id]]);
    demandKardex($justApproved, $this->calculo);

    // El avance viejo la daba por acreditada, pero el kárdex ya no lo dice.
    $corrected = demandStudent($this->iia);
    StudentProgress::factory()->for($corrected)->create(['subjects_approved' => 1, 'subjects_pending' => []]);

    expect(demandFor($this->calculo))->toBe(['total' => 1, 'plans' => ['IIND-2010-227']]);
});

describe('especialidades (S3)', function () {
    beforeEach(function () {
        $this->redes = Specialty::factory()->for($this->isc)->create(['code' => 'REDES']);
        $this->web = Specialty::factory()->for($this->isc)->create(['code' => 'WEB']);
        $this->routing = Subject::factory()->create(['code' => 'RED-2001']);
        $this->switching = Subject::factory()->create(['code' => 'RED-2002']);
        $this->frontend = Subject::factory()->create(['code' => 'WEB-2001']);
        placeInPlan($this->isc, $this->routing, 7, $this->redes);
        placeInPlan($this->isc, $this->switching, 8, $this->redes);
        placeInPlan($this->isc, $this->frontend, 7, $this->web);
    });

    it('mientras no elige especialidad, le faltan las de todas', function () {
        demandStudent($this->isc);

        expect(demandFor($this->routing))->toBe(['total' => 1, 'plans' => ['ISIC-2010-224']])
            ->and(demandFor($this->frontend)['total'])->toBe(1);
    });

    it('quien eligió otra especialidad, por lo aprobado o por lo que cursa, ya no la necesita', function (KardexResult $result) {
        demandKardex(demandStudent($this->isc), $this->frontend, $result);

        expect(demandFor($this->routing))->toBe(['total' => 0, 'plans' => []])
            ->and(demandFor($this->switching)['total'])->toBe(0);
    })->with([
        'aprobada' => KardexResult::Aprobada,
        'cursando' => KardexResult::Cursando,
    ]);

    it('quien eligió esa especialidad sí la necesita, y una materia común la necesitan todos', function () {
        demandKardex(demandStudent($this->isc), $this->routing);
        demandKardex(demandStudent($this->isc), $this->frontend);

        expect(demandFor($this->switching)['total'])->toBe(1)
            ->and(demandFor($this->calculo)['total'])->toBe(2);
    });

    it('una materia que es de especialidad en un plan y común en otro se cuenta por plan', function () {
        placeInPlan($this->iia, $this->frontend);
        demandKardex(demandStudent($this->isc), $this->routing);
        demandKardex(demandStudent($this->iia), $this->routing);

        expect(demandFor($this->frontend))->toBe(['total' => 1, 'plans' => ['IIND-2010-227']]);
    });
});
