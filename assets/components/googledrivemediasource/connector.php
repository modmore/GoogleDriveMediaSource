<?php
ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * @var modX $modx
 */
require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH.'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH.'index.php';

$mgOptions = array();
if (isset($_REQUEST['resource']) && is_numeric($_REQUEST['resource']))
{
    $mgOptions['resource'] = (int)$_REQUEST['resource'];
}

$corePath = $modx->getOption('googledrivemediasource.core_path',null,$modx->getOption('core_path').'components/googledrivemediasource/');

$modx->lexicon->load('googledrivemediasource:default');

/* handle request */
$modx->request->handleRequest(array(
    'processors_path' => $corePath . 'src/Processors/',
    'location' => '',
));
