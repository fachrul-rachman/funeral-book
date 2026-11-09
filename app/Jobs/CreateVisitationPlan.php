<?php

namespace App\Jobs;

use App\Services\VisitationPlans;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateVisitationPlan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public string $requestId) {}

    public function uniqueId(): string
    {
        return $this->requestId;
    }

    public function handle(VisitationPlans $plans): void
    {
        $plans->create($this->requestId);
    }
}
