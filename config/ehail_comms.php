<?php

/*
|--------------------------------------------------------------------------
| R-46 comms: notifications and communication (docs/R46_COMMS.md)
|--------------------------------------------------------------------------
| Every notification is written to the in-app inbox first. Push, email and SMS are extra copies,
| each recorded in notification_deliveries with its real outcome. Nothing here claims a delivery a
| provider did not accept.
*/

return [

    /*
    | Categories people choose channels for. `critical` categories cannot be switched off (the screen
    | says why). `channels` lists the extra channels a category may use; in-app is always on.
    | `defaults` are the starting choices before a person changes anything.
    */
    'categories' => [
        'trip' => [
            'label' => 'Trip updates',
            'description' => 'Your driver is assigned, nearly there or has arrived; the trip started, ended or was cancelled.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => false],
        ],
        'scheduled' => [
            'label' => 'Scheduled rides',
            'description' => 'Booking confirmed, reminders, a new price that needs your OK, and when a driver is confirmed.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'payments' => [
            'label' => 'Payments and receipts',
            'description' => 'Payment received, declined or refunded, and your receipts.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'driver_account' => [
            'label' => 'Documents and verification',
            'description' => 'Document reviews, and documents about to expire or expired.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'earnings' => [
            'label' => 'Payouts and incentives',
            'description' => 'A payout was paid or failed, or you earned an incentive.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'offers' => [
            'label' => 'Ride offers',
            'description' => 'New ride offers while you are online. Shown in the app only: an offer lasts seconds, so it is never pushed or emailed.',
            'channels' => [],
            'defaults' => [],
        ],
        'support' => [
            'label' => 'Support',
            'description' => 'Replies and updates on your support cases.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => true],
        ],
        'account' => [
            'label' => 'Ratings and reports',
            'description' => 'Rating appeals and reports you made about a trip.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => true],
        ],
        'fleet_admin' => [
            'label' => 'Fleet applications',
            'description' => 'New driver applications to a fleet you run.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => true, 'email' => false],
        ],
        'safety' => [
            'label' => 'Safety',
            'description' => 'Emergency alerts, "are you OK?" check-ins and updates on incidents you reported.',
            'channels' => ['push', 'email', 'sms'],
            'defaults' => ['push' => true, 'email' => true, 'sms' => true],
            'critical' => true,
            'critical_reason' => 'Safety messages cannot be switched off: they are how we reach you, or the operations team, when someone may be in danger. SMS is used only if you confirm a number below.',
        ],
        'general' => [
            'label' => 'Other updates',
            'description' => 'Anything that does not fit above.',
            'channels' => ['push', 'email'],
            'defaults' => ['push' => false, 'email' => false],
        ],
    ],

    /*
    | Notification `type` (the `type` key of the in-app record) to category. Types written by other
    | domains are listed here too, so every in-app item gets the same push/email/SMS treatment.
    */
    'types' => [
        // comms-originated (NotificationRouter)
        'driver_assigned' => 'trip',
        'driver_approaching' => 'trip',
        'driver_arrived' => 'trip',
        'trip_started' => 'trip',
        'scheduled_booked' => 'scheduled',
        'scheduled_reminder' => 'scheduled',
        'scheduled_requote_required' => 'scheduled',
        'scheduled_driver_confirmed' => 'scheduled',
        'payment_receipt' => 'payments',
        'payout_paid' => 'earnings',
        'payout_failed' => 'earnings',
        'incentive_earned' => 'earnings',
        'offer_received' => 'offers',

        // written by dispatch, money, safety, verification and the pre-R-46 code
        'ride_completed' => 'trip',
        'ride_cancelled' => 'trip',
        'ride_requeued' => 'trip',
        'ride_expired' => 'trip',
        'ride_no_show' => 'trip',
        'ride_paid' => 'payments',
        'ride_payment_failed' => 'payments',
        'ride_refunded' => 'payments',
        'ride_fare_waived' => 'payments',
        'verification_document_reviewed' => 'driver_account',
        'verification_document_expiring' => 'driver_account',
        'verification_document_expired' => 'driver_account',
        'driver_application_submitted' => 'fleet_admin',
        'safety_incident_alert' => 'safety',
        'safety_incident_acknowledged' => 'safety',
        'safety_incident_resolved' => 'safety',
        'safety_check_in' => 'safety',
        'support_reply' => 'support',
        'support_relay' => 'support',
        'support_escalated' => 'support',
        'support_urgent' => 'support',
        'rating_appeal_decided' => 'account',
        'user_report_reviewed' => 'account',
    ],

    // Types that stay in the app even when the category allows more (a push about something the
    // person is watching happen adds nothing).
    'in_app_only_types' => ['trip_started', 'offer_received'],

    // Types whose email another part of the app already sends (no second copy from comms).
    'email_sent_elsewhere' => [
        'payment_receipt' => 'receipt_email_sent_by_money',
    ],

    // Comms-originated types whose email Dot.Notify sends when it is configured (precedence rule:
    // Notify owns email for events in its catalogue). Source notifications declare it themselves
    // through toNotifyEvent().
    'notify_handled_types' => [
        'driver_assigned' => 'ride.accepted',
        'scheduled_driver_confirmed' => 'ride.accepted',
    ],

    // Dot.Notify events (dots as underscores) and the comms category whose email choice governs them.
    // Only an explicit "off" stops them (NotifyClient::queue).
    'notify_event_categories' => [
        'ride_accepted' => 'trip',
        'ride_cancelled' => 'trip',
        'ride_expired' => 'trip',
        'driver_application_decided' => 'driver_account',
        'driver_document_reviewed' => 'driver_account',
        'driver_document_expiring' => 'driver_account',
        'driver_document_expired' => 'driver_account',
        'safety_incident_alert' => 'safety',
    ],

    // "Driver approaching": once per assignment, when the driver's reported position is this close
    // (great-circle metres) to the pickup point.
    'approaching_m' => (int) env('EHAIL_COMMS_APPROACHING_M', 500),

    'delivery' => [
        // Attempts per delivery, and the wait before each retry (seconds).
        'max_attempts' => 5,
        'backoff' => [60, 300, 900, 3600],
        // A claimed delivery not finished in this many seconds is treated as abandoned and retried.
        'lease_seconds' => 120,
        // Deliveries processed per worker run.
        'batch' => 100,
        // Delivery records are deleted after this many days (POPIA: keep only what is needed).
        'retention_days' => (int) env('EHAIL_COMMS_RETENTION_DAYS', 90),
    ],

    'push' => [
        'public_key' => env('EHAIL_VAPID_PUBLIC_KEY'),
        'private_key' => env('EHAIL_VAPID_PRIVATE_KEY'),
        'subject' => env('EHAIL_VAPID_SUBJECT', 'mailto:no-reply@infodot.co.za'),
        'ttl' => 3600,
        'timeout' => 5,
        'max_subscriptions_per_user' => 10,
        // Push endpoints are posted to by the server, so only real push services are accepted
        // (no request to an arbitrary host). Suffix match on the host name.
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('EHAIL_PUSH_ALLOWED_HOSTS',
            'fcm.googleapis.com,android.googleapis.com,updates.push.services.mozilla.com,push.services.mozilla.com,push.apple.com,notify.windows.com'))))),
    ],

    'sms' => [
        // Confirmation code for a new number.
        'code_ttl_minutes' => 10,
        'code_max_attempts' => 5,
    ],
];
