<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Actions\DeliverHeldPositionMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Manda en la cola `bulk` un correo de puesto que se liberó (DeliverHeldPositionMail, CE-02): uno por trabajo, así una
 * falla o el freno de Microsoft (429) solo detiene ese. Reintenta con espera; si se rinde, el correo vuelve a la espera
 * con un intento más (y queda fallido al llegar al tope), y la siguiente corrida lo vuelve a tomar.
 */
final class SendHeldPositionMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $heldId)
    {
        $this->onQueue('bulk');
    }

    public function handle(DeliverHeldPositionMail $deliver): void
    {
        $deliver->handle($this->heldId);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(DeliverHeldPositionMail::class)->giveBack($this->heldId);
    }
}
