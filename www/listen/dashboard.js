// =============================================================================
// dashboard.js — Eavesdrop Network Dashboard
// =============================================================================
// Card-based network configuration. Each interface gets a card with a
// configurable role. Internet/Show roles use FPP's API; Listener uses ours.
//
// Served directly by Apache from /listen/ (not through FPP's plugin.php
// handler which produces malformed Content-Type headers).
// =============================================================================

var pluginName = 'fpp-eavesdrop';
var clientRefreshTimer = null;
var currentInterfaces = [];
var currentRoles = {};
var currentFppMap = {};

// =============================================================================
// API Helpers
// =============================================================================

// Call our plugin API (listener-api.php)
function pluginAPI(action, data, callback) {
    var fd = new FormData();
    fd.append('action', action);
    if (data) {
        for (var k in data) fd.append(k, data[k]);
    }
    $.ajax({
        url: 'plugin.php?plugin=' + pluginName + '&page=listener-api.php&nopage=1',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) { callback(res); },
        error: function(xhr) {
            callback({ success: false, error: 'Request failed: ' + xhr.status });
        }
    });
}

// Call FPP's native network API
function fppAPI(method, path, data, callback) {
    var opts = {
        url: '/api/network/' + path,
        method: method,
        dataType: 'json',
        success: function(res) { callback(res); },
        error: function(xhr) {
            callback({ error: 'FPP API error: ' + xhr.status });
        }
    };
    if (data && method !== 'GET') {
        opts.data = JSON.stringify(data);
        opts.contentType = 'application/json';
    }
    $.ajax(opts);
}

// =============================================================================
// Interface Cards
// =============================================================================

function loadDashboard() {
    // Fetch interfaces + roles from our API, then FPP interface data
    pluginAPI('get_interfaces', null, function(res) {
        if (!res.success) return;
        currentInterfaces = res.interfaces;

        pluginAPI('get_roles', null, function(roleRes) {
            if (roleRes.success) currentRoles = roleRes.roles;

            // Also get FPP's interface data for IP/config details
            fppAPI('GET', 'interface', null, function(fppData) {
                currentFppMap = {};
                if (Array.isArray(fppData)) {
                    fppData.forEach(function(iface) {
                        currentFppMap[iface.ifname] = iface;
                    });
                }
                renderCards(currentInterfaces, currentRoles, currentFppMap);
                loadTetherStatus();
            });
        });
    });
}

function renderCards(interfaces, roles, fppMap) {
    var container = $('#interface-cards');
    container.empty();
    var hasListener = false;

    interfaces.forEach(function(iface) {
        var role = roles[iface.name] || '';
        if (role === 'listener') hasListener = true;

        var colSize = 'col-lg-6';
        var card = $('<div>').addClass(colSize);
        card.html(buildCard(iface, role, fppMap[iface.name] || {}));
        container.append(card);
    });

    // Show/hide clients section
    if (hasListener) {
        $('#clients-section').show();
        loadClients();
        startClientRefresh();
    } else {
        $('#clients-section').hide();
    }

    // Update quick links (use relative paths so they work from any IP the admin is on)
    $('#link-listener').attr('href', '/listen/listen.html');
    $('#link-qrcode').attr('href', '/qrcode.html');
    $('#link-sign').attr('href', '/print-sign.html');
}

function buildCard(iface, role, fppData) {
    var statusColor = iface.operstate === 'up' ? 'success' : 'secondary';
    var statusText = iface.operstate === 'up' ? 'Connected' : (iface.operstate === 'down' ? 'Down' : iface.operstate);

    // Icon based on type
    var icon = 'fa-ethernet';
    if (iface.type === 'wifi' || iface.type === 'wifi-usb') icon = 'fa-wifi';

    var html = '';
    html += '<div class="card iface-card" data-iface="' + iface.name + '">';

    // Card header
    html += '<div class="card-header d-flex justify-content-between align-items-center">';
    html += '<div><i class="fas ' + icon + ' me-2"></i><strong>' + iface.label + '</strong>';
    html += ' <code class="ms-1 text-muted" style="font-size:0.8em;">' + iface.mac + '</code></div>';
    html += '<span class="badge bg-' + statusColor + '">' + statusText + '</span>';
    html += '</div>';

    // Card body
    html += '<div class="card-body">';

    // Role selector
    html += '<div class="row mb-3">';
    html += '<label class="col-sm-3 col-form-label fw-bold">Role</label>';
    html += '<div class="col-sm-9">';
    html += '<select class="form-select role-select" data-iface="' + iface.name + '">';
    html += '<option value=""' + (role === '' ? ' selected' : '') + '>-- Select Role --</option>';

    // Only show appropriate roles based on interface type
    var internetLabel = iface.wireless ? 'Internet / Tether' : 'Internet / Management';
    html += '<option value="internet"' + (role === 'internet' ? ' selected' : '') + '>' + internetLabel + '</option>';
    if (iface.wireless) {
        html += '<option value="show"' + (role === 'show' ? ' selected' : '') + '>Show Network</option>';
        html += '<option value="listener"' + (role === 'listener' ? ' selected' : '') + '>Listener Network (AP)</option>';
    }
    html += '<option value="unused"' + (role === 'unused' ? ' selected' : '') + '>Unused</option>';
    html += '</select>';
    html += '</div></div>';

    // Role-specific settings
    if (role === 'internet') {
        html += buildInternetSettings(iface, fppData);
    } else if (role === 'show') {
        html += buildShowSettings(iface, fppData);
    } else if (role === 'listener') {
        html += buildListenerSettings(iface);
    } else if (role === 'unused') {
        html += '<p class="text-muted mb-0"><i class="fas fa-power-off"></i> Interface not in use.</p>';
    } else {
        html += '<p class="text-muted mb-0">Select a role to configure this interface.</p>';
    }

    html += '</div>'; // card-body
    html += '</div>'; // card

    return html;
}

// =============================================================================
// Internet/Management Role Settings
// =============================================================================
function buildInternetSettings(iface, fppData) {
    var cfg = fppData.config || {};
    var proto = cfg.PROTO || 'dhcp';
    var addr = cfg.ADDRESS || '';
    var mask = cfg.NETMASK || '255.255.255.0';
    var gw = cfg.GATEWAY || '';
    var ssid = cfg.SSID || '';
    var psk = cfg.PSK || '';
    var currentIP = iface.ip || (proto === 'static' && addr ? addr + ' (configured)' : '(no IP)');

    var html = '';
    html += '<div class="mb-2"><small class="text-muted">Current IP: <strong>' + currentIP + '</strong></small></div>';

    // WiFi fields for wireless interfaces (tether to phone hotspot, etc.)
    if (iface.wireless) {
        html += inputRow(iface.name, 'ssid', 'SSID', ssid, 'Phone hotspot or network name');
        html += inputRow(iface.name, 'psk', 'Password', psk, 'WiFi password', 'password');

        // Scan button
        html += '<div class="row mb-2">';
        html += '<div class="col-sm-9 offset-sm-3">';
        html += '<button class="btn btn-info btn-sm btn-wifi-scan" data-iface="' + iface.name + '">';
        html += '<i class="fas fa-search"></i> Scan WiFi</button>';
        html += '<span class="scan-status-' + iface.name + ' ms-2"></span>';
        html += '</div></div>';

        // Scan results (hidden initially)
        html += '<div class="scan-results-' + iface.name + ' mb-2" style="display:none;"></div>';
    }

    // Protocol radio
    html += '<div class="row mb-2">';
    html += '<label class="col-sm-3 col-form-label">Protocol</label>';
    html += '<div class="col-sm-9">';
    html += '<div class="form-check form-check-inline">';
    html += '<input class="form-check-input proto-radio" type="radio" name="proto-' + iface.name + '" value="dhcp"' + (proto === 'dhcp' ? ' checked' : '') + ' data-iface="' + iface.name + '">';
    html += '<label class="form-check-label">DHCP</label></div>';
    html += '<div class="form-check form-check-inline">';
    html += '<input class="form-check-input proto-radio" type="radio" name="proto-' + iface.name + '" value="static"' + (proto === 'static' ? ' checked' : '') + ' data-iface="' + iface.name + '">';
    html += '<label class="form-check-label">Static</label></div>';
    html += '</div></div>';

    // Static fields (hidden if DHCP)
    var staticDisplay = proto === 'static' ? '' : 'display:none;';
    html += '<div class="static-fields-' + iface.name + '" style="' + staticDisplay + '">';
    html += inputRow(iface.name, 'address', 'IP Address', addr, 'e.g. 192.168.1.100');
    html += inputRow(iface.name, 'netmask', 'Netmask', mask, '255.255.255.0');
    html += inputRow(iface.name, 'gateway', 'Gateway', gw, 'e.g. 192.168.1.1');
    html += '</div>';

    // Tethering section (uses FPP's built-in tethering)
    html += buildTetherSection(iface);

    // Save button
    html += '<div class="mt-3">';
    html += '<button class="btn btn-success btn-sm btn-save-fpp" data-iface="' + iface.name + '" data-role="internet">';
    html += '<i class="fas fa-save"></i> Save & Apply</button>';
    html += '<span class="save-status-' + iface.name + ' ms-2"></span>';
    html += '</div>';

    return html;
}

// =============================================================================
// Show Network Role Settings
// =============================================================================
function buildShowSettings(iface, fppData) {
    var cfg = fppData.config || {};
    var proto = cfg.PROTO || 'dhcp';
    var addr = cfg.ADDRESS || '';
    var mask = cfg.NETMASK || '255.255.255.0';
    var gw = cfg.GATEWAY || '';
    var ssid = cfg.SSID || '';
    var psk = cfg.PSK || '';
    var currentIP = iface.ip || (proto === 'static' && addr ? addr + ' (configured)' : '(no IP)');

    var html = '';
    html += '<div class="mb-2"><small class="text-muted">Current IP: <strong>' + currentIP + '</strong></small></div>';

    // WiFi SSID and password
    html += inputRow(iface.name, 'ssid', 'SSID', ssid, 'Network name');
    html += inputRow(iface.name, 'psk', 'Password', psk, 'WiFi password', 'password');

    // Scan button
    html += '<div class="row mb-2">';
    html += '<div class="col-sm-9 offset-sm-3">';
    html += '<button class="btn btn-info btn-sm btn-wifi-scan" data-iface="' + iface.name + '">';
    html += '<i class="fas fa-search"></i> Scan WiFi</button>';
    html += '<span class="scan-status-' + iface.name + ' ms-2"></span>';
    html += '</div></div>';

    // Scan results (hidden initially)
    html += '<div class="scan-results-' + iface.name + ' mb-2" style="display:none;"></div>';

    // Protocol radio
    html += '<div class="row mb-2">';
    html += '<label class="col-sm-3 col-form-label">Protocol</label>';
    html += '<div class="col-sm-9">';
    html += '<div class="form-check form-check-inline">';
    html += '<input class="form-check-input proto-radio" type="radio" name="proto-' + iface.name + '" value="dhcp"' + (proto === 'dhcp' ? ' checked' : '') + ' data-iface="' + iface.name + '">';
    html += '<label class="form-check-label">DHCP</label></div>';
    html += '<div class="form-check form-check-inline">';
    html += '<input class="form-check-input proto-radio" type="radio" name="proto-' + iface.name + '" value="static"' + (proto === 'static' ? ' checked' : '') + ' data-iface="' + iface.name + '">';
    html += '<label class="form-check-label">Static</label></div>';
    html += '</div></div>';

    // Static fields
    var staticDisplay = proto === 'static' ? '' : 'display:none;';
    html += '<div class="static-fields-' + iface.name + '" style="' + staticDisplay + '">';
    html += inputRow(iface.name, 'address', 'IP Address', addr, 'e.g. 10.1.66.201');
    html += inputRow(iface.name, 'netmask', 'Netmask', mask, '255.255.255.0');
    html += inputRow(iface.name, 'gateway', 'Gateway', gw, 'e.g. 10.1.66.1');
    html += '</div>';

    // Tethering section (uses FPP's built-in tethering)
    html += buildTetherSection(iface);

    // Save button
    html += '<div class="mt-3">';
    html += '<button class="btn btn-success btn-sm btn-save-fpp" data-iface="' + iface.name + '" data-role="show">';
    html += '<i class="fas fa-save"></i> Save & Apply</button>';
    html += '<span class="save-status-' + iface.name + ' ms-2"></span>';
    html += '</div>';

    return html;
}

// =============================================================================
// Tethering Section (uses FPP's built-in tethering — no duplication)
// =============================================================================
function buildTetherSection(iface) {
    if (!iface.wireless) return '';

    var html = '<hr class="my-2">';
    html += '<div class="row mb-2 tether-row" data-iface="' + iface.name + '" style="display:none;">';
    html += '<label class="col-sm-3 col-form-label"><i class="fas fa-mobile-alt me-1"></i> Tether</label>';
    html += '<div class="col-sm-9 d-flex align-items-center justify-content-between">';
    html += '<small class="text-muted tether-status">Loading...</small>';
    html += '<a href="/networkconfig-original.php#tab-tethering" target="_blank" class="btn btn-outline-info btn-sm ms-2 text-nowrap">';
    html += '<i class="fas fa-cog"></i> Tether Settings</a>';
    html += '</div></div>';

    return html;
}

function loadTetherStatus() {
    $.when(
        $.get('/api/settings/EnableTethering'),
        $.get('/api/settings/TetherInterface'),
        $.get('/api/settings/TetherSSID')
    ).done(function(r1, r2, r3) {
        var mode = String(r1[0].value != null ? r1[0].value : r1[0]);
        var iface = String(r2[0].value != null ? r2[0].value : r2[0]);
        var ssid = String(r3[0].value != null ? r3[0].value : r3[0]) || 'FPP';

        var modeLabel = 'Disabled';
        if (mode === '0') modeLabel = 'If no connection';
        else if (mode === '1') modeLabel = 'Always';

        var statusText = iface + ' / ' + modeLabel + ' / SSID: ' + escHtml(ssid);

        $('.tether-row').each(function() {
            if ($(this).data('iface') === iface) {
                $(this).find('.tether-status').html(statusText);
                $(this).show();
            }
        });
    });
}

// =============================================================================
// Listener Network Role Settings
// =============================================================================
function buildListenerSettings(iface) {
    var html = '';
    html += '<div class="listener-config-' + iface.name + '">';
    html += '<div class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading AP config...</div>';
    html += '</div>';

    setTimeout(function() { loadListenerConfig(iface.name); }, 100);

    return html;
}

function getSubnet24(ip) {
    if (!ip) return '';
    var parts = ip.replace(/\/.*/, '').split('.');
    return parts.length >= 3 ? parts[0] + '.' + parts[1] + '.' + parts[2] : '';
}

function checkSubnetConflicts(apIp, listenerIface) {
    var apSubnet = getSubnet24(apIp);
    if (!apSubnet) return [];
    var conflicts = [];
    currentInterfaces.forEach(function(iface) {
        if (iface.name === listenerIface) return;
        var fpp = currentFppMap[iface.name];
        if (!fpp) return;
        var cfgAddr = (fpp.config && fpp.config.ADDRESS) ? fpp.config.ADDRESS : '';
        if (cfgAddr && getSubnet24(cfgAddr) === apSubnet) {
            conflicts.push({ iface: iface.label || iface.name, ip: cfgAddr, source: 'configured' });
        }
        var liveAddr = fpp.addr || '';
        if (liveAddr && liveAddr !== cfgAddr && getSubnet24(liveAddr) === apSubnet) {
            conflicts.push({ iface: iface.label || iface.name, ip: liveAddr, source: 'active' });
        }
    });
    return conflicts;
}

function loadListenerConfig(ifaceName) {
    pluginAPI('get_status', null, function(statusRes) {
        pluginAPI('get_config', null, function(cfgRes) {
            if (!cfgRes.success) return;
            var cfg = cfgRes.config;
            var apIp = cfg.ap_ip || '192.168.50.1';
            var html = '';

            // Service status row
            if (statusRes.success) {
                html += '<div class="mb-3">';
                html += svcBadge('AP', statusRes.services['listener-ap']);
                html += svcBadge('DNS', statusRes.services['dnsmasq']);
                html += svcBadge('WS-Sync', statusRes.services['ws-sync']);
                html += svcBadge('Firewall', statusRes.services['nftables']);
                if (statusRes.clientCount > 0) {
                    html += '<span class="badge bg-info ms-1">' + statusRes.clientCount + ' client' + (statusRes.clientCount !== 1 ? 's' : '') + '</span>';
                }
                html += '</div>';
            }

            // Network isolation notice
            html += '<div class="alert alert-info py-2 mb-3" style="font-size:0.85em;">';
            html += '<i class="fas fa-shield-alt me-1"></i> <strong>Isolated network.</strong> ';
            html += 'This interface runs a standalone access point with its own DHCP, DNS, and firewall. ';
            html += 'Devices on this network <strong>cannot</strong> reach the show network, internet, or FPP admin. ';
            html += 'IP forwarding is disabled and all non-listener traffic is rejected.';
            html += '</div>';

            // Subnet conflict check
            var conflicts = checkSubnetConflicts(apIp, ifaceName);
            if (conflicts.length > 0) {
                html += '<div class="alert alert-warning py-2 mb-3" style="font-size:0.85em;">';
                html += '<i class="fas fa-exclamation-triangle me-1"></i> <strong>Subnet conflict!</strong> ';
                html += 'The AP subnet <code>' + getSubnet24(apIp) + '.x</code> overlaps with: ';
                conflicts.forEach(function(c, i) {
                    if (i > 0) html += ', ';
                    html += '<strong>' + c.iface + '</strong> (' + c.ip + ')';
                });
                html += '. Change the AP IP to a different subnet to avoid routing issues.';
                html += '</div>';
            }

            // AP settings
            html += inputRow(ifaceName, 'ssid', 'SSID', cfg.ssid || 'SHOW_AUDIO', 'Network name', 'text', 32);

            // Channel dropdown
            html += '<div class="row mb-2">';
            html += '<label class="col-sm-3 col-form-label">Channel</label>';
            html += '<div class="col-sm-9">';
            html += '<select class="form-select form-select-sm" id="field-' + ifaceName + '-channel" style="width:auto;display:inline-block;">';
            for (var ch = 1; ch <= 11; ch++) {
                var sel = (ch == (cfg.channel || 6)) ? ' selected' : '';
                html += '<option value="' + ch + '"' + sel + '>' + ch + '</option>';
            }
            html += '</select></div></div>';

            html += inputRow(ifaceName, 'password', 'Password', '', 'Open (no password)', 'password');
            html += '<div class="row mb-2"><div class="col-sm-9 offset-sm-3"><small class="form-text">Leave blank for open network. 8-63 chars for WPA2.</small></div></div>';
            html += inputRow(ifaceName, 'ap_ip', 'AP IP Address', apIp, '192.168.50.1');

            // Save button
            html += '<div class="mt-3">';
            html += '<button class="btn btn-success btn-sm btn-save-listener" data-iface="' + ifaceName + '">';
            html += '<i class="fas fa-save"></i> Save & Restart AP</button>';
            html += '<span class="save-status-' + ifaceName + ' ms-2"></span>';
            html += '</div>';

            $('.listener-config-' + ifaceName).html(html);
        });
    });
}

function svcBadge(label, status) {
    var color = status === 'active' ? 'success' : (status === 'inactive' ? 'secondary' : 'danger');
    return '<span class="badge bg-' + color + ' me-1">' + label + '</span>';
}

// =============================================================================
// Form Helpers
// =============================================================================
function inputRow(iface, field, label, value, placeholder, type, maxlen) {
    type = type || 'text';
    maxlen = maxlen || '';
    var maxAttr = maxlen ? ' maxlength="' + maxlen + '"' : '';
    var inputId = 'field-' + iface + '-' + field;
    var html = '<div class="row mb-2">';
    html += '<label class="col-sm-3 col-form-label">' + label + '</label>';
    html += '<div class="col-sm-9">';
    if (type === 'password') {
        html += '<div class="input-group input-group-sm">';
        html += '<input type="password" class="form-control form-control-sm" id="' + inputId + '"';
        html += ' value="' + escHtml(value) + '" placeholder="' + escHtml(placeholder) + '"' + maxAttr + '>';
        html += '<button class="btn btn-outline-secondary btn-toggle-pw" type="button" data-target="' + inputId + '" title="Show/hide password">';
        html += '<i class="fas fa-eye"></i></button>';
        html += '</div>';
    } else {
        html += '<input type="' + type + '" class="form-control form-control-sm" id="' + inputId + '"';
        html += ' value="' + escHtml(value) + '" placeholder="' + escHtml(placeholder) + '"' + maxAttr + '>';
    }
    html += '</div></div>';
    return html;
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function getField(iface, field) {
    return $('#field-' + iface + '-' + field).val() || '';
}

// =============================================================================
// Save Handlers
// =============================================================================

// Save Internet or Show role via FPP API
function saveFPP(ifaceName, role) {
    var proto = $('input[name="proto-' + ifaceName + '"]:checked').val() || 'dhcp';
    var data = {
        INTERFACE: ifaceName,
        PROTO: proto
    };

    if (proto === 'static') {
        data.ADDRESS = getField(ifaceName, 'address');
        data.NETMASK = getField(ifaceName, 'netmask');
        data.GATEWAY = getField(ifaceName, 'gateway');
    }

    // WiFi fields — include whenever the SSID field exists (Show or Internet on wireless)
    if ($('#field-' + ifaceName + '-ssid').length) {
        data.SSID = getField(ifaceName, 'ssid');
        data.PSK = getField(ifaceName, 'psk');
        data.HIDDEN = '';
        data.WPA3 = '';
        data.BACKUPSSID = '';
        data.BACKUPPSK = '';
        data.BACKUPHIDDEN = '';
        data.BACKUPWPA3 = '';
    }

    var statusEl = $('.save-status-' + ifaceName);
    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    // Save config, then apply, then fix WiFi PMF if wireless
    fppAPI('POST', 'interface/' + ifaceName, data, function(res) {
        if (res.status === 'OK') {
            statusEl.html('<i class="fas fa-spinner fa-spin"></i> Applying...');
            fppAPI('POST', 'interface/' + ifaceName + '/apply', {}, function(applyRes) {
                if (data.SSID) {
                    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Connecting WiFi...');
                    pluginAPI('fix_wifi', { interface: ifaceName }, function(fixRes) {
                        var state = fixRes.state || 'unknown';
                        if (state === 'COMPLETED') {
                            statusEl.html('<span class="text-success"><i class="fas fa-check"></i> Connected!</span>');
                        } else {
                            statusEl.html('<span class="text-warning"><i class="fas fa-clock"></i> WiFi state: ' + state + ' (may take a moment)</span>');
                        }
                        setTimeout(function() { statusEl.html(''); loadDashboard(); }, 5000);
                    });
                } else {
                    statusEl.html('<span class="text-success"><i class="fas fa-check"></i> Applied</span>');
                    setTimeout(function() { statusEl.html(''); loadDashboard(); }, 3000);
                }
            });
        } else {
            statusEl.html('<span class="text-danger">Error: ' + (res.status || 'unknown') + '</span>');
        }
    });
}

// Save Listener role via our API
function saveListener(ifaceName) {
    var data = {
        interface: ifaceName,
        ssid: getField(ifaceName, 'ssid'),
        channel: $('#field-' + ifaceName + '-channel').val() || '6',
        password: getField(ifaceName, 'password'),
        ap_ip: getField(ifaceName, 'ap_ip')
    };

    if (!data.ssid) {
        $('.save-status-' + ifaceName).html('<span class="text-danger">SSID is required</span>');
        return;
    }

    if (!confirm('Save settings and restart the AP?')) return;

    var statusEl = $('.save-status-' + ifaceName);
    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    pluginAPI('save_config', data, function(res) {
        if (res.success) {
            statusEl.html('<span class="text-success"><i class="fas fa-check"></i> ' + res.message + '</span>');
            setTimeout(function() { statusEl.html(''); loadDashboard(); }, 3000);
        } else {
            statusEl.html('<span class="text-danger">' + res.error + '</span>');
        }
    });
}

// WiFi Scan
function wifiScan(ifaceName) {
    var statusEl = $('.scan-status-' + ifaceName);
    statusEl.html('<i class="fas fa-spinner fa-spin"></i> Scanning (up to 30s)...');

    $.ajax({
        url: '/api/network/wifi/scan/' + ifaceName,
        method: 'GET',
        dataType: 'json',
        timeout: 35000,
        success: function(res) {
            statusEl.html('');
            showScanResults(ifaceName, res);
        },
        error: function(xhr, textStatus) {
            var msg = textStatus === 'timeout' ? 'Scan timed out' : 'Scan failed (HTTP ' + xhr.status + ')';
            statusEl.html('<small class="text-danger">' + msg + '</small>');
        }
    });
}

function showScanResults(ifaceName, res) {
    var resultsEl = $('.scan-results-' + ifaceName);

    if (!res || res.error) {
        resultsEl.show().html('<small class="text-danger">Scan failed</small>');
        return;
    }

    var raw = Array.isArray(res) ? res : (res.networks || []);
    var networks = [];
    raw.forEach(function(net) {
        if (net && typeof net === 'object' && !Array.isArray(net) && net.SSID) {
            networks.push(net);
        }
    });

    if (!networks.length) {
        resultsEl.show().html('<small class="text-muted">No networks found</small>');
        return;
    }

    networks.sort(function(a, b) {
        var sa = parseFloat(a.signal) || -100;
        var sb = parseFloat(b.signal) || -100;
        return sb - sa;
    });

    var html = '<div class="list-group list-group-flush" style="max-height:200px; overflow-y:auto;">';
    networks.forEach(function(net) {
        var ssid = net.SSID || '(hidden)';
        var signal = net.signal || '';
            html += '<a href="#" class="list-group-item list-group-item-action scan-result-item py-1 px-2" data-ssid="' + escHtml(ssid) + '" data-iface="' + ifaceName + '">';
            html += '<small>' + escHtml(ssid);
            if (signal) html += ' <span class="text-muted">(' + signal + ')</span>';
            html += '</small></a>';
    });
    html += '</div>';
    resultsEl.show().html(html);
}

// =============================================================================
// Connected Clients
// =============================================================================
function loadClients() {
    pluginAPI('get_clients', null, function(res) {
        var tbody = $('#clients-tbody');
        tbody.empty();
        if (!res.success || !res.clients || !res.clients.length) {
            tbody.html('<tr><td colspan="5" class="text-muted text-center">No clients connected</td></tr>');
            return;
        }
        res.clients.forEach(function(c) {
            var signal = c.signal || '--';
            var signalClass = '';
            if (c.signal) {
                var dbm = parseInt(c.signal);
                if (dbm >= -50) signalClass = 'text-success';
                else if (dbm >= -70) signalClass = 'text-warning';
                else signalClass = 'text-danger';
            }
            tbody.append(
                '<tr>' +
                '<td><code>' + (c.mac || '--') + '</code></td>' +
                '<td>' + (c.ip || '--') + '</td>' +
                '<td>' + (c.hostname || '--') + '</td>' +
                '<td class="' + signalClass + '">' + signal + '</td>' +
                '<td>' + (c.connected || '--') + '</td>' +
                '</tr>'
            );
        });
    });
}

function startClientRefresh() {
    if (clientRefreshTimer) clearInterval(clientRefreshTimer);
    clientRefreshTimer = setInterval(function() {
        if ($('#auto-refresh-clients').is(':checked') && $('#clients-section').is(':visible')) {
            loadClients();
        }
    }, 5000);
}

// =============================================================================
// Logs & Diagnostics
// =============================================================================
function loadLogs() {
    var source = $('#log-source').val();
    var lines = $('#log-lines').val();
    $('#log-output').text('Loading...');
    pluginAPI('get_logs', { source: source, lines: lines }, function(res) {
        if (res.success) {
            $('#log-output').text(res.log || '(empty)');
            var el = document.getElementById('log-output');
            el.scrollTop = el.scrollHeight;
        } else {
            $('#log-output').text('Error: ' + res.error);
        }
    });
}

function clearLogs() {
    var source = $('#log-source').val();
    if (!confirm('Clear ' + source + ' logs?')) return;
    pluginAPI('clear_logs', { source: source }, function(res) {
        if (res.success) {
            $('#log-output').text('(logs cleared)');
        } else {
            $('#log-output').text('Error: ' + (res.error || 'Failed to clear logs'));
        }
    });
}

function runSelfTest() {
    $('#btn-selftest').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Testing...');
    pluginAPI('selftest', null, function(res) {
        $('#btn-selftest').prop('disabled', false).html('<i class="fas fa-stethoscope"></i> Self-Test');
        if (!res.success) {
            $('#selftest-results').show().html('<div class="alert alert-danger">Self-test failed to run</div>');
            return;
        }
        var html = '<table class="table table-sm">';
        res.results.forEach(function(r) {
            var icon = r.pass ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>';
            html += '<tr><td>' + icon + '</td><td>' + r.test + '</td><td>' + r.detail + '</td></tr>';
        });
        html += '</table>';
        var alertClass = res.allPass ? 'alert-success' : 'alert-warning';
        var summary = res.allPass ? 'All checks passed!' : 'Some checks failed.';
        $('#selftest-results').show().html('<div class="alert ' + alertClass + ' py-1">' + summary + '</div>' + html);
    });
}

function restartServiceBtn(service) {
    if (!confirm('Restart ' + service + '?')) return;
    pluginAPI('restart_service', { service: service }, function(res) {
        if (res.success) {
            $.jGrowl(res.message, { themeState: 'success' });
        } else {
            $.jGrowl(res.error || 'Failed', { themeState: 'danger' });
        }
        setTimeout(loadDashboard, 2000);
    });
}

// =============================================================================
// Event Delegation (cards are dynamic, use delegation on container)
// =============================================================================
$(document).ready(function() {
    loadDashboard();

    // Role change handler
    $(document).on('change', '.role-select', function() {
        var iface = $(this).data('iface');
        var role = $(this).val();
        pluginAPI('save_role', { interface: iface, role: role }, function(res) {
            if (res.success) {
                currentRoles = res.roles;
                loadDashboard();
            }
        });
    });

    // Protocol radio change
    $(document).on('change', '.proto-radio', function() {
        var iface = $(this).data('iface');
        var proto = $(this).val();
        if (proto === 'static') {
            $('.static-fields-' + iface).slideDown(200);
        } else {
            $('.static-fields-' + iface).slideUp(200);
        }
    });

    // Save FPP interface (Internet/Show)
    $(document).on('click', '.btn-save-fpp', function() {
        var iface = $(this).data('iface');
        var role = $(this).data('role');
        saveFPP(iface, role);
    });

    // Save Listener config
    $(document).on('click', '.btn-save-listener', function() {
        var iface = $(this).data('iface');
        saveListener(iface);
    });

    // WiFi scan
    $(document).on('click', '.btn-wifi-scan', function() {
        var iface = $(this).data('iface');
        wifiScan(iface);
    });

    // Scan result click — fill SSID
    $(document).on('click', '.scan-result-item', function(e) {
        e.preventDefault();
        var ssid = $(this).data('ssid');
        var iface = $(this).data('iface');
        $('#field-' + iface + '-ssid').val(ssid);
        $('.scan-results-' + iface).slideUp(200);
    });

    // Password show/hide toggle
    $(document).on('click', '.btn-toggle-pw', function() {
        var targetId = $(this).data('target');
        var input = $('#' + targetId);
        var icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // Refresh clients
    $('#btn-refresh-clients').on('click', loadClients);

    // Logs & diagnostics
    $('#btn-selftest').on('click', runSelfTest);
    $('#btn-load-logs').on('click', loadLogs);
    $('#btn-clear-logs').on('click', clearLogs);
    $('#btn-restart-ap').on('click', function() { restartServiceBtn('listener-ap'); });
    $('#btn-restart-ws').on('click', function() { restartServiceBtn('ws-sync'); });
    $('#btn-restart-dns').on('click', function() { restartServiceBtn('dnsmasq'); });

    // Help button
    $('#btn-help').on('click', showHelp);
});

// =============================================================================
// Help / README display
// =============================================================================
function showHelp() {
    var modal = new bootstrap.Modal(document.getElementById('helpModal'));
    modal.show();
    pluginAPI('get_readme', null, function(res) {
        if (res.success) {
            $('#help-content').html(renderMarkdown(res.content));
        } else {
            $('#help-content').html('<p class="text-danger">Could not load README.</p>');
        }
    });
}

// Minimal markdown-to-HTML renderer
function renderMarkdown(md) {
    var html = '';
    var lines = md.split('\n');
    var inCode = false, inTable = false, inList = false, listType = '';
    var codeBlock = '';

    for (var i = 0; i < lines.length; i++) {
        var line = lines[i];

        if (line.match(/^```/)) {
            if (inCode) {
                html += '<pre class="bg-black text-light p-2 rounded" style="font-size:0.85em;">' + escHtml(codeBlock) + '</pre>';
                codeBlock = '';
                inCode = false;
            } else {
                if (inList) { html += '</' + listType + '>'; inList = false; }
                if (inTable) { html += '</tbody></table>'; inTable = false; }
                inCode = true;
            }
            continue;
        }
        if (inCode) { codeBlock += (codeBlock ? '\n' : '') + line; continue; }

        if (inList && !line.match(/^(\s*[-*]\s|^\s*\d+\.\s)/)) {
            html += '</' + listType + '>'; inList = false;
        }

        if (inTable && !line.match(/^\|/)) {
            html += '</tbody></table>'; inTable = false;
        }

        if (line.trim() === '') { continue; }

        if (line.match(/^#{1,6}\s/)) {
            var level = line.match(/^(#{1,6})/)[1].length;
            var text = inlineFormat(line.replace(/^#{1,6}\s+/, ''));
            html += '<h' + level + ' class="mt-3 mb-2">' + text + '</h' + level + '>';
            continue;
        }

        if (line.match(/^(-{3,}|\*{3,}|_{3,})\s*$/)) {
            html += '<hr>';
            continue;
        }

        if (line.match(/^>\s/)) {
            html += '<blockquote class="border-start border-info ps-3 text-muted">' + inlineFormat(line.replace(/^>\s+/, '')) + '</blockquote>';
            continue;
        }

        if (line.match(/^\|/)) {
            var cells = line.split('|').filter(function(c) { return c.trim() !== ''; });
            if (cells[0] && cells[0].match(/^[\s-:]+$/)) { continue; }
            if (!inTable) {
                inTable = true;
                html += '<table class="table table-sm table-dark table-bordered" style="font-size:0.85em;"><tbody>';
                var isHeader = (i + 1 < lines.length && lines[i + 1].match(/^\|[\s-:|]+\|/));
                var tag = isHeader ? 'th' : 'td';
                html += '<tr>';
                cells.forEach(function(c) { html += '<' + tag + '>' + inlineFormat(c.trim()) + '</' + tag + '>'; });
                html += '</tr>';
            } else {
                html += '<tr>';
                cells.forEach(function(c) { html += '<td>' + inlineFormat(c.trim()) + '</td>'; });
                html += '</tr>';
            }
            continue;
        }

        if (line.match(/^\s*[-*]\s/)) {
            if (!inList || listType !== 'ul') {
                if (inList) html += '</' + listType + '>';
                html += '<ul>'; inList = true; listType = 'ul';
            }
            html += '<li>' + inlineFormat(line.replace(/^\s*[-*]\s+/, '')) + '</li>';
            continue;
        }

        if (line.match(/^\s*\d+\.\s/)) {
            if (!inList || listType !== 'ol') {
                if (inList) html += '</' + listType + '>';
                html += '<ol>'; inList = true; listType = 'ol';
            }
            html += '<li>' + inlineFormat(line.replace(/^\s*\d+\.\s+/, '')) + '</li>';
            continue;
        }

        html += '<p>' + inlineFormat(line) + '</p>';
    }

    if (inList) html += '</' + listType + '>';
    if (inTable) html += '</tbody></table>';
    if (inCode) html += '<pre class="bg-black text-light p-2 rounded">' + escHtml(codeBlock) + '</pre>';
    return html;
}

function inlineFormat(text) {
    text = escHtml(text);
    text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1" style="max-width:100%;">');
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" class="text-info">$1</a>');
    text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
    text = text.replace(/`([^`]+)`/g, '<code class="text-info">$1</code>');
    return text;
}
