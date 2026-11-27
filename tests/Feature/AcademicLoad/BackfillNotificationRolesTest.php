<?php

use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/*
| 3.P6: los avisos guardados antes de que existiera data.role reciben el puesto al que iban dirigidos.
*/
it('rellena data.role de los avisos viejos de propuestas sin tocar los que ya lo tienen', function () {
    $user = User::factory()->create();
    $insert = fn (string $type, array $data) => DB::table('notifications')->insertGetId([
        'type' => $type, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
        'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now(),
    ], 'id');

    $submitted = $insert(ProposalSubmitted::class, ['title' => 'a']);
    $returned = $insert(ProposalReturned::class, ['title' => 'b']);
    $already = $insert(ProposalSubmitted::class, ['title' => 'c', 'role' => 'admin']);

    (require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_06_000001_backfill_proposal_notification_roles.php'))->up();

    $role = fn ($id) => json_decode(DB::table('notifications')->where('id', $id)->value('data'), true)['role'] ?? null;
    expect($role($submitted))->toBe('jefe_departamento')
        ->and($role($returned))->toBe('coordinador')
        ->and($role($already))->toBe('admin');
});
