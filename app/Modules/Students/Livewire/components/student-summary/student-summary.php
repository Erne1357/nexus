<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\CredentialRequestQuery;
use App\Modules\Students\Queries\MyRecordQuery;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Queries\StudentQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use App\Modules\Students\Queries\StudentSummaryQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El tablero «Resumen» del alumno (AC-15, tarea 1.67; DESIGN.md, «Resumen del alumno»): su identidad, el avance en
 * créditos, sus promedios (AC-24), el avance por semestre, lo que cursa, lo que necesita atención, lo que puede seleccionar,
 * su credencial y, solo para él, su acceso. Es uno solo para dos lugares: el Resumen del propio alumno (`/resumen`, Core lo
 * pinta con HomePanels sin importar Students; sin `studentId` es el alumno de la sesión, en segunda persona) y la pestaña
 * «Resumen» de la ficha del personal (`studentId`, en tercera persona y sin «Tu acceso»). Solo lectura. Cada render
 * vuelve a autorizar: el propio alumno con `students.my_record.view` sobre lo suyo; el personal con la Policy `view` del alumno.
 * Los enlaces a otras pestañas van a «Mis datos» en el propio y, en la ficha, avisan a la página con `student-tab`.
 */
new class extends Component
{
    /** El alumno de la ficha del personal; null es «el mío» (el alumno de la sesión). El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $studentId = null;

    public function mount(?string $studentId = null): void
    {
        $this->studentId = $studentId;
        $this->student(app(Actor::class));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, StudentRecordQuery $records, StudentSummaryQuery $summaries, StudentCredentialQuery $credentials, CredentialRequestQuery $requests): array
    {
        $student = $this->student($context);
        $own = $this->studentId === null;

        if ($student === null) {
            return ['student' => null, 'own' => $own];
        }

        $record = $records->forStudent($student);
        $credential = $own ? $credentials->of($context, $student) : $credentials->forStaff($context, $student);
        $user = $context->user();

        return [
            'student' => $student,
            'own' => $own,
            'record' => $record,
            'summary' => $summaries->forRecord($record, $own),
            'credential' => $credential,
            'openRequests' => $requests->history($student)->filter(fn (CredentialRequest $request): bool => $request->status->isOpen())->values(),
            'access' => $own ? ['username' => $user->username, 'email' => $user->email, 'usesSiiNip' => $user->password_origin === PasswordOrigin::SiiNip] : null,
        ];
    }

    /**
     * El alumno del tablero, ya autorizado; null solo para un alumno de sesión sin ficha escolar.
     */
    private function student(Actor $context): ?Student
    {
        if ($this->studentId !== null) {
            $student = app(StudentQuery::class)->findOrFail($this->studentId);
            $this->authorize('view', $student);

            return $student;
        }

        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        return app(MyRecordQuery::class)->for($user);
    }
};
