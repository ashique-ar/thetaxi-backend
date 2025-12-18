<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'profile_image' => $this->profile_image,
            'avatar_url' => $this->avatar_url,
            'role' => $this->whenLoaded('role', function () {
                return [
                    'id' => $this->role->id,
                    'name' => $this->role->name,
                    'display_name' => $this->role->display_name ?? $this->role->name,
                ];
            }),
            'permissions' => $this->getPermissionsArray(),
            'roles' => $this->getRolesArray(),
            'is_active' => $this->is_active,
            'status' => $this->status,
            'email_verified' => $this->hasVerifiedEmail(),
            'phone_verified' => $this->hasVerifiedPhone(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'agent' => $this->whenLoaded('agent', function () {
                return [
                    'id' => $this->agent->id,
                    'name' => $this->agent->name,
                    'code' => $this->agent->code,
                ];
            }),
            'statistics' => $this->when($request->has('include_stats'), function () {
                return [
                    'total_bookings' => $this->bookings()->count(),
                    'active_sessions' => $this->tokens()->where('revoked', false)->count(),
                ];
            }),
        ];
    }
}
