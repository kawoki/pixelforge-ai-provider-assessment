<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Pushed to the owner's browser when a generation changes (ADR-0009). Broadcast immediately,
 * not via the queue, so live updates don't depend on the worker. Carries only ids: the
 * page reloads the generation through the normal, authorised Inertia props.
 */
final readonly class GenerationUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public int $userId,
        public string $generationId,
        public string $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("App.Models.User.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'generation.updated';
    }

    /**
     * @return array{id: string, status: string}
     */
    public function broadcastWith(): array
    {
        return ['id' => $this->generationId, 'status' => $this->status];
    }
}
