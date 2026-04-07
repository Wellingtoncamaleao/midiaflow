<?php

// Helper: busca env var de qualquer fonte (getenv, $_ENV, $_SERVER)
function env(string $key, string $default = ''): string
{
    return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? $default));
}

return [
    'telegram' => [
        'token'      => env('TELEGRAM_BOT_TOKEN'),
        'group_id'   => env('TELEGRAM_GROUP_ID'),
    ],
    'claude' => [
        'api_key'    => env('OPENROUTER_API_KEY'),
        'model'      => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4-6'),
    ],
    'instagram' => [
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        'account_id'   => env('INSTAGRAM_ACCOUNT_ID'),
    ],
    'storage' => [
        'uploads'   => __DIR__ . '/../storage/uploads/',
        'processed' => __DIR__ . '/../storage/processed/',
        'queue'     => __DIR__ . '/../storage/queue/',
    ],
    'cobalt' => [
        'url' => env('COBALT_URL', 'http://cobalt:9000'),
    ],
    // Formatos de saída suportados
    'formats' => [
        'feed'    => ['width' => 1080, 'height' => 1080],   // quadrado
        'reel'    => ['width' => 1080, 'height' => 1920],   // vertical
        'story'   => ['width' => 1080, 'height' => 1920],   // vertical
        'portrait'=> ['width' => 1080, 'height' => 1350],   // retrato
    ],
];
