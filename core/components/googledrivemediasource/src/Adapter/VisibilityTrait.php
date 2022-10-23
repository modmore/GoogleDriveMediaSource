<?php


namespace modmore\GoogleDriveMediaSource\Adapter;

use Google\Service\Drive\DriveFile;
use League\Flysystem\Visibility;

/**
 * @property DriveFile $file
 */
trait VisibilityTrait
{
    protected function translatePermissionsToVisibility(): string
    {
        foreach ($this->file->getPermissions() as $permission) {
            // @fixme Should this also consider other more generous roles?
            if ($permission->getType() === 'anyone' && $permission->getRole() === 'reader') {
                return Visibility::PUBLIC;
            }
        }

        return Visibility::PRIVATE;
    }

}