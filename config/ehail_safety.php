<?php

/*
|--------------------------------------------------------------------------
| R-46 safety, verification and support (docs/R46_SAFETY.md)
|--------------------------------------------------------------------------
| Everything here is owned by the safety domain (App\Domain\Safety, Verification, Support).
| Document requirements below are the platform's working defaults for South Africa; the legal
| list of what an e-hailing operator must hold is "to be confirmed by the owner's legal adviser"
| (docs/R46_RESEARCH.md, "Safety and trust").
*/

return [

    'default_market' => env('EHAIL_MARKET', 'ZA'),

    /*
    |--------------------------------------------------------------------------
    | Verification documents per market
    |--------------------------------------------------------------------------
    | subject: driver (belongs to the driver profile) or vehicle (belongs to one vehicle).
    | required: mandatory for every category unless a category override says otherwise.
    | expiry_required: the uploader must give an expiry date; past it the document is expired and,
    |   when mandatory, the driver or vehicle cannot go online.
    | kinds: image (re-encoded, EXIF stripped) and/or pdf (stored as uploaded).
    | categories: per vehicle category overrides (vehicles.type): required, max_age_days (an
    |   approved document older than this, counted from submission, no longer satisfies it).
    */
    'markets' => [
        'ZA' => [
            'document_types' => [
                'identity_document' => ['subject' => 'driver', 'label' => 'South African ID or passport', 'required' => true, 'expiry_required' => false, 'kinds' => ['image', 'pdf']],
                'drivers_licence' => ['subject' => 'driver', 'label' => "Driver's licence card", 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'prdp' => ['subject' => 'driver', 'label' => 'Professional driving permit (PrDP)', 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'profile_photo' => ['subject' => 'driver', 'label' => 'Profile photo (clear face, no sunglasses)', 'required' => true, 'expiry_required' => false, 'kinds' => ['image']],
                'vehicle_licence_disc' => ['subject' => 'vehicle', 'label' => 'Vehicle licence disc (registration)', 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'roadworthy_certificate' => ['subject' => 'vehicle', 'label' => 'Roadworthy certificate', 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'insurance' => ['subject' => 'vehicle', 'label' => 'Passenger liability insurance', 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'operating_licence' => ['subject' => 'vehicle', 'label' => 'Operating licence (e-hailing permit)', 'required' => true, 'expiry_required' => true, 'kinds' => ['image', 'pdf']],
                'vehicle_photo_front' => ['subject' => 'vehicle', 'label' => 'Vehicle photo: front with number plate', 'required' => true, 'expiry_required' => false, 'kinds' => ['image'], 'categories' => ['premium' => ['max_age_days' => 180], 'suv' => ['max_age_days' => 365]]],
                'vehicle_photo_back' => ['subject' => 'vehicle', 'label' => 'Vehicle photo: back with number plate', 'required' => true, 'expiry_required' => false, 'kinds' => ['image'], 'categories' => ['premium' => ['max_age_days' => 180], 'suv' => ['max_age_days' => 365]]],
                'vehicle_photo_interior' => ['subject' => 'vehicle', 'label' => 'Vehicle photo: interior', 'required' => true, 'expiry_required' => false, 'kinds' => ['image'], 'categories' => ['premium' => ['max_age_days' => 180], 'suv' => ['max_age_days' => 365]]],
            ],
        ],
    ],

    'verification' => [
        // Turn off ONLY to run a market before its document list is confirmed; the profile must
        // still be approved and the vehicle active. Off is logged on every go-online decision.
        'require_documents' => (bool) env('EHAIL_VERIFICATION_REQUIRE_DOCUMENTS', true),
        'expiry_warning_days' => [30, 7],
        'reject_reasons' => [
            'illegible' => 'The document cannot be read',
            'expired' => 'The document has expired',
            'mismatch' => 'The details do not match the profile or vehicle',
            'wrong_document' => 'This is not the requested document',
            'incomplete' => 'Part of the document is missing or cut off',
            'suspected_tampering' => 'The document appears altered',
            'photo_quality' => 'The photo is unclear or does not show what is required',
            'other' => 'Another reason (see the note)',
        ],
    ],

    /*
    | Upload limits. Virus scanning is NOT available on the host (no ClamAV); files are type-sniffed,
    | size-capped, images are decoded and re-encoded (which drops EXIF/GPS metadata and anything
    | appended to the file), PDFs are stored as uploaded and only ever streamed as attachments.
    */
    'uploads' => [
        'disk' => 'driver-documents',
        'support_disk' => 'safety-files',
        'max_image_kb' => 8192,
        'max_pdf_kb' => 10240,
        'max_megapixels' => 40,
        'max_edge_px' => 2400,
        'jpeg_quality' => 85,
    ],

    'trusted_contacts' => [
        'max' => 5,
        'confirmation_valid_hours' => 72,
    ],

    'trip_share' => [
        // Hard ceiling for a link on a ride that never ends; normally it expires 30 min after the trip.
        'max_lifetime_hours' => 6,
        'after_trip_minutes' => 30,
        // Driver position is rounded to this many decimals (3 is about 110 m).
        'position_decimals' => 3,
    ],

    'check_ins' => [
        // A passenger or driver who does not answer the "are you OK?" prompt within this many
        // minutes is treated as needing help: an incident is raised.
        'respond_within_minutes' => (int) env('EHAIL_CHECKIN_MINUTES', 3),
    ],

    /*
    | Incident escalation. Level 0 is the operations queue (every platform operator gets an in-app
    | notification). If nobody acknowledges within minutes_by_severity, the incident moves to the
    | next level and those people are notified. On-call users are user ids; emails are mailed.
    */
    'escalation' => [
        'minutes_by_severity' => ['critical' => 2, 'high' => 5, 'medium' => 15, 'low' => 60],
        'levels' => [
            1 => [
                'label' => 'On-call operations lead',
                'user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EHAIL_SAFETY_ONCALL_USER_IDS', ''))))),
                'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('EHAIL_SAFETY_ONCALL_EMAILS', ''))))),
            ],
            2 => [
                'label' => 'Safety manager',
                'user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EHAIL_SAFETY_MANAGER_USER_IDS', ''))))),
                'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('EHAIL_SAFETY_MANAGER_EMAILS', ''))))),
            ],
        ],
    ],

    /*
    | Emergency numbers shown with tel: links. Dot.Ehail does not dispatch emergency services and
    | has no armed-response or medical partner integrated (owner decision, docs/R46_SAFETY.md).
    */
    'emergency_numbers' => [
        'ZA' => [
            ['key' => 'police', 'label' => 'SAPS emergency (police)', 'number' => '10111'],
            ['key' => 'ambulance', 'label' => 'Ambulance and fire', 'number' => '10177'],
            ['key' => 'mobile', 'label' => 'Emergency from any mobile phone', 'number' => '112'],
        ],
    ],

    'sms' => [
        // null (default, sends nothing) or bulksms. BulkSMS is only used when both keys are set.
        'driver' => env('EHAIL_SMS_DRIVER', 'null'),
        'timeout' => 5,
        'bulksms' => [
            'endpoint' => env('BULKSMS_ENDPOINT', 'https://api.bulksms.com/v1/messages'),
            'token_id' => env('BULKSMS_TOKEN_ID'),
            'token_secret' => env('BULKSMS_TOKEN_SECRET'),
            'sender' => env('BULKSMS_SENDER'),
        ],
    ],

    'support' => [
        // Minutes.
        'sla' => [
            'urgent' => ['first_response' => 15, 'resolution' => 240],
            'high' => ['first_response' => 60, 'resolution' => 1440],
            'normal' => ['first_response' => 480, 'resolution' => 4320],
            'low' => ['first_response' => 1440, 'resolution' => 10080],
        ],
        'default_priority' => [
            'in_trip_assistance' => 'urgent',
            'safety_incident' => 'urgent',
            'lost_property' => 'high',
            'driver_conduct' => 'high',
            'passenger_conduct' => 'high',
            'fare_dispute' => 'normal',
            'payment_issue' => 'normal',
            'post_trip' => 'normal',
            'account_problem' => 'normal',
        ],
    ],

    'cancellations' => [
        // Flag (never ban) an account cancelling this many accepted rides in the window.
        'window_days' => 7,
        'driver_threshold' => 5,
        'passenger_threshold' => 5,
    ],

    'ratings' => [
        // Below this many ratings the band is "new" (no average shown on sensitive surfaces).
        'min_count_for_band' => 5,
        // Comments containing any of these words are held for moderation instead of shown (a hold,
        // never a deletion; an operator releases or hides it). Whole-word, case-insensitive.
        'held_terms' => ['kill', 'rape', 'kaffir', 'nigger', 'whore', 'bitch', 'cunt', 'poes', 'moer'],
    ],
];
