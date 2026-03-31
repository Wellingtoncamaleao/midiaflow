<?php

class TelegramBot
{
    private string $token;
    private string $apiUrl;

    public function __construct(string $token)
    {
        $this->token  = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}";
    }

    // Envia mensagem de texto
    public function sendMessage(string|int $chatId, string $text, array $keyboard = []): array
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if (!empty($keyboard)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ]);
        }

        return $this->request('sendMessage', $payload);
    }

    // Envia foto com legenda opcional
    public function sendPhoto(string|int $chatId, string $filePath, string $caption = '', array $keyboard = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if (!empty($keyboard)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ]);
        }

        $ch = curl_init("{$this->apiUrl}/sendPhoto");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge($payload, [
            'photo' => new CURLFile($filePath),
        ]));

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    // Responde callback de botão inline
    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);
    }

    // Baixa arquivo do Telegram
    public function downloadFile(string $fileId, string $destPath): bool
    {
        $fileInfo = $this->request('getFile', ['file_id' => $fileId]);

        if (empty($fileInfo['result']['file_path'])) {
            return false;
        }

        $url      = "https://api.telegram.org/file/bot{$this->token}/{$fileInfo['result']['file_path']}";
        $content  = file_get_contents($url);

        if ($content === false) return false;

        file_put_contents($destPath, $content);
        return true;
    }

    // Faz request genérico pra API do Telegram
    private function request(string $method, array $data = []): array
    {
        $ch = curl_init("{$this->apiUrl}/{$method}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }
}
