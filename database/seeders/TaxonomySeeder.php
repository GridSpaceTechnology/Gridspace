<?php

namespace Database\Seeders;

use App\Models\CandidateProfile;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\JobRole;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Idempotent taxonomy seeder for the official ten-category GridSpace
 * professional taxonomy.
 *
 * Every row is keyed by a stable slug (role/category) or name (skill) and uses
 * updateOrCreate, so re-running never duplicates data mirror fresh installs.
 * It never deletes, truncates or drops anything, and only seats NEW skills -
 * skills that already exist elsewhere in the system (e.g. seeded inline in a
 * migration) are updated in place rather than duplicated.
 *
 * Existing job_listings and candidate_profiles receive conservative backfills:
 * only rows whose free-text role/title maps unambiguously to a taxonomy role
 * are linked (mapsSameRole guard). Anything the classifier is not 100% sure
 * about is left null so the lexical domain gate still handles it and we never
 * mislabel a professional pairing.
 */
class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->categories();
        $this->roles();
        $this->skills();
        $this->backfillJobs();
        $this->backfillCandidates();

        Cache::forget('taxonomy');
    }

    protected function categories(): void
    {
        $categories = [
            ['Administration & Office Support', 'administration-and-office-support', 'Office administration, executive assistance and general clerical support.', ['administration'], 10],
            ['Sales & Marketing', 'sales-and-marketing', 'Business development, sales and marketing roles.', ['sales', 'marketing'], 20],
            ['Customer Service & Support', 'customer-service-and-support', 'Candidate-facing service, support and help desk roles.', ['customer_support'], 420],
            ['Accounting, Finance & Banking', 'accounting-finance-and-banking', 'Accounting, finance, banking and treasury positions.', ['finance'], 40],
            ['Human Resources & Recruitment', 'human-resources-and-recruitment', 'People operations, HR and recruitment positions.', ['hr'], 460],
            ['Information Technology & Software', 'information-technology-and-software', 'Software development, engineering and IT positions.', ['technology'], 60],
            ['Engineering & Technical Services', 'engineering-and-technical-services', 'Engineering and hands-on technical service roles.', ['engineering', 'construction'], 470],
            ['Media, Creative & Communications', 'media-creative-and-communications', 'Design, content, media and communications roles.', ['design', 'media', 'marketing'], 80],
            ['Education & Training', 'education-and-training', 'Teaching, instruction and training roles.', ['education'], 490],
            ['Healthcare & Medical Services', 'healthcare-and-medical-services', 'Clinical, nursing and medical service roles.', ['healthcare'], 100],
        ];

        foreach ($categories as [$name, $slug, $description, $domains, $sort]) {
            JobCategory::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'domain_keys' => $domains,
                    'sort_order' => $sort,
                    'is_active' => true,
                ]
            );
        }
    }

    protected function roles(): void
    {
        $rolesByCategory = [
            'administration-and-office-support' => [
                ['Virtual Assistant', 'virtual-assistant', ['administrative assistant', 'executive assistant', 'office assistant', 'personal assistant']],
                ['Administrative Officer', 'administrative-officer', ['office administrator', 'administration officer', 'general administration officer']],
                ['Receptionist', 'receptionist', ['front desk clerk', 'front office assistant']],
                ['Data Entry Clerk', 'data-entry-clerk', ['data entry officer', 'data processor']],
            ],
            'sales-and-marketing' => [
                ['Sales Representative', 'sales-representative', ['sales executive', 'account executive', 'business development representative', 'sales agent']],
                ['Sales Manager', 'sales-manager', ['national sales manager', 'sales lead']],
                ['Account Manager', 'account-manager', ['client account manager', 'key account manager']],
                ['Marketing Specialist', 'marketing-specialist', ['digital marketing specialist', 'content marketing specialist', 'brand specialist']],
                ['Social Media Manager', 'social-media-manager', ['social media specialist', 'community manager']],
            ],
            'customer-service-and-support' => [
                ['Customer Service Representative', 'customer-service-representative', ['customer care representative', 'customer service agent', 'customer service officer']],
                ['Customer Support Specialist', 'customer-support-specialist', ['customer support officer', 'client support specialist']],
            ],
            'accounting-finance-and-banking' => [
                ['Accountant', 'accountant', ['financial accountant', 'accounts officer', 'management accountant']],
                ['Finance Officer', 'finance-officer', ['finance manager', 'accounting and finance officer']],
                ['Banking Officer', 'banking-officer', ['bank teller', 'bank operations officer']],
            ],
            'human-resources-and-recruitment' => [
                ['HR Officer', 'human-resources-officer', ['human resources officer', 'hr administrator', 'personnel officer']],
                ['Recruitment Specialist', 'recruitment-specialist', ['recruiter', 'talent acquisition specialist', 'resourcing officer']],
            ],
            'information-technology-and-software' => [
                ['Software Developer', 'software-developer', ['software engineer', 'application developer', 'developer', 'programmer']],
                ['Software Engineer', 'software-engineer', ['senior software engineer', 'staff software engineer']],
                ['Backend Developer', 'backend-developer', ['back end developer', 'backend engineer']],
                ['Frontend Developer', 'frontend-developer', ['front end developer', 'frontend engineer']],
                ['Full Stack Developer', 'full-stack-developer', ['fullstack developer', 'full stack engineer']],
                ['Web Developer', 'web-developer', ['web programmer']],
                ['IT Support Specialist', 'it-support-specialist', ['it support officer', 'it technician', 'helpdesk officer']],
            ],
            'engineering-and-technical-services' => [
                ['Electrical Engineer', 'electrical-engineer', ['electric power engineer', 'electrical design engineer']],
                ['Mechanical Engineer', 'mechanical-engineer', ['mechanical design engineer']],
                ['Civil Engineer', 'civil-engineer', ['civil construction engineer']],
                ['Technical Support Engineer', 'technical-support-engineer', ['support engineer', 'field engineer']],
            ],
            'media-creative-and-communications' => [
                ['Graphic Designer', 'graphic-designer', ['visual designer', 'graphic design artist', 'creative graphic designer']],
                ['Content Creator', 'content-creator', ['content writer', 'digital content creator']],
                ['Communications Specialist', 'communications-specialist', ['communications officer', 'public relations specialist']],
            ],
            'education-and-training' => [
                ['Teacher', 'teacher', ['classroom teacher', 'school teacher', 'educator', 'instructor']],
                ['Trainer', 'trainer', ['training officer', 'corporate trainer', 'facilitator']],
                ['Education Officer', 'education-officer', ['education programme officer']],
            ],
            'healthcare-and-medical-services' => [
                ['Nurse', 'nurse', ['registered nurse', 'staff nurse', 'nursing officer', 'care assistant']],
                ['Healthcare Assistant', 'healthcare-assistant', ['health aide', 'patient care assistant']],
                ['Medical Officer', 'medical-officer', ['medical doctor', 'doctor', 'physician']],
            ],
        ];

        foreach ($rolesByCategory as $categorySlug => $roles) {
            $category = JobCategory::where('slug', $categorySlug)->first();

            if (! $category) {
                continue;
            }

            foreach ($roles as [$name, $slug, $aliases]) {
                JobRole::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'category_id' => $category->id,
                        'name' => $name,
                        'alternative_titles' => $aliases,
                        'sort_order' => 1,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    protected function skills(): void
    {
        $skillsByCategory = [
            'Administrative' => ['Microsoft Office', 'Data Entry', 'Calendar Management', 'Administrative Support', 'Documentation', 'Scheduling', 'Communication'],
            'Sales & Marketing' => ['Sales', 'Lead Generation', 'Customer Acquisition', 'Digital Marketing', 'Social Media Marketing', 'Negotiation', 'CRM', 'Market Research'],
            'Customer Service' => ['Customer Service', 'Customer Support', 'Communication', 'Complaint Resolution', 'CRM', 'Call Handling', 'Email Support'],
            'Accounting & Finance' => ['Accounting', 'Bookkeeping', 'Financial Reporting', 'Auditing', 'Taxation', 'Payroll', 'Budgeting', 'Excel', 'Financial Analysis'],
            'HR & Recruitment' => ['Recruitment', 'Talent Acquisition', 'Human Resources', 'Employee Relations', 'HR Administration', 'Payroll', 'Interviewing'],
            'IT & Software' => ['Software Development', 'Programming', 'PHP', 'Laravel', 'Python', 'JavaScript', 'TypeScript', 'React', 'Vue', 'Node.js', 'REST API', 'MySQL', 'PostgreSQL', 'Git', 'Linux', 'Cybersecurity', 'Cloud Computing', 'IT Support'],
            'Engineering & Technical' => ['Engineering', 'AutoCAD', 'Electrical Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Technical Drawing', 'Project Management', 'CAD', 'Maintenance'],
            'Media & Creative' => ['Graphic Design', 'Adobe Photoshop', 'Adobe Illustrator', 'Figma', 'UI Design', 'UX Design', 'Branding', 'Content Creation', 'Copywriting', 'Video Editing', 'Social Media', 'Communication'],
            'Education & Training' => ['Teaching', 'Lesson Planning', 'Classroom Management', 'Curriculum Development', 'Training', 'Educational Technology', 'Communication'],
            'Healthcare' => ['Nursing', 'Patient Care', 'Clinical Care', 'Healthcare', 'Medical Records', 'First Aid', 'Health Education', 'Clinical Support'],
        ];

        $now = now();

        foreach ($skillsByCategory as $category => $skillNames) {
            foreach ($skillNames as $name) {
                Skill::updateOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'category' => $category,
                        'type' => 'technical',
                        'description' => null,
                        'is_verified' => true,
                        'demand_score' => 80,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    protected function backfillJobs(): void
    {
        $jobs = Job::query()
            ->whereNull('category_id')
            ->orWhereNull('role_id')
            ->whereNotNull('role')
            ->get();

        foreach ($jobs as $job) {
            $role = $this->resolveRole($job->title.' '.$job->role);

            if (! $role) {
                continue;
            }

            $job->category_id ??= $role->category_id;
            $job->role_id ??= $role->id;
            $job->save();
        }
    }

    protected function backfillCandidates(): void
    {
        $profiles = CandidateProfile::query()
            ->where(function ($q) {
                $q->whereNull('desired_category_id')
                    ->orWhereNull('desired_role_id');
            })
            ->whereNotNull('desired_role')
            ->get();

        foreach ($profiles as $profile) {
            $role = $this->resolveRole($profile->desired_role);

            if (! $role) {
                continue;
            }

            $profile->desired_category_id ??= $role->category_id;
            $profile->desired_role_id ??= $role->id;
            $profile->save();
        }
    }

    protected function resolveRole(string $text): ?JobRole
    {
        $normalized = strtolower(trim($text));

        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $candidates = [];

        foreach (JobRole::all() as $role) {
            $names = array_merge([$role->name], (array) $role->alternative_titles, [$role->slug]);
            $score = 0;
            $exact = false;

            foreach ($names as $name) {
                $name = strtolower(trim((string) $name));

                if ($name === '' || ! str_contains(' '.$normalized.' ', ' '.$name.' ')) {
                    continue;
                }

                $score = max($score, strlen($name));
                $exact = $name === $normalized;

                if ($exact) {
                    break;
                }
            }

            if ($score > 0) {
                $candidates[] = compact('role', 'score', 'exact');

                if ($exact) {
                    break;
                }
            }
        }

        usort($candidates, fn ($a, $b) => $b['exact'] <=> $a['exact'] ?: ($b['score'] <=> $a['score']));

        $winner = $candidates[0] ?? null;

        if (! $winner) {
            return null;
        }

        // Tie-break: if the top two scored roles are equally strong and neither
        // is an exact match, we cannot be certain - leave it unassigned.
        if (! $winner['exact'] && isset($candidates[1]) && $candidates[1]['score'] === $winner['score']) {
            return null;
        }

        return $winner['role'];
    }
}
