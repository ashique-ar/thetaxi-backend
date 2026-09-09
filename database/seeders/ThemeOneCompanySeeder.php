<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\NavigationMenu;
use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Seeder;

class ThemeOneCompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['domain' => 'thetaxi.lk'],
            ['name' => 'The Taxi', 'website' => 'https://thetaxi.lk', 'is_active' => true, 'is_default' => false]
        );

        foreach (['active_theme' => 'default', 'site_name' => 'The Taxi', 'header_help_label' => 'Need Help?', 'header_cart_label' => 'My Cart'] as $type => $value) {
            WebsiteSetting::setValue($type, $value, $company->id);
        }

        $this->seedHeader($company->id);
        $this->seedHeader(null);
    }

    private function seedHeader(?string $companyId): void
    {
        $items = [
            ['Home', '/', 1],
            ['Services', '/services', 2],
            ['Corporate Transport', '/corporate-transfers', 3],
            ['Rate Chart', '/rate-chart', 4],
            ['About', '/about', 5],
            ['Inquiry', '/inquiry', 6],
        ];

        NavigationMenu::where('company_id', $companyId)
            ->whereNull('parent_id')
            ->whereNotIn('title', array_column($items, 0))
            ->update(['is_active' => false]);

        foreach ($items as [$title, $url, $order]) {
            NavigationMenu::updateOrCreate(
                ['company_id' => $companyId, 'parent_id' => null, 'title' => $title],
                ['url' => $url, 'target' => '_self', 'sort_order' => $order, 'is_active' => true, 'show_in_header' => true, 'show_in_footer' => false]
            );
        }
    }
}
