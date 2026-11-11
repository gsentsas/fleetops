<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeApiOrderCreation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $orderUuid,
        public ?string $serviceQuoteUuid = null,
        public bool $shouldDispatch = false,
        public ?string $companyUuid = null,
    ) {
    }

    public function handle(): void
    {
        $this->requireCompanyUuid();

        $order = $this->findOrder();
        if (!$order) {
            return;
        }

        $serviceQuote = $this->findServiceQuote();
        if ($this->serviceQuoteUuid !== null && !$serviceQuote) {
            throw new \RuntimeException('Queued service quote was not found for this tenant.');
        }
        if ($serviceQuote && $serviceQuote->company_uuid !== $this->companyUuid) {
            throw new \RuntimeException('Queued service quote company mismatch.');
        }

        $order->notifyDriverAssigned();
        $order->setPreliminaryDistanceAndTime();
        $order->purchaseServiceQuote($serviceQuote);

        // The order may have been dispatched or started while this job waited in the
        // queue; dispatching it again would move its status backwards.
        if ($this->shouldDispatch && $order->isAwaitingDispatch()) {
            $order->dispatchWithActivity();
        }

        $this->fireOrderReady($order);
    }

    protected function findOrder(): ?Order
    {
        return Order::where('uuid', $this->orderUuid)->where('company_uuid', $this->requireCompanyUuid())->first();
    }

    protected function findServiceQuote(): ?ServiceQuote
    {
        return $this->serviceQuoteUuid
            ? ServiceQuote::where('uuid', $this->serviceQuoteUuid)->where('company_uuid', $this->requireCompanyUuid())->first()
            : null;
    }

    protected function requireCompanyUuid(): string
    {
        if (!isset($this->companyUuid) || !is_string($this->companyUuid) || $this->companyUuid === '') {
            throw new \RuntimeException('Queued order finalization is missing its tenant.');
        }

        return $this->companyUuid;
    }

    protected function fireOrderReady(Order $order): void
    {
        event(new OrderReady($order));
    }
}
