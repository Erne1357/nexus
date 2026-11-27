<?php

namespace App\Modules\Academic\Console;

use App\Modules\Academic\Actions\SetSubjectTeacherHours;
use App\Modules\Academic\Models\Subject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comando de una sola vez (docs/operacion/despliegue.md, PL-02, tarea 1.73): marca que las horas de las materias cuyo
 * nombre contiene `--nombre` (sin importar acentos ni mayúsculas; p. ej. `--nombre=tutori`) no cuentan en la carga del
 * docente, con SetSubjectTeacherHours (bitácora, sin usuario). El texto llega por el argumento, nunca del código.
 * Idempotente: las que ya no cuentan no se tocan. Con `--dry-run` solo dice cuáles marcaría. Todo o nada.
 */
final class ExcludeTeacherHoursCommand extends Command
{
    protected $signature = 'academic:exclude-teacher-hours
        {--nombre= : Texto que contiene el nombre de la materia (al menos 4 letras), p. ej. tutori}
        {--dry-run : Solo dice qué materias marcaría, sin escribir}';

    protected $description = 'Marca que las horas de las materias con ese nombre no cuentan en la carga del docente (PL-02)';

    public function handle(SetSubjectTeacherHours $set): int
    {
        $needle = self::fold((string) $this->option('nombre'));

        if (mb_strlen($needle) < 4) {
            $this->error('Da en --nombre al menos 4 letras del nombre de las materias, p. ej. --nombre=tutori.');

            return self::FAILURE;
        }

        $subjects = Subject::query()->orderBy('code')->get(['id', 'code', 'name', 'counts_teacher_hours'])
            ->filter(fn (Subject $subject): bool => str_contains(self::fold($subject->name), $needle))
            ->values();
        $pending = $subjects->filter(fn (Subject $subject): bool => $subject->counts_teacher_hours)->values();

        if (! $this->option('dry-run')) {
            DB::transaction(fn () => $pending->each(fn (Subject $subject) => $set->handle($subject, false, null)));
        }

        $verb = $this->option('dry-run') ? 'Marcaría' : 'Marcó';
        $this->info("{$verb} {$pending->count()} materias; ".($subjects->count() - $pending->count()).' ya no contaban en la carga del docente.');

        foreach ($pending as $subject) {
            $this->line("  {$subject->code} · {$subject->name}");
        }

        return self::SUCCESS;
    }

    private static function fold(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }
}
