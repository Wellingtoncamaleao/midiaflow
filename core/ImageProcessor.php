<?php

class ImageProcessor
{
    private array $formats;

    public function __construct(array $formats)
    {
        $this->formats = $formats;
    }

    // Processa a imagem para o formato escolhido
    // Faz crop centralizado + resize — sem distorcer
    public function process(string $inputPath, string $outputPath, string $format = 'feed'): bool
    {
        if (!isset($this->formats[$format])) {
            throw new \InvalidArgumentException("Formato '{$format}' não existe.");
        }

        $targetW = $this->formats[$format]['width'];
        $targetH = $this->formats[$format]['height'];

        $src = $this->loadImage($inputPath);
        if (!$src) return false;

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Calcula crop centralizado (cover)
        $srcRatio    = $srcW / $srcH;
        $targetRatio = $targetW / $targetH;

        if ($srcRatio > $targetRatio) {
            // Imagem mais larga que o target — corta nas laterais
            $cropH = $srcH;
            $cropW = (int)($srcH * $targetRatio);
            $cropX = (int)(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Imagem mais alta que o target — corta em cima/baixo
            $cropW = $srcW;
            $cropH = (int)($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int)(($srcH - $cropH) / 2);
        }

        $dst = imagecreatetruecolor($targetW, $targetH);

        // Preserva transparência se PNG
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

        $result = $this->saveImage($dst, $outputPath, $inputPath);

        imagedestroy($src);
        imagedestroy($dst);

        return $result;
    }

    // Carrega imagem independente do tipo
    private function loadImage(string $path): \GdImage|false
    {
        $mime = mime_content_type($path);

        return match (true) {
            str_contains($mime, 'jpeg') => imagecreatefromjpeg($path),
            str_contains($mime, 'png')  => imagecreatefrompng($path),
            str_contains($mime, 'webp') => imagecreatefromwebp($path),
            default                     => false,
        };
    }

    // Salva sempre como JPEG (Instagram prefere)
    private function saveImage(\GdImage $img, string $outputPath, string $originalPath): bool
    {
        // Garante extensão .jpg no output
        $outputPath = preg_replace('/\.[^.]+$/', '.jpg', $outputPath);
        return imagejpeg($img, $outputPath, 92);
    }

    // Retorna dimensões de uma imagem
    public function getDimensions(string $path): array
    {
        [$w, $h] = getimagesize($path);
        return ['width' => $w, 'height' => $h];
    }
}
