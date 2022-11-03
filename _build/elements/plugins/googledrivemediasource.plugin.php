<?php
/**
 * @var modX $modx
 * @var array $scriptProperties
 * @var modManagerController $controller
 */

if ($modx->event->name !== 'OnManagerPageBeforeRender') {
    return;
}

$assetsUrl = $modx->getOption('googledrivemediasource.assets_url', null, $modx->getOption('assets_url') . 'components/googledrivemediasource/');

$controller->addJavascript($assetsUrl . 'tree-actions.js');