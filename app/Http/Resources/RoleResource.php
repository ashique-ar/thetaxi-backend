<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'display_name' => $this->display_name ?? ucfirst($this->name),
            'description' => $this->description,
            'context_types' => $this->decodeContextTypes(),
            'auto_assign_contexts' => (bool) ($this->auto_assign_contexts ?? true),
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'display_name' => $permission->display_name ?? ucfirst($permission->name),
                        'description' => $permission->description,
                    ];
                });
            }),
            'users_count' => $this->when($request->has('include_counts'), function () {
                return $this->users()->count();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function decodeContextTypes(): ?array
    {
        $contextTypes = $this->context_types ?? null;

        if ($contextTypes === null) {
            return null;
        }

        if (is_array($contextTypes)) {
            return array_values($contextTypes);
        }

        $decoded = json_decode((string) $contextTypes, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }
}
