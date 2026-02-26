<?php
// =============================================================================
// plugin.php - SBS Audio Sync Network Dashboard
// =============================================================================
// Card-based network configuration page. Each detected interface gets its own
// card with a configurable role (Internet, Show, Listener, Unused).
//
// Replaces FPP's networkconfig.php when the plugin is installed.
// FPP's plugin.php handler wraps this with header/navbar/footer.
// jQuery 3.7.1 and Bootstrap 5 are available from FPP.
//
// JS/CSS are in www/listen/ and served directly by Apache (not through FPP's
// plugin.php file handler which produces malformed Content-Type headers that
// browsers reject with X-Content-Type-Options: nosniff).
// =============================================================================

$version = trim(@file_get_contents(dirname(__FILE__) . '/VERSION') ?: 'unknown');
$vEnc = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
$pluginName = 'SBSPlus';
?>

<!-- Cache-busted CSS/JS (served directly by Apache, not through FPP's PHP handler) -->
<link rel="stylesheet" href="/listen/dashboard.css?v=<?= $vEnc ?>">
<script src="/listen/dashboard.js?v=<?= $vEnc ?>"></script>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1><i class="fas fa-network-wired"></i> SBS Audio Sync
        <img src="/listen/logo.png" alt="" style="height:36px; vertical-align:middle; margin-left:12px;">
        <small class="text-muted" style="font-size:0.4em; vertical-align:middle; margin-left:4px;">v<?= $vEnc ?></small>
    </h1>
    <div>
        <button id="btn-help" class="btn btn-outline-info btn-sm me-1" title="About SBS Audio Sync">
            <i class="fas fa-question-circle"></i> Help
        </button>
        <a href="/networkconfig-original.php" class="btn btn-outline-secondary btn-sm"
           title="Open FPP's original network configuration page">
            <i class="fas fa-cogs"></i> Advanced (FPP)
        </a>
    </div>
</div>

<!-- Quick Links Bar -->
<div class="mb-4">
    <a id="link-admin" href="/listen/admin.html" target="_blank" class="btn btn-outline-warning btn-sm me-1">
        <i class="fas fa-sliders-h"></i> Admin Page
    </a>
    <a id="link-listener" href="#" target="_blank" class="btn btn-outline-info btn-sm me-1">
        <i class="fas fa-broadcast-tower"></i> Listener Page
    </a>
    <a id="link-qrcode" href="#" target="_blank" class="btn btn-outline-info btn-sm me-1">
        <i class="fas fa-qrcode"></i> QR Code
    </a>
    <a id="link-sign" href="#" target="_blank" class="btn btn-outline-info btn-sm me-1">
        <i class="fas fa-print"></i> Print Sign
    </a>
</div>

<!-- Interface Cards Container (populated by JS) -->
<div id="interface-cards" class="row g-3 mb-4">
    <div class="col-12 text-center text-muted py-4">
        <i class="fas fa-spinner fa-spin"></i> Detecting network interfaces...
    </div>
</div>

<!-- Connected Clients Section (shown when a Listener interface exists) -->
<div id="clients-section" class="mb-4" style="display:none;">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"
             data-bs-toggle="collapse" data-bs-target="#clients-collapse" role="button">
            <h5 class="mb-0"><i class="fas fa-mobile-alt"></i> Connected Clients</h5>
            <div>
                <label class="form-check-label me-2">
                    <input type="checkbox" id="auto-refresh-clients" class="form-check-input" checked>
                    Auto-refresh
                </label>
                <button id="btn-refresh-clients" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        <div id="clients-collapse" class="collapse show">
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>MAC Address</th>
                            <th>IP</th>
                            <th>Hostname</th>
                            <th>Signal</th>
                            <th>Connected</th>
                        </tr>
                    </thead>
                    <tbody id="clients-tbody">
                        <tr><td colspan="5" class="text-muted text-center">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Logs & Diagnostics Section -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center"
         data-bs-toggle="collapse" data-bs-target="#logs-collapse" role="button">
        <h5 class="mb-0"><i class="fas fa-file-alt"></i> Logs & Diagnostics</h5>
        <div>
            <button id="btn-selftest" class="btn btn-outline-info btn-sm me-1">
                <i class="fas fa-stethoscope"></i> Self-Test
            </button>
            <button id="btn-restart-ap" class="btn btn-outline-warning btn-sm me-1" title="Restart Listener AP">
                <i class="fas fa-redo"></i> AP
            </button>
            <button id="btn-restart-ws" class="btn btn-outline-warning btn-sm me-1" title="Restart WebSocket Sync">
                <i class="fas fa-redo"></i> WS
            </button>
            <button id="btn-restart-dns" class="btn btn-outline-warning btn-sm" title="Restart DNS/DHCP">
                <i class="fas fa-redo"></i> DNS
            </button>
        </div>
    </div>
    <div id="logs-collapse" class="collapse show">
        <div class="card-body">
            <div id="selftest-results" class="mb-3" style="display:none;"></div>
            <div class="mb-2">
                <select id="log-source" class="form-select d-inline-block" style="width:auto;">
                    <option value="ws-sync">WebSocket Sync</option>
                    <option value="listener-ap">Listener AP</option>
                    <option value="dnsmasq">DNS/DHCP</option>
                    <option value="sync">Sync Reports</option>
                </select>
                <select id="log-lines" class="form-select d-inline-block ms-1" style="width:auto;">
                    <option value="25">25 lines</option>
                    <option value="50" selected>50 lines</option>
                    <option value="100">100 lines</option>
                    <option value="200">200 lines</option>
                </select>
                <button id="btn-load-logs" class="btn btn-info btn-sm ms-1">
                    <i class="fas fa-download"></i> Load
                </button>
                <button id="btn-clear-logs" class="btn btn-outline-danger btn-sm ms-1">
                    <i class="fas fa-trash-alt"></i> Clear
                </button>
            </div>
            <pre id="log-output" class="log-output">Select a log source and click Load.</pre>
        </div>
    </div>
</div>

<!-- Help/About Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="helpModalLabel">
                    <i class="fas fa-question-circle"></i> SBS Audio Sync - Help
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="help-content" style="font-size:0.9rem;">
                <div class="text-center text-muted py-4">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <a href="https://github.com/UndocEng/fpp-sbs-plus" target="_blank" class="btn btn-outline-info btn-sm me-auto">
                    <i class="fab fa-github"></i> GitHub
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
