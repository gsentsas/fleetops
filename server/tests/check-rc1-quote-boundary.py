#!/usr/bin/env python3
"""Guard the explicit invalid/foreign quote validation in order controllers."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
paths = {
  'model': root / 'src/Models/ServiceQuote.php',
  'api': root / 'src/Http/Controllers/Api/v1/OrderController.php',
  'internal': root / 'src/Http/Controllers/Internal/v1/OrderController.php',
  'customer': root / 'src/Http/Controllers/Api/v1/CustomerController.php',
}
s = {k: v.read_text() for k,v in paths.items()}
assert "public static function hasReferenceInRequest(" in s['model']
assert s['model'].count("->where('company_uuid', $companyUuid)") == 2
assert s['api'].count("ServiceQuote::hasReferenceInRequest($request)") == 2
assert s['internal'].count("ServiceQuote::hasReferenceInRequest($request)") == 2
assert s['customer'].count("ServiceQuote::hasReferenceInRequest($request)") == 1
assert s['internal'].count("throw new FleetbaseRequestValidationException(['service_quote'") == 2
assert s['api'].count("Invalid service quote for this company.") == 2
assert s['customer'].count("Invalid service quote for this company.") == 1
print('RC1_QUOTE_REQUEST_PREMUTATION_GUARDS=PASS')
