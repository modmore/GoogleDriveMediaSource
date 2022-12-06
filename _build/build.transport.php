<?php

use MODX\Revolution\Transport\modPackageBuilder;
use xPDO\Transport\xPDOFileVehicle;
use xPDO\Transport\xPDOTransport;
use xPDO\xPDO;

/**
 * @param string $filename The name of the file.
 * @return string The file's content
 * @by splittingred
 */
function getSnippetContent($filename = '') {
    $o = file_get_contents($filename);
    $o = str_replace('<?php','',$o);
    $o = str_replace('?>','',$o);
    $o = trim($o);
    return $o;
}

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$tstart = $mtime;
set_time_limit(0);

if (!defined('MOREPROVIDER_BUILD')) {
    /* define version */
    define('PKG_NAME', 'GoogleDriveMediaSource');
    define('PKG_NAMESPACE', 'googledrivemediasource');
    define('PKG_VERSION', '1.0.0');
    define('PKG_RELEASE', 'dev11');

    /* load modx */
    require_once dirname(__DIR__) . '/config.core.php';
    require_once MODX_CORE_PATH . 'vendor/autoload.php';
    $modx = new \MODX\Revolution\modX();
    $modx->initialize('mgr');
    $modx->setLogLevel(xPDO::LOG_LEVEL_INFO);
    $modx->setLogTarget('ECHO');
    $targetDirectory = dirname(__DIR__) . '/_packages/';
}
else {
    $targetDirectory = MOREPROVIDER_BUILD_TARGET;
}

$root = dirname(__DIR__).'/';
$sources = [
    'root' => $root,
    'build' => $root .'_build/',
    'events' => $root . '_build/events/',
    'resolvers' => $root . '_build/resolvers/',
    'validators' => $root . '_build/validators/',
    'data' => $root . '_build/data/',
    'plugins' => $root.'_build/elements/plugins/',
    'snippets' => $root.'_build/elements/snippets/',
    'widgets' => $root . 'core/components/'.PKG_NAMESPACE.'/elements/widgets/',
    'source_core' => $root.'core/components/'.PKG_NAMESPACE,
    'source_assets' => $root.'assets/components/'.PKG_NAMESPACE,
    'lexicon' => $root . 'core/components/'.PKG_NAMESPACE.'/lexicon/',
    'docs' => $root.'core/components/'.PKG_NAMESPACE.'/docs/',
    'model' => $root.'core/components/'.PKG_NAMESPACE.'/model/',
];
unset($root);

$builder = new modPackageBuilder($modx);
$builder->directory = $targetDirectory;
$builder->createPackage(PKG_NAMESPACE,PKG_VERSION,PKG_RELEASE);
$builder->registerNamespace(PKG_NAMESPACE,false,true,'{core_path}components/'.PKG_NAMESPACE.'/', '{assets_path}components/'.PKG_NAMESPACE.'/');

$modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in namespace.'); flush();

if (file_exists($sources['source_core'] . '/vendor/')) {
    rename($sources['source_core'] . '/vendor/', dirname($sources['source_core']) . '/vendor/');
}

$builder->package->put(
    [
        'source' => $sources['source_core'],
        'target' => "return MODX_CORE_PATH . 'components/';",
    ],
    [
        xPDOTransport::ABORT_INSTALL_ON_VEHICLE_FAIL => true,
        'vehicle_class' => xPDOFileVehicle::class,
        'validate' => [
            [
                'type' => 'php',
                'source' => $sources['validators'] . 'requirements.script.php'
            ]
        ],
        'resolve' => [
            [
                'type' => 'php',
                'source' => $sources['resolvers'] . 'composer.resolver.php'
            ],
        ]
    ]
);
$modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in core, validators, and resolvers.'); flush();


if (file_exists(dirname($sources['source_core']) . '/vendor/')) {
    rename(dirname($sources['source_core']) . '/vendor/', $sources['source_core'] . '/vendor/');
}

/**
 * Assets
 */
$builder->package->put(
    [
        'source' => $sources['source_assets'],
        'target' => "return MODX_ASSETS_PATH . 'components/';",
    ],
    [
        'vehicle_class' => xPDOFileVehicle::class,
    ]
);
$modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in assets.'); flush();

/**
 * Settings
 */
//$settings = include $sources['data'] . 'transport.settings.php';
//if (is_array($settings)) {
//    $attributes = [
//        xPDOTransport::UNIQUE_KEY => 'key',
//        xPDOTransport::PRESERVE_KEYS => true,
//        xPDOTransport::UPDATE_OBJECT => false,
//    ];
//    foreach ($settings as $setting) {
//        $vehicle = $builder->createVehicle($setting,$attributes);
//        $builder->putVehicle($vehicle);
//    }
//    $modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in ' . count($settings) . ' system settings.'); flush();
//    unset($settings,$setting,$attributes);
//}

/**
 * Category
 */
$category= $modx->newObject(\MODX\Revolution\modCategory::class);
$category->set('category','GoogleDriveMediaSource');
$modx->log(xPDO::LOG_LEVEL_INFO,'Created category.');


$plugins = include $sources['data'].'transport.plugins.php';
if (is_array($plugins)) {
    $category->addMany($plugins,'Plugins');
    $modx->log(modX::LOG_LEVEL_INFO,'Packaged in '.count($plugins).' plugins.'); flush();
}
else {
    $modx->log(modX::LOG_LEVEL_FATAL,'Adding plugins failed.');
}
unset($plugins);

$attr = [
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
        'Plugins' => [
            xPDOTransport::PRESERVE_KEYS => true,
            xPDOTransport::UPDATE_OBJECT => true,
            xPDOTransport::UNIQUE_KEY => 'name',
            xPDOTransport::RELATED_OBJECTS => true,
            xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
                'PluginEvents' => [
                    xPDOTransport::PRESERVE_KEYS => true,
                    xPDOTransport::UPDATE_OBJECT => false,
                    xPDOTransport::UNIQUE_KEY => ['pluginid','event'],
                ],
            ],
        ],
    ]
];

$vehicle = $builder->createVehicle($category,$attr);
$builder->putVehicle($vehicle);


/* now pack in the license file, readme and setup options */
$builder->setPackageAttributes([
    'license' => file_get_contents($sources['docs'] . 'license.txt'),
    'readme' => file_get_contents($sources['docs'] . 'readme.txt'),
    'changelog' => file_get_contents($sources['docs'] . 'changelog.txt'),
]);
$modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in package attributes.'); flush();

$modx->log(xPDO::LOG_LEVEL_INFO,'Zipping up the package...'); flush();
$builder->pack();

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$tend = $mtime;
$totalTime = ($tend - $tstart);
$totalTime = sprintf("%2.4f s", $totalTime);

$modx->log(xPDO::LOG_LEVEL_INFO,"\nPackage Built.\nExecution time: {$totalTime}\n");

