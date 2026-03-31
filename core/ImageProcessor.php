<?php

class ImageProcessor
{
    private array $formats;

    public function __construct(array $formats)
    {
        $this->formats = $formats;
    }

    // Processa imagem: crop centralizado + resize pro formato escolhido
    // Retorna true se salvou com sucesso
    public function process(string $inputPath, string $outputPath, string $formato): bool
    {
        if (!isset($this->formats[$formato])) {
            error_log("Formato desconhecido: {$formato}");
            return false;
        }

        $targetW = $this->formats[$formato]['width'];
        $targetH = $this->formats[$formato]['height'];

        $source = $this->loadImage($inputPath);
        if (!$source) return false;

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Calcula crop centralizado mantendo aspect ratio do formato alvo
        $targetRatio = $targetW / $targetH;
        $srcRatio    = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            // Imagem mais larga que o alvo — corta laterais
            $cropH = $srcH;
            $cropW = (int)($srcH * $targetRatio);
            $cropX = (int)(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Imagem mais alta que o alvo — corta topo/base
            $cropW = $srcW;
            $cropH = (int)($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int)(($srcH - $cropH) / 2);
        }

        // Cria imagem final no tamanho alvo
        $output = imagecreatetruecolor($targetW, $targetH);

        // Preserva qualidade — resampling bicubico
        imagecopyresampled(
            $output, $source,
            0, 0,                    // dst x, y
            $cropX, $cropY,          // src x, y (crop centralizado)
            $targetW, $targetH,      // dst w, h
            $cropW, $cropH           // src w, h (area cropada)
        );

        // Salva como JPEG qualidade 92
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $saved = imagejpeg($output, $outputPath, 92);

        imagedestroy($source);
        imagedestroy($output);

        return $saved;
    }

    // Carrega imagem de qualquer formato suportado pelo GD
    private function loadImage(string $path): \GdImage|false
    {
        if (!file_exists($path)) {
            error_log("Arquivo nao encontrado: {$path}");
            return false;
        }

        $mime = mime_content_type($path);

        return match ($mime) {
            'image/jpeg'   => imagecreatefromjpeg($path),
            'image/png'    => imagecreatefrompng($path),
            'image/webp'   => imagecreatefromwebp($path),
            'image/gif'    => imagecreatefromgif($path),
            'image/bmp'    => imagecreatefrombmp($path),
            default        => $this->tryLoadAny($path),
        };
    }

    // Fallback — tenta carregar como JPEG (yt-dlp as vezes salva com extensao errada)
    private function tryLoadAny(string $path): \GdImage|false
    {
        $img = @imagecreatefromjpeg($path);
        if ($img) return $img;

        $img = @imagecreatefrompng($path);
        if ($img) return $img;

        $img = @imagecreatefromwebp($path);
        if ($img) return $img;

        error_log("Nao foi possivel carregar imagem: {$path}");
        return false;
    }
}
