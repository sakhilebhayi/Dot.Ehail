<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is ENFORCED (nonce-based, R-46 security S-29; a render sweep of every page in a real browser
    | showed no violation). SECURITY_CSP_REPORT_ONLY=true turns the same policy back into report-only without a deploy.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
];
