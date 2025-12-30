<?php

namespace HybridPHP\Core\Http;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 compatible UploadedFile implementation with Yii2-style convenience methods
 */
class UploadedFile implements UploadedFileInterface
{
    private StreamInterface $stream;
    private ?int $size;
    private int $error;
    private ?string $clientFilename;
    private ?string $clientMediaType;
    private bool $moved = false;

    public function __construct(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        $this->stream = $stream;
        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new \RuntimeException('File has already been moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cannot retrieve stream due to upload error');
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('File has already been moved');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cannot move file due to upload error');
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new \RuntimeException("Cannot create directory: {$targetDir}");
            }
        }

        if (!is_writable($targetDir)) {
            throw new \RuntimeException("Target directory is not writable: {$targetDir}");
        }

        // Write stream contents to target file
        $targetStream = fopen($targetPath, 'w');
        if ($targetStream === false) {
            throw new \RuntimeException("Cannot open target file: {$targetPath}");
        }

        $sourceStream = $this->getStream();
        $sourceStream->rewind();

        while (!$sourceStream->eof()) {
            $chunk = $sourceStream->read(8192);
            if (fwrite($targetStream, $chunk) === false) {
                fclose($targetStream);
                throw new \RuntimeException("Failed to write to target file: {$targetPath}");
            }
        }

        fclose($targetStream);
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    // Yii2-style convenience methods

    /**
     * Check if file upload was successful
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * Get upload error message
     */
    public function getErrorMessage(): string
    {
        switch ($this->error) {
            case UPLOAD_ERR_OK:
                return 'No error';
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds upload_max_filesize directive';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds MAX_FILE_SIZE directive';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Get file extension
     */
    public function getExtension(): string
    {
        if (!$this->clientFilename) {
            return '';
        }
        
        return strtolower(pathinfo($this->clientFilename, PATHINFO_EXTENSION));
    }

    /**
     * Get base filename without extension
     */
    public function getBasename(): string
    {
        if (!$this->clientFilename) {
            return '';
        }
        
        return pathinfo($this->clientFilename, PATHINFO_FILENAME);
    }

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        return in_array($this->clientMediaType, $imageTypes);
    }

    /**
     * Get image dimensions (if it's an image)
     */
    public function getImageSize(): ?array
    {
        if (!$this->isImage() || !$this->isValid()) {
            return null;
        }

        try {
            $stream = $this->getStream();
            $stream->rewind();
            $imageData = $stream->getContents();
            $stream->rewind();

            $size = getimagesizefromstring($imageData);
            return $size ? [
                'width' => $size[0],
                'height' => $size[1],
                'type' => $size[2],
                'mime' => $size['mime']
            ] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validate file against rules
     */
    public function validate(array $rules): array
    {
        $errors = [];

        foreach ($rules as $rule => $value) {
            switch ($rule) {
                case 'required':
                    if ($value && $this->error === UPLOAD_ERR_NO_FILE) {
                        $errors[] = 'File is required';
                    }
                    break;

                case 'maxSize':
                    if ($this->size && $this->size > $value) {
                        $errors[] = "File size exceeds maximum allowed size of " . $this->formatBytes($value);
                    }
                    break;

                case 'minSize':
                    if ($this->size && $this->size < $value) {
                        $errors[] = "File size is below minimum required size of " . $this->formatBytes($value);
                    }
                    break;

                case 'extensions':
                    $allowedExtensions = is_array($value) ? $value : explode(',', $value);
                    $allowedExtensions = array_map('trim', array_map('strtolower', $allowedExtensions));
                    
                    if (!in_array($this->getExtension(), $allowedExtensions)) {
                        $errors[] = "File extension must be one of: " . implode(', ', $allowedExtensions);
                    }
                    break;

                case 'mimeTypes':
                    $allowedTypes = is_array($value) ? $value : explode(',', $value);
                    $allowedTypes = array_map('trim', $allowedTypes);
                    
                    if (!in_array($this->clientMediaType, $allowedTypes)) {
                        $errors[] = "File type must be one of: " . implode(', ', $allowedTypes);
                    }
                    break;

                case 'imageOnly':
                    if ($value && !$this->isImage()) {
                        $errors[] = 'File must be an image';
                    }
                    break;

                case 'maxWidth':
                    if ($this->isImage()) {
                        $imageSize = $this->getImageSize();
                        if ($imageSize && $imageSize['width'] > $value) {
                            $errors[] = "Image width exceeds maximum allowed width of {$value}px";
                        }
                    }
                    break;

                case 'maxHeight':
                    if ($this->isImage()) {
                        $imageSize = $this->getImageSize();
                        if ($imageSize && $imageSize['height'] > $value) {
                            $errors[] = "Image height exceeds maximum allowed height of {$value}px";
                        }
                    }
                    break;

                case 'minWidth':
                    if ($this->isImage()) {
                        $imageSize = $this->getImageSize();
                        if ($imageSize && $imageSize['width'] < $value) {
                            $errors[] = "Image width is below minimum required width of {$value}px";
                        }
                    }
                    break;

                case 'minHeight':
                    if ($this->isImage()) {
                        $imageSize = $this->getImageSize();
                        if ($imageSize && $imageSize['height'] < $value) {
                            $errors[] = "Image height is below minimum required height of {$value}px";
                        }
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * Save file with automatic filename generation
     */
    public function save(string $directory, string $filename = null): string
    {
        if (!$this->isValid()) {
            throw new \RuntimeException('Cannot save invalid file: ' . $this->getErrorMessage());
        }

        if ($filename === null) {
            $filename = $this->generateFilename();
        }

        $targetPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $this->moveTo($targetPath);
        
        return $targetPath;
    }

    /**
     * Save file as image with optional resizing
     */
    public function saveAsImage(string $directory, string $filename = null, array $options = []): string
    {
        if (!$this->isImage()) {
            throw new \RuntimeException('File is not an image');
        }

        $targetPath = $this->save($directory, $filename);

        // Apply image processing if options are provided
        if (!empty($options)) {
            $this->processImage($targetPath, $options);
        }

        return $targetPath;
    }

    /**
     * Generate unique filename
     */
    private function generateFilename(): string
    {
        $extension = $this->getExtension();
        $basename = uniqid('upload_', true);
        
        return $extension ? "{$basename}.{$extension}" : $basename;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Process image with options (resize, crop, etc.)
     */
    private function processImage(string $imagePath, array $options): void
    {
        // Basic image processing - in a real implementation, you might use
        // libraries like Intervention Image or GD/ImageMagick
        
        if (!extension_loaded('gd')) {
            return; // Skip processing if GD is not available
        }

        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return;
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $imageType = $imageInfo[2];

        // Load image based on type
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($imagePath);
                break;
            default:
                return; // Unsupported image type
        }

        if (!$sourceImage) {
            return;
        }

        $processed = false;

        // Resize if width or height is specified
        if (isset($options['width']) || isset($options['height'])) {
            $newWidth = $options['width'] ?? $originalWidth;
            $newHeight = $options['height'] ?? $originalHeight;

            // Maintain aspect ratio if only one dimension is specified
            if (isset($options['width']) && !isset($options['height'])) {
                $newHeight = ($originalHeight * $newWidth) / $originalWidth;
            } elseif (isset($options['height']) && !isset($options['width'])) {
                $newWidth = ($originalWidth * $newHeight) / $originalHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefill($resizedImage, 0, 0, $transparent);
            }

            imagecopyresampled(
                $resizedImage, $sourceImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $originalWidth, $originalHeight
            );

            imagedestroy($sourceImage);
            $sourceImage = $resizedImage;
            $processed = true;
        }

        // Save processed image
        if ($processed) {
            switch ($imageType) {
                case IMAGETYPE_JPEG:
                    $quality = $options['quality'] ?? 85;
                    imagejpeg($sourceImage, $imagePath, $quality);
                    break;
                case IMAGETYPE_PNG:
                    $compression = $options['compression'] ?? 6;
                    imagepng($sourceImage, $imagePath, $compression);
                    break;
                case IMAGETYPE_GIF:
                    imagegif($sourceImage, $imagePath);
                    break;
            }
        }

        imagedestroy($sourceImage);
    }

    /**
     * Create UploadedFile from $_FILES array
     */
    public static function createFromGlobals(array $fileData): self
    {
        $stream = new class($fileData['tmp_name']) implements StreamInterface {
            private $resource;
            private string $tmpName;

            public function __construct(string $tmpName)
            {
                $this->tmpName = $tmpName;
                $this->resource = fopen($tmpName, 'r');
            }

            public function __toString(): string
            {
                if (!$this->resource) {
                    return '';
                }
                $this->rewind();
                return $this->getContents();
            }

            public function close(): void
            {
                if ($this->resource) {
                    fclose($this->resource);
                    $this->resource = null;
                }
            }

            public function detach()
            {
                $resource = $this->resource;
                $this->resource = null;
                return $resource;
            }

            public function getSize(): ?int
            {
                return $this->resource ? fstat($this->resource)['size'] ?? null : null;
            }

            public function tell(): int
            {
                return $this->resource ? ftell($this->resource) : 0;
            }

            public function eof(): bool
            {
                return $this->resource ? feof($this->resource) : true;
            }

            public function isSeekable(): bool
            {
                return (bool) $this->resource;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                if ($this->resource) {
                    fseek($this->resource, $offset, $whence);
                }
            }

            public function rewind(): void
            {
                if ($this->resource) {
                    rewind($this->resource);
                }
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Stream is not writable');
            }

            public function isReadable(): bool
            {
                return (bool) $this->resource;
            }

            public function read(int $length): string
            {
                return $this->resource ? fread($this->resource, $length) : '';
            }

            public function getContents(): string
            {
                return $this->resource ? stream_get_contents($this->resource) : '';
            }

            public function getMetadata(?string $key = null)
            {
                if (!$this->resource) {
                    return $key === null ? [] : null;
                }
                
                $meta = stream_get_meta_data($this->resource);
                return $key === null ? $meta : ($meta[$key] ?? null);
            }
        };

        return new self(
            $stream,
            $fileData['size'],
            $fileData['error'],
            $fileData['name'],
            $fileData['type']
        );
    }
}