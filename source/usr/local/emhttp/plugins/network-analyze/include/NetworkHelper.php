<?php
/**
 * NetworkHelper - Backend data aggregation for the Network Analyze plugin
 *
 * Combines connection mapping, namespace detection, interface stats,
 * and I/O rate calculation into AJAX response formats.
 */
require_once __DIR__ . '/ConnectionMapper.php';

class NetworkHelper
{
    private $mapper;
    private $stateFile;

    // Previous poll data for rate calculation
    private $prevInterfaces = [];
    private $prevProcessIO = [];
    private $prevTimestamp = 0;

    public function __construct()
    {
        $this->mapper = new ConnectionMapper();
        $this->stateFile = '/tmp/network-analyze-state.json';
        $this->loadState();
    }

    /**
     * Get list of processes with network activity
     */
    public function getProcessList()
    {
        $this->mapper->update();
        $processes = $this->mapper->getProcessesWithSockets();
        $connections = $this->mapper->getAllConnections();

        // Build connection summary per process
        $connByPid = [];
        foreach ($connections as $conn) {
            $pid = $conn['pid'];
            if (!$pid) continue;
            if (!isset($connByPid[$pid])) $connByPid[$pid] = [];
            $connByPid[$pid][] = [
                'protocol' => $conn['protocol'],
                'local' => $conn['local_addr'] . ':' . $conn['local_port'],
                'remote' => $conn['remote_addr'] . ':' . $conn['remote_port'],
                'state' => $conn['state'],
            ];
        }

        $now = microtime(true);
        $dt = ($this->prevTimestamp > 0) ? ($now - $this->prevTimestamp) : 1;

        $result = [];
        foreach ($processes as $pid => $proc) {
            // Read /proc/pid/io for I/O rates
            $ioRxRate = 0;
            $ioTxRate = 0;
            $ioData = @file_get_contents("/proc/{$pid}/io");
            if ($ioData && preg_match('/rchar:\s+(\d+)/', $ioData, $rm) && preg_match('/wchar:\s+(\d+)/', $ioData, $wm)) {
                $rchar = (int)$rm[1];
                $wchar = (int)$wm[1];
                if (isset($this->prevProcessIO[$pid])) {
                    $ioRxRate = max(0, ($rchar - $this->prevProcessIO[$pid]['rchar']) / $dt);
                    $ioTxRate = max(0, ($wchar - $this->prevProcessIO[$pid]['wchar']) / $dt);
                }
                $this->prevProcessIO[$pid] = ['rchar' => $rchar, 'wchar' => $wchar];
            }

            $result[] = [
                'pid' => $pid,
                'comm' => $proc['comm'],
                'cmdline' => $proc['cmdline'],
                'ns_net' => $proc['ns_net'],
                'ns_label' => $this->getNsLabel($proc['ns_net']),
                'socket_count' => $proc['socket_count'],
                'io_rx_rate' => round($ioRxRate),
                'io_tx_rate' => round($ioTxRate),
                'connections' => array_slice($connByPid[$pid] ?? [], 0, 20),
            ];
        }

        // Clean up old PIDs from prevProcessIO
        $activePids = array_column($result, 'pid');
        $this->prevProcessIO = array_intersect_key($this->prevProcessIO, array_flip($activePids));

        $this->saveState();

        return [
            'timestamp' => time(),
            'processes' => $result,
        ];
    }

    /**
     * Get all connections with process info
     */
    public function getConnections()
    {
        $this->mapper->update();
        $connections = $this->mapper->getAllConnections();

        $result = [];
        foreach ($connections as $conn) {
            $result[] = [
                'protocol' => $conn['protocol'],
                'local_addr' => $conn['local_addr'],
                'local_port' => $conn['local_port'],
                'remote_addr' => $conn['remote_addr'],
                'remote_port' => $conn['remote_port'],
                'state' => $conn['state'],
                'pid' => $conn['pid'],
                'process' => $conn['process'],
                'inode' => $conn['inode'],
            ];
        }

        return [
            'timestamp' => time(),
            'connections' => $result,
        ];
    }

    /**
     * Get per-interface bandwidth rates
     */
    public function getInterfaces()
    {
        $now = microtime(true);
        $dt = ($this->prevTimestamp > 0) ? ($now - $this->prevTimestamp) : 1;

        $interfaces = $this->readInterfaceStats();
        $result = [];

        foreach ($interfaces as $name => $stats) {
            $rxRate = 0;
            $txRate = 0;
            if (isset($this->prevInterfaces[$name])) {
                $rxRate = max(0, ($stats['rx_bytes'] - $this->prevInterfaces[$name]['rx_bytes']) / $dt);
                $txRate = max(0, ($stats['tx_bytes'] - $this->prevInterfaces[$name]['tx_bytes']) / $dt);
            }

            $result[] = [
                'name' => $name,
                'rx_bytes' => $stats['rx_bytes'],
                'tx_bytes' => $stats['tx_bytes'],
                'rx_rate' => round($rxRate),
                'tx_rate' => round($txRate),
                'rx_packets' => $stats['rx_packets'],
                'tx_packets' => $stats['tx_packets'],
                'errors' => $stats['errors'],
                'drops' => $stats['drops'],
            ];
        }

        $this->prevInterfaces = $interfaces;
        $this->prevTimestamp = $now;
        $this->saveState();

        return [
            'timestamp' => time(),
            'interfaces' => $result,
        ];
    }

    /**
     * Get bandwidth grouped by network namespace (Host vs Docker containers)
     */
    public function getNamespaces()
    {
        $this->mapper->update();
        $processes = $this->mapper->getProcessesWithSockets();

        // Group PIDs by namespace
        $nsGroups = [];
        foreach ($processes as $pid => $proc) {
            $ns = $proc['ns_net'] ?: 'unknown';
            if (!isset($nsGroups[$ns])) {
                $nsGroups[$ns] = ['pids' => [], 'label' => $this->getNsLabel($ns)];
            }
            $nsGroups[$ns]['pids'][] = $pid;
        }

        $result = [];
        foreach ($nsGroups as $nsInode => $group) {
            $result[] = [
                'ns_inode' => $nsInode,
                'label' => $group['label'],
                'process_count' => count($group['pids']),
            ];
        }

        // Sort: Host first, then by process count descending
        usort($result, function ($a, $b) {
            if ($a['label'] === 'Host') return -1;
            if ($b['label'] === 'Host') return 1;
            return $b['process_count'] - $a['process_count'];
        });

        return [
            'timestamp' => time(),
            'namespaces' => $result,
        ];
    }

    /**
     * Read /proc/net/dev and parse interface statistics
     */
    private function readInterfaceStats()
    {
        $interfaces = [];
        $fp = @fopen('/proc/net/dev', 'r');
        if (!$fp) return $interfaces;

        // Skip 2 header lines
        fgets($fp);
        fgets($fp);

        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            // Format: iface: rx_bytes rx_packets rx_errs rx_drop rx_fifo rx_frame rx_compressed rx_multicast tx_bytes tx_packets ...
            if (preg_match('/^\s*(\S+):\s*(.+)$/', $line, $m)) {
                $name = $m[1];
                $stats = preg_split('/\s+/', trim($m[2]));
                if (count($stats) >= 16) {
                    // Skip loopback
                    if ($name === 'lo') continue;

                    $interfaces[$name] = [
                        'rx_bytes' => (int)$stats[0],
                        'rx_packets' => (int)$stats[1],
                        'tx_bytes' => (int)$stats[8],
                        'tx_packets' => (int)$stats[9],
                        'errors' => (int)$stats[2] + (int)$stats[10],
                        'drops' => (int)$stats[3] + (int)$stats[11],
                    ];
                }
            }
        }

        fclose($fp);
        return $interfaces;
    }

    /**
     * Get a human-readable label for a network namespace inode
     */
    private function getNsLabel(string $nsInode)
    {
        if (empty($nsInode) || $nsInode === 'unknown') return 'Unknown';

        // Check if it's the init namespace (Host)
        $initNs = @readlink('/proc/1/ns/net');
        if ($initNs && preg_match('/\[(\d+)\]/', $initNs, $m) && $m[1] === $nsInode) {
            return 'Host';
        }

        // Try to find a Docker container name from cgroup
        $procDir = @opendir('/proc');
        if (!$procDir) return "Namespace:{$nsInode}";

        while (($entry = readdir($procDir)) !== false) {
            if (!is_numeric($entry)) continue;
            $pidNs = @readlink("/proc/{$entry}/ns/net");
            if ($pidNs && preg_match('/\[(\d+)\]/', $pidNs, $m) && $m[1] === $nsInode) {
                // Found a process in this namespace, check cgroup for container name
                $cgroup = @file_get_contents("/proc/{$entry}/cgroup");
                if ($cgroup) {
                    // Docker: look for container ID in cgroup
                    if (@preg_match('/docker[-\/]([a-f0-9]{64})/', $cgroup, $dm)) {
                        $containerId = substr($dm[1], 0, 12);
                        // Try to get container name from /proc/pid/environ
                        $environ = @file_get_contents("/proc/{$entry}/environ");
                        if ($environ && @preg_match('/HOSTNAME=([^\x00]+)/', $environ, $hm)) {
                            return 'Docker: ' . $hm[1];
                        }
                        return 'Docker: ' . $containerId;
                    }
                    // Podman
                    if (@preg_match('/podman[-\/]([a-f0-9]{64})/', $cgroup, $pm)) {
                        return 'Podman: ' . substr($pm[1], 0, 12);
                    }
                    // LXC
                    if (strpos($cgroup, 'lxc/') !== false) {
                        return 'LXC: ' . $entry;
                    }
                }

                // Fallback: use process name
                $comm = @trim(file_get_contents("/proc/{$entry}/comm"));
                if ($comm && $comm !== 'bash' && $comm !== 'sh') {
                    return 'Container: ' . $comm;
                }
            }
        }
        closedir($procDir);

        return "NS:{$nsInode}";
    }

    /**
     * Load persistent state from disk (for cross-request rate calculation)
     */
    private function loadState()
    {
        $data = @file_get_contents($this->stateFile);
        if ($data) {
            $state = json_decode($data, true);
            if ($state) {
                $this->prevInterfaces = $state['interfaces'] ?? [];
                $this->prevProcessIO = $state['process_io'] ?? [];
                $this->prevTimestamp = $state['timestamp'] ?? 0;
            }
        }
    }

    /**
     * Save state to disk for next request
     */
    private function saveState()
    {
        $state = [
            'interfaces' => $this->prevInterfaces,
            'process_io' => $this->prevProcessIO,
            'timestamp' => $this->prevTimestamp,
        ];
        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }
}
