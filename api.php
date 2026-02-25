<?php
// FPP Plugin API endpoints for SBSPlus

function getEndpointsSBSPlus() {
    $result = array();

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'headerIndicator',
        'callback' => 'SBSPlusHeaderIndicator'
    );
    array_push($result, $ep);

    return $result;
}

function SBSPlusHeaderIndicator() {
    return json(array(
        'visible' => true,
        'icon' => 'fa-headphones',
        'color' => '#D4A030',
        'tooltip' => 'SBS Audio Sync Admin',
        'link' => '/listen/admin.html',
        'animate' => ''
    ));
}
