<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentCredentialQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El QR del reverso de la credencial (AC-20, tarea 1.27): un componente aparte para que, al cambiar cada 30 s, solo se
 * vuelva a pintar el código y no la credencial ni la foto. La cuenta atrás la lleva Alpine con los segundos que le quedan a
 * la ventana; al llegar a cero pide `$refresh` y el servidor arma el token de la ventana nueva. Solo lo ve el dueño de la
 * credencial (`students.my_record.view` sobre lo suyo), en cada render, y solo si su credencial está vigente.
 */
new class extends Component
{
    #[Locked]
    public string $studentId;

    public function mount(string $studentId): void
    {
        $this->studentId = $studentId;
        $this->owned();
    }

    /**
     * @return array{qr: array{url: string, svg: string, window: int, seconds_left: int}}
     */
    public function with(StudentCredentialQuery $credentials): array
    {
        return ['qr' => $credentials->qrFor($this->owned(), now()->getTimestamp())];
    }

    private function owned(): Student
    {
        $context = app(Actor::class);
        $user = $context->user();
        $student = KeyLookup::find(Student::query(), $this->studentId);

        abort_unless(
            $user !== null && $student !== null && (string) $student->user_id === (string) $user->getKey()
                && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey()))
                && StudentCredentialQuery::isCurrent($student->status),
            403,
        );

        return $student;
    }
};
