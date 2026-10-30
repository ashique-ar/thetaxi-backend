<?php

namespace App\Http\ViewComposers;

use App\Models\NavigationMenu;
use Illuminate\View\View;

class NavigationViewComposer
{
    public function compose(View $view): void
    {
        $query = NavigationMenu::headerNavigation();

        $view->with('headerNavigation', $query
            ->with(['children' => fn ($query) => $query->where('show_in_header', true)])
            ->get());
    }
}
