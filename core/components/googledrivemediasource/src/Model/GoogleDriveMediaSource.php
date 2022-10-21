<?php
namespace modmore\GoogleDriveMediaSource\Model;

use xPDO\xPDO;

/**
 * Class GoogleDriveMediaSource
 *
 *
 * @package modmore\GoogleDriveMediaSource\Model
 */
class GoogleDriveMediaSource extends \MODX\Revolution\Sources\modMediaSource
{
    public function initialize()
    {
        parent::initialize();
    }

    public function getTypeName(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.type');
    }

    public function getTypeDescription(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.description');
    }
}
