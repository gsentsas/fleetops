<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;

class NavigatorController extends Controller
{
    /**
     * Retrieve the driver onboard settings for an organization.
     *
     * The organization is resolved from its public id. An unknown public id is a client
     * error, not a server error, so it answers 404 rather than dereferencing a null company.
     *
     * @return JsonResponse
     */
    public function getDriverOnboardSettings($companyId)
    {
        $company = $this->findCompanyByPublicId($companyId);
        if (!$company) {
            return $this->errorResponse('Organization not found.', 404);
        }

        $driverOnboardSettings = $this->driverOnboardSetting($company->uuid);
        if (!$driverOnboardSettings) {
            $driverOnboardSettings = [];
        }

        return $this->jsonResponse(['driverOnboardSettings' => $driverOnboardSettings]);
    }

    protected function findCompanyByPublicId(string $companyId): ?Company
    {
        $query = Company::select()->where('public_id', $companyId);
        if ($credential = request()->attributes->get('navigator_credential')) {
            $query->where('uuid', $credential->company_uuid);
        }

        return $query->first();
    }

    protected function driverOnboardSetting(string $companyUuid): mixed
    {
        return Setting::where('key', 'fleet-ops.driver-onboard-settings.' . $companyUuid)->value('value');
    }

    protected function jsonResponse(array $payload): JsonResponse
    {
        return response()->json($payload);
    }

    protected function errorResponse(string $message, int $statusCode): JsonResponse
    {
        return response()->error($message, $statusCode);
    }
}
