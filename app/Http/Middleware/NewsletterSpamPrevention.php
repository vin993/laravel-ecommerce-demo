<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class NewsletterSpamPrevention
{
    protected $suspiciousPatterns = [
        '/http[s]?:\/\/[^\s]{3,}/i',
        '/www\.[^\s]{3,}/i',
        '/\b[A-Z]{10,}\b/',
        '/(.)\1{4,}/',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        // Check for missing or suspicious user agent
        if (empty($userAgent) || $userAgent === '-') {
            Log::warning('Newsletter blocked: No user agent', ['ip' => $ip]);
            return back()->with('error', 'Invalid request. Please enable JavaScript and try again.');
        }

        // Honeypot check
        if ($request->filled('website')) {
            Log::warning('Newsletter blocked: Honeypot filled', [
                'ip' => $ip,
                'honeypot_value' => $request->input('website')
            ]);
            return back()->with('success', 'Thank you for subscribing!');
        }

        // Time-based check (must spend at least 3 seconds on page)
        $formLoadedAt = $request->input('form_loaded_at');
        if ($formLoadedAt) {
            $timeSpent = (time() * 1000) - intval($formLoadedAt);
            if ($timeSpent < 3000) {
                Log::warning('Newsletter blocked: Submitted too fast', [
                    'ip' => $ip,
                    'time_spent_ms' => $timeSpent
                ]);
                return back()->with('error', 'Please take a moment to review your email before submitting.');
            }
        }

        $email = strtolower($request->input('email', ''));

        // Check for suspicious patterns in email
        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $email)) {
                Log::warning('Newsletter blocked: Suspicious pattern in email', [
                    'ip' => $ip,
                    'pattern' => $pattern
                ]);
                return back()->with('error', 'Please enter a valid email address.');
            }
        }

        // Disposable email check
        $disposableEmailDomains = [
            'tempmail.com', 'guerrillamail.com', '10minutemail.com', 'throwaway.email',
            'mailinator.com', 'maildrop.cc', 'temp-mail.org', 'yopmail.com',
            'trashmail.com', 'getnada.com', 'dispostable.com', 'fakeinbox.com'
        ];

        $emailDomain = substr(strrchr($email, "@"), 1);
        if (in_array($emailDomain, $disposableEmailDomains)) {
            Log::warning('Newsletter blocked: Disposable email', [
                'ip' => $ip,
                'email_domain' => $emailDomain
            ]);
            return back()->with('error', 'Please use a valid email address.');
        }

        // Rate limiting per IP (max 5 subscriptions per hour)
        $submissionKey = 'newsletter_submission_' . $ip;
        $recentSubmissions = Cache::get($submissionKey, []);

        $duplicateCount = 0;
        $currentEmailHash = md5($email);
        $currentTime = time();

        // Check for rapid spam (same email within 1 minute from same IP)
        foreach ($recentSubmissions as $submission) {
            if ($submission['hash'] === $currentEmailHash && ($currentTime - $submission['time']) < 60) {
                Log::warning('Newsletter blocked: Rapid duplicate submission', [
                    'ip' => $ip,
                    'hash' => $currentEmailHash,
                    'seconds_ago' => $currentTime - $submission['time']
                ]);
                return back()->with('error', 'Please wait a moment before trying again.');
            }

            // Count submissions in last hour
            if ($currentTime - $submission['time'] < 3600) {
                $duplicateCount++;
            }
        }

        // Block if more than 5 subscriptions from this IP in the last hour
        if ($duplicateCount >= 5) {
            Log::warning('Newsletter blocked: Too many submissions from IP', [
                'ip' => $ip,
                'count' => $duplicateCount
            ]);
            return back()->with('error', 'Too many subscription attempts. Please try again later.');
        }

        // Store this submission
        $recentSubmissions[] = [
            'hash' => $currentEmailHash,
            'time' => $currentTime,
            'email' => $email
        ];

        // Clean old submissions (older than 1 hour)
        $recentSubmissions = array_filter($recentSubmissions, function($submission) use ($currentTime) {
            return $currentTime - $submission['time'] < 3600;
        });

        Cache::put($submissionKey, $recentSubmissions, now()->addHours(2));

        Log::info('Newsletter submission passed spam checks', [
            'ip' => $ip,
            'email' => $email
        ]);

        return $next($request);
    }
}
