<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is REPORT-ONLY: the browser reports what it would have blocked to /csp-report and blocks nothing.
    | Set SECURITY_CSP_REPORT_ONLY=false to enforce the same policy without a deploy, once the reports have been reviewed.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
];
