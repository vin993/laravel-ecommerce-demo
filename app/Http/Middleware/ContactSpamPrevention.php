<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ContactSpamPrevention
{
    protected $whitelistedIPs = [
        '202.88.244.180',
        '64.233.129.205',
    ];

    protected $spamKeywords = [
        'viagra', 'cialis', 'casino', 'poker', 'lottery', 'bitcoin', 'cryptocurrency',
        'click here', 'buy now', 'limited time', 'act now', 'subscribe', 'unsubscribe',
        'free money', 'make money', 'earn money', 'work from home', 'mlm', 'multi-level',
        'seo service', 'link building', 'backlink', 'ranking', 'traffic boost',
        'loan', 'credit card', 'debt', 'refinance', 'mortgage',
        'weight loss', 'diet pill', 'male enhancement',
        'click this link', 'visit this site', 'check out this'
    ];

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

        if (in_array($ip, $this->whitelistedIPs)) {
            Log::info('Contact form bypassed spam checks (whitelisted IP)', ['ip' => $ip]);
            return $next($request);
        }

        $userAgent = $request->header('User-Agent');

        if (empty($userAgent) || $userAgent === '-') {
            Log::warning('Contact form blocked: No user agent', ['ip' => $ip]);
            return back()->with('error', 'Invalid request. Please enable JavaScript and try again.');
        }

        if ($request->filled('website')) {
            Log::warning('Contact form blocked: Honeypot filled', [
                'ip' => $ip,
                'honeypot_value' => $request->input('website')
            ]);
            return back()->with('success', 'Thank you for your message. We will get back to you soon.');
        }

        $formLoadedAt = $request->input('form_loaded_at');
        if ($formLoadedAt) {
            $timeSpent = (time() * 1000) - intval($formLoadedAt);
            if ($timeSpent < 3000) {
                Log::warning('Contact form blocked: Submitted too fast', [
                    'ip' => $ip,
                    'time_spent_ms' => $timeSpent
                ]);
                return back()->with('error', 'Please take a moment to review your message before submitting.');
            }
        }

        $message = strtolower($request->input('message', ''));
        $name = strtolower($request->input('name', ''));
        $email = strtolower($request->input('email', ''));

        foreach ($this->spamKeywords as $keyword) {
            if (stripos($message, $keyword) !== false || stripos($name, $keyword) !== false) {
                Log::warning('Contact form blocked: Spam keyword detected', [
                    'ip' => $ip,
                    'keyword' => $keyword
                ]);
                return back()->with('error', 'Your message contains prohibited content. Please review and try again.');
            }
        }

        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                Log::warning('Contact form blocked: Suspicious pattern', [
                    'ip' => $ip,
                    'pattern' => $pattern
                ]);
                return back()->with('error', 'Your message contains suspicious content. Please remove any URLs or unusual formatting.');
            }
        }

        $disposableEmailDomains = [
            'tempmail.com', 'guerrillamail.com', '10minutemail.com', 'throwaway.email',
            'mailinator.com', 'maildrop.cc', 'temp-mail.org', 'yopmail.com'
        ];

        $emailDomain = substr(strrchr($email, "@"), 1);
        if (in_array($emailDomain, $disposableEmailDomains)) {
            Log::warning('Contact form blocked: Disposable email', [
                'ip' => $ip,
                'email_domain' => $emailDomain
            ]);
            return back()->with('error', 'Please use a valid email address.');
        }

        $submissionKey = 'contact_submission_' . $ip;
        $recentSubmissions = Cache::get($submissionKey, []);

        $duplicateCount = 0;
        $currentMessageHash = md5($message . $name . $email);

        foreach ($recentSubmissions as $submission) {
            if ($submission['hash'] === $currentMessageHash) {
                Log::warning('Contact form blocked: Duplicate submission', [
                    'ip' => $ip,
                    'hash' => $currentMessageHash
                ]);
                return back()->with('error', 'You have already submitted this message. Please wait before submitting again.');
            }

            if (time() - $submission['time'] < 3600) {
                $duplicateCount++;
            }
        }

        if ($duplicateCount >= 3) {
            Log::warning('Contact form blocked: Too many submissions from IP', [
                'ip' => $ip,
                'count' => $duplicateCount
            ]);
            return back()->with('error', 'Too many submissions. Please try again later.');
        }

        $recentSubmissions[] = [
            'hash' => $currentMessageHash,
            'time' => time(),
            'email' => $email
        ];

        $recentSubmissions = array_filter($recentSubmissions, function($submission) {
            return time() - $submission['time'] < 3600;
        });

        Cache::put($submissionKey, $recentSubmissions, now()->addHours(2));

        Log::info('Contact form submission passed spam checks', [
            'ip' => $ip,
            'email' => $email
        ]);

        return $next($request);
    }
}
