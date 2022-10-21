<?php
namespace modmore\GoogleDriveMediaSource\Model\mysql;

use xPDO\xPDO;

class GoogleDriveMediaSource extends \modmore\GoogleDriveMediaSource\Model\GoogleDriveMediaSource
{

    public static $metaMap = array (
        'package' => 'modmore\\GoogleDriveMediaSource\\Model',
        'version' => '3.0',
        'extends' => 'MODX\\Revolution\\Sources\\modMediaSource',
        'inherit' => 'single',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
        ),
        'fieldMeta' => 
        array (
        ),
    );

}
