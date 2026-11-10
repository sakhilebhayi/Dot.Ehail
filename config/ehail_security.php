<?php

/*
 * R-46 security and privacy settings (docs/R46_SECURITY.md, docs/R46_PRIVACY.md).
 */
return [

    // Sensitive actions (payout bank account, trusted contacts) need a sign-in or password confirmation this recent.
    'reauth_minutes' => (int) env('EHAIL_REAUTH_MINUTES', 15),

    // A changed payout bank account receives no payout until this many hours after the change (the owner is told
    // at once by email and in the app, so a hijacked session cannot quietly redirect earnings).
    'payout_account_cooling_off_hours' => (int) env('EHAIL_PAYOUT_COOLING_OFF_HOURS', 72),

    // New-device sign-in notice (email + in-app) when a sign-in comes from a browser this account has not used.
    'new_device_notice' => (bool) env('EHAIL_NEW_DEVICE_NOTICE', true),

    // Card verification (R1 setup charge) limits beyond the per-user hourly limit: per user per day and per IP per hour.
    'card_setup_per_user_daily' => (int) env('EHAIL_CARD_SETUP_PER_USER_DAILY', 20),
    'card_setup_per_ip_hourly' => (int) env('EHAIL_CARD_SETUP_PER_IP_HOURLY', 20),

    /*
     * Retention (days) applied by `ehail:privacy-purge` (scheduled daily). Financial records (ledger, receipts,
     * payments, payouts) are kept for the statutory period and are not purged here; safety records are kept while
     * an incident is open and for `incidents` days after it closes. Owner decision: confirm these periods with
     * the information officer (docs/R46_PRIVACY.md section 5).
     */
    'retention' => [
        'notifications_read' => (int) env('EHAIL_RETAIN_NOTIFICATIONS_DAYS', 180),
        'consumed_quote_coordinates' => (int) env('EHAIL_RETAIN_QUOTE_COORDS_DAYS', 90),
        'ride_route_polylines' => (int) env('EHAIL_RETAIN_ROUTE_POLYLINES_DAYS', 90),
        'ride_event_coordinates' => (int) env('EHAIL_RETAIN_EVENT_COORDS_DAYS', 90),
        'incidents' => (int) env('EHAIL_RETAIN_INCIDENT_DAYS', 1825),
        'support_closed' => (int) env('EHAIL_RETAIN_SUPPORT_DAYS', 730),
        'verification_files_superseded' => (int) env('EHAIL_RETAIN_SUPERSEDED_DOCS_DAYS', 90),
        'trusted_contacts_declined' => (int) env('EHAIL_RETAIN_DECLINED_CONTACTS_DAYS', 30),
        'failed_jobs' => (int) env('EHAIL_RETAIN_FAILED_JOBS_DAYS', 30),
        'audit_logs' => (int) env('EHAIL_RETAIN_AUDIT_DAYS', 2190),
        'webhook_events' => (int) env('EHAIL_RETAIN_WEBHOOK_DAYS', 365),
        'business_approvals' => (int) env('EHAIL_RETAIN_BUSINESS_APPROVALS_DAYS', 365),
    ],
];
