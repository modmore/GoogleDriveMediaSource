<?php
$plugins = array();

/** create the plugin object */
$plugins[0] = $modx->newObject(\MODX\Revolution\modPlugin::class);
$plugins[0]->set('name', 'Google Drive Media Source');
$plugins[0]->set('description', '');
$plugins[0]->set('plugincode', getSnippetContent($sources['plugins'] . 'googledrivemediasource.plugin.php'));

$events = include $sources['data'].'transport.plugin.events.php';
if (is_array($events) && !empty($events)) {
    $plugins[0]->addMany($events);
    $modx->log(xPDO::LOG_LEVEL_INFO,'Packaged in '.count($events).' Plugin Events.'); flush();
} else {
    $modx->log(xPDO::LOG_LEVEL_ERROR,'Could not find plugin events!');
}
unset($events);

return $plugins;
