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
Crie uma imagem artistica para Instagram. TUDO EM PORTUGUES BRASILEIRO.

Estilo visual: inspire-se nesta descricao de referencia, mas crie algo ORIGINAL (NAO copie arrobas, marcas dagua ou creditos da referencia):
{$descricaoImagem}

REGRAS OBRIGATORIAS:
1. Escreva EXATAMENTE este texto em PORTUGUES na imagem, de forma legivel e estilosa: "{$frase}"
2. No canto inferior direito, escreva EXATAMENTE: "{$perfil}" (e NENHUM outro arroba)
3. NAO inclua nenhum outro texto, arroba, marca dagua ou credito alem dos dois acima
4. O texto deve estar em PORTUGUES BRASILEIRO, nunca em ingles
5. Composicao profissional, cores vibrantes, impactante
6. Sem bordas, molduras ou elementos de interface
PROMPT;

        $outputPath = sys_get_temp_dir() . '/midiaflow_modelar_' . md5(time() . rand()) . '.png';
        return $this->generate($prompt, $outputPath);
    }

    // Clonar fundo: recria imagem sem texto
    public function clonarFundo(string $descricaoImagem): string|false
    {
        $prompt = <<<PROMPT
Recrie esta cena como uma imagem LIMPA, SEM NENHUM TEXTO, SEM letras, SEM palavras, SEM arrobas, SEM marcas dagua, SEM creditos:

{$descricaoImagem}

REGRAS:
1. ZERO texto na imagem — apenas o cenario/fundo
2. Manter o mesmo estilo visual, cores e composicao
3. Imagem limpa e profissional, pronta pra usar como template
PROMPT;

        $outputPath = sys_get_temp_dir() . '/midiaflow_fundo_' . md5(time() . rand()) . '.png';
        return $this->generate($prompt, $outputPath);
    }

    // Criar: combina fundo + frase do banco
    public function criar(string $descricaoFundo, string $frase, string $perfil): string|false
    {
        $prompt = <<<PROMPT
Crie uma imagem artistica para Instagram. TUDO EM PORTUGUES BRASILEIRO.

Cenario/fundo baseado em: {$descricaoFundo}

REGRAS OBRIGATORIAS:
1. Escreva EXATAMENTE este texto em PORTUGUES na imagem, de forma legivel e estilosa: "{$frase}"
2. No canto inferior direito, escreva EXATAMENTE: "{$perfil}" (e NENHUM outro arroba)
3. NAO inclua nenhum outro texto, arroba, marca dagua ou credito
4. Composicao profissional, cores vibrantes, impactante
5. Sem bordas, molduras ou elementos de interface
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
