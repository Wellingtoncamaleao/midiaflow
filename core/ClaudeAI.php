<?php

class ClaudeAI
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // Analisa imagem e retorna array com frase, legenda, hashtags, formato_sugerido
    // Fluxo: OpenAI descreve a imagem → WellDev gera conteudo criativo
    public function analyzeImage(string $imagePath): array
    {
        // Passo 1: Vision — descrever a imagem (WellDev nao suporta imagem)
        $descricao = $this->describeImage($imagePath);

        if (!$descricao) {
            error_log('[MidiaFlow] Falha ao descrever imagem com vision');
            return $this->fallback();
        }

        error_log('[MidiaFlow] Descricao da imagem: ' . substr($descricao, 0, 200));

        // Passo 2: WellDev gera conteudo criativo baseado na descricao
        $resultado = $this->gerarConteudo($descricao);

        if ($resultado) return $resultado;

        // Fallback: tenta gerar tudo direto com OpenAI vision
        error_log('[MidiaFlow] WellDev falhou, tentando OpenAI direto');
        return $this->gerarConteudoVision($imagePath) ?: $this->fallback();
    }

    // Passo 1: OpenAI Vision descreve a imagem
    private function describeImage(string $imagePath): string|false
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mediaType = mime_content_type($imagePath) ?: 'image/jpeg';

        $payload = [
            'model'    => 'gpt-4o-mini',
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => ['url' => "data:{$mediaType};base64,{$imageData}"],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Descreva esta imagem em detalhes para um criador de conteudo de Instagram. Inclua: o que aparece na imagem, cores dominantes, emocao/clima, se tem pessoas/produtos/paisagem, orientacao (vertical, horizontal, quadrada). Maximo 150 palavras.',
                        ],
                    ],
                ],
            ],
            'max_tokens' => 300,
        ];

        // Tenta OpenAI primeiro, depois OpenRouter
        $providers = $this->getVisionProviders();

        foreach ($providers as $provider) {
            $response = $this->requestOpenAI($payload, $provider['url'], $provider['key']);
            if ($response) {
                return $response['choices'][0]['message']['content'] ?? false;
            }
        }

        return false;
    }

    // Passo 2: WellDev gera conteudo criativo a partir da descricao
    private function gerarConteudo(string $descricao): array|false
    {
        $welldevUrl = $this->config['welldev_url'] ?? '';
        $welldevKey = $this->config['welldev_key'] ?? '';

        if (!$welldevUrl || !$welldevKey) {
            error_log('[MidiaFlow] WellDev nao configurado, pulando');
            return false;
        }

        $prompt = <<<PROMPT
Voce e um especialista em conteudo para Instagram. Com base na descricao abaixo de uma imagem, retorne um JSON com:

1. "frase" — frase curta e impactante para sobrepor na imagem (max 10 palavras)
2. "legenda" — legenda completa para o post (2-3 paragrafos, tom engajador, com emojis)
3. "hashtags" — 15-20 hashtags relevantes separadas por espaco
4. "formato_sugerido" — melhor formato: "feed" (1:1), "portrait" (4:5), "reel" (9:16) ou "story" (9:16)

Descricao da imagem:
{$descricao}

Responda APENAS com o JSON, sem markdown, sem blocos de codigo, sem texto adicional.
PROMPT;

        $url = rtrim($welldevUrl, '/') . '/api/chat.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-token: ' . $welldevKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'message' => $prompt,
                'model'   => 'claude',
                'app_id'  => 'midiaflow',
                'tool'    => 'geral',
                'sync'    => true,
                'timeout' => 80,
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

        return $this->parseJson($data['response']);
    }

    // Fallback: gerar tudo direto com OpenAI vision (sem WellDev)
    private function gerarConteudoVision(string $imagePath): array|false
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mediaType = mime_content_type($imagePath) ?: 'image/jpeg';

        $prompt = <<<PROMPT
Voce e um especialista em conteudo para Instagram. Analise esta imagem e retorne um JSON com:

1. "frase" — frase curta e impactante para sobrepor na imagem (max 10 palavras)
2. "legenda" — legenda completa para o post (2-3 paragrafos, tom engajador, com emojis)
3. "hashtags" — 15-20 hashtags relevantes separadas por espaco
4. "formato_sugerido" — melhor formato: "feed" (1:1), "portrait" (4:5), "reel" (9:16) ou "story" (9:16)

Responda APENAS com o JSON, sem markdown, sem blocos de codigo, sem texto adicional.
PROMPT;

        $payload = [
            'model'    => 'gpt-4o-mini',
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mediaType};base64,{$imageData}"]],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ],
            'max_tokens' => 1024,
        ];

        $providers = $this->getVisionProviders();

        foreach ($providers as $provider) {
            $response = $this->requestOpenAI($payload, $provider['url'], $provider['key']);
            if ($response) {
                $text = $response['choices'][0]['message']['content'] ?? '';
                $result = $this->parseJson($text);
                if ($result) return $result;
            }
        }

        return false;
    }

    // Retorna lista de providers de vision ordenada por prioridade
    private function getVisionProviders(): array
    {
        $providers = [];

        $openaiKey = $this->config['openai_key'] ?? '';
        if ($openaiKey) {
            $providers[] = [
                'url' => 'https://api.openai.com/v1/chat/completions',
                'key' => $openaiKey,
            ];
        }

        $openrouterKey = $this->config['openrouter_key'] ?? '';
        if ($openrouterKey) {
            $providers[] = [
                'url' => 'https://openrouter.ai/api/v1/chat/completions',
                'key' => $openrouterKey,
            ];
        }

        return $providers;
    }

    // Request generico formato OpenAI
    private function requestOpenAI(array $payload, string $url, string $key): array|false
    {
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

        return json_decode($response, true) ?: false;
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
