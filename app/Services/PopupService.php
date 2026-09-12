<?php

namespace App\Services;

use App\Models\Website\Popup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PopupService
{
    /**
     * Cache key prefix for popup data
     */
    private const CACHE_PREFIX = 'popups_';
    
    /**
     * Cache duration in seconds (1 hour)
     */
    private const CACHE_DURATION = 3600;

    /**
     * Get all popups with optional filtering
     */
    public function getAll(array $filters = []): Collection
    {
        $query = Popup::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('priority', 'desc')
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    /**
     * Get active popups for a specific page with caching
     */
    public function getActivePopups(string $page = 'all'): Collection
    {
        $cacheKey = self::CACHE_PREFIX . 'active_' . $page;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($page) {
            return Popup::active()
                ->forPage($page)
                ->byPriority()
                ->get();
        });
    }

    /**
     * Get a popup by ID
     */
    public function getPopupById(string $id): ?Popup
    {
        return Popup::find($id);
    }

    /**
     * Create a new popup
     *
     * @throws ValidationException
     */
    public function createPopup(array $data): Popup
    {
        $this->validatePopupData($data);

        DB::beginTransaction();

        try {
            $popup = Popup::create([
                'title' => $data['title'],
                'content' => $data['content'],
                'image' => $data['image'] ?? null,
                'cta_text' => $data['cta_text'] ?? null,
                'cta_link' => $data['cta_link'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'display_frequency' => $data['display_frequency'] ?? Popup::FREQUENCY_ALWAYS,
                'target_pages' => $data['target_pages'] ?? [Popup::TARGET_ALL],
                'priority' => $data['priority'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            DB::commit();
            $this->clearCache();


            return $popup;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating popup: ' . $e->getMessage(), ['data' => $data]);
            throw $e;
        }
    }

    /**
     * Update an existing popup
     *
     * @throws ValidationException
     */
    public function updatePopup(string $id, array $data): Popup
    {
        $popup = Popup::findOrFail($id);

        $this->validatePopupData($data, $popup);

        DB::beginTransaction();

        try {
            $popup->update([
                'title' => $data['title'] ?? $popup->title,
                'content' => $data['content'] ?? $popup->content,
                'image' => $data['image'] ?? $popup->image,
                'cta_text' => $data['cta_text'] ?? $popup->cta_text,
                'cta_link' => $data['cta_link'] ?? $popup->cta_link,
                'start_date' => array_key_exists('start_date', $data) ? $data['start_date'] : $popup->start_date,
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $popup->end_date,
                'display_frequency' => $data['display_frequency'] ?? $popup->display_frequency,
                'target_pages' => $data['target_pages'] ?? $popup->target_pages,
                'priority' => $data['priority'] ?? $popup->priority,
                'is_active' => $data['is_active'] ?? $popup->is_active,
            ]);

            DB::commit();
            $this->clearCache();


            return $popup->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating popup: ' . $e->getMessage(), ['popup_id' => $id, 'data' => $data]);
            throw $e;
        }
    }

    /**
     * Delete a popup
     */
    public function deletePopup(string $id): bool
    {
        $popup = Popup::findOrFail($id);

        DB::beginTransaction();

        try {
            $popup->delete();

            DB::commit();
            $this->clearCache();


            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting popup: ' . $e->getMessage(), ['popup_id' => $id]);
            throw $e;
        }
    }

    /**
     * Toggle popup active status
     */
    public function toggleStatus(string $id): Popup
    {
        $popup = Popup::findOrFail($id);

        DB::beginTransaction();

        try {
            $popup->update([
                'is_active' => !$popup->is_active,
            ]);

            DB::commit();
            $this->clearCache();


            return $popup->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error toggling popup status: ' . $e->getMessage(), ['popup_id' => $id]);
            throw $e;
        }
    }

    /**
     * Validate popup data
     *
     * @throws ValidationException
     */
    public function validatePopupData(array $data, ?Popup $existingPopup = null): array
    {
        $errors = [];

        // Validate required fields for new popups
        if (!$existingPopup) {
            if (empty($data['title'])) {
                $errors['title'] = ['The title field is required.'];
            }
            if (empty($data['content'])) {
                $errors['content'] = ['The content field is required.'];
            }
        } else {
            // For updates, validate only if the field is being updated
            if (array_key_exists('title', $data) && empty($data['title'])) {
                $errors['title'] = ['The title field is required.'];
            }
            if (array_key_exists('content', $data) && empty($data['content'])) {
                $errors['content'] = ['The content field is required.'];
            }
        }

        // Validate date range
        $startDate = $data['start_date'] ?? ($existingPopup?->start_date);
        $endDate = $data['end_date'] ?? ($existingPopup?->end_date);

        if ($startDate && $endDate) {
            $start = $startDate instanceof \Carbon\Carbon ? $startDate : \Carbon\Carbon::parse($startDate);
            $end = $endDate instanceof \Carbon\Carbon ? $endDate : \Carbon\Carbon::parse($endDate);

            if ($end->lt($start)) {
                $errors['end_date'] = ['The end date must be after or equal to the start date.'];
            }
        }

        // Validate display frequency
        if (isset($data['display_frequency'])) {
            $validFrequencies = [
                Popup::FREQUENCY_ALWAYS,
                Popup::FREQUENCY_ONCE_PER_SESSION,
                Popup::FREQUENCY_ONCE_PER_DAY,
            ];
            if (!in_array($data['display_frequency'], $validFrequencies)) {
                $errors['display_frequency'] = ['Invalid display frequency value.'];
            }
        }

        // Validate target pages
        if (isset($data['target_pages'])) {
            if (!is_array($data['target_pages'])) {
                $errors['target_pages'] = ['Target pages must be an array.'];
            } elseif (empty($data['target_pages'])) {
                $errors['target_pages'] = ['At least one target page must be selected.'];
            }
        }

        // Validate priority
        if (isset($data['priority']) && !is_numeric($data['priority'])) {
            $errors['priority'] = ['Priority must be a number.'];
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Get the highest priority active popup for a page
     */
    public function getHighestPriorityPopup(string $page = 'all'): ?Popup
    {
        return $this->getActivePopups($page)->first();
    }

    /**
     * Clear all popup-related cache
     */
    public function clearCache(): void
    {
        // Clear cache for all known page types
        $pages = [
            'all',
            Popup::TARGET_ALL,
            Popup::TARGET_HOMEPAGE,
            Popup::TARGET_CHECKOUT,
        ];

        foreach ($pages as $page) {
            Cache::forget(self::CACHE_PREFIX . 'active_' . $page);
        }

    }

    /**
     * Get popup statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => Popup::count(),
            'active' => Popup::where('is_active', true)->count(),
            'inactive' => Popup::where('is_active', false)->count(),
            'currently_displayed' => Popup::active()->count(),
        ];
    }
}
