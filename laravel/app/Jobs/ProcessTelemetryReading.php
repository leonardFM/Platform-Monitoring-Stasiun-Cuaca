<?php

namespace App\Jobs;

use App\Services\TelemetryProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelemetryReading implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(
        public string $deviceId,
        public string $messageId,
        public string $takenAt,
        public array $sensors,
    ) {
    }

    public function handle(TelemetryProcessor $processor): void
    {
        $processor->process($this->deviceId, $this->messageId, $this->takenAt, $this->sensors);
    }
}