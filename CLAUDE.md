# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Unraid webGUI plugin for real-time network monitoring. Zero dependencies — reads Linux `/proc` filesystem directly. No build step, no package manager, no compilation. Files are deployed as raw source via the `.plg` installer.

## Architecture

```
Browser (jQuery, 2s polling)
  └─ $.post() to /plugins/network-analyze/include/ajax.php
       └─ ajax.php (POST cmd router)
            └─ NetworkHelper.php (data aggregation, rate calculation, namespace detection)
                 └─ ConnectionMapper.php (/proc reader: socket→PID mapping, connection parsing)
```

The `.plg` file fetches files from GitHub `master` at install time. Source files under `source/` mirror the Unraid runtime paths at `/usr/local/emhttp/plugins/network-analyze/`.

## Key Technical Details

**Rate calculation is stateless across PHP requests.** `NetworkHelper` persists previous counter values to `/tmp/network-analyze-state.json` so it can compute deltas between polls.

**I/O Rate includes disk I/O.** `/proc/[pid]/io` `rchar`/`wchar` cannot isolate network-only bytes. The UI labels these as "Read/Write I/O Rate" with tooltip caveats.

**Socket-to-PID mapping uses incremental scanning.** `ConnectionMapper::update()` only re-scans PIDs whose fd count changed since last poll. Avoid touching this logic without understanding the performance implications at 2s intervals.

**IPv6 addresses in `/proc/net/tcp6`** are stored in little-endian 32-bit word groups, not byte-by-byte. `hexToIpv6()` handles this specific format.

## Deployment

Changes pushed to `master` are live immediately — the `.plg` file uses raw GitHub URLs (`<URL>` tags) with no version pinning on individual files. Bump the version in `network-analyze.plg` (`<!ENTITY version>`) on every release.

## Testing Backend Locally

```bash
php source/usr/local/emhttp/plugins/network-analyze/include/NetworkHelper.php  # won't work standalone
# Instead test by requiring and calling methods:
php -r "require 'source/usr/local/emhttp/plugins/network-analyze/include/NetworkHelper.php'; \$h = new NetworkHelper(); echo json_encode(\$h->getConnections(), JSON_PRETTY_PRINT);"
```

## Unraid-Specific Conventions

- `.page` files use a two-section format separated by `---`: header metadata above, HTML/PHP below
- `Menu="Tasks:65"` registers in the sidebar; `Code="f0ac"` is a Font Awesome icon hex
- CSS uses `var(--cfg-*)` custom properties for theme compatibility (dark/light)
- The `.plg` file is XML with DTD entities — the `NetworkAnalyze.page` content must be XML-escaped (`&lt;` / `&gt;`) when embedded inline
