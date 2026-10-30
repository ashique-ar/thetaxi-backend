<?php

namespace App\Http\Resources;

use App\Services\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = app(PermissionRegistry::class)->formatPermission($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'module' => $metadata['module'],
            'module_label' => $metadata['module_label'],
            'action' => $metadata['action'],
            'display_name' => $this->display_name ?? ucfirst(str_replace('-', ' ', $this->name)),
            'description' => $this->description,
            'roles_count' => $this->when($request->has('include_counts'), function () {
                return $this->roles()->count();
            }),
            'users_count' => $this->when($request->has('include_counts'), function () {
                return $this->users()->count();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
