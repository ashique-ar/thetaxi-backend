<?php

namespace App\Http\ViewComposers;

use App\Models\NavigationMenu;
use App\Services\WebsiteSettingsService;
use Illuminate\View\View;

class NavigationViewComposer
{
    public function compose(View $view): void
    {
        $companyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        $query = NavigationMenu::headerNavigation();
        $query->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId));

        $view->with('headerNavigation', $query
            ->with(['children' => fn ($query) => $query->where('show_in_header', true)])
            ->get());
    }
}
