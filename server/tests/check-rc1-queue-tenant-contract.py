#!/usr/bin/env python3
"""Static guard against accidental removal of RC1 queued order tenant binding."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
api_job = (root / 'src/Jobs/FinalizeApiOrderCreation.php').read_text()
internal_job = (root / 'src/Jobs/FinalizeInternalOrderCreation.php').read_text()
api_ctrl = (root / 'src/Http/Controllers/Api/v1/OrderController.php').read_text()
internal_ctrl = (root / 'src/Http/Controllers/Internal/v1/OrderController.php').read_text()

required = {
    'api_job_company_field': ('api_job', 'public ?string $companyUuid = null'),
    'api_job_company_guard': ('api_job', '$this->requireCompanyUuid();'),
    'api_job_order_scope': ('api_job', "Order::where('uuid', $this->orderUuid)->where('company_uuid', $this->requireCompanyUuid())->first()"),
    'api_job_quote_scope': ('api_job', "ServiceQuote::where('uuid', $this->serviceQuoteUuid)->where('company_uuid', $this->requireCompanyUuid())->first()"),
    'api_job_missing_quote_denial': ('api_job', "Queued service quote was not found for this tenant."),
    'internal_job_order_scope': ('internal_job', "Order::where('uuid', $this->orderUuid)->where('company_uuid', $this->requireCompanyUuid())->first()"),
    'internal_job_company_guard': ('internal_job', '$this->requireCompanyUuid();'),
    'public_dispatch_company': ('api_ctrl', 'FinalizeApiOrderCreation::dispatch($orderUuid, $serviceQuoteUuid, $shouldDispatch, $companyUuid)->afterCommit()'),
    'public_dispatch_trusted_order': ('api_ctrl', '$order->company_uuid'),
    'internal_dispatch_company': ('internal_ctrl', 'FinalizeInternalOrderCreation::dispatch($order->uuid, $order->company_uuid)->afterCommit()'),
}
sources = {'api_job': api_job, 'internal_job': internal_job, 'api_ctrl': api_ctrl, 'internal_ctrl': internal_ctrl}
for name, (source, expected) in required.items():
    assert expected in sources[source], f"RC1 queue tenant contract missing: {name}"
for name, text in [('api', api_job), ('internal', internal_job)]:
    assert 'Queued order finalization is missing its tenant.' in text, f"{name}: legacy must fail closed"
print('RC1_QUEUE_TENANT_STATIC_CONTRACT=PASS_12_OF_12')
