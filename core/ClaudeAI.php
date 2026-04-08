<?php

class ClaudeAI
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // Analisa imagem e retorna array com frase, legenda, hashtags, formato_sugerido
    // Prioridade: WellDev (com image_url) → OpenAI → OpenRouter
    public function analyzeImage(string $imagePath): array
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mediaType = mime_content_type($imagePath) ?: 'image/jpeg';
        $dataUrl   = "data:{$mediaType};base64,{$imageData}";

        $prompt = <<<PROMPT
Voce e um especialista em conteudo para Instagram. Analise esta imagem e retorne um JSON com:

1. "frase" — frase curta e impactante para sobrepor na imagem (max 10 palavras)
2. "legenda" — legenda completa para o post (2-3 paragrafos, tom engajador, com emojis)
3. "hashtags" — 15-20 hashtags relevantes separadas por espaco
4. "formato_sugerido" — melhor formato: "feed" (1:1), "portrait" (4:5), "reel" (9:16) ou "story" (9:16)

Responda APENAS com o JSON, sem markdown, sem blocos de codigo, sem texto adicional.
PROMPT;

        // 1. WellDev (com image_url — usa OpenAI/OpenRouter por tras)
        $result = $this->tryWellDev($prompt, $dataUrl);
        if ($result) return $result;

        // 2. OpenAI direto
        $result = $this->tryOpenAI($prompt, $dataUrl);
        if ($result) return $result;

        // 3. OpenRouter direto
        $result = $this->tryOpenRouter($prompt, $dataUrl);
        if ($result) return $result;

        return $this->fallback();
    }

    // Tenta via WellDev (com image_url)
    private function tryWellDev(string $prompt, string $dataUrl): array|false
    {
        $url = $this->config['welldev_url'] ?? '';
        $key = $this->config['welldev_key'] ?? '';

        if (!$url || !$key) return false;

        $endpoint = rtrim($url, '/') . '/api/chat.php';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-token: ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'message'   => $prompt,
                'model'     => 'openai',
                'app_id'    => 'midiaflow',
                'tool'      => 'geral',
                'sync'      => true,
                'timeout'   => 80,
                'image_url' => $dataUrl,
                'fallback'  => false,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[MidiaFlow] WellDev curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[MidiaFlow] WellDev error HTTP {$httpCode}: " . substr($response, 0, 300));
            return false;
        }

        $data = json_decode($response, true);

        if (empty($data['ok']) || empty($data['response'])) {
            error_log('[MidiaFlow] WellDev resposta invalida: ' . substr($response, 0, 300));
            return false;
        }

        error_log('[MidiaFlow] WellDev respondeu via model=' . ($data['model'] ?? '?'));
        return $this->parseJson($data['response']);
    }

    // Tenta OpenAI direto
    private function tryOpenAI(string $prompt, string $dataUrl): array|false
    {
        $key = $this->config['openai_key'] ?? '';
        if (!$key) return false;

        error_log('[MidiaFlow] Tentando OpenAI direto');
        return $this->callVisionAPI(
            'https://api.openai.com/v1/chat/completions',
            $key,
            'gpt-4o-mini',
            $prompt,
            $dataUrl
        );
    }

    // Tenta OpenRouter direto
    private function tryOpenRouter(string $prompt, string $dataUrl): array|false
    {
        $key = $this->config['openrouter_key'] ?? '';
        if (!$key) return false;

        error_log('[MidiaFlow] Tentando OpenRouter direto');
        return $this->callVisionAPI(
            'https://openrouter.ai/api/v1/chat/completions',
            $key,
            'anthropic/claude-sonnet-4-6',
            $prompt,
            $dataUrl
        );
    }

    // Chamada generica pra API formato OpenAI com vision
    private function callVisionAPI(string $url, string $key, string $model, string $prompt, string $dataUrl): array|false
    {
        $payload = [
            'model'    => $model,
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ],
            'max_tokens' => 1024,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[MidiaFlow] Vision curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[MidiaFlow] Vision error HTTP {$httpCode}: " . substr($response, 0, 300));
            return false;
        }

        $data = json_decode($response, true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        return $this->parseJson($text);
    }

    // Extrai texto/frase de uma imagem via OCR (GPT-4o vision)
    public function extractText(string $imagePath): string|false
    {
        $dataUrl = $this->imageToDataUrl($imagePath);

        $prompt = 'Extraia TODO o texto visivel nesta imagem. Retorne apenas o texto, sem explicacoes.';

        return $this->visionRequest($prompt, $dataUrl);
    }

    // Descreve a imagem em detalhes (pra usar no DALL-E)
    public function describeImage(string $imagePath): string|false
    {
        $dataUrl = $this->imageToDataUrl($imagePath);

        $prompt = 'Descreva esta imagem em detalhes para recriar algo similar com IA generativa. Inclua: cenario, cores dominantes, estilo artistico, composicao, iluminacao, elementos visuais. NAO inclua arrobas (@), marcas dagua ou creditos na descricao — ignore esses elementos. Se tiver frase/texto motivacional, inclua o texto. Maximo 200 palavras.';

        return $this->visionRequest($prompt, $dataUrl);
    }

    // Request de vision que retorna texto puro (nao JSON)
    private function visionRequest(string $prompt, string $dataUrl): string|false
    {
        // Tenta WellDev primeiro
        $url = $this->config['welldev_url'] ?? '';
        $key = $this->config['welldev_key'] ?? '';

        if ($url && $key) {
            $endpoint = rtrim($url, '/') . '/api/chat.php';
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-token: ' . $key,
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'message'   => $prompt,
                    'model'     => 'openai',
                    'app_id'    => 'midiaflow',
                    'sync'      => true,
                    'timeout'   => 80,
                    'image_url' => $dataUrl,
                    'fallback'  => false,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['ok']) && !empty($data['response'])) {
                    return $data['response'];
                }
            }
        }

        // Fallback: OpenAI direto
        $openaiKey = $this->config['openai_key'] ?? '';
        if ($openaiKey) {
            $result = $this->callVisionAPI(
                'https://api.openai.com/v1/chat/completions',
                $openaiKey, 'gpt-4o-mini', $prompt, $dataUrl
            );
            if ($result) {
                return $result['choices'][0]['message']['content'] ?? false;
            }
        }

        return false;
    }

    // Converte imagem em data URL base64
    private function imageToDataUrl(string $imagePath): string
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mediaType = mime_content_type($imagePath) ?: 'image/jpeg';
        return "data:{$mediaType};base64,{$imageData}";
    }

    // Extrai JSON de uma string (remove markdown wrapping)
    private function parseJson(string $text): array|false
    {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (!$parsed || !isset($parsed['frase'])) {
            error_log('[MidiaFlow] JSON nao-parseavel: ' . substr($text, 0, 300));
            return false;
        }

        return $parsed;
    }

    // Fallback quando tudo falha
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
