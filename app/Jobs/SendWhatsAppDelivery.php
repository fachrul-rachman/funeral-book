<?php

namespace App\Jobs;

use App\Services\WhatsAppDelivery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppDelivery implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 120;

    public function __construct(public string $deliveryId) {}

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(WhatsAppDelivery $delivery): void
    {
        $delivery->send($this->deliveryId);
    }
}
