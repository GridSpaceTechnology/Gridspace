<?php

namespace App\Services;

use App\Models\JobCategory;
use App\Models\JobRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cached, single-source taxonomy accessor introduced with the official ten
 * category / ~30 role taxonomy. All reads for forms, filters, validation,
 * matching and search flow through this service so there is never a second,
 * divergent taxonomy list in the application.
 */
class TaxonomyService
{
    private const CACHE_KEY = 'taxonomy';

    private const CACHE_TTL = 3600;

    public function categories(): Collection
    {
        return $this->cached()->get('categories', collect());
    }

    public function roles(): Collection
    {
        return $this->cached()->get('roles', collect());
    }

    public function rolesForCategory(?int $categoryId): Collection
    {
        if (! $categoryId) {
            return collect();
        }

        return $this->roles()->filter(fn (JobRole $role) => $role->category_id === $categoryId);
    }

    public function categoryById(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        return $this->categories()->firstWhere('id', $id);
    }

    public function categoryBySlug(?string $slug): ?array
    {
        if (! $slug) {
            return null;
        }

        return $this->categories()->firstWhere('slug', $slug);
    }

    public function roleById(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        return $this->roles()->firstWhere('id', $id);
    }

    public function roleBySlug(?string $slug): ?array
    {
        if (! $slug) {
            return null;
        }

        return $this->roles()->firstWhere('slug', $slug);
    }

    public function rolesMatchingTerm(string $term): Collection
    {
        $term = strtolower(trim($term));

        if ($term === '') {
            return collect();
        }

        return $this->roles()->filter(function (JobRole $role) use ($term) {
            if (str_contains(strtolower($role->name), $term)) {
                return true;
            }

            foreach (($role->alternative_titles ?? []) as $alias) {
                if (str_contains(strtolower($alias), $term)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function roleBelongsToCategory(JobRole $role, JobCategory $category): bool
    {
        return $role->category_id === $category->id;
    }

    public function categoriesForRoles(Collection $roles): Collection
    {
        $ids = $roles->pluck('category_id')->unique()->values();

        return $this->categories()->whereIn('id', $ids)->values();
    }

    public function categoryDomainKeys(?int $categoryId): array
    {
        $category = $this->categoryById($categoryId);

        if (! $category || empty($category['domain_keys'])) {
            return [];
        }

        return array_values(array_filter((array) $category['domain_keys']));
    }

    protected function cached(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $categories = JobCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (JobCategory $category) => $this->presentCategory($category))
                ->values();

            $roles = JobRole::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->with('category:id,name')
                ->get()
                ->map(fn (JobRole $role) => $this->presentRole($role))
                ->values();

            return collect([
                'categories' => $categories,
                'roles' => $roles,
            ]);
        });
    }

    protected function presentCategory(JobCategory $category): array
    {
        return [
            'id' => $category->getKey(),
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'domain_keys' => $category->domain_keys ?? [],
            'sort_order' => $category->sort_order,
        ];
    }

    protected function presentRole(JobRole $role): array
    {
        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'slug' => $role->slug,
            'category_id' => $role->category_id,
            'alternative_titles' => $role->alternative_titles ?? [],
        ];
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
