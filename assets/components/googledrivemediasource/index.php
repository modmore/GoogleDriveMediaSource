<?php

use modmore\GoogleDriveMediaSource\Processors\RenderFile;

ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

const MODX_REQP = false;
$_REQUEST['ctx'] = 'web';

/**
 * @var modX $modx
 */
require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH.'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH.'index.php';

if (!class_exists(RenderFile::class)) {
    @session_write_close();
    echo 'Assets unavailable';
    exit();
}

$modx->runProcessor(RenderFile::class, [
    'source' => (int)($_GET['s'] ?: 0),
    'id' => (string)($_GET['id'] ?: ''),
    'format' => (string)($_GET['f'] ?? ''),
]);
