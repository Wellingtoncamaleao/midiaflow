<?php

class ClaudeAI
{
    private string $apiUrl;
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'openrouter')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;

        // Detecta qual API usar baseado no modelo
        if ($model === 'openrouter' || str_contains($model, '/')) {
            $this->apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
            if ($model === 'openrouter') {
                $this->model = 'anthropic/claude-sonnet-4-6';
            }
        } else {
            // Well-Dev API (texto, sem vision)
            $this->apiUrl = rtrim($apiKey, '/') . '/api/chat.php';
        }
    }

    // Analisa imagem e retorna array com frase, legenda, hashtags, formato_sugerido
    public function analyzeImage(string $imagePath): array
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mediaType = mime_content_type($imagePath) ?: 'image/jpeg';

        $prompt = <<<PROMPT
Voce e um especialista em conteudo para Instagram. Analise esta imagem e retorne um JSON com:

1. "frase" — uma frase curta e impactante para sobrepor na imagem (max 10 palavras)
2. "legenda" — legenda completa para o post (2-3 paragrafos, tom engajador, com emojis)
3. "hashtags" — 15-20 hashtags relevantes separadas por espaco
4. "formato_sugerido" — o melhor formato para essa imagem: "feed" (1:1), "portrait" (4:5), "reel" (9:16) ou "story" (9:16)

Responda APENAS com o JSON, sem markdown, sem blocos de codigo, sem texto adicional.
PROMPT;

        // OpenRouter (formato OpenAI com vision)
        $payload = [
            'model'    => $this->model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mediaType};base64,{$imageData}",
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
            'max_tokens' => 1024,
        ];

        $response = $this->request($payload);

        if (!$response) {
            return $this->fallback();
        }

        $text = $response['choices'][0]['message']['content'] ?? '';

        // Remove possivel markdown wrapping (```json ... ```)
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (!$parsed || !isset($parsed['frase'])) {
            error_log('[MidiaFlow] Resposta nao-parseavel: ' . substr($text, 0, 300));
            return $this->fallback();
        }

        return $parsed;
    }

    // Chamada pra OpenRouter API (formato OpenAI)
    private function request(array $payload): array|false
    {
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[MidiaFlow] API curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[MidiaFlow] API error HTTP {$httpCode}: " . substr($response, 0, 300));
            return false;
        }

        return json_decode($response, true) ?: false;
    }

    // Fallback quando a API falha
    private function fallback(): array
    {
        return [
            'frase'            => '',
            'legenda'          => '(Analise indisponivel — edite a legenda manualmente)',
            'hashtags'         => '',
            'formato_sugerido' => 'feed',
        ];
    }
}
