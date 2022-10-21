<?php

use MODX\Revolution\modSystemSetting;

$settingSource = include __DIR__ . '/settings.php';

$settings = [];

/**
 * Loop over setting stuff to interpret the xtype and to create the modSystemSetting object for the package.
 */
foreach ($settingSource as $key => $options) {
    $val = $options['value'];

    if (isset($options['xtype'])) $xtype = $options['xtype'];
    elseif (is_int($val)) $xtype = 'numberfield';
    elseif (is_bool($val)) $xtype = 'modx-combo-boolean';
    else $xtype = 'textfield';

    $settings[$key] = $modx->newObject(modSystemSetting::class);
    $settings[$key]->fromArray(array(
        'key' => 'googledrivemediasource.' . $key,
        'xtype' => $xtype,
        'value' => $options['value'],
        'namespace' => 'googledrivemediasource',
        'area' => $options['area'],
        'editedon' => time(),
    ), '', true, true);
}

return $settings;
