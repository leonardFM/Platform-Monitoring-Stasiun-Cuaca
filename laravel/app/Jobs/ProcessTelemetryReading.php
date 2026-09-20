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
        public ?string $fw,
        public array $items,
    ) {
    }

    public function handle(TelemetryProcessor $processor): void
    {
        $processor->processItems($this->deviceId, $this->fw, $this->items);
    }
}