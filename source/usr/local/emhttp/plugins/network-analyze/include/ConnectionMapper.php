<?php
/**
 * ConnectionMapper - Socket inode <-> PID mapping and /proc/net connection parsing
 *
 * Reads /proc filesystem to map network connections to processes.
 * Uses incremental scanning for performance with 1-2 second polling.
 */
class ConnectionMapper
{
    private $socketMap = [];      // inode => pid
    private $knownPids = [];      // pid => fd count
    private $processInfo = [];    // pid => ['comm', 'cmdline', 'ns_net']

    /**
     * Incrementally update the socket map.
     * Only scans new PIDs and PIDs whose fd count changed.
     */
    public function update()
    {
        $currentPids = [];
        $procDir = @opendir('/proc');
        if (!$procDir) return;

        while (($entry = readdir($procDir)) !== false) {
            if (!is_numeric($entry)) continue;
            $pid = (int)$entry;
            $currentPids[$pid] = true;
        }
        closedir($procDir);

        // Remove dead PIDs
        foreach (array_diff_key($this->knownPids, $currentPids) as $deadPid => $_) {
            $this->removePidFromMap($deadPid);
            unset($this->processInfo[$deadPid]);
            unset($this->knownPids[$deadPid]);
        }

        // Scan new PIDs
        $newPids = array_diff_key($currentPids, $this->knownPids);
        foreach ($newPids as $pid => $_) {
            $this->scanPid($pid);
        }

        // Re-scan existing PIDs if fd count changed
        foreach ($this->knownPids as $pid => $oldFdCount) {
            if (!isset($currentPids[$pid])) continue;
            $newFdCount = @count(scandir("/proc/{$pid}/fd"));
            if ($newFdCount !== $oldFdCount) {
                $this->removePidFromMap($pid);
                $this->scanPid($pid);
            }
        }
    }

    private function scanPid(int $pid)
    {
        $fdPath = "/proc/{$pid}/fd";
        if (!is_dir($fdPath)) return;

        // Read process info
        $comm = @trim(file_get_contents("/proc/{$pid}/comm")) ?: "unknown";
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline") ?: "";
        $cmdline = str_replace("\0", " ", trim($cmdline));
        $nsNet = @readlink("/proc/{$pid}/ns/net") ?: "";
        if (preg_match('/\[(\d+)\]/', $nsNet, $m)) {
            $nsNet = $m[1];
        }

        $this->processInfo[$pid] = [
            'comm' => $comm,
            'cmdline' => $cmdline ?: $comm,
            'ns_net' => $nsNet,
        ];

        // Scan file descriptors for sockets
        $fdDir = @opendir($fdPath);
        if (!$fdDir) return;

        $fdCount = 0;
        $socketCount = 0;
        while (($fd = readdir($fdDir)) !== false) {
            $fdCount++;
            $target = @readlink("{$fdPath}/{$fd}");
            if ($target && preg_match('/^socket:\[(\d+)\]$/', $target, $m)) {
                $this->socketMap[$m[1]] = $pid;
                $socketCount++;
            }
        }
        closedir($fdDir);

        $this->knownPids[$pid] = $fdCount;

        // Clean up processes with no sockets (to save memory)
        if ($socketCount === 0) {
            unset($this->processInfo[$pid]);
        }
    }

    private function removePidFromMap(int $pid)
    {
        $this->socketMap = array_filter(
            $this->socketMap,
            function ($p) use ($pid) { return $p !== $pid; }
        );
    }

    /**
     * Parse /proc/net/tcp and /proc/net/tcp6
     */
    public function parseTcpConnections()
    {
        $connections = [];
        $this->parseInetFile('/proc/net/tcp', 'tcp', $connections);
        $this->parseInetFile('/proc/net/tcp6', 'tcp6', $connections);
        return $connections;
    }

    /**
     * Parse /proc/net/udp and /proc/net/udp6
     */
    public function parseUdpConnections()
    {
        $connections = [];
        $this->parseInetFile('/proc/net/udp', 'udp', $connections);
        $this->parseInetFile('/proc/net/udp6', 'udp6', $connections);
        return $connections;
    }

    /**
     * Get all connections (TCP + UDP) with process info attached
     */
    public function getAllConnections()
    {
        $this->update();

        $connections = [];

        // TCP
        $this->parseInetFile('/proc/net/tcp', 'tcp', $connections);
        $this->parseInetFile('/proc/net/tcp6', 'tcp6', $connections);

        // UDP
        $this->parseInetFile('/proc/net/udp', 'udp', $connections);
        $this->parseInetFile('/proc/net/udp6', 'udp6', $connections);

        // Attach process info
        foreach ($connections as &$conn) {
            $pid = isset($this->socketMap[$conn['inode']]) ? $this->socketMap[$conn['inode']] : 0;
            $conn['pid'] = $pid;
            if ($pid && isset($this->processInfo[$pid])) {
                $conn['process'] = $this->processInfo[$pid]['comm'];
                $conn['cmdline'] = $this->processInfo[$pid]['cmdline'];
            } else {
                $conn['process'] = '-';
                $conn['cmdline'] = '';
            }
        }
        unset($conn);

        return $connections;
    }

    /**
     * Get process info for a specific PID
     */
    public function getProcessInfo(int $pid)
    {
        return $this->processInfo[$pid] ?? null;
    }

    /**
     * Get all known processes with sockets
     */
    public function getProcessesWithSockets()
    {
        $result = [];
        // Build pid => socket count map
        $pidSocketCounts = array_count_values($this->socketMap);

        foreach ($pidSocketCounts as $pid => $count) {
            if (!isset($this->processInfo[$pid])) continue;
            $result[$pid] = [
                'pid' => $pid,
                'comm' => $this->processInfo[$pid]['comm'],
                'cmdline' => $this->processInfo[$pid]['cmdline'],
                'ns_net' => $this->processInfo[$pid]['ns_net'],
                'socket_count' => $count,
            ];
        }

        // Sort by socket count descending
        uasort($result, function ($a, $b) {
            return $b['socket_count'] - $a['socket_count'];
        });

        return $result;
    }

    /**
     * Parse a /proc/net/{tcp,tcp6,udp,udp6} file
     *
     * Format: sl local_address remote_address st tx_queue:rx_queue tr:tm->when retrnsmt uid timeout inode
     */
    private function parseInetFile(string $file, string $proto, array &$connections)
    {
        $fp = @fopen($file, 'r');
        if (!$fp) return;

        // Skip header line
        fgets($fp);

        $isV6 = (strpos($proto, '6') !== false);
        $isUdp = (strpos($proto, 'udp') !== false);

        while (($line = fgets($fp)) !== false) {
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) < 10) continue;

            $local = $this->parseAddress($fields[1], $isV6);
            $remote = $this->parseAddress($fields[2], $isV6);
            $state = $this->tcpStateHex($fields[3]);
            $inode = (int)$fields[9];

            $conn = [
                'protocol' => $isUdp ? 'udp' : 'tcp',
                'local_addr' => $local['ip'],
                'local_port' => $local['port'],
                'remote_addr' => $remote['ip'],
                'remote_port' => $remote['port'],
                'state' => $state,
                'inode' => $inode,
            ];

            $connections[] = $conn;
        }

        fclose($fp);
    }

    /**
     * Parse hex address:port from /proc/net/tcp[6]
     * IPv4 format: AABBCCDD:PORT (little-endian hex)
     * IPv6 format: 32-char-hex:PORT
     */
    private function parseAddress(string $hex, bool $isV6)
    {
        $parts = explode(':', $hex);
        if (count($parts) !== 2) {
            return ['ip' => '0.0.0.0', 'port' => 0];
        }

        $hexIp = $parts[0];
        $port = hexdec($parts[1]);

        if ($isV6) {
            $ip = $this->hexToIpv6($hexIp);
        } else {
            $ip = $this->hexToIpv4($hexIp);
        }

        return ['ip' => $ip, 'port' => (int)$port];
    }

    /**
     * Convert little-endian hex IPv4 to dotted decimal
     */
    private function hexToIpv4(string $hex)
    {
        if (strlen($hex) !== 8) return '0.0.0.0';
        $hex = str_split($hex, 2);
        // Little-endian: reverse byte order
        return hexdec($hex[3]) . '.' . hexdec($hex[2]) . '.' . hexdec($hex[1]) . '.' . hexdec($hex[0]);
    }

    /**
     * Convert hex IPv6 to standard notation
     */
    private function hexToIpv6(string $hex)
    {
        if (strlen($hex) !== 32) return '::';
        // /proc/net/tcp6 stores IPv6 in little-endian 32-bit words
        // Each 8-char group is a 32-bit word in little-endian
        $groups = str_split($hex, 8);
        $bytes = '';
        foreach ($groups as $group) {
            // Reverse byte order within each 32-bit word
            $b = str_split($group, 2);
            $bytes .= $b[3] . $b[2] . $b[1] . $b[0];
        }
        // Insert colons every 4 chars
        $parts = str_split($bytes, 4);
        $ipv6 = implode(':', $parts);

        // Compress ::1 and :: for readability
        if ($ipv6 === '0000:0000:0000:0000:0000:0000:0000:0001') return '::1';
        if ($ipv6 === '0000:0000:0000:0000:0000:0000:0000:0000') return '::';

        // Compress leading zeros
        $ipv6 = preg_replace('/(^|:)0+([0-9a-f])/', '$1$2', $ipv6);

        return $ipv6;
    }

    /**
     * Convert TCP state hex code to string
     */
    private function tcpStateHex(string $hex)
    {
        $states = [
            '01' => 'ESTABLISHED',
            '02' => 'SYN_SENT',
            '03' => 'SYN_RECV',
            '04' => 'FIN_WAIT1',
            '05' => 'FIN_WAIT2',
            '06' => 'TIME_WAIT',
            '07' => 'CLOSE',
            '08' => 'CLOSE_WAIT',
            '09' => 'LAST_ACK',
            '0A' => 'LISTEN',
            '0B' => 'CLOSING',
        ];
        return $states[strtoupper($hex)] ?? "UNKNOWN({$hex})";
    }
}
