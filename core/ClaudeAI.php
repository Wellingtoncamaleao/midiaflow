<?php

class ClaudeAI
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    // Analisa imagem e retorna array com frase, legenda, hashtags, formato_sugerido
    public function analyzeImage(string $imagePath): array
    {
        $imageData   = base64_encode(file_get_contents($imagePath));
        $mediaType   = mime_content_type($imagePath) ?: 'image/jpeg';

        $prompt = <<<PROMPT
Voce e um especialista em conteudo para Instagram. Analise esta imagem e retorne um JSON com:

1. "frase" — uma frase curta e impactante para sobrepor na imagem (max 10 palavras)
2. "legenda" — legenda completa para o post (2-3 paragrafos, tom engajador, com emojis)
3. "hashtags" — 15-20 hashtags relevantes separadas por espaco
4. "formato_sugerido" — o melhor formato para essa imagem: "feed" (1:1), "portrait" (4:5), "reel" (9:16) ou "story" (9:16)

Responda APENAS com o JSON, sem markdown, sem blocos de codigo, sem texto adicional.
PROMPT;

        $payload = [
            'model'      => $this->model,
            'max_tokens' => 1024,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'         => 'base64',
                                'media_type'   => $mediaType,
                                'data'         => $imageData,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->request($payload);

        if (!$response) {
            return $this->fallback();
        }

        $text = $response['content'][0]['text'] ?? '';

        // Remove possivel markdown wrapping (```json ... ```)
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (!$parsed || !isset($parsed['frase'])) {
            error_log('Claude retornou resposta nao-parseavel: ' . $text);
            return $this->fallback();
        }

        return $parsed;
    }

    // Chamada pra API da Anthropic
    private function request(array $payload): array|false
    {
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('Claude API curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Claude API error HTTP {$httpCode}: " . $response);
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
