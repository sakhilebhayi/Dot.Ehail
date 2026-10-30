<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports while the policy is in report-only mode, so a strict policy can be designed from what the
 * pages actually load instead of guessed. It keeps almost nothing: the directive and the blocked HOST (never the full address, which can carry
 * tokens in its query), logged at most once per pair per day. The body is limited and never echoed.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = substr((string) $request->getContent(), 0, 8192);
        $json = json_decode($raw, true);
        // Two shapes exist: {"csp-report": {...}} (report-uri) and [{"type": "csp-violation", "body": {...}}] (Reporting API).
        $reports = is_array($json) ? (isset($json['csp-report']) ? [$json['csp-report']] : (array_is_list($json) ? array_map(fn ($r) => is_array($r) ? ($r['body'] ?? []) : [], $json) : [])) : [];

        foreach (array_slice($reports, 0, 5) as $r) {
            if (! is_array($r)) {
                continue;
            }

            $directive = preg_replace('/[^a-z-]/', '', strtolower((string) ($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? '')));
            $blocked = (string) ($r['blocked-uri'] ?? $r['blockedURL'] ?? '');
            $host = in_array($blocked, ['inline', 'eval', 'self', ''], true) ? ($blocked ?: 'none') : (strtolower((string) parse_url($blocked, PHP_URL_HOST)) ?: 'other');
            $host = preg_replace('/[^a-z0-9.\-]/', '', $host);

            if ($directive !== '' && Cache::add("csp:$directive:$host:".now()->format('Y-m-d'), 1, 90000)) {
                Log::info('csp.violation', ['directive' => $directive, 'blocked_host' => $host]);
            }
        }

        return response()->noContent();
    }
}
