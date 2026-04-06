# Unraid Network Analyze

Unraid web 插件，实时监控网络状况——查看哪个进程在占用网络、当前有哪些网络连接。

## 功能

- **进程监控** — 显示每个进程的 socket 数量和 I/O 速率，点击展开查看详细连接列表
- **连接追踪** — 所有 TCP/UDP 连接，附带进程名、协议、地址、状态
- **网卡速率** — 顶部实时显示各网卡上传/下载速率
- **排序 & 过滤** — 支持列排序、协议/状态筛选、关键词搜索
- **1-2 秒刷新** — 自动轮询实时数据，可暂停/恢复

## 安装

Unraid → **Plugins** → **Install Plugin**，粘贴以下地址：

```
https://raw.githubusercontent.com/Sky1wu/unraid-network-analyze/master/network-analyze.plg
```

安装后在左侧菜单 **Tasks** 中点击 **Network Analyze**。

## 工作原理

全部基于 `/proc` 文件系统，无需安装额外依赖。

| 数据来源 | 说明 |
|---------|------|
| `/proc/[pid]/fd/` | 扫描 socket symlink，建立 inode → PID 映射 |
| `/proc/net/tcp`, `/proc/net/udp` | 解析所有网络连接，与 inode 映射关联得到进程信息 |
| `/proc/net/dev` | 网卡字节计数器，计算实时速率 |
| `/proc/[pid]/io` | 进程级读写字节计数器，计算 I/O 速率 |
| `/proc/[pid]/ns/net` | 区分 Host 和 Docker 容器的网络命名空间 |

### 关于 I/O 速率

**Read/Write I/O Rate** 来自 `/proc/[pid]/io` 的 `rchar`/`wchar`，包含进程的所有读写活动（磁盘 + 网络）。例如 qBittorrent 下载时 Write I/O Rate 很高，是因为数据从网络接收后写入磁盘。这是进程级的总 I/O 速率，不是纯网络速率——`/proc` 文件系统无法单独隔离网络字节，要获取精确的进程级网络带宽需要 eBPF 等内核追踪手段。

## 兼容性

- Unraid 6.9.0+
- 深色 / 浅色主题

## 卸载

**Plugins** → **Network Analyze** → **Remove Plugin**
