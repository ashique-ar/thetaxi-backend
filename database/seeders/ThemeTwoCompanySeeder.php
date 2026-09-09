<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\NavigationMenu;
use App\Models\Website\WebsiteSetting;
use Illuminate\Database\Seeder;

class ThemeTwoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['domain' => 'casons.lk'],
            ['name' => 'Casons', 'website' => 'https://casons.lk', 'is_active' => true, 'is_default' => false]
        );

        foreach (['active_theme' => 'theme-02', 'site_name' => 'Casons', 'header_help_label' => 'Call Us', 'header_cart_label' => 'Booking Cart'] as $type => $value) {
            WebsiteSetting::setValue($type, $value, $company->id);
        }

        $this->seedHeader($company->id);
    }

    private function seedHeader(string $companyId): void
    {
        $items = [
            ['Home', '/', 1],
            ['Services', '/services', 2],
            ['About', '/about', 3],
            ['Inquiry', '/inquiry', 4],
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
