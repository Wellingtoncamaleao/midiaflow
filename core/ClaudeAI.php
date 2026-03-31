<?php

class ClaudeAI
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    // Analisa imagem e sugere legenda + hashtags para Instituto Haux
    public function analyzeImage(string $imagePath): array
    {
        $imageData  = base64_encode(file_get_contents($imagePath));
        $mimeType   = mime_content_type($imagePath);

        $prompt = <<<PROMPT
Você é o assistente de conteúdo do Instituto Haux, uma marca de numerologia e espiritualidade brasileira.

Analise esta imagem e crie:
1. Uma frase impactante e espiritual (máximo 15 palavras) que combine com a imagem
2. Uma legenda completa para Instagram (máximo 150 palavras) no estilo místico/numerológico
3. 10 hashtags relevantes em português sobre numerologia/espiritualidade
4. Sugestão de melhor formato: "feed", "reel", "story" ou "portrait"

Responda SOMENTE em JSON válido, sem markdown, exatamente assim:
{
  "frase": "...",
  "legenda": "...",
  "hashtags": "#numerologia #...",
  "formato_sugerido": "feed"
}
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
                                'type'       => 'base64',
                                'media_type' => $mimeType,
                                'data'       => $imageData,
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

        if (empty($response['content'][0]['text'])) {
            return $this->fallback();
        }

        $json = json_decode($response['content'][0]['text'], true);

        return $json ?? $this->fallback();
    }

    // Faz request para a API do Claude
    private function request(array $payload): array
    {
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    private function fallback(): array
    {
        return [
            'frase'            => 'Os números revelam o que os olhos não veem.',
            'legenda'          => 'A numerologia é a linguagem do universo. Cada número carrega uma vibração única que guia o seu caminho. ✨',
            'hashtags'         => '#numerologia #espiritualidade #institutohaux #numerologiabrasileira #autoconhecimento',
            'formato_sugerido' => 'feed',
        ];
    }
}
