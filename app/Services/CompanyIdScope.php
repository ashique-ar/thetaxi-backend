<?php

namespace App\Services;

class CompanyIdScope
{
    public static function sameCompanyId(?string $left, ?string $right): bool
    {
        $left = $left === '' ? null : $left;
        $right = $right === '' ? null : $right;
        if ($left !== null && $left === $right) {
            return true;
        }
        $defaultCompanyId = self::activeDefaultCompanyId();

        return $defaultCompanyId !== null
            && ($left === null || $left === $defaultCompanyId)
            && ($right === null || $right === $defaultCompanyId);
    }

    public static function defaultCompanyId(): ?string
    {
        return self::activeDefaultCompanyId();
    }

    public static function sameCompany($query, string $left, string $right): void
    {
        $query->where(fn ($sameCompany) => $sameCompany->whereColumn($left, $right)
            ->orWhere(fn ($leftIsDefault) => $leftIsDefault->whereNull($left)->whereExists(fn ($defaultCompany) => $defaultCompany
                ->selectRaw('1')->from('companies as default_company')
                ->whereColumn('default_company.id', $right)
                ->where('default_company.is_default', true)
                ->where('default_company.is_active', true)
                ->whereNull('default_company.deleted_at')))
            ->orWhere(fn ($rightIsDefault) => $rightIsDefault->whereNull($right)->whereExists(fn ($defaultCompany) => $defaultCompany
                ->selectRaw('1')->from('companies as default_company')
                ->whereColumn('default_company.id', $left)
                ->where('default_company.is_default', true)
                ->where('default_company.is_active', true)
                ->whereNull('default_company.deleted_at')))
            ->orWhere(fn ($bothAreDefault) => $bothAreDefault->whereNull($left)->whereNull($right)->whereExists(fn ($defaultCompany) => $defaultCompany
                ->selectRaw('1')->from('companies as default_company')
                ->where('default_company.is_default', true)
                ->where('default_company.is_active', true)
                ->whereNull('default_company.deleted_at'))));
    }

    public static function constrainIn($query, string $column, $companyIds): void
    {
        if ($companyIds instanceof \Illuminate\Database\Eloquent\Builder) {
            $companyIds = $companyIds->toBase();
        }
        if ($companyIds instanceof \Illuminate\Database\Query\Builder) {
            $companyIds = clone $companyIds;
            $sourceColumn = $companyIds->columns[0] ?? null;
            if (is_string($sourceColumn)) {
                $sourceColumn = preg_split('/\s+as\s+/i', $sourceColumn)[1] ?? $sourceColumn;
                $sourceColumn = str_contains($sourceColumn, '.') ? substr($sourceColumn, strrpos($sourceColumn, '.') + 1) : $sourceColumn;
            }
            $query->where(function ($scope) use ($column, $companyIds, $sourceColumn): void {
                $scope->whereIn($column, clone $companyIds)
                    ->orWhere(fn ($defaultRow) => $defaultRow->whereExists(function ($defaultCompany) use ($column, $companyIds, $sourceColumn): void {
                        $defaultCompany->selectRaw('1')->from('companies as default_company')
                            ->where('default_company.is_default', true)->where('default_company.is_active', true)->whereNull('default_company.deleted_at')
                            ->where(function ($included) use ($companyIds, $sourceColumn): void {
                                $included->whereIn('default_company.id', clone $companyIds);
                                if (is_string($sourceColumn)) {
                                    $sourceWithNull = clone $companyIds;
                                    $sourceWithNull->whereNull($sourceColumn);
                                    $included->orWhereExists(fn ($nullCompanyId) => $nullCompanyId->selectRaw('1')->fromSub($sourceWithNull, 'source_company_ids'));
                                }
                            })
                            ->where(fn ($sameCompany) => $sameCompany->whereNull($column)->orWhereColumn('default_company.id', $column));
                    }));
            });
            return;
        }

        $companyIds = collect($companyIds);
        $includesDefault = $companyIds->contains(fn ($id) => $id === null || $id === '');
        $companyIds = $companyIds->filter(fn ($id) => $id !== null && $id !== '')->values()->all();

        if ($companyIds === [] && ! $includesDefault) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($scope) use ($column, $companyIds, $includesDefault): void {
            if ($companyIds !== []) {
                $scope->whereIn($column, $companyIds);
            }
            if ($includesDefault) {
                $scope->orWhere(fn ($defaultRow) => $defaultRow->whereExists(fn ($defaultCompany) => $defaultCompany
                    ->selectRaw('1')->from('companies as default_company')
                    ->where('default_company.is_default', true)
                    ->where('default_company.is_active', true)
                    ->whereNull('default_company.deleted_at')
                    ->where(fn ($sameCompany) => $sameCompany->whereNull($column)->orWhereColumn('default_company.id', $column))));
            } else {
                $scope->orWhere(fn ($unassigned) => $unassigned->whereNull($column)->whereExists(fn ($defaultCompany) => $defaultCompany
                    ->selectRaw('1')->from('companies as default_company')
                    ->whereIn('default_company.id', $companyIds)
                    ->where('default_company.is_default', true)
                    ->where('default_company.is_active', true)
                    ->whereNull('default_company.deleted_at')));
            }
        });
    }

    public static function constrain($query, string $column, ?string $companyId): void
    {
        if ($companyId === null || $companyId === '') {
            $query->where(fn ($scope) => $scope->where(fn ($legacy) => $legacy->whereNull($column)->whereExists(fn ($defaultCompany) => $defaultCompany
                    ->selectRaw('1')->from('companies as default_company')
                    ->where('default_company.is_default', true)
                    ->where('default_company.is_active', true)
                    ->whereNull('default_company.deleted_at')))
                ->orWhereExists(fn ($defaultCompany) => $defaultCompany
                    ->selectRaw('1')->from('companies as default_company')
                    ->whereColumn('default_company.id', $column)
                    ->where('default_company.is_default', true)
                    ->where('default_company.is_active', true)
                    ->whereNull('default_company.deleted_at')));
            return;
        }

        $query->where(function ($scope) use ($column, $companyId): void {
            $scope->where($column, $companyId)
                ->orWhere(function ($unassigned) use ($column, $companyId): void {
                    $unassigned->whereNull($column)->whereExists(fn ($defaultCompany) => $defaultCompany
                        ->selectRaw('1')->from('companies as default_company')
                        ->where('default_company.id', $companyId)
                        ->where('default_company.is_default', true)
                        ->where('default_company.is_active', true)
                        ->whereNull('default_company.deleted_at'));
                });
        });
    }

    private static function activeDefaultCompanyId(): ?string
    {
        $request = app()->bound('request') ? request() : null;
        $key = 'company_id_scope.active_default_company_id';
        if ($request && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $ids = \Illuminate\Support\Facades\DB::table('companies')
            ->where('is_default', true)->where('is_active', true)->whereNull('deleted_at')
            ->limit(2)->pluck('id');
        $companyId = $ids->count() === 1 ? (string) $ids->first() : null;
        $request?->attributes->set($key, $companyId);

        return $companyId;
    }
}
