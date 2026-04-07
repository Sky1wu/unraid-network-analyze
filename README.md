# Unraid Network Analyze

Unraid webGUI plugin for real-time network monitoring — see which processes are using the network and what connections are active.

## Features

- **Process monitoring** — per-process socket counts and I/O rates, click to expand detailed connection list
- **Connection tracking** — all TCP/UDP connections with process name, protocol, address, and state
- **NIC rates** — real-time upload/download rates for each network interface
- **Sorting & filtering** — column sorting, protocol/state filters, keyword search
- **1–2 second refresh** — automatic polling with pause/resume support

## Installation

Unraid → **Plugins** → **Install Plugin**, paste this URL:

```
https://raw.githubusercontent.com/Sky1wu/unraid-network-analyze/master/network-analyze.plg
```

After installation, click **Network Analyze** under **Tasks** in the sidebar.

## How It Works

Reads the `/proc` filesystem directly — zero external dependencies.

| Source | Purpose |
|--------|---------|
| `/proc/[pid]/fd/` | Scan socket symlinks to build inode → PID mapping |
| `/proc/net/tcp`, `/proc/net/udp` | Parse all network connections, associate with inode mapping to get process info |
| `/proc/net/dev` | NIC byte counters for real-time rate calculation |
| `/proc/[pid]/io` | Per-process read/write byte counters for I/O rate calculation |
| `/proc/[pid]/ns/net` | Distinguish Host vs Docker container network namespaces |

### About I/O Rate

**Read/Write I/O Rate** comes from `/proc/[pid]/io` `rchar`/`wchar`, which includes all process read/write activity (disk + network). For example, qBittorrent shows a high Write I/O Rate when downloading because data received from the network is written to disk. This is total process-level I/O rate, not network-only — the `/proc` filesystem cannot isolate network bytes alone. Accurate per-process network bandwidth would require kernel tracing tools like eBPF.

## Compatibility

- Unraid 6.9.0+
- Dark / light theme support

## Uninstall

**Plugins** → **Network Analyze** → **Remove Plugin**
