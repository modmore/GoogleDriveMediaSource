<?php


namespace modmore\GoogleDriveMediaSource\Adapter;

use Google\Service\Drive\DriveFile;

/**
 * @property DriveFile $file
 * @property string $path
 */
trait CacheableTrait
{
    public function toCacheArray(): array
    {
        return [
            'type' => $this instanceof File ? File::class : Directory::class,
            'path' => $this->path(),
            'file' => get_object_vars($this->file),
        ];
    }

    public static function fromCacheArray(array $data): File|Directory
    {
        $file = new DriveFile();
        foreach ($data['file'] as $key => $value) {
            $file->$key = $value;
        }

        return new $data['type']($file, $data['path']);
    }

}