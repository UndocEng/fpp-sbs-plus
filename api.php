<?php
// FPP Plugin API endpoints for fpp-eavesdrop

function getEndpointsfppeavesdrop() {
    $result = array();

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'headerIndicator',
        'callback' => 'fppeavesdropHeaderIndicator'
    );
    array_push($result, $ep);

    return $result;
}

function fppeavesdropHeaderIndicator() {
    return json(array(
        'visible' => true,
        'icon' => 'fa-headphones',
        'color' => '#D4A030',
        'tooltip' => 'Eavesdrop Dashboard',
        'link' => 'plugin.php?plugin=fpp-eavesdrop&page=plugin.php',
        'animate' => ''
    ));
}
