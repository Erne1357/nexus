<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Actions\SaveStageWindows;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Siembra la demo hasta el inicio de una etapa: corre, punto por punto, los pasos que declaran los módulos; al llegar
 * a cada punto corre sus revisiones; al final deja el calendario de 20271 con la etapa del punto en curso. No borra la
 * base (eso lo hace `demo:seed`), así las pruebas lo corren sobre la suya.
 *
 * Como `MassiveSeeder` (`WithoutModelEvents`), todo corre sin eventos de modelo, y los eventos que los módulos piden
 * silenciar (`demoQuietEvents()`) se descartan. Mientras corre, el admin queda autenticado en cuanto existe, porque las
 * Actions que usan los pasos registran quién hizo cada cosa; al terminar vuelve el usuario que había.
 */
final readonly class DemoRunner
{
    use RefusesProduction;

    public function __construct(
        private DemoPlan $plan,
        private SaveStageWindows $saveStageWindows,
    ) {}

    /**
     * @param  (Closure(DemoCheckpoint, list<array{checkpoint: DemoCheckpoint, label: string, passed: bool, reason: ?string}>): void)|null  $afterCheckpoint
     */
    public function run(DemoCheckpoint $target, DemoContext $demo, ?Closure $afterCheckpoint = null): DemoReport
    {
        $this->refuseProduction();

        $checkpoints = DemoCheckpoint::upTo($target);
        $steps = [];
        $checks = [];

        $previousUser = Auth::user();

        $work = function () use ($checkpoints, $target, $demo, $afterCheckpoint, &$steps, &$checks): void {
            $this->authenticateAdmin();

            foreach ($checkpoints as $checkpoint) {
                foreach ($this->plan->stepsFor($checkpoint) as $step) {
                    $started = hrtime(true);
                    ($step->run)($demo);
                    $seconds = (hrtime(true) - $started) / 1e9;

                    $this->authenticateAdmin();
                    $steps[] = ['checkpoint' => $checkpoint, 'label' => $step->label, 'seconds' => $seconds];
                    $demo->info(sprintf('[%d] %s: %.1f s', $checkpoint->number(), $step->label, $seconds));
                }

                $results = array_map(fn (DemoCheck $check): array => $this->check($check, $checkpoint, $demo), $this->plan->checksAt($checkpoint));
                array_push($checks, ...$results);

                if ($afterCheckpoint !== null) {
                    $afterCheckpoint($checkpoint, $results);
                }
            }

            $this->writeCalendar($target, $demo);
        };

        $withoutModelEvents = fn () => Model::withoutEvents($work);
        $quiet = $this->plan->quietEvents();

        try {
            $quiet === [] ? $withoutModelEvents() : Event::fakeFor($withoutModelEvents, $quiet);
        } finally {
            $previousUser === null ? Auth::forgetUser() : Auth::setUser($previousUser);
        }

        return new DemoReport($steps, $checks);
    }

    /**
     * @return array{checkpoint: DemoCheckpoint, label: string, passed: bool, reason: ?string}
     */
    private function check(DemoCheck $check, DemoCheckpoint $checkpoint, DemoContext $demo): array
    {
        try {
            $result = ($check->passes)($demo);
        } catch (Throwable $exception) {
            $result = $exception->getMessage();
        }

        return [
            'checkpoint' => $checkpoint,
            'label' => $check->label,
            'passed' => $result === true,
            'reason' => $result === true ? null : (is_string($result) ? $result : 'La revisión no devolvió true.'),
        ];
    }

    /**
     * El admin, en cuanto existe, aunque hubiera otro usuario autenticado; al terminar, `run()` devuelve el que había.
     */
    private function authenticateAdmin(): void
    {
        if (Auth::user()?->username === 'admin') {
            return;
        }

        $admin = User::query()->where('username', 'admin')->first();

        if ($admin !== null) {
            Auth::setUser($admin);
        }
    }

    private function writeCalendar(DemoCheckpoint $target, DemoContext $demo): void
    {
        $term = $demo->term();
        $today = CarbonImmutable::now('America/Ciudad_Juarez')->startOfDay();

        foreach (DemoCalendar::windows($target, $today) as $stage => $window) {
            $this->saveStageWindows->handle($term, Stage::from($stage), $window, []);
        }
    }
}
