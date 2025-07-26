<?php

class ImageProcessor
{
    public static function resizeToStandard(string $path, string $size): string {
        $sizes = [
            'square' => [500, 500],
            'portrait' => [400, 600],
            'landscape' => [800, 400],
        ];

        if (!isset($sizes[$size])) {
            throw new InvalidArgumentException("Unknown size preset");
        }

        [$newW, $newH] = $sizes[$size];

        $src = imagecreatefromstring(file_get_contents($path));
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $srcAspect = $srcW / $srcH;
        $dstAspect = $newW / $newH;

        if ($srcAspect > $dstAspect) {
            $cropH = $srcH;
            $cropW = $srcH * $dstAspect;
        } else {
            $cropW = $srcW;
            $cropH = $srcW / $dstAspect;
        }

        $cropX = ($srcW - $cropW) / 2;
        $cropY = ($srcH - $cropH) / 2;

        $cropped = imagecrop($src, [
            'x' => (int)$cropX,
            'y' => (int)$cropY,
            'width' => (int)$cropW,
            'height' => (int)$cropH
        ]);

        $resized = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($resized, $cropped, 0, 0, 0, 0, $newW, $newH, imagesx($cropped), imagesy($cropped));

        $output = tempnam("storage", "resized_") . '.jpg';
        imagejpeg($resized, $output);

        imagedestroy($src);
        imagedestroy($cropped);
        imagedestroy($resized);

        return $output;
    }

    public static function convertToGrayscale(string $path): string {
        $src = imagecreatefromstring(file_get_contents($path));
        imagefilter($src, IMG_FILTER_GRAYSCALE);

        $output = tempnam("storage", "bw_") . '.jpg';
        imagejpeg($src, $output);
        imagedestroy($src);

        return $output;
    }

    public static function convertFormat(string $path, string $format): string {
    $src = imagecreatefromstring(file_get_contents($path));
    $ext = strtolower($format);
    $output = tempnam("storage", "converted_") . '.' . $ext;

    switch ($ext) {
        case 'png':
            imagepng($src, $output);
            break;
        case 'jpg':
        case 'jpeg':
            imagejpeg($src, $output);
            break;
        case 'tiff':
            $converted = false;
            if (shell_exec("which convert")) {
                exec("convert $path $output", $outputLog, $returnCode);
                if (file_exists($output) && $returnCode === 0) {
                    $converted = true;
                }
            }

            if (!$converted) {
                imagedestroy($src);
                throw new Exception("Конвертация в TIFF невозможна: ImageMagick не установлена на сервере.");
            }
            break;
        default:
            imagedestroy($src);
            throw new Exception("Неподдерживаемый формат");
    }

    imagedestroy($src);
    return $output;
    }


    public static function isImageFile(string $mime): bool {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/tiff']);
    }

    public static function getMimeType(string $path): string {
        $info = getimagesize($path);
        return $info['mime'] ?? '';
    }
}
