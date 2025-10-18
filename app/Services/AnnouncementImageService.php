<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class AnnouncementImageService
{
    protected string $disk = 'public';
    protected string $directory = 'announcements/images';
    protected int $maxWidth = 1920;  // Maximum width for optimization
    protected int $maxHeight = 1920; // Maximum height for optimization
    protected int $quality = 85;

    /**
     * Upload and process announcement image
     */
    public function uploadImage(UploadedFile $file): array
    {
        // Validate file
        $this->validateImage($file);

        // Generate unique filename
        $filename = $this->generateFilename($file);
        $path = $this->directory . '/' . $filename;

        // Get image dimensions
        $image = Image::make($file);
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Resize image while maintaining aspect ratio and quality
        $image = $this->resizeImage($image);

        // Create thumbnail
        $thumbnailPath = $this->createThumbnailVersion($image, $filename);

        // Create compressed version
        $compressedPath = $this->createCompressedVersion($image, $filename);

        // Optimize and save original
        $image->encode(null, $this->quality);
        Storage::disk($this->disk)->put($path, $image->stream());

        return [
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size' => Storage::disk($this->disk)->size($path),
            'width' => $image->width(),
            'height' => $image->height(),
            'url' => asset('storage/' . $path),
            'thumbnail_path' => $thumbnailPath,
            'compressed_path' => $compressedPath,
        ];
    }

    /**
     * Delete announcement image
     */
    public function deleteImage(string $imagePath): bool
    {
        if (Storage::disk($this->disk)->exists($imagePath)) {
            return Storage::disk($this->disk)->delete($imagePath);
        }
        return true;
    }

    /**
     * Get image download response
     */
    public function downloadImage(string $imagePath, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!Storage::disk($this->disk)->exists($imagePath)) {
            abort(404, 'Image not found');
        }

        return Storage::disk($this->disk)->download($imagePath, $filename);
    }

    /**
     * Validate uploaded image
     */
    protected function validateImage(UploadedFile $file): void
    {
        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Image size must not exceed 10MB');
        }

        // Check MIME type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            throw new \InvalidArgumentException('Invalid image format. Allowed formats: JPEG, PNG, GIF, WebP');
        }

        // Check if it's actually an image
        if (!getimagesize($file->getPathname())) {
            throw new \InvalidArgumentException('File is not a valid image');
        }
    }

    /**
     * Generate unique filename
     */
    protected function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $random = Str::random(8);
        
        return "announcement_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Create thumbnail for image
     */
    public function createThumbnail(string $imagePath, int $width = 300, int $height = 300): string
    {
        $fullPath = Storage::disk($this->disk)->path($imagePath);
        $thumbnailPath = str_replace('.', '_thumb.', $imagePath);
        
        $image = Image::make($fullPath);
        $image->fit($width, $height);
        $image->save(Storage::disk($this->disk)->path($thumbnailPath), $this->quality);
        
        return $thumbnailPath;
    }

    /**
     * Get image info
     */
    public function getImageInfo(string $imagePath): array
    {
        if (!Storage::disk($this->disk)->exists($imagePath)) {
            return [];
        }

        $fullPath = Storage::disk($this->disk)->path($imagePath);
        $imageInfo = getimagesize($fullPath);

        return [
            'width' => $imageInfo[0] ?? 0,
            'height' => $imageInfo[1] ?? 0,
            'mime_type' => $imageInfo['mime'] ?? null,
            'size' => Storage::disk($this->disk)->size($imagePath),
        ];
    }

    /**
     * Resize image while maintaining aspect ratio
     */
    protected function resizeImage($image)
    {
        $currentWidth = $image->width();
        $currentHeight = $image->height();

        // Only resize if image exceeds maximum dimensions
        if ($currentWidth > $this->maxWidth || $currentHeight > $this->maxHeight) {
            $image->resize($this->maxWidth, $this->maxHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        return $image;
    }

    /**
     * Create thumbnail version
     */
    protected function createThumbnailVersion($image, string $filename): string
    {
        $thumbnailFilename = 'thumb_' . $filename;
        $thumbnailPath = $this->directory . '/thumbnails/' . $thumbnailFilename;
        
        $thumbnail = clone $image;
        $thumbnail->resize(320, 180, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        
        $thumbnail->encode(null, 75);
        Storage::disk($this->disk)->put($thumbnailPath, $thumbnail->stream());
        
        return $thumbnailPath;
    }

    /**
     * Create compressed version
     */
    protected function createCompressedVersion($image, string $filename): string
    {
        $compressedFilename = 'compressed_' . $filename;
        $compressedPath = $this->directory . '/compressed/' . $compressedFilename;
        
        $compressed = clone $image;
        $compressed->resize(960, 540, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        
        $compressed->encode(null, 70);
        Storage::disk($this->disk)->put($compressedPath, $compressed->stream());
        
        return $compressedPath;
    }
}
