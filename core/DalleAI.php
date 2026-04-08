<?php

class DalleAI
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    // Gera imagem com DALL-E 3 baseado num prompt
    // Retorna path do arquivo salvo ou false
    public function generate(string $prompt, string $outputPath, string $size = '1024x1024'): string|false
    {
        $payload = [
            'model'  => 'dall-e-3',
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
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
            error_log('[MidiaFlow] DALL-E curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[MidiaFlow] DALL-E error HTTP {$httpCode}: " . substr($response, 0, 300));
            return false;
        }

        $data = json_decode($response, true);
        $imageUrl = $data['data'][0]['url'] ?? null;

        if (!$imageUrl) {
            error_log('[MidiaFlow] DALL-E sem URL na resposta');
            return false;
        }

        // Baixa a imagem gerada
        return $this->downloadImage($imageUrl, $outputPath);
    }

    // Modelar: recria imagem similar com frase e @perfil
    public function modelar(string $descricaoImagem, string $frase, string $perfil): string|false
    {
        $prompt = <<<PROMPT
Crie uma imagem artistica para Instagram com estas caracteristicas:

Estilo visual inspirado em: {$descricaoImagem}

A imagem deve conter o texto "{$frase}" escrito de forma legivel e estilosa, integrado ao visual.
No canto inferior direito, incluir discretamente o texto "{$perfil}".

A imagem deve ser impactante, com cores vibrantes e composicao profissional.
Nao incluir bordas, molduras ou elementos de interface. Apenas a arte.
PROMPT;

        $outputPath = sys_get_temp_dir() . '/midiaflow_modelar_' . md5(time() . rand()) . '.png';
        return $this->generate($prompt, $outputPath);
    }

    // Clonar fundo: recria imagem sem texto
    public function clonarFundo(string $descricaoImagem): string|false
    {
        $prompt = <<<PROMPT
Recrie esta cena como uma imagem limpa SEM NENHUM TEXTO, SEM letras, SEM palavras, SEM watermarks:

{$descricaoImagem}

A imagem deve manter o mesmo estilo visual, cores e composicao, mas completamente limpa — apenas o cenario/fundo, sem qualquer elemento textual.
PROMPT;

        $outputPath = sys_get_temp_dir() . '/midiaflow_fundo_' . md5(time() . rand()) . '.png';
        return $this->generate($prompt, $outputPath);
    }

    // Criar: combina fundo + frase do banco
    public function criar(string $descricaoFundo, string $frase, string $perfil): string|false
    {
        $prompt = <<<PROMPT
Crie uma imagem artistica para Instagram com estas caracteristicas:

Cenario/fundo: {$descricaoFundo}

A imagem deve conter o texto "{$frase}" escrito de forma legivel e estilosa, integrado ao visual.
No canto inferior direito, incluir discretamente o texto "{$perfil}".

Composicao profissional, cores vibrantes, impactante. Sem bordas ou molduras.
PROMPT;

        $outputPath = sys_get_temp_dir() . '/midiaflow_criar_' . md5(time() . rand()) . '.png';
        return $this->generate($prompt, $outputPath);
    }

    // Baixa imagem de URL pra disco
    private function downloadImage(string $url, string $outputPath): string|false
    {
        $ch = curl_init($url);
        $fp = fopen($outputPath, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200 || !file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            return false;
        }

        return $outputPath;
    }
}
