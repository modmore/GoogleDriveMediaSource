<?php


namespace modmore\GoogleDriveMediaSource\Adapter;

use Google\Service\Drive\DriveFile;
use League\Flysystem\FileAttributes;

class File extends FileAttributes
{
    use VisibilityTrait;
    use CacheableTrait;

    public DriveFile $file;

    public function __construct(DriveFile $file, string $path)
    {
        $this->file = $file;

        parent::__construct(
            $path,
            $file->getSize(),
            $this->translatePermissionsToVisibility(),
            strtotime($file->getModifiedTime()),
            $file->getMimeType(),
            []
        );
    }

    public function getName(): string
    {
        return $this->file->getName();
    }

    public function getId(): string
    {
        return (string)$this->file->getId();
    }
}