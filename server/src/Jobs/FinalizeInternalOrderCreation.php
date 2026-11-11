<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeInternalOrderCreation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $orderUuid, public ?string $companyUuid = null)
    {
    }

    public function handle(): void
    {
        $this->requireCompanyUuid();

        $order = $this->findOrder();
        if (!$order) {
            return;
        }

        $order->notifyDriverAssigned();

        $this->fireOrderReady($order);
    }

    protected function findOrder(): ?Order
    {
        return Order::where('uuid', $this->orderUuid)->where('company_uuid', $this->requireCompanyUuid())->first();
    }

    protected function requireCompanyUuid(): string
    {
        if (!is_string($this->companyUuid) || $this->companyUuid === '') {
            throw new \RuntimeException('Queued order finalization is missing its tenant.');
        }

        return $this->companyUuid;
    }

    protected function fireOrderReady(Order $order): void
    {
        event(new OrderReady($order));
    }
}
