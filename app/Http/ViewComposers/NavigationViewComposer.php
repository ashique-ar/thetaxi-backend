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
        $loadNavigation = static fn ($scopedCompanyId) => NavigationMenu::headerNavigation()
            ->when(
                $scopedCompanyId,
                fn ($query) => $query->where('company_id', $scopedCompanyId),
                fn ($query) => $query->whereNull('company_id')
            )
            ->with(['children' => fn ($query) => $query->where('show_in_header', true)])
            ->get();

        $globalNavigation = $companyId ? $loadNavigation(null) : collect();
        $companyNavigation = $companyId ? $loadNavigation($companyId) : $loadNavigation(null);

        if ($companyId) {
            $companyByDestination = $companyNavigation->keyBy(static function (NavigationMenu $item): string {
                return $item->route_name ?: ($item->url ?: 'id:' . $item->id);
            });
            $globalDestinations = $globalNavigation->mapWithKeys(static function (NavigationMenu $item): array {
                return [($item->route_name ?: ($item->url ?: 'id:' . $item->id)) => true];
            });

            $navigation = $globalNavigation
                ->map(fn (NavigationMenu $item) => $companyByDestination->get(
                    $item->route_name ?: ($item->url ?: 'id:' . $item->id),
                    $item
                ))
                ->concat($companyNavigation->reject(static function (NavigationMenu $item) use ($globalDestinations): bool {
                    $destination = $item->route_name ?: ($item->url ?: 'id:' . $item->id);
                    return $globalDestinations->has($destination);
                }));
        } else {
            $navigation = $companyNavigation;
        }

        $view->with('headerNavigation', $navigation->values());
    }
}
