<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Website\CmsContentType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert partners content type
        // Do not emit application activity events while the schema is still
        // being assembled. A fresh install reaches this migration before the
        // activity-log v5 columns exist.
        CmsContentType::withoutEvents(fn () => CmsContentType::create([
            'name' => 'Partners',
            'slug' => 'partners',
            'description' => 'Manage partner logos and companies for the homepage partner section',
            'is_active' => true,
            'fields' => [
                'title' => 'required|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'featured_image' => 'nullable|string',
                'link' => 'nullable|url',
                'order' => 'nullable|integer',
                'is_featured' => 'boolean'
            ]
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        CmsContentType::withoutEvents(
            fn () => CmsContentType::where('slug', 'partners')->delete()
        );
    }
};
