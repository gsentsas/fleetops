#!/usr/bin/env python3
"""RC1 delivery boundary source contract. Not a replacement for HTTP race tests."""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
api = (root / "server/src/Http/Controllers/Api/v1/OrderController.php").read_text()
bff = (root / "server/src/Http/Controllers/Internal/v1/OrderController.php").read_text()
middle = (root / "server/src/Http/Middleware/AuthorizeDeliveryOperations.php").read_text()
order_scope = (root / "server/src/Scopes/AssignedDriverOrderScope.php").read_text()
file_scope = (root / "server/src/Scopes/AssignedDriverFileScope.php").read_text()
model = (root / "server/src/Models/Order.php").read_text()
provider = (root / "server/src/Providers/FleetOpsServiceProvider.php").read_text()

def method(source: str, name: str) -> str:
    match = re.search(r"public function " + re.escape(name) + r"\s*\(", source)
    assert match, f"Missing method {name}"
    end = source.find("\n    /**", match.end())
    assert end > match.end(), f"Method boundary missing for {name}"
    return source[match.start():end]

gates = {
    "public_destination_transaction": "DB::transaction(" in method(api, "setDestination"),
    "public_destination_row_lock": "], [], true)" in method(api, "setDestination"),
    "internal_destination_transaction": "DB::transaction(" in method(bff, "setDestination"),
    "internal_destination_row_lock": "], true)" in method(bff, "setDestination"),
    "public_qr_transaction": "DB::transaction(" in method(api, "captureQrScan"),
    "public_qr_dedup_lock": "lockForUpdate()->first()" in method(api, "captureQrScan"),
    "public_photo_transaction": "DB::transaction(" in method(api, "capturePhoto"),
    "public_photo_storage_compensation": "cleanupIncompleteProofFiles" in method(api, "capturePhoto"),
    "internal_proof_fail_closed": "if (!$subject)" in method(bff, "proofs"),
    "permission_denial": "This operation is not authorized." in middle,
    "tenant_model_lookup": "where('company_uuid', session('company'))" in middle,
    "driver_order_assignment_scope": "driver_assigned_uuid" in order_scope and "user_uuid" in order_scope,
    "driver_file_proof_scope": "delivery_proofs" in file_scope and "delivery_orders" in file_scope,
    "order_scope_registered": "AssignedDriverOrderScope" in model,
    "file_scope_registered": "AssignedDriverFileScope" in provider,
    "middleware_registered_both_surfaces": "'fleetbase.api', 'fleetbase.protected'" in provider,
}
for name, passed in gates.items():
    if not passed:
        raise AssertionError(f"RC1_DELIVERY_CONTRACT_FAILED:{name}")
print(f"RC1_DELIVERY_STATIC_CONTRACT=PASS_{len(gates)}_OF_{len(gates)}")
