<?php
// Wrapper to load FPP's original network configuration page.
// The backup is at networkconfig.php.listener-backup which Apache won't
// process as PHP, so this wrapper includes it instead.
include '/opt/fpp/www/networkconfig.php.listener-backup';
