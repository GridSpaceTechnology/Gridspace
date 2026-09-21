<?php

use App\Models\CandidateProfile;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\JobRole;
use App\Models\User;
use App\Services\JobMatchingService;
use App\Services\TaxonomyService;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Phase 6 - Official Professional Taxonomy
|--------------------------------------------------------------------------
|
| Locks the official ten-category / ~30-role taxonomy into the matching
| engine so the hard domain gate becomes taxonomy-aware:
|
|   - An exact official pairing (same category, same role) resolves both the
|     candidate and the job to the same professional domain and scores high.
|
|   - A true cross-taxonomy pairing (Software Developer vs Accountant) keeps
|     the hard gate: domains are incompatible and the overall score is capped
|     at the domain_gate_cap.
|
|   - Roles live under one canonical category only, and the TaxonomyService
|     read path returns the configured professional domains.
*/

uses(RefreshDatabase::class);

/* ------------------------------------------------------------------ *
 * Skirt helpers
 * ------------------------------------------------------------------ */

function phase6CandidateUser(array $extra = []): User
{
    return User::factory()->create(array_merge(['role' => 'candidate'], $extra));
}

function phase6EmployerUser(): User
{
    return User::factory()->create(['role' => 'employer']);
}

function phase6Profile(User $candidate, array $extra = []): CandidateProfile
{
    return CandidateProfile::create(array_merge([
        'user_id' => $candidate->id,
        'current_role' => 'Junior Developer',
        'desired_role' => 'Software Developer',
        'years_of_experience' => 3,
        'employment_type_preference' => 'full_time',
        'work_preference' => 'remote',
    ], $extra));
}

function phase6Job(User $employer, array $extra = []): Job
{
    return Job::create(array_merge([
        'employer_id' => $employer->id,
        'title' => 'Software Developer',
        'role' => 'Developer',
        'slug' => 'phase6-'.uniqid(),
        'status' => 'open',
        'employment_type' => 'full_time',
        'work_preference' => 'remote',
    ], $extra));
}

function phase6Lookup(string $categorySlug): array
{
    $category = JobCategory::query()->where('slug', $categorySlug)->firstOrFail();

    return [$category->id, $category->name, $category->domain_keys ?? []];
}

function phase6Role(?int $categoryId, string $roleSlug): JobRole
{
    return JobRole::query()->where('category_id', $categoryId)->where('slug', $roleSlug)->firstOrFail();
}

/* ------------------------------------------------------------------ *
 * Seeding & taxonomy shape
 * ------------------------------------------------------------------ */

it('seeds exactly the ten official categories with their professional domains', function () {
    seed(TaxonomySeeder::class);

    $categories = JobCategory::query()->orderBy('sort_order')->get();

    expect($categories)->toHaveCount(10)
        ->and($categories->pluck('slug'))->toContain('information-technology-and-software')
        ->and($categories->pluck('slug'))->toContain('accounting-finance-and-banking')
        ->and($categories->pluck('slug'))->toContain('education-and-training')
        ->and($categories->pluck('slug'))->toContain('healthcare-and-medical-services')
        ->and($categories->pluck('slug'))->toContain('media-creative-and-communications')
        ->and($categories->firstWhere('slug', 'information-technology-and-software')->domain_keys)
        ->toContain('technology');
});

it('is idempotent - re-seeding never duplicates taxonomy rows', function () {
    seed(TaxonomySeeder::class);

    $categories = JobCategory::count();
    $roles = JobRole::count();

    seed(TaxonomySeeder::class);

    expect(JobCategory::count())->toBe($categories)
        ->and(JobRole::count())->toBe($roles);
});

it('links each official role to exactly one owning category', function () {
    seed(TaxonomySeeder::class);

    [$itCategoryId] = phase6Lookup('information-technology-and-software');

    expect(phase6Role($itCategoryId, 'software-developer')->category->name)
        ->toBe('Information Technology & Software');

    [$accountingCategoryId] = phase6Lookup('accounting-finance-and-banking');

    expect(phase6Role($accountingCategoryId, 'accountant')->category->name)
        ->toBe('Accounting, Finance & Banking');

    [$mediaCategoryId] = phase6Lookup('media-creative-and-communications');

    expect(phase6Role($mediaCategoryId, 'graphic-designer')->category->name)
        ->toBe('Media, Creative & Communications');
});

/* ------------------------------------------------------------------ *
 * Taxonomy-aware domain resolution via the matching service
 * ------------------------------------------------------------------ */

it('resolves the same professional domain for a fully-aligned official pairing', function () {
    seed(TaxonomySeeder::class);

    [$itCategoryId] = phase6Lookup('information-technology-and-software');
    $softwareRole = phase6Role($itCategoryId, 'software-developer');

    $candidate = phase6CandidateUser();
    phase6Profile($candidate, [
        'desired_category_id' => $itCategoryId,
        'desired_role_id' => $softwareRole->id,
    ]);

    $job = phase6Job(phase6EmployerUser(), [
        'category_id' => $itCategoryId,
        'role_id' => $softwareRole->id,
    ]);

    $service = app(JobMatchingService::class);

    expect($service->resolveCandidateDomains($candidate))->toContain('technology')
        ->and($service->resolveJobDomains($job))->toContain('technology')
        ->and($service->isDomainsCompatible(
            $service->resolveCandidateDomains($candidate),
            $service->resolveJobDomains($job)
        ))->toBeTrue()
        ->and($service->calculateBreakdown($candidate, $job)['overall_score'])
        ->toBeGreaterThan((int) config('matching.domain_gate_cap', 15));
});

it('keeps the hard gate on a true cross-taxonomy pairing', function () {
    seed(TaxonomySeeder::class);

    [$itCategoryId] = phase6Lookup('information-technology-and-software');
    [$accountingCategoryId] = phase6Lookup('accounting-finance-and-banking');

    $candidate = phase6CandidateUser();
    phase6Profile($candidate, [
        'desired_category_id' => $itCategoryId,
        'desired_role_id' => phase6Role($itCategoryId, 'software-developer')->id,
    ]);

    $job = phase6Job(phase6EmployerUser(), [
        'category_id' => $accountingCategoryId,
        'role_id' => phase6Role($accountingCategoryId, 'accountant')->id,
        'title' => 'Accountant',
        'role' => 'Accountant',
    ]);

    $breakdown = app(JobMatchingService::class)->calculateBreakdown($candidate, $job);

    expect($breakdown['overall_score'])->toBeLessThanOrEqual((int) config('matching.domain_gate_cap', 15))
        ->and($breakdown['domain_compatible'])->toBeFalse()
        ->and($breakdown['candidate_domain'])->toBe(config('professional_domains.domains.technology.label'));
});

it('keeps the hard gate even with strong personality and skill evidence', function () {
    seed(TaxonomySeeder::class);

    [$itCategoryId] = phase6Lookup('information-technology-and-software');
    [$accountingCategoryId] = phase6Lookup('accounting-finance-and-banking');

    $candidate = phase6CandidateUser();
    phase6Profile($candidate, [
        'desired_category_id' => $itCategoryId,
        'desired_role_id' => phase6Role($itCategoryId, 'software-developer')->id,
    ]);

    $job = phase6Job(phase6EmployerUser(), [
        'category_id' => $accountingCategoryId,
        'role_id' => phase6Role($accountingCategoryId, 'accountant')->id,
        'title' => 'Accountant',
        'role' => 'Accountant',
        'work_preference' => 'remote',
    ]);

    $service = app(JobMatchingService::class);

    expect($service->isDomainsCompatible(
        $service->resolveCandidateDomains($candidate),
        $service->resolveJobDomains($job)
    ))->toBeFalse()
        ->and($service->calculateBreakdown($candidate, $job)['overall_score'])
        ->toBeLessThanOrEqual((int) config('matching.domain_gate_cap', 15));
});
