<?php

use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/*
| 3.P6: los avisos de revisiones pendientes guardados antes de data.role iban al jefe de departamento.
*/
it('rellena data.role de los avisos viejos de revisiones pendientes sin tocar los que ya lo tienen', function () {
    $user = User::factory()->create();
    $insert = fn (array $data) => DB::table('notifications')->insertGetId([
        'type' => CatalogReviewsPending::class, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
        'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now(),
    ], 'id');

    $old = $insert(['title' => 'a']);
    $already = $insert(['title' => 'b', 'role' => 'admin']);

    (require base_path('app/Modules/CatalogValidation/Database/Migrations/2026_11_06_000001_backfill_catalog_notification_roles.php'))->up();

    $role = fn ($id) => json_decode(DB::table('notifications')->where('id', $id)->value('data'), true)['role'] ?? null;
    expect($role($old))->toBe('jefe_departamento')->and($role($already))->toBe('admin');
});
