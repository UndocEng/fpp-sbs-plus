<?php
// FPP Plugin Menu Entries for SBS Audio Sync
// This file is included by FPP's menu.inc for each dropdown section.
// $menu = current section being rendered (status, content, output, help)
// $plugin = this plugin's directory name

if ($menu == "status") {
    printf("<li><a href='plugin.php?plugin=%s&page=plugin.php'><i class='fas fa-headphones'></i> SBS Audio Sync</a></li>\n", $plugin);
}

if ($menu == "help") {
    printf("<li><a href='plugin.php?plugin=%s&page=about.php'><i class='fas fa-info-circle'></i> SBS Audio Sync</a></li>\n", $plugin);
}
?>
