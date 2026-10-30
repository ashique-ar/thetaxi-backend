<?php

namespace App\Http\Middleware;

use App\Models\Corporate\Corporate;
use App\Services\CompanyIdScope;
use App\Services\CompanySettingsAccessService;
use App\Services\SingleCompanyScope;
use App\Services\WebsiteSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorporateBusinessScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->attributes->get('business_company_id')
            ?? app(WebsiteSettingsService::class)->resolveCurrentCompanyId()
            ?? app(SingleCompanyScope::class)->activeDefaultCompany()?->id;

        abort_unless($companyId, 409, 'Select an active business before managing corporate accounts.');
        if ($request->user()) {
            app(CompanySettingsAccessService::class)->assertCanAccess($request->user(), (string) $companyId);
        }

        $routeCorporate = $request->route('corporate');
        if ($routeCorporate) {
            $corporateId = $routeCorporate instanceof Corporate ? $routeCorporate->id : (string) $routeCorporate;
            $exists = Corporate::query()->whereKey($corporateId)
                ->where(fn ($query) => CompanyIdScope::constrain($query, 'company_id', (string) $companyId))
                ->exists();
            abort_unless($exists, 404);
        }

        $request->attributes->set('business_company_id', (string) $companyId);

        return $next($request);
    }
}
