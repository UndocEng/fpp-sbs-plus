<?php
// Wrapper to load FPP's original network configuration page.
// The backup is at networkconfig.php.listener-backup which Apache won't
// process as PHP, so this wrapper includes it instead.
include '/opt/fpp/www/networkconfig.php.listener-backup';
?>
<script>
(function() {
    // Check if SBS Audio Sync has an active SBS role — if so, warn on the tethering tab
    fetch('/listen/listener-api.php?action=get_roles')
        .then(r => r.json())
        .then(data => {
            if (!data.roles) return;
            var hasSBS = Object.values(data.roles).some(function(v) {
                return v === 'sbs';
            });
            if (!hasSBS) return;

            var tab = document.getElementById('tab-tethering');
            if (!tab) return;

            var warn = document.createElement('div');
            warn.className = 'callout callout-danger';
            warn.innerHTML =
                '<h4><i class="fas fa-exclamation-triangle"></i> SBS Audio Sync Active</h4>' +
                '<p>SBS Audio Sync is managing the WiFi adapters as access points. ' +
                'Changing tethering settings will conflict with the SBS access points ' +
                'and <strong>you will lose connectivity</strong> to this device over WiFi.</p>' +
                '<p>Tethering has been automatically disabled while SBS mode is active. ' +
                'To change tethering, first remove the SBS role from the ' +
                '<a href="/plugin.php?plugin=SBSPlus&page=plugin.php">SBS Audio Sync dashboard</a>.</p>';
            tab.insertBefore(warn, tab.firstChild);
        })
        .catch(function() { /* listener-api not available — no warning needed */ });
})();
</script>
<?php