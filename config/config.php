<?php

return [
    'telegram' => [
        'token'      => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
        'group_id'   => $_ENV['TELEGRAM_GROUP_ID'] ?? '',  // ID do grupo onde vc joga os conteúdos
    ],
    'claude' => [
        'api_key'    => $_ENV['CLAUDE_API_KEY'] ?? '',
        'model'      => 'claude-sonnet-4-20250514',
    ],
    'instagram' => [
        // Fase 3 — por enquanto vazio
        'access_token' => $_ENV['INSTAGRAM_ACCESS_TOKEN'] ?? '',
        'account_id'   => $_ENV['INSTAGRAM_ACCOUNT_ID'] ?? '',
    ],
    'storage' => [
        'uploads'   => __DIR__ . '/../storage/uploads/',
        'processed' => __DIR__ . '/../storage/processed/',
        'queue'     => __DIR__ . '/../storage/queue/',
    ],
    'ytdlp' => [
        'bin' => $_ENV['YTDLP_BIN'] ?? 'yt-dlp',  // caminho do binário no VPS
    ],
    // Formatos de saída suportados
    'formats' => [
        'feed'    => ['width' => 1080, 'height' => 1080],   // quadrado
        'reel'    => ['width' => 1080, 'height' => 1920],   // vertical
        'story'   => ['width' => 1080, 'height' => 1920],   // vertical
        'portrait'=> ['width' => 1080, 'height' => 1350],   // retrato
    ],
];
