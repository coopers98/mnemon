<?php

return [
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
];
