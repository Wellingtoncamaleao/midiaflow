<?php

// ============================================================
// MídiaFlow — Webhook Telegram v2
// Entrada: link do Instagram (ou outra rede)
// Saída: imagem processada + legenda pronta
// ============================================================

// Log de erros visivel no container
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', '/dev/stderr');

// Health check — GET retorna 200 sem processar nada
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'ok', 'service' => 'midiaflow']);
    exit;
}

require_once __DIR__ . '/../core/TelegramBot.php';
require_once __DIR__ . '/../core/ImageProcessor.php';
require_once __DIR__ . '/../core/ClaudeAI.php';
require_once __DIR__ . '/../core/MediaDownloader.php';

// Carrega .env (arquivo local) — no Docker as vars ja vem via environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

error_log('[MidiaFlow] Config carregado. Token: ' . (empty($config['telegram']['token']) ? 'VAZIO' : 'ok') . ' GroupID: ' . ($config['telegram']['group_id'] ?: 'VAZIO'));

// Instancia dependências
$bot        = new TelegramBot($config['telegram']['token']);
$processor  = new ImageProcessor($config['formats']);
$claude     = new ClaudeAI($config['claude']['api_key'], $config['claude']['model']);
$downloader = new MediaDownloader($config['storage']['uploads'], $config['cobalt']['url']);

// Lê update do Telegram
$raw = file_get_contents('php://input');
error_log('[MidiaFlow] Update recebido: ' . substr($raw, 0, 500));

$update = json_decode($raw, true);

if (!$update) {
    error_log('[MidiaFlow] Update vazio ou invalido');
    http_response_code(200);
    exit;
}

// ── Callback de botão inline (escolha de formato) ──
if (!empty($update['callback_query'])) {
    handleCallback($update['callback_query'], $bot, $processor, $config);
    http_response_code(200);
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chatId  = $message['chat']['id'];
$groupId = $config['telegram']['group_id'];
$text    = trim($message['text'] ?? '');

// Comando /id — responde com o chat ID (util pra configurar TELEGRAM_GROUP_ID)
if ($text === '/id' || $text === '/id@' . ($bot->getUsername() ?? '')) {
    $bot->sendMessage($chatId, "Chat ID: <code>{$chatId}</code>\n\nColoque esse valor na variavel TELEGRAM_GROUP_ID.");
    http_response_code(200);
    exit;
}

error_log("[MidiaFlow] chatId={$chatId} groupId={$groupId} text={$text}");

// Se group_id nao esta configurado, aceita qualquer grupo (e avisa o ID)
if (!empty($groupId) && (string)$chatId !== (string)$groupId) {
    error_log("[MidiaFlow] Ignorado — chatId nao bate com groupId");
    http_response_code(200);
    exit;
}

// Detecta URL na mensagem
$url = extractUrl($text);

if ($url) {
    handleLink($url, $chatId, $bot, $downloader, $claude, $config);
}

http_response_code(200);

// ============================================================
// HANDLERS
// ============================================================

function handleLink(string $url, int|string $chatId, TelegramBot $bot, MediaDownloader $downloader, ClaudeAI $claude, array $config): void
{
    if (!$downloader->isSupported($url)) {
        $bot->sendMessage($chatId, "❌ Link não suportado.\n\nFunciona com: Instagram, Pinterest, TikTok, Twitter/X, YouTube.");
        return;
    }

    $bot->sendMessage($chatId, "🔗 Link recebido! Baixando mídia...\n<i>{$url}</i>");

    $mediaPath = $downloader->download($url);

    if (!$mediaPath) {
        $bot->sendMessage($chatId, "❌ Não consegui baixar esse link.\n\nPossíveis causas:\n• Post privado\n• Link inválido\n• Instagram bloqueou temporariamente");
        return;
    }

    $bot->sendMessage($chatId, '🧠 Mídia baixada! Analisando com IA...');

    $analysis = $claude->analyzeImage($mediaPath);

    $fileId   = pathinfo($mediaPath, PATHINFO_FILENAME);
    $metaPath = $config['storage']['uploads'] . $fileId . '.json';

    file_put_contents($metaPath, json_encode([
        'file_id'    => $fileId,
        'media_path' => $mediaPath,
        'source_url' => $url,
        'analysis'   => $analysis,
        'created_at' => date('Y-m-d H:i:s'),
    ]));

    $frase   = $analysis['frase'] ?? '';
    $formato = $analysis['formato_sugerido'] ?? 'feed';

    $msg  = "✨ <b>Análise pronta!</b>\n\n";
    $msg .= "💬 <b>Frase sugerida:</b>\n<i>{$frase}</i>\n\n";
    $msg .= "📐 <b>Formato sugerido:</b> {$formato}\n\n";
    $msg .= "Escolha o formato de saída:";

    $keyboard = [
        [
            ['text' => '⬜ Feed (1:1)',     'callback_data' => "format:{$fileId}:feed"],
            ['text' => '📱 Reel (9:16)',    'callback_data' => "format:{$fileId}:reel"],
        ],
        [
            ['text' => '🖼 Portrait (4:5)', 'callback_data' => "format:{$fileId}:portrait"],
            ['text' => '📖 Story (9:16)',   'callback_data' => "format:{$fileId}:story"],
        ],
    ];

    $bot->sendMessage($chatId, $msg, $keyboard);
}

function handleCallback(array $callback, TelegramBot $bot, ImageProcessor $processor, array $config): void
{
    $chatId          = $callback['message']['chat']['id'];
    $callbackQueryId = $callback['id'];
    $data            = $callback['data'];

    $bot->answerCallbackQuery($callbackQueryId, '⏳ Processando...');

    if (!str_starts_with($data, 'format:')) return;

    [, $fileId, $formato] = explode(':', $data, 3);

    $metaPath = $config['storage']['uploads'] . $fileId . '.json';

    if (!file_exists($metaPath)) {
        $bot->sendMessage($chatId, '❌ Sessão expirada. Manda o link de novo.');
        return;
    }

    $meta      = json_decode(file_get_contents($metaPath), true);
    $mediaPath = $meta['media_path'];
    $analysis  = $meta['analysis'];

    $outputPath = $config['storage']['processed'] . $fileId . "_{$formato}.jpg";
    $success    = $processor->process($mediaPath, $outputPath, $formato);

    if (!$success) {
        $bot->sendMessage($chatId, '❌ Erro ao processar imagem. Tenta de novo.');
        return;
    }

    $legenda  = $analysis['legenda'] ?? '';
    $hashtags = $analysis['hashtags'] ?? '';
    $caption  = "✅ <b>Pronta para postar!</b>\n\n📋 <b>Legenda:</b>\n{$legenda}\n\n{$hashtags}";

    $bot->sendPhoto($chatId, $outputPath, $caption);
}

// ============================================================
// HELPERS
// ============================================================

function extractUrl(string $text): string|false
{
    preg_match('/(https?:\/\/[^\s]+)/', $text, $matches);
    return $matches[1] ?? false;
}
