{{-- GridSpace — New Application Notification for Employers --}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>New application for {{ $job->title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { margin: 0; padding: 0; width: 100%; background-color: #f4f6f9; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        table { border-collapse: collapse; }
        img { border: 0; line-height: 100%; }
        a { text-decoration: none; }
        .wrapper { width: 100%; background-color: #f4f6f9; padding: 24px 0; }
        .container { max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .brand-bar { background-color: #052e5c; padding: 24px 32px; }
        .brand-name { color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: 0.5px; }
        .brand-dot { color: #eb5333; }
        .preheader { display: none; max-height: 0; overflow: hidden; mso-hide: all; }
        .content { padding: 32px; }
        h1 { font-size: 22px; line-height: 1.35; color: #0f172a; font-weight: 700; margin-bottom: 8px; }
        h2 { font-size: 15px; text-transform: uppercase; letter-spacing: 0.6px; color: #eb5333; font-weight: 700; margin-bottom: 14px; }
        p { font-size: 15px; line-height: 1.55; color: #334155; }
        .lead { font-size: 16px; color: #334155; margin-bottom: 24px; }
        .section { padding: 20px 0; border-top: 1px solid #eef1f5; }
        .section:first-of-type { border-top: 0; }
        .kv { width: 100%; margin: 0; }
        .kv td { padding: 6px 0; vertical-align: baseline; }
        .kv .k { width: 38%; color: #64748b; font-size: 14px; }
        .kv .v { color: #0f172a; font-size: 14px; font-weight: 600; }
        .job-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; }
        .job-box .title { font-size: 17px; font-weight: 700; color: #052e5c; }
        .job-box .meta { font-size: 13px; color: #64748b; margin-top: 4px; }
        .match-ring { text-align: center; padding: 8px 0 16px; }
        .match-ring .score { font-size: 40px; font-weight: 800; color: #052e5c; }
        .match-ring .unit { font-size: 20px; font-weight: 700; color: #052e5c; }
        .match-bar { height: 8px; background-color: #e2e8f0; border-radius: 999px; overflow: hidden; margin: 10px 0 18px; max-width: 380px; margin-left: auto; margin-right: auto; }
        .match-bar span { display: block; height: 100%; background-color: #eb5333; border-radius: 999px; }
        .component { margin-bottom: 14px; }
        .component .label { font-size: 14px; font-weight: 600; color: #0f172a; display: flex; justify-content: space-between; align-items: center; }
        .component .pct { color: #eb5333; }
        .component .bar { height: 6px; background-color: #eef2f7; border-radius: 999px; overflow: hidden; margin-top: 5px; }
        .component .bar span { display: block; height: 100%; background-color: #052e5c; border-radius: 999px; }
        .reason { font-size: 13px; color: #475569; margin-top: 6px; padding-left: 12px; border-left: 2px solid #eb5333; }
        .cover-letter { background-color: #fffbfa; border: 1px solid #fde3db; border-radius: 8px; padding: 14px 16px; white-space: pre-line; font-size: 14px; line-height: 1.6; color: #334155; }
        .muted { color: #94a3b8; font-size: 14px; }
        .cta-wrap { text-align: center; padding: 12px 0 6px; }
        .cta { display: inline-block; background-color: #eb5333; color: #ffffff !important; font-size: 15px; font-weight: 700; padding: 14px 32px; border-radius: 8px; text-align: center; }
        .note { text-align: center; font-size: 13px; color: #94a3b8; margin-top: 16px; }
        .footer { background-color: #052e5c; padding: 20px 32px; text-align: center; }
        .footer p { color: #cbd5e1; font-size: 13px; margin-bottom: 4px; }
        .footer a { color: #ffffff; }
        ul.skills { list-style: none; padding: 0; }
        ul.skills li { display: inline-block; background-color: #f1f5f9; color: #334155; border-radius: 999px; padding: 4px 12px; font-size: 13px; margin: 0 6px 6px 0; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; border-radius: 0; }
            .content { padding: 24px; }
            .brand-bar, .footer { padding-left: 24px; padding-right: 24px; }
        }
    </style>
</head>
<body>
    <div class="preheader">[[Candidate: {{ $candidate->name }}]] applied to {{ $job->title }} on GridSpace — review the application.</div>
    <div class="wrapper">
        <div class="container">

            <div class="brand-bar">
                <span class="brand-name">Grid<span class="brand-dot">Space</span></span>
            </div>

            <div class="content">

                <h1>You have received a new application for {{ $job->title }}.</h1>
                <p class="lead">{{ $candidate->name }} has just applied to <strong>{{ $job->title }}</strong> through GridSpace.</p>
                <p class="lead">Review the summary below, then open the full application to shortlist, interview, or message the candidate.</p>

                <div class="job-box">
                    <div class="title">{{ $job->title }}</div>
                    <div class="meta">
                        {{ $job->employment_type ?? 'Full-time' }}
                        @if($job->location)
                            &nbsp;&middot;&nbsp; {{ $job->location }}
                        @endif
                        @if($job->salaryLabel())
                            &nbsp;&middot;&nbsp; {{ $job->salaryLabel() }}
                        @endif
                    </div>
                </div>

                <div class="section">
                    <h2>Candidate Summary</h2>
                    <table class="kv" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="k">Candidate</td>
                            <td class="v">{{ $candidate->name }}</td>
                        </tr>
                        @if($profile?->current_role)
                            <tr>
                                <td class="k">Current Role</td>
                                <td class="v">{{ $profile->current_role }}</td>
                            </tr>
                        @endif
                        @if($profile?->desired_role)
                            <tr>
                                <td class="k">Professional Role</td>
                                <td class="v">{{ $profile->desired_role }}</td>
                            </tr>
                        @endif
                        @if($profile?->years_of_experience !== null)
                            <tr>
                                <td class="k">Experience</td>
                                <td class="v">{{ $profile->years_of_experience }} {{ Str::plural('year', $profile->years_of_experience) }}</td>
                            </tr>
                        @endif
                        @if($profile?->location)
                            <tr>
                                <td class="k">Location</td>
                                <td class="v">{{ $profile->location }}</td>
                            </tr>
                        @elseif($profile?->location_country)
                            <tr>
                                <td class="k">Location</td>
                                <td class="v">{{ $profile->location_country }}</td>
                            </tr>
                        @endif
                        @if($profile?->salary_expectation)
                            <tr>
                                <td class="k">Expected Salary</td>
                                <td class="v">{{ number_format((float) $profile->salary_expectation) }}</td>
                            </tr>
                        @endif
                        @if($profile?->availability)
                            <tr>
                                <td class="k">Availability</td>
                                <td class="v">{{ ucwords(str_replace('_', ' ', $profile->availability)) }}</td>
                            </tr>
                        @endif
                    </table>
                    @if($candidateSkills->isNotEmpty())
                        <p style="margin-top: 12px;"><strong style="color:#0f172a; font-size:14px;">Relevant Skills</strong></p>
                        <ul class="skills">
                            @foreach($candidateSkills as $skill)
                                <li>{{ $skill->skill?->name ?? $skill->skill_name ?? 'Unknown' }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="section">
                    <h2>Match Analysis</h2>
                    @if($match['overall'] > 0)
                        <div class="match-ring">
                            <span class="score">{{ $match['overall'] }}</span><span class="unit">%</span>
                            @if($match['category'])
                                <p class="muted" style="margin-top:2px;">{{ $match['category'] }}</p>
                            @endif
                        </div>
                        <div class="match-bar">
                            <span style="width: {{ min(100, $match['overall']) }}%"></span>
                        </div>
                        @foreach($match['components'] as $component)
                            <div class="component">
                                <div class="label">
                                    <span>{{ $component['label'] }}</span>
                                    <span class="pct">{{ $component['score'] }}%</span>
                                </div>
                                <div class="bar">
                                    <span style="width: {{ min(100, $component['score']) }}%"></span>
                                </div>
                                @foreach($component['reasons'] as $reason)
                                    <div class="reason">{{ $reason }}</div>
                                @endforeach
                            </div>
                        @endforeach
                        @if(!empty($match['matched_skills']))
                            <p class="muted" style="margin-top:12px;">Matched skills: {{ implode(', ', $match['matched_skills']) }}</p>
                        @endif
                    @else
                        <p class="muted">Match analysis is currently unavailable.</p>
                    @endif
                </div>

                <div class="section">
                    <h2>Personality &amp; Work Style</h2>
                    @if($personality?->assessment_completed)
                        @php
                            $rows = [
                                'work_style' => 'Work Style',
                                'communication_style' => 'Communication Style',
                                'collaboration_style' => 'Team Dynamics',
                                'leadership_style' => 'Leadership & Initiative',
                                'motivation_type' => 'Motivation Drivers',
                                'temperament_type' => 'Temperament / Personality',
                            ];
                        @endphp
                        <table class="kv" cellpadding="0" cellspacing="0">
                            @foreach($rows as $key => $label)
                                @if($personality->$key)
                                    <tr>
                                        <td class="k">{{ $label }}</td>
                                        <td class="v">{{ $personality->$key }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </table>
                        @if($personality->personality_summary)
                            <p class="muted" style="margin-top:12px;">{{ $personality->personality_summary }}</p>
                        @elseif($personality->work_style_summary)
                            <p class="muted" style="margin-top:12px;">{{ $personality->work_style_summary }}</p>
                        @elseif($personality->strengths_summary)
                            <p class="muted" style="margin-top:12px;">{{ $personality->strengths_summary }}</p>
                        @endif
                    @else
                        <p class="muted">Personality assessment information is not available for this candidate.</p>
                    @endif
                </div>

                <div class="section">
                    <h2>Cover Letter</h2>
                    @if($coverLetter)
                        <div class="cover-letter">{{ $coverLetter }}</div>
                    @else
                        <p class="muted">No cover letter was provided.</p>
                    @endif
                </div>

                <div class="section">
                    <h2>CV / Resume</h2>
                    @if($hasCv)
                        <p>A copy of {{ $candidate->name }}&rsquo;s CV is attached to this email.</p>
                    @else
                        <p class="muted">No CV was attached to this application.</p>
                    @endif
                </div>

                <div class="cta-wrap">
                    <a class="cta" href="{{ $viewApplicationUrl }}" target="_blank">View Full Application</a>
                </div>
                <p class="note">Signed in as the employer of record for this job, you can review, shortlist, and manage this candidate from your GridSpace dashboard.</p>

            </div>

            <div class="footer">
                <p style="font-weight:700; color:#ffffff;">Grid<span style="color:#eb5333;">Space</span></p>
                <p>Structured hiring &amp; talent discovery for growing teams.</p>
                <p><a href="{{ url('/') }}" style="color:#fbbf24;">job.gridspace.com.ng</a></p>
            </div>

        </div>
    </div>
</body>
</html>