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
        $fileId = md5($url . time());

        // Tenta API oEmbed do Instagram primeiro (sem login, para posts públicos)
        if (str_contains($url, 'instagram.com')) {
            $result = $this->downloadInstagramOembed($url, $fileId);
            if ($result) return $result;
        }

        // Fallback: yt-dlp
        return $this->downloadYtDlp($url, $fileId);
    }

    // Tenta baixar via oEmbed + scraping da thumbnail pública
    private function downloadInstagramOembed(string $url, string $fileId): string|false
    {
        // Extrai shortcode do link
        preg_match('/\/(p|reel|tv)\/([A-Za-z0-9_-]+)/', $url, $matches);
        $shortcode = $matches[2] ?? null;

        if (!$shortcode) return false;

        // oEmbed público do Instagram — retorna thumbnail_url sem login
        $oembedUrl = 'https://graph.facebook.com/v18.0/instagram_oembed?url=' . urlencode($url) . '&fields=thumbnail_url,title&access_token=instagram_basic';

        // Tenta oEmbed anônimo (funciona para posts públicos)
        $apiUrl  = 'https://www.instagram.com/p/' . $shortcode . '/?__a=1&__d=dis';
        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: pt-BR,pt;q=0.9',
                    'Cookie: ig_did=1; csrftoken=1;',
                ]),
                'timeout' => 15,
            ],
        ]);

        $html = @file_get_contents('https://www.instagram.com/p/' . $shortcode . '/', false, $context);

        if ($html) {
            // Extrai og:image da meta tag
            preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $imgMatches);
            $imageUrl = $imgMatches[1] ?? null;

            if ($imageUrl) {
                $imageUrl  = html_entity_decode($imageUrl);
                $imagePath = $this->uploadsPath . $fileId . '.jpg';
                $content   = @file_get_contents($imageUrl);

                if ($content) {
                    file_put_contents($imagePath, $content);
                    return $imagePath;
                }
            }
        }

        return false;
    }

    // Download via yt-dlp (fallback)
    private function downloadYtDlp(string $url, string $fileId): string|false
    {
        $output = $this->uploadsPath . $fileId;

        // Credenciais do Instagram se configuradas no .env
        $authFlags = '';
        $username  = $_ENV['INSTAGRAM_USERNAME'] ?? '';
        $password  = $_ENV['INSTAGRAM_PASSWORD'] ?? '';

        if ($username && $password) {
            $authFlags = sprintf(
                '--username %s --password %s',
                escapeshellarg($username),
                escapeshellarg($password)
            );
        }

        $cmd = sprintf(
            '%s --no-playlist --max-filesize 50m -f "best" %s -o %s %s 2>&1',
            escapeshellcmd($this->ytDlpBin),
            $authFlags,
            escapeshellarg($output . '.%(ext)s'),
            escapeshellarg($url)
        );

        exec($cmd, $outputLines, $exitCode);

        // Loga o erro completo pra debug
        if ($exitCode !== 0) {
            error_log('yt-dlp error [' . $url . ']: ' . implode(' | ', $outputLines));
            return false;
        }

        $files = glob($output . '.*');

        if (empty($files)) return false;

        $downloaded = $files[0];

        if ($this->isVideo($downloaded)) {
            $imagePath = $output . '_thumb.jpg';
            $extracted = $this->extractFrame($downloaded, $imagePath);
            if (!$extracted) return false;
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
