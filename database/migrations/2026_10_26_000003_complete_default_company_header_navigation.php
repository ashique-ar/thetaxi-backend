<?php

use App\Models\Company;
use App\Models\NavigationMenu;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $company = Company::query()->where('is_default', true)->first();
        if (!$company) {
            return;
        }

        $companyRoots = NavigationMenu::query()
            ->where('company_id', $company->id)
            ->whereNull('parent_id')
            ->get()
            ->keyBy(fn (NavigationMenu $item) => $this->destination($item));

        NavigationMenu::headerNavigation()
            ->whereNull('company_id')
            ->with('children')
            ->get()
            ->each(function (NavigationMenu $globalItem) use ($company, $companyRoots): void {
                $destination = $this->destination($globalItem);
                $companyItem = $companyRoots->get($destination);

                if ($companyItem) {
                    if ($companyItem->sort_order !== $globalItem->sort_order) {
                        $companyItem->sort_order = $globalItem->sort_order;
                        $companyItem->saveQuietly();
                    }

                    return;
                }

                $this->copyTree($globalItem, (string) $company->id, null);
            });
    }

    public function down(): void
    {
        // Preserve company menu rows if this data migration is rolled back.
    }

    private function copyTree(NavigationMenu $source, string $companyId, ?string $parentId): NavigationMenu
    {
        $copy = $source->replicate(['id', 'created_at', 'updated_at']);
        $copy->company_id = $companyId;
        $copy->parent_id = $parentId;
        $copy->save();

        NavigationMenu::query()
            ->where('parent_id', $source->id)
            ->orderBy('sort_order')
            ->get()
            ->each(fn (NavigationMenu $child) => $this->copyTree($child, $companyId, (string) $copy->id));

        return $copy;
    }

    private function destination(NavigationMenu $item): string
    {
        return $item->route_name
            ? 'route:' . $item->route_name . ':' . json_encode($item->route_params ?? [])
            : 'url:' . rtrim((string) $item->url, '/');
    }
};
