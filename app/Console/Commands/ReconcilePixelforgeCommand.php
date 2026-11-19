<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Generations\ReconcileStaleGenerationsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:reconcile')]
#[Description('Fetch generations whose webhooks never arrived from Pixelforge, and settle them')]
final class ReconcilePixelforgeCommand extends Command
{
    public function handle(ReconcileStaleGenerationsAction $reconcile): int
    {
        $result = $reconcile->execute();

        $this->info(sprintf(
            'Polled %d stale generation(s), %d error(s), requeued %d lost submission(s)%s.',
            $result->polled,
            $result->errors,
            $result->requeued,
            $result->stoppedByRateLimit ? '; stopped early: Pixelforge rate limit' : '',
        ));

        return self::SUCCESS;
    }
}
