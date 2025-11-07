<?php

namespace Database\Seeders;

use App\Models\NavigationMenu;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NavigationMenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing navigation menus
        NavigationMenu::truncate();

        // Main navigation items
        $homeMenu = NavigationMenu::create([
            'title' => 'Home',
            'url' => '/',
            'target' => '_self',
            'icon' => 'home',
            'description' => 'Homepage navigation',
            'sort_order' => 1,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        $servicesMenu = NavigationMenu::create([
            'title' => 'Our Services',
            'url' => '/services',
            'target' => '_self',
            'icon' => 'services',
            'description' => 'Car rental services overview',
            'sort_order' => 2,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
        ]);

        // Services submenu
        NavigationMenu::create([
            'parent_id' => $servicesMenu->id,
            'title' => 'Self Drive Rentals',
            'url' => '/services/self-drive',
            'target' => '_self',
            'description' => 'Self-drive car rental service',
            'sort_order' => 1,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        NavigationMenu::create([
            'parent_id' => $servicesMenu->id,
            'title' => 'Chauffeur Service',
            'url' => '/services/chauffeur',
            'target' => '_self',
            'description' => 'Professional chauffeur service',
            'sort_order' => 2,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        NavigationMenu::create([
            'parent_id' => $servicesMenu->id,
            'title' => 'Wedding Cars',
            'url' => '/services/wedding',
            'target' => '_self',
            'description' => 'Luxury wedding car rentals',
            'sort_order' => 3,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        $vehiclesMenu = NavigationMenu::create([
            'title' => 'Our Fleet',
            'url' => '/vehicles',
            'target' => '_self',
            'icon' => 'car',
            'description' => 'Browse our vehicle fleet',
            'sort_order' => 3,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
        ]);

        // Vehicle categories submenu
        NavigationMenu::create([
            'parent_id' => $vehiclesMenu->id,
            'title' => 'Economy Cars',
            'url' => '/vehicles/economy',
            'target' => '_self',
            'description' => 'Budget-friendly vehicles',
            'sort_order' => 1,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        NavigationMenu::create([
            'parent_id' => $vehiclesMenu->id,
            'title' => 'Luxury Cars',
            'url' => '/vehicles/luxury',
            'target' => '_self',
            'description' => 'Premium luxury vehicles',
            'sort_order' => 2,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        NavigationMenu::create([
            'parent_id' => $vehiclesMenu->id,
            'title' => 'SUVs & Vans',
            'url' => '/vehicles/suv-vans',
            'target' => '_self',
            'description' => 'Large vehicles for groups',
            'sort_order' => 3,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
        ]);

        NavigationMenu::create([
            'title' => 'About Us',
            'url' => '/about',
            'target' => '_self',
            'icon' => 'info',
            'description' => 'Learn about Casons Rent A Car',
            'sort_order' => 4,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
        ]);

        NavigationMenu::create([
            'title' => 'Contact',
            'url' => '/contact',
            'target' => '_self',
            'icon' => 'phone',
            'description' => 'Get in touch with us',
            'sort_order' => 5,
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
        ]);

        $faqMenu = NavigationMenu::create([
            'title' => 'FAQ',
            'url' => '/faq',
            'target' => '_self',
            'icon' => 'help_outline',
            'description' => 'Frequently asked questions',
            'sort_order' => 6,
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => true,
        ]);

        // Footer-only navigation items
        NavigationMenu::create([
            'title' => 'Privacy Policy',
            'url' => '/privacy-policy',
            'target' => '_self',
            'description' => 'Privacy policy and data protection',
            'sort_order' => 7,
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => true,
        ]);

        NavigationMenu::create([
            'title' => 'Terms & Conditions',
            'url' => '/terms-conditions',
            'target' => '_self',
            'description' => 'Terms and conditions of service',
            'sort_order' => 8,
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => true,
        ]);

        NavigationMenu::create([
            'title' => 'Booking Policy',
            'url' => '/booking-policy',
            'target' => '_self',
            'description' => 'Vehicle booking and rental policy',
            'sort_order' => 9,
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => true,
        ]);

        // Admin panel navigation (for reference)
        NavigationMenu::create([
            'title' => 'Admin Dashboard',
            'route_name' => 'admin.dashboard',
            'target' => '_self',
            'icon' => 'dashboard',
            'description' => 'Admin dashboard access',
            'sort_order' => 10,
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => false,
            'permissions' => ['admin.access']
        ]);

        $this->command->info('Navigation menu items seeded successfully!');
    }
}
