<?php

return [
    // Where AdminUserSeeder writes the generated admin password. Injectable so
    // tests never touch — and never delete — the real file: storage_path() is
    // not redirected under APP_ENV=testing, so the default is the same file a
    // native install produces, and there is no password reset flow to recover
    // from clobbering it.
    'admin_password_path' => storage_path('admin-password.txt'),

    // Address the public landing page's contact form notifies, in addition
    // to always persisting the submission as a ContactSubmission row. Empty
    // by default: ContactController skips the send entirely rather than
    // falling back to any hardcoded address — every self-hosted install
    // must opt in to where its own visitors' messages get mailed.
    'contact_to' => env('MNEMON_CONTACT_TO'),

    // Which Claude Code marketplace the device setup script installs from. A
    // fork points its own installs at its own repository without editing the
    // script.
    'plugin' => [
        'marketplace' => env('MNEMON_PLUGIN_MARKETPLACE', 'coopers98/mnemon'),
    ],

    'embedding' => [
        'driver' => env('MNEMON_EMBEDDING_DRIVER', 'openai'),
        'drivers' => [
            'openai' => [
                'model' => env('MNEMON_OPENAI_MODEL', 'text-embedding-3-small'),
                'dimensions' => 1536,
            ],
            'ollama' => [
                'model' => env('MNEMON_OLLAMA_MODEL', 'nomic-embed-text'),
                'host' => env('MNEMON_OLLAMA_HOST', 'http://localhost:11434'),
                'dimensions' => 768,
            ],
            'none' => [],
        ],
    ],
    'retrieval' => [
        'weights' => [
            'semantic' => 0.6,
            'fulltext' => 0.3,
            'temporal' => 0.1,
        ],
        'temporal_boost_days' => 7,
        'default_limit' => 5,
        'max_limit' => 20,
    ],
    'wiki' => [
        'stale_days' => 30,
        'confidence_decay_days' => 90,
    ],

    // Item 12: Quality Scoring
    'quality' => [
        'enabled' => env('MNEMON_QUALITY_LLM', false), // false = heuristics only
        'low_threshold' => 0.4,
        'min_length' => 100,
        'vague_phrases' => [
            'it depends', 'various factors', 'many things', 'could be', 'might be',
            'in some cases', 'sometimes', 'generally speaking', 'it is important to note',
            'as mentioned', 'basically', 'essentially', 'kind of', 'sort of',
        ],
    ],

    // Item 14: Retention / Forgetting Curves
    'retention' => [
        'half_lives' => [
            'raw' => 30,         // fast decay
            'reviewed' => 90,    // medium decay
            'consolidated' => 365, // slow decay
        ],
        'soft_delete_threshold' => 0.05, // delete drawers below this score
    ],

    'recall' => [
        'confidence_floor' => env('MNEMON_RECALL_FLOOR', 0.45),
        'default_token_budget' => 1500,
    ],

    'digest' => [
        'driver' => env('MNEMON_DIGEST_DRIVER', 'openai'),
        'confidence_floor' => env('MNEMON_DIGEST_FLOOR', 0.5),
        'openai_model' => env('MNEMON_DIGEST_OPENAI_MODEL', 'gpt-4o-mini'),

        // Seconds to wait on the digest completion. The old hard-coded 20 was
        // too tight for a large transcript and produced `cURL error 28`, which
        // reached the client as an opaque 500 rather than as a retryable
        // timeout. Raise it if digests of long sessions still time out.
        'timeout' => env('MNEMON_DIGEST_TIMEOUT', 60),
    ],
];
