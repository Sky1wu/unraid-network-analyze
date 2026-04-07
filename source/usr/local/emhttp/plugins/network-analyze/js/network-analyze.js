/**
 * Network Analyze - Frontend for Unraid plugin
 *
 * Real-time network monitoring with 2-second polling.
 * Uses jQuery (bundled with Unraid webGUI).
 */
var NetworkAnalyze = (function ($) {
    'use strict';

    var POLL_INTERVAL = 2000;
    var AJAX_URL = '/plugins/network-analyze/include/ajax.php';
    var MAX_PROCESS_ROWS = 100;
    var MAX_CONN_ROWS = 500;

    var timer = null;
    var paused = false;
    var activeTab = 'processes';
    var processData = [];
    var connData = [];
    var sortState = { col: 'socket_count', dir: 'desc' };
    var connSortState = { col: 'pid', dir: 'asc' };
    var expandedPid = null;
    var searchTimer = null;
    var hiddenNamespaces = {};

    // --- Initialization ---

    function init() {
        bindEvents();
        loadNamespaces();
        startPolling();
    }

    function bindEvents() {
        // Tab switching
        $('.na-tab').on('click', function () {
            var tab = $(this).data('tab');
            switchTab(tab);
        });

        // Pause/Resume
        $('#na-pause').on('click', togglePause);

        // Table sorting
        $('#process-table th.sortable').on('click', function () {
            var col = $(this).data('col');
            toggleSort(col, 'process');
        });
        $('#connection-table th.sortable').on('click', function () {
            var col = $(this).data('col');
            toggleSort(col, 'connection');
        });

        // Filters
        $('#filter-protocol, #filter-state, #filter-hide-timewait').on('change', function () {
            renderConnTable(connData);
        });
        $('#filter-search').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                renderConnTable(connData);
            }, 300);
        });
    }

    // --- Namespace Filters ---

    function loadNamespaces() {
        $.post(AJAX_URL, { cmd: 'get_namespaces' }, function (data) {
            var namespaces = data.namespaces || [];
            var $container = $('#na-ns-filters');
            var html = '';

            for (var i = 0; i < namespaces.length; i++) {
                var ns = namespaces[i];
                var label = escHtml(ns.label);
                var checked = hiddenNamespaces[ns.label] ? '' : ' checked';
                html += '<label class="na-checkbox">';
                html += '<input type="checkbox" class="na-ns-cb" data-ns-label="' + label + '"' + checked + '> ';
                html += label + ' <span class="na-ns-count">(' + ns.process_count + ')</span>';
                html += '</label>';
            }

            $container.html(html);

            // Bind checkbox change
            $container.find('.na-ns-cb').on('change', function () {
                var nsLabel = $(this).data('ns-label');
                if ($(this).is(':checked')) {
                    delete hiddenNamespaces[nsLabel];
                } else {
                    hiddenNamespaces[nsLabel] = true;
                }
                // Re-render current tab
                if (activeTab === 'processes') {
                    renderProcessTable();
                } else {
                    renderConnTable(connData);
                }
            });
        }, 'json');
    }

    // --- Polling ---

    function startPolling() {
        poll();
        timer = setInterval(poll, POLL_INTERVAL);
    }

    function poll() {
        if (paused) return;

        // Always fetch interfaces for the summary bar
        $.post(AJAX_URL, { cmd: 'get_interfaces' }, renderInterfaces, 'json');

        if (activeTab === 'processes') {
            $.post(AJAX_URL, { cmd: 'get_process_list' }, function (data) {
                processData = data.processes || [];
                renderProcessTable();
            }, 'json');
        } else {
            $.post(AJAX_URL, { cmd: 'get_connections' }, function (data) {
                connData = data.connections || [];
                renderConnTable(connData);
            }, 'json');
        }
    }

    // --- Tab switching ---

    function switchTab(tab) {
        activeTab = tab;
        $('.na-tab').removeClass('active');
        $('.na-tab[data-tab="' + tab + '"]').addClass('active');
        $('.na-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');

        // Immediately fetch data for the new tab
        poll();
    }

    function togglePause() {
        paused = !paused;
        $('#na-pause').text(paused ? 'Resume' : 'Pause');
        $('#na-status').html(paused ? '<span class="na-paused">Paused</span>' : 'Live &middot; 2s');
    }

    // --- Sorting ---

    function toggleSort(col, tableType) {
        var state = tableType === 'process' ? sortState : connSortState;
        if (state.col === col) {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.col = col;
            state.dir = 'asc';
        }

        if (tableType === 'process') {
            sortState = state;
            renderProcessTable();
        } else {
            connSortState = state;
            renderConnTable(connData);
        }
    }

    function sortArray(arr, col, dir) {
        return arr.slice().sort(function (a, b) {
            var va = a[col];
            var vb = b[col];
            var cmp = 0;

            if (typeof va === 'number' && typeof vb === 'number') {
                cmp = va - vb;
            } else {
                cmp = String(va || '').localeCompare(String(vb || ''));
            }

            return dir === 'asc' ? cmp : -cmp;
        });
    }

    // --- Process Table ---

    function renderProcessTable() {
        var sorted = sortArray(processData, sortState.col, sortState.dir);

        // Filter by namespace
        if (Object.keys(hiddenNamespaces).length > 0) {
            sorted = sorted.filter(function (p) {
                return !hiddenNamespaces[p.ns_label];
            });
        }

        var rows = sorted.slice(0, MAX_PROCESS_ROWS);
        var $tbody = $('#process-table tbody');
        var html = '';

        for (var i = 0; i < rows.length; i++) {
            var p = rows[i];
            var isExpanded = (expandedPid === p.pid);
            html += '<tr class="na-proc-row' + (isExpanded ? ' expanded' : '') + '" data-pid="' + p.pid + '">';
            html += '<td class="na-proc-name" title="' + escHtml(p.cmdline) + '">' + escHtml(p.comm) + '</td>';
            html += '<td>' + p.pid + '</td>';
            html += '<td><span class="na-ns-badge">' + escHtml(p.ns_label) + '</span></td>';
            html += '<td>' + p.socket_count + '</td>';
            html += '<td>' + formatBytes(p.io_rx_rate) + '/s</td>';
            html += '<td>' + formatBytes(p.io_tx_rate) + '/s</td>';
            html += '<td>' + (p.connections ? p.connections.length : 0) + ' <span class="na-expand-hint">' + (isExpanded ? '&#9650;' : '&#9660;') + '</span></td>';
            html += '</tr>';

            if (isExpanded && p.connections && p.connections.length > 0) {
                html += '<tr class="na-detail-row"><td colspan="7">';
                html += '<table class="na-inner-table"><thead><tr><th>Protocol</th><th>Local</th><th>Remote</th><th>State</th></tr></thead><tbody>';
                for (var j = 0; j < p.connections.length; j++) {
                    var c = p.connections[j];
                    html += '<tr>';
                    html += '<td>' + escHtml(c.protocol) + '</td>';
                    html += '<td>' + escHtml(c.local) + '</td>';
                    html += '<td>' + escHtml(c.remote) + '</td>';
                    html += '<td><span class="na-state na-state-' + escHtml(c.state).toLowerCase() + '">' + escHtml(c.state) + '</span></td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                if (p.connections.length >= 20) {
                    html += '<div class="na-more">Showing first 20 connections, switch to "Connections" tab for the full list.</div>';
                }
                html += '</td></tr>';
            }
        }

        if (rows.length === 0) {
            html = '<tr><td colspan="7" class="na-empty">No processes with network activity found</td></tr>';
        }

        if (processData.length > MAX_PROCESS_ROWS) {
            html += '<tr><td colspan="7" class="na-more">Showing first ' + MAX_PROCESS_ROWS + ' processes (of ' + processData.length + ', sorted by socket count)</td></tr>';
        }

        $tbody.html(html);

        // Update sort indicators
        updateSortIndicators('#process-table', sortState);

        // Click to expand
        $tbody.find('.na-proc-row').on('click', function () {
            var pid = parseInt($(this).data('pid'));
            expandedPid = (expandedPid === pid) ? null : pid;
            renderProcessTable();
        });
    }

    // --- Connection Table ---

    function renderConnTable(connections) {
        var filtered = filterConnections(connections);
        var sorted = sortArray(filtered, connSortState.col, connSortState.dir);
        var rows = sorted.slice(0, MAX_CONN_ROWS);
        var $tbody = $('#connection-table tbody');
        var html = '';

        for (var i = 0; i < rows.length; i++) {
            var c = rows[i];
            html += '<tr>';
            html += '<td>' + escHtml(c.process) + '</td>';
            html += '<td>' + (c.pid || '-') + '</td>';
            html += '<td>' + escHtml(c.protocol).toUpperCase() + '</td>';
            html += '<td>' + escHtml(c.local_addr) + ':' + c.local_port + '</td>';

            var remoteDisplay = (c.remote_addr === '0.0.0.0' || c.remote_addr === '::') ? '*' : c.remote_addr + ':' + c.remote_port;
            html += '<td>' + escHtml(remoteDisplay) + '</td>';
            html += '<td><span class="na-state na-state-' + escHtml(c.state).toLowerCase() + '">' + escHtml(c.state) + '</span></td>';
            html += '</tr>';
        }

        if (rows.length === 0) {
            html = '<tr><td colspan="6" class="na-empty">No connections match current filters</td></tr>';
        }

        if (filtered.length > MAX_CONN_ROWS) {
            html += '<tr><td colspan="6" class="na-more">Showing first ' + MAX_CONN_ROWS + ' connections (of ' + filtered.length + ')</td></tr>';
        }

        $tbody.html(html);
        updateSortIndicators('#connection-table', connSortState);
    }

    function filterConnections(connections) {
        var proto = $('#filter-protocol').val();
        var state = $('#filter-state').val();
        var search = ($('#filter-search').val() || '').toLowerCase().trim();
        var hideTimeWait = $('#filter-hide-timewait').is(':checked');

        return connections.filter(function (c) {
            if (proto !== 'all' && c.protocol !== proto) return false;
            if (state !== 'all' && c.state !== state) return false;
            if (hideTimeWait && c.state === 'TIME_WAIT') return false;
            if (Object.keys(hiddenNamespaces).length > 0 && hiddenNamespaces[c.ns_label]) return false;
            if (search) {
                var haystack = (c.process + ' ' + c.local_addr + ' ' + c.local_port + ' ' +
                    c.remote_addr + ' ' + c.remote_port + ' ' + c.pid).toLowerCase();
                if (haystack.indexOf(search) === -1) return false;
            }
            return true;
        });
    }

    // --- Interface Summary ---

    function renderInterfaces(data) {
        var interfaces = data.interfaces || [];
        var $bar = $('#na-iface-bar');
        var html = '';

        for (var i = 0; i < interfaces.length; i++) {
            var iface = interfaces[i];
            html += '<div class="na-iface-card">';
            html += '<span class="na-iface-name">' + escHtml(iface.name) + '</span>';
            html += '<span class="na-iface-stat"><span class="na-arrow na-down">&#9660;</span> ' + formatBytes(iface.rx_rate) + '/s</span>';
            html += '<span class="na-iface-stat"><span class="na-arrow na-up">&#9650;</span> ' + formatBytes(iface.tx_rate) + '/s</span>';
            html += '</div>';
        }

        if (interfaces.length === 0) {
            html = '<div class="na-iface-card na-iface-empty">No network interfaces detected</div>';
        }

        $bar.html(html);
    }

    // --- Utilities ---

    function formatBytes(bytes) {
        if (bytes === 0 || bytes === undefined || bytes === null) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        if (i >= sizes.length) i = sizes.length - 1;
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function updateSortIndicators(tableSelector, state) {
        $(tableSelector + ' th.sortable').removeClass('sort-asc sort-desc');
        $(tableSelector + ' th.sortable[data-col="' + state.col + '"]').addClass('sort-' + state.dir);
    }

    // --- Start ---

    $(document).ready(init);

    return { init: init };

})(jQuery);
