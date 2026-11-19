<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\BackgroundProcess;

/**
 * Whether the processes that move generations forward are running (ADR-0015).
 */
final readonly class BackgroundHealthData
{
    public function __construct(
        public bool $schedulerRunning,
        public bool $queueRunning,
    ) {}

    public function degraded(): bool
    {
        return ! $this->schedulerRunning || ! $this->queueRunning;
    }

    /**
     * @return list<BackgroundProcess>
     */
    public function down(): array
    {
        return array_values(array_filter([
            $this->schedulerRunning ? null : BackgroundProcess::Scheduler,
            $this->queueRunning ? null : BackgroundProcess::QueueWorker,
        ]));
    }

    /**
     * For operators: which processes are down and what that breaks.
     */
    public function summary(): string
    {
        return implode(' ', array_map(fn (BackgroundProcess $process): string => $process->outage(), $this->down()));
    }

    /**
     * @return array{scheduler: bool, queue: bool}
     */
    public function toArray(): array
    {
        return ['scheduler' => $this->schedulerRunning, 'queue' => $this->queueRunning];
    }

    /**
     * @param  array{scheduler: bool, queue: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['scheduler'], $data['queue']);
    }
}
