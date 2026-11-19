<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores portfolio images safely:
 *  - only real JPEG / PNG / WebP / GIF images are accepted (checked by content, not file name)
 *  - files get a random name (user-supplied names are never used on disk)
 *  - the image is RE-ENCODED with GD, which strips EXIF/GPS data and any payload hidden in the file
 *  - a resized thumbnail is generated for the grid
 */
class ImageService
{
    private const array ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    /** @return array{image_path:string, thumb_path:string, image_width:int, image_height:int} */
    public function storeUpload(UploadedFile $file): array
    {
        return $this->storeFromPath($file->getRealPath());
    }

    /** @return array{image_path:string, thumb_path:string, image_width:int, image_height:int} */
    public function storeFromPath(string $sourcePath): array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false || ! isset(self::ALLOWED[$info[2]])) {
            throw new RuntimeException('File is not a supported image.');
        }

        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1 || $width * $height > 60_000_000) {
            throw new RuntimeException('Image dimensions are not allowed.');
        }

        $ext = self::ALLOWED[$type];
        $dir = 'portfolio/'.now()->format('Y/m');
        $name = Str::random(32);

        $disk = Storage::disk(config('jackychee.images.disk'));
        $disk->makeDirectory($dir);

        if (! extension_loaded('gd')) {
            // GD missing: keep the original bytes (still validated + random name)
            $path = "{$dir}/{$name}.{$ext}";
            $disk->put($path, file_get_contents($sourcePath));

            return ['image_path' => $path, 'thumb_path' => $path, 'image_width' => $width, 'image_height' => $height];
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
        };
        if ($src === false) {
            throw new RuntimeException('Image could not be decoded.');
        }

        $large = $this->resize($src, $width, $height, (int) config('jackychee.images.large_width'));
        $thumb = $this->resize($src, $width, $height, (int) config('jackychee.images.thumb_width'));

        $imagePath = "{$dir}/{$name}.{$ext}";
        $thumbPath = "{$dir}/{$name}-thumb.{$ext}";

        $disk->put($imagePath, $this->encode($large['img'], $type));
        $disk->put($thumbPath, $this->encode($thumb['img'], $type));

        return [
            'image_path' => $imagePath,
            'thumb_path' => $thumbPath,
            'image_width' => $large['w'],
            'image_height' => $large['h'],
        ];
    }

    public function delete(?string ...$paths): void
    {
        $disk = Storage::disk(config('jackychee.images.disk'));
        foreach (array_unique(array_filter($paths)) as $path) {
            // never allow path traversal outside the portfolio folder
            if (str_starts_with($path, 'portfolio/') && ! str_contains($path, '..')) {
                $disk->delete($path);
            }
        }
    }

    /** @return array{img:\GdImage,w:int,h:int} */
    private function resize(\GdImage $src, int $w, int $h, int $maxWidth): array
    {
        if ($w <= $maxWidth) {
            $newW = $w;
            $newH = $h;
        } else {
            $newW = $maxWidth;
            $newH = (int) round($h * ($maxWidth / $w));
        }

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        return ['img' => $dst, 'w' => $newW, 'h' => $newH];
    }

    private function encode(\GdImage $img, int $type): string
    {
        ob_start();
        match ($type) {
            IMAGETYPE_PNG => imagepng($img, null, 6),
            IMAGETYPE_WEBP => imagewebp($img, null, (int) config('jackychee.images.jpeg_quality')),
            IMAGETYPE_GIF => imagegif($img),
            default => imagejpeg($img, null, (int) config('jackychee.images.jpeg_quality')),
        };

        return (string) ob_get_clean();
    }
}
