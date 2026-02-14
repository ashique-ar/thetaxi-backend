<?php

namespace App\Http\ViewComposers;

use App\Models\Website\CmsContent;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;

class ServicesViewComposer
{
    /**
     * Bind data to the view.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view)
    {
        // Cache services for 1 hour to improve performance
        $services = Cache::remember('header_services', 3600, function () {
            return CmsContent::published()
                ->byType('services')
                ->whereNotNull('slug')
                ->where('slug', '!=', '')
                ->orderBy('display_order', 'asc')
                ->orderBy('title', 'asc')
                ->select('id', 'title', 'slug', 'excerpt', 'thumbnail')
                ->get();
        });

        $view->with('headerServices', $services);
    }
}
