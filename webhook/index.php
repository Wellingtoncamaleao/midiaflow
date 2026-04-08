<?php

// ============================================================
// MidiaFlow v2 — Pipeline de reaproveitamento de conteudo
// ============================================================

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', '/dev/stderr');

// Health check
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'ok', 'service' => 'midiaflow', 'version' => '2.0']);
    exit;
}

require_once __DIR__ . '/../core/TelegramBot.php';
require_once __DIR__ . '/../core/ImageProcessor.php';
require_once __DIR__ . '/../core/ClaudeAI.php';
require_once __DIR__ . '/../core/MediaDownloader.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/DalleAI.php';

// Carrega .env (local dev)
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

// Instancias
$bot        = new TelegramBot($config['telegram']['token']);
$processor  = new ImageProcessor($config['formats']);
$claude     = new ClaudeAI($config['ai']);
$downloader = new MediaDownloader($config['storage']['uploads'], $config['cobalt']['url']);
$dalle      = new DalleAI($config['ai']['openai_key']);

Database::setPath($config['database']['path']);

// Le update
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) {
    http_response_code(200);
    exit;
}

// ── Callback de botao ──
if (!empty($update['callback_query'])) {
    handleCallback($update['callback_query'], $bot, $claude, $dalle, $processor, $config);
    http_response_code(200);
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    http_response_code(200);
    exit;
}

$chatId = $message['chat']['id'];
$text   = trim($message['text'] ?? '');

// Filtro de grupo (se configurado)
$groupId = $config['telegram']['group_id'];
if (!empty($groupId) && (string)$chatId !== (string)$groupId) {
    http_response_code(200);
    exit;
}

// ── Comandos ──
if ($text === '/id' || str_starts_with($text, '/id@')) {
    $bot->sendMessage($chatId, "Chat ID: <code>{$chatId}</code>");
    http_response_code(200);
    exit;
}

if ($text === '/criar' || str_starts_with($text, '/criar@')) {
    handleCriar($chatId, $bot);
    http_response_code(200);
    exit;
}

// ── Link recebido ──
$url = extractUrl($text);
if ($url) {
    handleLink($url, $chatId, $bot, $downloader, $config);
}

http_response_code(200);

// ============================================================
// HANDLERS
// ============================================================

function handleLink(string $url, int|string $chatId, TelegramBot $bot, MediaDownloader $downloader, array $config): void
{
    if (!$downloader->isSupported($url)) {
        $bot->sendMessage($chatId, "Link nao suportado.\n\nFunciona com: Instagram, Pinterest, TikTok, Twitter/X, YouTube.");
        return;
    }

    $bot->sendMessage($chatId, "Link recebido! Baixando midia...");
    $mediaPath = $downloader->download($url);

    if (!$mediaPath) {
        $bot->sendMessage($chatId, "Nao consegui baixar esse link.\n\nPostagem privada ou link invalido.");
        return;
    }

    // Cria sessao
    $sessionId = md5($mediaPath . time());
    Database::criarSessao($sessionId, (string)$chatId, $mediaPath, $url);

    // Envia a imagem baixada
    $bot->sendPhoto($chatId, $mediaPath, 'Midia baixada! O que deseja fazer?');

    // Botoes de acao
    $keyboard = [
        [
            ['text' => 'Modelar', 'callback_data' => "action:{$sessionId}:modelar"],
            ['text' => 'Clonar Fundo', 'callback_data' => "action:{$sessionId}:clonar"],
        ],
        [
            ['text' => 'Guardar Texto', 'callback_data' => "action:{$sessionId}:texto"],
        ],
    ];

    $bot->sendMessage($chatId, 'Escolha uma acao:', $keyboard);
}

function handleCallback(array $callback, TelegramBot $bot, ClaudeAI $claude, DalleAI $dalle, ImageProcessor $processor, array $config): void
{
    $chatId          = $callback['message']['chat']['id'];
    $callbackQueryId = $callback['id'];
    $data            = $callback['data'] ?? '';

    $bot->answerCallbackQuery($callbackQueryId);

    error_log("[MidiaFlow] Callback: {$data}");

    // ── Acoes principais: action:{sessionId}:{tipo} ──
    if (str_starts_with($data, 'action:')) {
        [, $sessionId, $tipo] = explode(':', $data, 3);
        $sessao = Database::buscarSessao($sessionId);

        if (!$sessao) {
            $bot->sendMessage($chatId, 'Sessao expirada. Manda o link de novo.');
            return;
        }

        match ($tipo) {
            'modelar' => handleModelar($chatId, $sessao, $bot, $claude, $dalle),
            'clonar'  => handleClonarFundo($chatId, $sessao, $bot, $claude, $dalle),
            'texto'   => handleGuardarTexto($chatId, $sessao, $bot, $claude),
            default   => $bot->sendMessage($chatId, 'Acao desconhecida.'),
        };
        return;
    }

    // ── Aprovar/Refazer: approve:{sessionId} / redo:{sessionId} ──
    if (str_starts_with($data, 'approve:')) {
        $sessionId = substr($data, 8);
        $bot->sendMessage($chatId, 'Imagem aprovada e salva!');
        return;
    }

    if (str_starts_with($data, 'redo:')) {
        $sessionId = substr($data, 5);
        $sessao = Database::buscarSessao($sessionId);
        if ($sessao) {
            handleModelar($chatId, $sessao, $bot, new ClaudeAI($config['ai']), $dalle);
        }
        return;
    }

    // ── /criar: selecao de texto ──
    if (str_starts_with($data, 'seltxt:')) {
        [, $textoId, $offset] = array_pad(explode(':', $data, 3), 3, '0');

        if ($textoId === 'more') {
            // Paginar textos
            mostrarTextos($chatId, $bot, (int)$offset);
            return;
        }

        // Texto selecionado, agora mostrar fundos
        $texto = Database::buscarTexto((int)$textoId);
        if (!$texto) {
            $bot->sendMessage($chatId, 'Texto nao encontrado.');
            return;
        }

        $bot->sendMessage($chatId, "Frase selecionada:\n<i>{$texto['texto']}</i>\n\nAgora escolha um fundo:");
        mostrarFundos($chatId, $bot, 0, (int)$textoId);
        return;
    }

    // ── /criar: selecao de fundo ──
    if (str_starts_with($data, 'selfundo:')) {
        [, $fundoId, $textoId, $offset] = array_pad(explode(':', $data, 4), 4, '0');

        if ($fundoId === 'more') {
            mostrarFundos($chatId, $bot, (int)$offset, (int)$textoId);
            return;
        }

        // Fundo + texto selecionados, gerar imagem
        handleCriarGerar($chatId, (int)$textoId, (int)$fundoId, $bot, $dalle);
        return;
    }
}

// ── MODELAR ──
function handleModelar(int|string $chatId, array $sessao, TelegramBot $bot, ClaudeAI $claude, DalleAI $dalle): void
{
    $bot->sendMessage($chatId, 'Analisando imagem e gerando versao modelada...');

    $descricao = $claude->describeImage($sessao['media_path']);
    if (!$descricao) {
        $bot->sendMessage($chatId, 'Nao consegui analisar a imagem.');
        return;
    }

    // Extrai frase da imagem
    $frase = $claude->extractText($sessao['media_path']);
    if (!$frase || strlen($frase) < 3) {
        $frase = 'Sua melhor versao comeca agora';
    }

    $perfil = Database::perfilPadrao();
    $bot->sendMessage($chatId, "Gerando imagem com DALL-E...\nFrase: <i>{$frase}</i>");

    $imagemPath = $dalle->modelar($descricao, $frase, $perfil['arroba']);

    if (!$imagemPath) {
        $bot->sendMessage($chatId, 'Erro ao gerar imagem. Tente novamente.');
        return;
    }

    $keyboard = [
        [
            ['text' => 'Aprovar', 'callback_data' => "approve:{$sessao['id']}"],
            ['text' => 'Refazer', 'callback_data' => "redo:{$sessao['id']}"],
        ],
    ];

    $bot->sendPhoto($chatId, $imagemPath, "Imagem gerada com {$perfil['arroba']}", $keyboard);
}

// ── CLONAR FUNDO ──
function handleClonarFundo(int|string $chatId, array $sessao, TelegramBot $bot, ClaudeAI $claude, DalleAI $dalle): void
{
    $bot->sendMessage($chatId, 'Analisando imagem e recriando sem texto...');

    $descricao = $claude->describeImage($sessao['media_path']);
    if (!$descricao) {
        $bot->sendMessage($chatId, 'Nao consegui analisar a imagem.');
        return;
    }

    $bot->sendMessage($chatId, 'Gerando fundo limpo com DALL-E...');
    $fundoPath = $dalle->clonarFundo($descricao);

    if (!$fundoPath) {
        $bot->sendMessage($chatId, 'Erro ao gerar fundo. Tente novamente.');
        return;
    }

    // Salva no storage permanente
    $destino = '/var/www/html/data/fundos/' . basename($fundoPath);
    $dir = dirname($destino);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    copy($fundoPath, $destino);

    Database::salvarFundo($destino, $descricao, $sessao['fonte_url']);
    $bot->sendPhoto($chatId, $fundoPath, 'Fundo salvo no banco!');
}

// ── GUARDAR TEXTO ──
function handleGuardarTexto(int|string $chatId, array $sessao, TelegramBot $bot, ClaudeAI $claude): void
{
    $bot->sendMessage($chatId, 'Extraindo texto da imagem...');

    $texto = $claude->extractText($sessao['media_path']);

    if (!$texto || strlen($texto) < 2) {
        $bot->sendMessage($chatId, 'Nao encontrei texto nesta imagem.');
        return;
    }

    Database::salvarTexto($texto, $sessao['fonte_url']);
    $bot->sendMessage($chatId, "Texto salvo!\n\n<i>{$texto}</i>");
}

// ── /CRIAR ──
function handleCriar(int|string $chatId, TelegramBot $bot): void
{
    $total = Database::contarTextos();

    if ($total === 0) {
        $bot->sendMessage($chatId, "Nenhuma frase salva ainda.\n\nMande um link e use [Guardar Texto] primeiro.");
        return;
    }

    $bot->sendMessage($chatId, 'Escolha uma frase:');
    mostrarTextos($chatId, $bot, 0);
}

function mostrarTextos(int|string $chatId, TelegramBot $bot, int $offset): void
{
    $textos = Database::listarTextos(5, $offset);
    $total  = Database::contarTextos();

    if (empty($textos)) {
        $bot->sendMessage($chatId, 'Sem mais frases.');
        return;
    }

    $keyboard = [];
    foreach ($textos as $t) {
        $label = mb_substr($t['texto'], 0, 40);
        if (mb_strlen($t['texto']) > 40) $label .= '...';
        $keyboard[] = [['text' => $label, 'callback_data' => "seltxt:{$t['id']}:0"]];
    }

    if ($offset + 5 < $total) {
        $nextOffset = $offset + 5;
        $keyboard[] = [['text' => 'Outras opcoes →', 'callback_data' => "seltxt:more:{$nextOffset}"]];
    }

    $bot->sendMessage($chatId, 'Frases disponveis:', $keyboard);
}

function mostrarFundos(int|string $chatId, TelegramBot $bot, int $offset, int $textoId): void
{
    $fundos = Database::listarFundos(5, $offset);
    $total  = Database::contarFundos();

    if (empty($fundos)) {
        $bot->sendMessage($chatId, "Nenhum fundo salvo ainda.\n\nMande um link e use [Clonar Fundo] primeiro.");
        return;
    }

    // Envia cada fundo como foto com botao
    foreach ($fundos as $f) {
        $label = $f['descricao'] ? mb_substr($f['descricao'], 0, 30) : "Fundo #{$f['id']}";
        $keyboard = [[['text' => "Selecionar", 'callback_data' => "selfundo:{$f['id']}:{$textoId}:0"]]];

        if (file_exists($f['path_imagem'])) {
            $bot->sendPhoto($chatId, $f['path_imagem'], $label, $keyboard);
        } else {
            $bot->sendMessage($chatId, "Fundo #{$f['id']}: {$label} (arquivo nao encontrado)", $keyboard);
        }
    }

    if ($offset + 5 < $total) {
        $nextOffset = $offset + 5;
        $keyboard = [[['text' => 'Outras opcoes →', 'callback_data' => "selfundo:more:{$textoId}:{$nextOffset}"]]];
        $bot->sendMessage($chatId, 'Mais fundos:', $keyboard);
    }
}

function handleCriarGerar(int|string $chatId, int $textoId, int $fundoId, TelegramBot $bot, DalleAI $dalle): void
{
    $texto = Database::buscarTexto($textoId);
    $fundo = Database::buscarFundo($fundoId);

    if (!$texto || !$fundo) {
        $bot->sendMessage($chatId, 'Texto ou fundo nao encontrado.');
        return;
    }

    $perfil = Database::perfilPadrao();
    $bot->sendMessage($chatId, "Gerando imagem...\nFrase: <i>{$texto['texto']}</i>");

    $imagemPath = $dalle->criar($fundo['descricao'], $texto['texto'], $perfil['arroba']);

    if (!$imagemPath) {
        $bot->sendMessage($chatId, 'Erro ao gerar imagem. Tente novamente.');
        return;
    }

    $keyboard = [
        [
            ['text' => 'Aprovar', 'callback_data' => "approve:criar"],
            ['text' => 'Refazer', 'callback_data' => "selfundo:{$fundoId}:{$textoId}:0"],
        ],
    ];

    $bot->sendPhoto($chatId, $imagemPath, "Criada com {$perfil['arroba']}", $keyboard);
}

// ============================================================
// HELPERS
// ============================================================

function extractUrl(string $text): string|false
{
    preg_match('/(https?:\/\/[^\s]+)/', $text, $matches);
    return $matches[1] ?? false;
}
