<?php

class MediaDownloader
{
    private string $uploadsPath;
    private string $cobaltUrl;

    public function __construct(string $uploadsPath, string $cobaltUrl)
    {
        $this->uploadsPath = rtrim($uploadsPath, '/') . '/';
        $this->cobaltUrl   = rtrim($cobaltUrl, '/');
    }

    // Baixa a midia via Cobalt API
    // Retorna caminho do arquivo baixado ou false
    public function download(string $url): string|false
    {
        $fileId = md5($url . time());

        // Passo 1: pedir URL de download ao Cobalt
        $mediaUrl = $this->cobaltRequest($url);

        if (!$mediaUrl) {
            error_log("[MidiaFlow] Cobalt falhou para: {$url}");
            return false;
        }

        // Passo 2: baixar o arquivo de midia
        $filePath = $this->downloadFile($mediaUrl, $fileId);

        if (!$filePath) {
            error_log("[MidiaFlow] Download do arquivo falhou: {$mediaUrl}");
            return false;
        }

        // Se for video, extrai primeiro frame como imagem
        if ($this->isVideo($filePath)) {
            $imagePath = $this->uploadsPath . $fileId . '_thumb.jpg';
            $extracted = $this->extractFrame($filePath, $imagePath);

            if (!$extracted) {
                error_log("[MidiaFlow] Falha ao extrair frame do video");
                return false;
            }

            unlink($filePath);
            return $imagePath;
        }

        return $filePath;
    }

    // Chama a API do Cobalt pra obter URL de download
    private function cobaltRequest(string $url): string|false
    {
        $ch = curl_init($this->cobaltUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'url'              => $url,
                'downloadMode'     => 'auto',
                'filenameStyle'    => 'basic',
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[MidiaFlow] Cobalt curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        $data = json_decode($response, true);

        if (!$data) {
            error_log("[MidiaFlow] Cobalt resposta invalida HTTP {$httpCode}: " . substr($response, 0, 300));
            return false;
        }

        // Cobalt retorna status: "redirect" (link direto), "tunnel" (proxy), "picker" (multiplas midias)
        $status = $data['status'] ?? '';

        if ($status === 'redirect' || $status === 'tunnel') {
            return $data['url'] ?? false;
        }

        if ($status === 'picker' && !empty($data['picker'])) {
            // Multiplas midias (carousel) — pega a primeira
            return $data['picker'][0]['url'] ?? false;
        }

        if ($status === 'error') {
            error_log('[MidiaFlow] Cobalt error: ' . ($data['error']['code'] ?? 'unknown'));
            return false;
        }

        error_log("[MidiaFlow] Cobalt status inesperado: {$status} — " . substr($response, 0, 300));
        return false;
    }

    // Baixa arquivo de uma URL pra disco
    private function downloadFile(string $url, string $fileId): string|false
    {
        $ch = curl_init($url);

        // Primeiro faz HEAD pra descobrir content-type
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        // Determina extensao pelo content-type
        $ext = match (true) {
            str_contains($contentType, 'jpeg')  => 'jpg',
            str_contains($contentType, 'png')   => 'png',
            str_contains($contentType, 'webp')  => 'webp',
            str_contains($contentType, 'gif')   => 'gif',
            str_contains($contentType, 'mp4')   => 'mp4',
            str_contains($contentType, 'webm')  => 'webm',
            default                             => 'jpg',
        };

        $filePath = $this->uploadsPath . $fileId . '.' . $ext;

        // Baixa o arquivo
        $ch = curl_init($url);
        $fp = fopen($filePath, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if ($error || $httpCode !== 200 || !file_exists($filePath) || filesize($filePath) === 0) {
            error_log("[MidiaFlow] Download falhou HTTP {$httpCode}: {$error}");
            @unlink($filePath);
            return false;
        }

        return $filePath;
    }

    // Verifica se o arquivo e video pela extensao
    private function isVideo(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv']);
    }

    // Extrai o primeiro frame do video usando ffmpeg
    private function extractFrame(string $videoPath, string $outputPath): bool
    {
        $cmd = sprintf(
            'ffmpeg -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );

        exec($cmd, $out, $code);
        return $code === 0 && file_exists($outputPath);
    }

    // Valida se a URL e de uma rede suportada
    public function isSupported(string $url): bool
    {
        $supported = [
            'instagram.com',
            'pinterest.com',
            'pin.it',
            'twitter.com',
            'x.com',
            'tiktok.com',
            'youtube.com',
            'youtu.be',
        ];

        foreach ($supported as $domain) {
            if (str_contains($url, $domain)) return true;
        }

        return false;
    }

    // Limpa arquivos antigos (mais de X horas)
    public function cleanup(int $hoursOld = 24): void
    {
        $files = glob($this->uploadsPath . '*');
        $limit = time() - ($hoursOld * 3600);

        foreach ($files as $file) {
            if (filemtime($file) < $limit) {
                unlink($file);
            }
        }
    }
}
