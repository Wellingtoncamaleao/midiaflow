<?php

class MediaDownloader
{
    private string $uploadsPath;
    private string $ytDlpBin;

    public function __construct(string $uploadsPath, string $ytDlpBin = 'yt-dlp')
    {
        $this->uploadsPath = rtrim($uploadsPath, '/') . '/';
        $this->ytDlpBin    = $ytDlpBin;
    }

    // Baixa a mídia de um link (Instagram, Pinterest, etc)
    // Retorna caminho do arquivo baixado ou false em caso de erro
    public function download(string $url): string|false
    {
        $fileId   = md5($url . time());
        $output   = $this->uploadsPath . $fileId;

        // yt-dlp com opções otimizadas:
        // --no-playlist     → só o post, não o perfil inteiro
        // --max-filesize    → limite de 50MB (evita vídeos pesados)
        // -f best           → melhor qualidade disponível
        // -o                → caminho de saída sem extensão (yt-dlp adiciona)
        $cmd = sprintf(
            '%s --no-playlist --max-filesize 50m -f "best" -o %s %s 2>&1',
            escapeshellcmd($this->ytDlpBin),
            escapeshellarg($output . '.%(ext)s'),
            escapeshellarg($url)
        );

        exec($cmd, $outputLines, $exitCode);

        if ($exitCode !== 0) {
            error_log('yt-dlp error: ' . implode("\n", $outputLines));
            return false;
        }

        // Encontra o arquivo baixado (yt-dlp adiciona a extensão)
        $files = glob($output . '.*');

        if (empty($files)) {
            return false;
        }

        $downloaded = $files[0];

        // Se for vídeo, extrai o primeiro frame como imagem
        if ($this->isVideo($downloaded)) {
            $imagePath = $output . '_thumb.jpg';
            $extracted = $this->extractFrame($downloaded, $imagePath);

            if (!$extracted) return false;

            // Remove o vídeo original pra economizar disco
            unlink($downloaded);

            return $imagePath;
        }

        return $downloaded;
    }

    // Verifica se o arquivo é vídeo pela extensão
    private function isVideo(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv']);
    }

    // Extrai o primeiro frame do vídeo usando ffmpeg
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

    // Valida se a URL é de uma rede suportada
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
