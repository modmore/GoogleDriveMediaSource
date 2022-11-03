<?php
/**
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

use xPDO\xPDO;

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    $modx->log(xPDO::LOG_LEVEL_ERROR, 'Autoloader not present for Google Drive Media Source');
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

class_exists(\MODX\Revolution\Sources\modMediaSource::class);
class_exists(\MODX\Revolution\Sources\mysql\modMediaSource::class);

if (!$modx->addPackage('modmore\\GoogleDriveMediaSource\\Model', __DIR__ . '/src/Model/', null, 'modmore\\GoogleDriveMediaSource\\Model')) {
    $modx->log(xPDO::LOG_LEVEL_ERROR, 'Failed adding Google Drive Media Source package');
}
