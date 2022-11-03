<?php
$events = array();

$events['OnManagerPageBeforeRender'] = $modx->newObject(\MODX\Revolution\modPluginEvent::class);
$events['OnManagerPageBeforeRender']->fromArray(array(
    'event' => 'OnManagerPageBeforeRender',
    'priority' => 0,
    'propertyset' => 0
),'',true,true);

return $events;
