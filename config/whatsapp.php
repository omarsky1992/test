<?php

// WhatsApp Cloud API (Meta) and the services that read the commands. Secrets come only from the
// environment (deploy/.env on the server); the admin turns the feature on in the panel.
return [
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    // Understanding free text in Iraqi Arabic (Claude).
    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('WHATSAPP_AI_MODEL', 'claude-opus-5-5'),
    ],

    // Voice notes to text: any OpenAI-compatible transcription endpoint.
    'transcribe' => [
        'api_key' => env('WHATSAPP_TRANSCRIBE_API_KEY', env('OPENAI_API_KEY')),
        'url' => env('WHATSAPP_TRANSCRIBE_URL', 'https://api.openai.com/v1/audio/transcriptions'),
        'model' => env('WHATSAPP_TRANSCRIBE_MODEL', 'whisper-1'),
    ],
];
