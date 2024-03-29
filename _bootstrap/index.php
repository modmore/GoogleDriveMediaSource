<?php
/* Get the core config */
$componentPath = dirname(__DIR__);
if (!file_exists($componentPath.'/config.core.php')) {
    die('ERROR: missing '.$componentPath.'/config.core.php file defining the MODX core path.');
}

echo "<pre>";
/* Boot up MODX */
echo "Loading modX...\n";
require_once $componentPath . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
$modx = new modX();
echo "Initializing manager...\n";
$modx->initialize('mgr');
$modx->getService('error','error.modError', '', '');
$modx->setLogTarget('HTML');

/* Namespace */
if (!createObject('modNamespace',array(
    'name' => 'googledrivemediasource',
    'path' => $componentPath.'/core/components/googledrivemediasource/',
    'assets_path' => $componentPath.'/assets/components/googledrivemediasource/',
),'name', false)) {
    echo "Error creating namespace googledrivemediasource.\n";
}

/* Path settings */
if (!createObject('modSystemSetting', array(
    'key' => 'googledrivemediasource.core_path',
    'value' => $componentPath.'/core/components/googledrivemediasource/',
    'xtype' => 'textfield',
    'namespace' => 'googledrivemediasource',
    'area' => 'Paths',
    'editedon' => time(),
), 'key', false)) {
    echo "Error creating googledrivemediasource.core_path setting.\n";
}

if (!createObject('modSystemSetting', array(
    'key' => 'googledrivemediasource.assets_path',
    'value' => $componentPath.'/assets/components/googledrivemediasource/',
    'xtype' => 'textfield',
    'namespace' => 'googledrivemediasource',
    'area' => 'Paths',
    'editedon' => time(),
), 'key', false)) {
    echo "Error creating googledrivemediasource.assets_path setting.\n";
}

/* Fetch assets url */
$requestUri = $_SERVER['REQUEST_URI'] ?: __DIR__ . '/_bootstrap/index.php';
$bootstrapPos = strpos($requestUri, '_bootstrap/');
$requestUri = rtrim(substr($requestUri, 0, $bootstrapPos), '/').'/';
$assetsUrl = "{$requestUri}assets/components/googledrivemediasource/";

if (!createObject('modSystemSetting', array(
    'key' => 'googledrivemediasource.assets_url',
    'value' => $assetsUrl,
    'xtype' => 'textfield',
    'namespace' => 'googledrivemediasource',
    'area' => 'Paths',
    'editedon' => time(),
), 'key', false)) {
    echo "Error creating googledrivemediasource.assets_url setting.\n";
}

if (!createObject('modPlugin', array(
    'name' => 'Google Drive Media Source',
    'static' => true,
    'static_file' => $componentPath.'/_build/elements/plugins/googledrivemediasource.plugin.php',
), 'name', false)) {
    echo "Error creating Google Drive Media Source Plugin.\n";
}

$settings = include dirname(__DIR__) . '/_build/data/settings.php';
foreach ($settings as $key => $opts) {
    $val = $opts['value'];

    if (isset($opts['xtype'])) $xtype = $opts['xtype'];
    elseif (is_int($val)) $xtype = 'numberfield';
    elseif (is_bool($val)) $xtype = 'modx-combo-boolean';
    else $xtype = 'textfield';

    if (!createObject('modSystemSetting', array(
        'key' => 'googledrivemediasource.' . $key,
        'value' => $opts['value'],
        'xtype' => $xtype,
        'namespace' => 'googledrivemediasource',
        'area' => $opts['area'],
        'editedon' => time(),
    ), 'key', false)) {
        echo "Error creating googledrivemediasource.".$key." setting.\n";
    }
}

require_once $componentPath . '/core/components/googledrivemediasource/vendor/autoload.php';

// Clear the cache
$modx->cacheManager->refresh();

echo "Done.";


/**
 * Creates an object.
 *
 * @param string $className
 * @param array $data
 * @param string $primaryField
 * @param bool $update
 * @return bool
 */
function createObject ($className = '', array $data = array(), $primaryField = '', $update = true) {
    global $modx;
    /* @var xPDOObject $object */
    $object = null;

    /* Attempt to get the existing object */
    if (!empty($primaryField)) {
        if (is_array($primaryField)) {
            $condition = array();
            foreach ($primaryField as $key) {
                $condition[$key] = $data[$key];
            }
        }
        else {
            $condition = array($primaryField => $data[$primaryField]);
        }
        $object = $modx->getObject($className, $condition);
        if ($object instanceof $className) {
            if ($update) {
                $object->fromArray($data);
                return $object->save();
            } else {
                $condition = $modx->toJSON($condition);
                echo "Skipping {$className} {$condition}: already exists.\n";
                return true;
            }
        }
    }

    /* Create new object if it doesn't exist */
    if (!$object) {
        $object = $modx->newObject($className);
        $object->fromArray($data, '', true);
        return $object->save();
    }

    return false;
}
