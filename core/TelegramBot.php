<?php

class TelegramBot
{
    private string $token;
    private string $apiBase;

    public function __construct(string $token)
    {
        $this->token   = $token;
        $this->apiBase = "https://api.telegram.org/bot{$token}";
    }

    // Retorna username do bot (lazy load)
    public function getUsername(): ?string
    {
        static $username = null;
        if ($username === null) {
            $me = $this->request('getMe', []);
            $username = $me['username'] ?? null;
        }
        return $username;
    }

    // Envia mensagem de texto (com HTML parse mode)
    // $keyboard = array de inline buttons (opcional)
    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): array|false
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ]);
        }

        return $this->request('sendMessage', $params);
    }

    // Envia foto com legenda
    public function sendPhoto(int|string $chatId, string $photoPath, string $caption = ''): array|false
    {
        $params = [
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'caption'    => $caption,
            'photo'      => new CURLFile($photoPath, 'image/jpeg'),
        ];

        return $this->request('sendPhoto', $params, true);
    }

    // Responde callback query (remove "carregando" do botao)
    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array|false
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);
    }

    // Edita mensagem existente
    public function editMessage(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): array|false
    {
        $params = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ]);
        }

        return $this->request('editMessageText', $params);
    }

    // Deleta mensagem
    public function deleteMessage(int|string $chatId, int $messageId): array|false
    {
        return $this->request('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    // Chamada generica pra API do Telegram
    private function request(string $method, array $params, bool $multipart = false): array|false
    {
        $ch = curl_init("{$this->apiBase}/{$method}");

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('Telegram API curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $data = json_decode($response, true);

        if (!$data || !($data['ok'] ?? false)) {
            error_log("Telegram API error [{$method}]: " . ($response ?: 'empty response'));
            return false;
        }

        return $data['result'] ?? $data;
    }
}
