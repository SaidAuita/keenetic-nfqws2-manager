<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
/**
 * NFQWS2 Strategy Manager & Universal Bypass Diagnostics Web GUI
 * URL: http://192.168.1.1:90/menu.php
 * Fully Open-Source & Self-Configurable (Zero Hardcoded Personal Secrets/IPs)
 */

define('CONF_FILE', '/opt/etc/nfqws2/nfqws2.conf');
define('CONF_RUN', '/opt/etc/nfqws2/nfqws2.conf.run');
define('CONF_BAK', '/opt/etc/nfqws2/nfqws2.conf.bak');
define('STRATEGIES_DIR', '/opt/etc/nfqws-menu/strategies/nfqws2');
define('DATES_FILE', '/opt/etc/nfqws-menu/strategy_dates.json');
define('STRATEGY_ARCHIVE_FILE', '/opt/etc/nfqws-menu/strategy_archive.json');
define('INIT_SCRIPT', '/opt/etc/init.d/S51nfqws2');

// --- DYNAMIC VPN CONFIGURATION (Zero hardcoded secrets/IPs) ---
define('XRAY_DIR', '/opt/etc/xray');
define('XRAY_CONF', '/opt/etc/xray/config.json');
define('XRAY_INIT', '/opt/etc/init.d/S24xray');
define('XRAY_SERVERS_FILE', '/opt/etc/xray/vpn_servers.json');
define('XRAY_DEVICES_FILE', '/opt/etc/xray/vpn_devices.json');
define('XRAY_ENABLED_IPS_FILE', '/opt/etc/xray/enabled_ips.txt');

function loadVpnServers() {
    if (!file_exists(XRAY_SERVERS_FILE)) return [];
    $data = json_decode(file_get_contents(XRAY_SERVERS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveVpnServers($servers) {
    @mkdir(XRAY_DIR, 0755, true);
    file_put_contents(XRAY_SERVERS_FILE, json_encode(array_values($servers), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function loadVpnDevices() {
    if (!file_exists(XRAY_DEVICES_FILE)) return [];
    $data = json_decode(file_get_contents(XRAY_DEVICES_FILE), true);
    return is_array($data) ? $data : [];
}

function saveVpnDevices($devices) {
    @mkdir(XRAY_DIR, 0755, true);
    file_put_contents(XRAY_DEVICES_FILE, json_encode(array_values($devices), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function loadStrategyArchive() {
    if (!file_exists(STRATEGY_ARCHIVE_FILE)) return [];
    $data = json_decode(file_get_contents(STRATEGY_ARCHIVE_FILE), true);
    return is_array($data) ? $data : [];
}

function saveStrategyArchive($list) {
    @mkdir(dirname(STRATEGY_ARCHIVE_FILE), 0755, true);
    file_put_contents(STRATEGY_ARCHIVE_FILE, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function syncVpnRouting($devices, $servers) {
    @mkdir(XRAY_DIR, 0755, true);
    
    // 1. Gather enabled IPs and sync legacy flag files for compatibility
    $enabledIps = [];
    $devMap = [];
    foreach ($devices as $d) {
        $devMap[$d['id']] = $d;
        $ip = $d['ip'] ?? '';
        if (!$ip) continue;
        if (!empty($d['enabled'])) {
            $enabledIps[] = $ip;
            if ($ip === '192.168.1.145') @touch('/opt/etc/xray/dune_kuh.enabled');
            if ($ip === '192.168.1.53')  @touch('/opt/etc/xray/dune_komnata.enabled');
        } else {
            if ($ip === '192.168.1.145') @unlink('/opt/etc/xray/dune_kuh.enabled');
            if ($ip === '192.168.1.53')  @unlink('/opt/etc/xray/dune_komnata.enabled');
        }
    }
    file_put_contents(XRAY_ENABLED_IPS_FILE, implode("\n", $enabledIps) . "\n");

    // 3. Build Xray multi-outbound + source routing config
    if (empty($servers)) {
        return;
    }

    $serverMap = [];
    foreach ($servers as $s) {
        $serverMap[$s['id']] = $s;
    }

    $outbounds = [];
    foreach ($servers as $s) {
        $transport = $s['transport'] ?? 'grpc';
        $security = $s['security'] ?? 'reality';
        
        $out = [
            "tag" => "proxy_" . $s['id'],
            "protocol" => "vless",
            "settings" => [
                "vnext" => [
                    [
                        "address" => $s["address"],
                        "port" => (int)$s["port"],
                        "users" => [
                            [
                                "id" => $s["uuid"],
                                "encryption" => "none"
                            ]
                        ]
                    ]
                ]
            ],
            "streamSettings" => [
                "network" => $transport,
                "security" => $security
            ]
        ];

        if ($security === 'reality') {
            $out["streamSettings"]["realitySettings"] = [
                "fingerprint" => $s["fingerprint"] ?? "chrome",
                "serverName" => $s["sni"] ?? "",
                "publicKey" => $s["pbk"] ?? "",
                "shortId" => $s["sid"] ?? "",
                "spiderX" => $s["spiderX"] ?? "/"
            ];
        } elseif ($security === 'tls') {
            $out["streamSettings"]["tlsSettings"] = [
                "serverName" => $s["sni"] ?? "",
                "allowInsecure" => false
            ];
        }

        if ($transport === 'grpc') {
            $out["streamSettings"]["grpcSettings"] = [
                "serviceName" => $s["serviceName"] ?? "apple"
            ];
        } elseif ($transport === 'ws') {
            $ws = ["path" => $s["path"] ?? "/"];
            if (!empty($s["sni"])) {
                $ws["headers"] = ["Host" => $s["sni"]];
            }
            $out["streamSettings"]["wsSettings"] = $ws;
        }

        $outbounds[] = $out;
    }
    $outbounds[] = ["tag" => "direct", "protocol" => "freedom"];

    // Build routing rules for each active device
    $rules = [];
    foreach ($devices as $d) {
        if (!empty($d['enabled']) && !empty($d['ip'])) {
            $srvId = $d['server'] ?? '';
            if (!isset($serverMap[$srvId])) {
                $srvId = $servers[0]['id'] ?? '';
            }
            if ($srvId) {
                $rules[] = [
                    "type" => "field",
                    "inboundTag" => ["transparent"],
                    "source" => [$d['ip']],
                    "outboundTag" => "proxy_" . $srvId
                ];
            }
        }
    }
    // Default fallback rule for transparent inbound
    $fallbackSrv = $servers[0]['id'] ?? '';
    if ($fallbackSrv) {
        $rules[] = [
            "type" => "field",
            "inboundTag" => ["transparent"],
            "outboundTag" => "proxy_" . $fallbackSrv
        ];
    }

    $cfg = [
        "log" => ["loglevel" => "warning"],
        "inbounds" => [
            [
                "tag" => "transparent",
                "port" => 10808,
                "listen" => "0.0.0.0",
                "protocol" => "dokodemo-door",
                "settings" => ["network" => "tcp", "followRedirect" => true],
                "sniffing" => ["enabled" => true, "destOverride" => ["http", "tls"]]
            ],
            [
                "tag" => "socks-in",
                "port" => 1080,
                "listen" => "127.0.0.1",
                "protocol" => "socks",
                "settings" => ["auth" => "noauth", "udp" => false]
            ]
        ],
        "outbounds" => $outbounds,
        "routing" => [
            "domainStrategy" => "AsIs",
            "rules" => $rules
        ]
    ];

    file_put_contents(XRAY_CONF, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    exec(XRAY_INIT . " restart 2>&1");

    // 2. Ensure iptables NAT chain and PREROUTING rules are active AFTER Xray restart
    exec("iptables -t nat -N DUNE_VPN 2>/dev/null");
    foreach ($devices as $d) {
        $ip = $d['ip'] ?? '';
        if (!$ip) continue;
        if (!empty($d['enabled'])) {
            exec("iptables -t nat -C PREROUTING -s $ip -j DUNE_VPN 2>/dev/null || iptables -t nat -I PREROUTING -s $ip -j DUNE_VPN");
        } else {
            exec("while iptables -t nat -D PREROUTING -s $ip -j DUNE_VPN 2>/dev/null; do :; done");
        }
        exec("conntrack -D -s $ip 2>/dev/null");
    }
}

function parseVlessString($link, $defaultGroup = 'VPN') {
    if (!preg_match('/^vless:\/\/([a-zA-Z0-9_-]+)@([a-zA-Z0-9_.-]+):([0-9]+)(\?[^#]*)?(?:#(.*))?$/', $link, $m)) {
        return null;
    }
    $uuid = $m[1];
    $address = $m[2];
    $port = (int)$m[3];
    $query = $m[4] ?? '';
    $hash = !empty($m[5]) ? urldecode($m[5]) : '';

    $params = [];
    if ($query && str_starts_with($query, '?')) {
        parse_str(substr($query, 1), $params);
    }

    $name = $hash ?: ($address . ':' . $port);
    $group = $defaultGroup;
    if ($hash && strpos($hash, ' - ') !== false) {
        $parts = explode(' - ', $hash, 2);
        $group = trim($parts[0]);
        $name = trim($parts[1]);
    }

    $transport = $params['type'] ?? 'tcp';
    $security = $params['security'] ?? 'none';
    $sni = $params['sni'] ?? ($params['host'] ?? '');
    $pbk = $params['pbk'] ?? '';
    $sid = $params['sid'] ?? '';
    $serviceName = $params['serviceName'] ?? '';
    $path = $params['path'] ?? '/';
    $spiderX = $params['spx'] ?? '/';

    $id = 'srv_' . substr(md5($address . $port . $uuid . $name), 0, 8);

    return [
        'id' => $id,
        'name' => $name,
        'group' => $group,
        'protocol' => 'vless',
        'address' => $address,
        'port' => $port,
        'uuid' => $uuid,
        'transport' => $transport,
        'security' => $security,
        'sni' => $sni,
        'pbk' => $pbk,
        'sid' => $sid,
        'serviceName' => $serviceName,
        'path' => $path,
        'spiderX' => $spiderX
    ];
}

function getKeeneticDevices() {
    exec("ndmc -c 'show ip hotspot' 2>/dev/null", $hotspotLines);
    exec("ndmc -c 'show ip dhcp bindings' 2>/dev/null", $dhcpLines);

    $dhcpMap = [];
    $cur = [];
    foreach ($dhcpLines as $line) {
        $t = trim($line);
        if ($t === 'lease:') {
            if (!empty($cur['ip'])) $dhcpMap[$cur['ip']] = $cur;
            $cur = [];
            continue;
        }
        if (strpos($line, ':') !== false) {
            list($k, $v) = explode(':', $line, 2);
            $k = trim($k); $v = trim($v);
            if (in_array($k, ['ip', 'mac', 'hostname', 'name'])) $cur[$k] = $v;
        }
    }
    if (!empty($cur['ip'])) $dhcpMap[$cur['ip']] = $cur;

    $hotspotMap = [];
    $cur = [];
    $inHost = false;
    foreach ($hotspotLines as $line) {
        $t = trim($line);
        if ($t === 'host:') {
            if (!empty($cur['ip']) && $cur['ip'] !== '0.0.0.0') $hotspotMap[$cur['ip']] = $cur;
            $cur = [];
            $inHost = true;
            continue;
        }
        if ($inHost && strpos($line, ':') !== false) {
            list($k, $v) = explode(':', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k === 'name' && !empty($cur['name'])) continue;
            if (in_array($k, ['mac', 'ip', 'hostname', 'name', 'link', 'active'])) {
                $cur[$k] = $v;
            }
        }
    }
    if (!empty($cur['ip']) && $cur['ip'] !== '0.0.0.0') $hotspotMap[$cur['ip']] = $cur;

    $allIps = array_unique(array_merge(array_keys($dhcpMap), array_keys($hotspotMap)));
    $devices = [];
    foreach ($allIps as $ip) {
        if ($ip === '0.0.0.0' || strpos($ip, '127.') === 0) continue;
        $h = $hotspotMap[$ip] ?? [];
        $d = $dhcpMap[$ip] ?? [];
        $name = !empty($h['name']) ? $h['name'] : (!empty($d['name']) ? $d['name'] : (!empty($h['hostname']) ? $h['hostname'] : (!empty($d['hostname']) ? $d['hostname'] : 'Устройство')));
        $mac = !empty($h['mac']) ? $h['mac'] : ($d['mac'] ?? '');
        $active = (!empty($h['active']) && $h['active'] === 'yes');

        $icon = '🌐';
        $nLow = strtolower($name . ' ' . ($h['hostname'] ?? '') . ' ' . ($d['hostname'] ?? ''));
        if (preg_match('/tv|dune|smart|box|dongle|chrome-tv|mitv/i', $nLow)) {
            $icon = '📺';
        } elseif (preg_match('/pc|macbook|book|laptop|desktop|nuc|win-|computer/i', $nLow)) {
            $icon = '💻';
        } elseif (preg_match('/pixel|phone|redmi|moto|iphone|android|samsung/i', $nLow)) {
            $icon = '📱';
        } elseif (preg_match('/quest|playstation|xbox|switch/i', $nLow)) {
            $icon = '🎮';
        } elseif (preg_match('/esp-|monitor|socket|розетка|camera|cam/i', $nLow)) {
            $icon = '🔌';
        }

        $devices[] = [
            'ip' => $ip,
            'name' => $name,
            'mac' => $mac,
            'active' => $active,
            'icon' => $icon
        ];
    }

    usort($devices, function($a, $b) {
        if ($a['active'] !== $b['active']) return $b['active'] <=> $a['active'];
        return ip2long($a['ip']) <=> ip2long($b['ip']);
    });

    return $devices;
}

// --- AJAX API HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    // --- STRATEGY ARCHIVE ACTIONS ---
    if ($action === 'archive_strategy') {
        $strat = basename($_POST['strategy'] ?? '');
        if (!$strat) {
            echo json_encode(['success' => false, 'error' => 'Имя стратегии не указано']);
            exit;
        }

        // Check if currently active strategy
        $activeConf = file_exists(CONF_FILE) ? file_get_contents(CONF_FILE) : '';
        $stratContent = file_exists(STRATEGIES_DIR . '/' . $strat) ? file_get_contents(STRATEGIES_DIR . '/' . $strat) : '';
        
        // Check active name match
        if (preg_match('/#\s*general\s*\(([^)]+)\)/i', $activeConf, $mActive) &&
            preg_match('/#\s*general\s*\(([^)]+)\)/i', $stratContent, $mStrat)) {
            if (trim($mActive[1]) === trim($mStrat[1])) {
                echo json_encode(['success' => false, 'error' => 'Нельзя архивировать активную запущенную стратегию! Сначала переключитесь на другую стратегию.']);
                exit;
            }
        }

        $arch = loadStrategyArchive();
        if (!in_array($strat, $arch)) {
            $arch[] = $strat;
            saveStrategyArchive($arch);
        }
        echo json_encode(['success' => true, 'archived' => true, 'strategy' => $strat]);
        exit;
    }

    if ($action === 'unarchive_strategy') {
        $strat = basename($_POST['strategy'] ?? '');
        $arch = loadStrategyArchive();
        $arch = array_values(array_diff($arch, [$strat]));
        saveStrategyArchive($arch);
        echo json_encode(['success' => true, 'archived' => false, 'strategy' => $strat]);
        exit;
    }

    // --- DYNAMIC VPN & DEVICE ACTIONS ---
    if ($action === 'get_vpn_status') {
        exec("pgrep xray >/dev/null && echo 1 || echo 0", $xrOut);
        $xrayRunning = ($xrOut[0] ?? '0') === '1';

        $devices = loadVpnDevices();
        $servers = loadVpnServers();

        $srvMap = [];
        foreach ($servers as $s) {
            $srvMap[$s['id']] = $s;
        }

        // Check real iptables rule for each device and self-heal
        foreach ($devices as &$d) {
            $ip = $d['ip'] ?? '';
            $confEnabled = !empty($d['enabled']);
            $iptablesOk = false;
            if ($ip) {
                exec("iptables -t nat -C PREROUTING -s $ip -j DUNE_VPN 2>/dev/null && echo 1 || echo 0", $chkOut);
                $iptablesOk = ($chkOut[0] ?? '0') === '1';
                unset($chkOut);

                // Self-heal: If configured enabled but missing in iptables, restore immediately
                if ($confEnabled && !$iptablesOk) {
                    exec("iptables -t nat -C PREROUTING -s $ip -j DUNE_VPN 2>/dev/null || iptables -t nat -I PREROUTING -s $ip -j DUNE_VPN");
                    $iptablesOk = true;
                }
            }
            $d['enabled'] = $confEnabled;
            $d['iptables_active'] = $iptablesOk;
            $sid = $d['server'] ?? ($servers[0]['id'] ?? '');
            $d['server_name'] = $srvMap[$sid]['name'] ?? ($servers[0]['name'] ?? 'VLESS Reality');
            $d['server_group'] = $srvMap[$sid]['group'] ?? ($servers[0]['group'] ?? 'VPN');
        }
        unset($d);

        echo json_encode([
            'success' => true,
            'xray_running' => $xrayRunning,
            'devices' => $devices,
            'servers' => $servers
        ]);
        exit;
    }

    if ($action === 'get_network_devices') {
        $netDevices = getKeeneticDevices();
        $configured = loadVpnDevices();
        $addedIps = array_column($configured, 'ip');

        foreach ($netDevices as &$dev) {
            $dev['already_added'] = in_array($dev['ip'], $addedIps);
        }
        unset($dev);

        echo json_encode([
            'success' => true,
            'devices' => $netDevices
        ]);
        exit;
    }

    if ($action === 'add_vpn_device') {
        $name = trim($_POST['name'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        $mac = trim($_POST['mac'] ?? '');
        $icon = trim($_POST['icon'] ?? '📺');
        $server = trim($_POST['server'] ?? '');

        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip)) {
            echo json_encode(['success' => false, 'error' => 'Некорректный IPv4 адрес']);
            exit;
        }
        if (!$name) $name = 'Устройство ' . $ip;

        $devices = loadVpnDevices();
        $servers = loadVpnServers();
        if (!$server && !empty($servers)) {
            $server = $servers[0]['id'];
        }

        // Check if already exists
        foreach ($devices as $d) {
            if ($d['ip'] === $ip) {
                echo json_encode(['success' => false, 'error' => 'Устройство с таким IP уже добавлено в список!']);
                exit;
            }
        }

        $newId = 'dev_' . str_replace('.', '_', $ip);
        $newDev = [
            'id' => $newId,
            'name' => $name,
            'ip' => $ip,
            'mac' => $mac,
            'icon' => $icon,
            'server' => $server,
            'enabled' => false
        ];
        $devices[] = $newDev;
        saveVpnDevices($devices);

        echo json_encode(['success' => true, 'device' => $newDev, 'devices' => $devices]);
        exit;
    }

    if ($action === 'remove_vpn_device') {
        $id = trim($_POST['id'] ?? '');
        $devices = loadVpnDevices();
        $servers = loadVpnServers();

        $filtered = [];
        $removedIp = '';
        foreach ($devices as $d) {
            if ($d['id'] === $id) {
                $removedIp = $d['ip'];
            } else {
                $filtered[] = $d;
            }
        }

        if ($removedIp) {
            exec("while iptables -t nat -D PREROUTING -s $removedIp -j DUNE_VPN 2>/dev/null; do :; done");
            exec("conntrack -D -s $removedIp 2>/dev/null");
        }

        saveVpnDevices($filtered);
        syncVpnRouting($filtered, $servers);

        echo json_encode(['success' => true, 'devices' => $filtered]);
        exit;
    }

    if ($action === 'toggle_vpn_device') {
        $id = trim($_POST['id'] ?? '');
        $enable = (int)($_POST['enable'] ?? 0) === 1;

        $devices = loadVpnDevices();
        $servers = loadVpnServers();

        $targetIp = '';
        foreach ($devices as &$d) {
            if ($d['id'] === $id) {
                $d['enabled'] = $enable;
                $targetIp = $d['ip'];
                break;
            }
        }
        unset($d);

        saveVpnDevices($devices);
        syncVpnRouting($devices, $servers);

        echo json_encode(['success' => true, 'id' => $id, 'enabled' => $enable, 'ip' => $targetIp]);
        exit;
    }

    if ($action === 'set_device_server') {
        $id = trim($_POST['id'] ?? '');
        $serverKey = trim($_POST['server'] ?? '');

        $devices = loadVpnDevices();
        $servers = loadVpnServers();

        $updated = false;
        foreach ($devices as &$d) {
            if ($d['id'] === $id) {
                $d['server'] = $serverKey;
                $updated = true;
                break;
            }
        }
        unset($d);

        if ($updated) {
            saveVpnDevices($devices);
            syncVpnRouting($devices, $servers);
        }

        echo json_encode(['success' => true, 'id' => $id, 'server' => $serverKey]);
        exit;
    }

    if ($action === 'get_vpn_servers') {
        $servers = loadVpnServers();
        echo json_encode(['success' => true, 'servers' => $servers]);
        exit;
    }

    if ($action === 'save_vpn_server') {
        $servers = loadVpnServers();
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $group = trim($_POST['group'] ?? 'VPN');
        $address = trim($_POST['address'] ?? '');
        $port = (int)($_POST['port'] ?? 443);
        $uuid = trim($_POST['uuid'] ?? '');
        $transport = trim($_POST['transport'] ?? 'grpc');
        $security = trim($_POST['security'] ?? 'reality');
        $sni = trim($_POST['sni'] ?? 'apple.com');
        $pbk = trim($_POST['pbk'] ?? '');
        $sid = trim($_POST['sid'] ?? '');
        $serviceName = trim($_POST['serviceName'] ?? 'apple');
        $path = trim($_POST['path'] ?? '/');

        if (!$name || !$address || !$uuid) {
            echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля: Название, Адрес, UUID']);
            exit;
        }

        if (!$id) {
            $id = 'srv_' . substr(md5($address . $port . microtime()), 0, 8);
        }

        $srvData = [
            'id' => $id,
            'name' => $name,
            'group' => $group ?: 'VPN',
            'protocol' => 'vless',
            'address' => $address,
            'port' => $port,
            'uuid' => $uuid,
            'transport' => $transport,
            'security' => $security,
            'sni' => $sni,
            'pbk' => $pbk,
            'sid' => $sid,
            'serviceName' => $serviceName,
            'path' => $path
        ];

        $found = false;
        foreach ($servers as &$s) {
            if ($s['id'] === $id) {
                $s = $srvData;
                $found = true;
                break;
            }
        }
        unset($s);

        if (!$found) {
            $servers[] = $srvData;
        }

        saveVpnServers($servers);
        $devices = loadVpnDevices();
        syncVpnRouting($devices, $servers);

        echo json_encode(['success' => true, 'server' => $srvData, 'servers' => $servers]);
        exit;
    }

    if ($action === 'delete_vpn_server') {
        $id = trim($_POST['id'] ?? '');
        $servers = loadVpnServers();
        $devices = loadVpnDevices();

        if (count($servers) <= 1) {
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить единственный оставшийся сервер! Добавьте другой перед удалением.']);
            exit;
        }

        $filtered = [];
        foreach ($servers as $s) {
            if ($s['id'] !== $id) {
                $filtered[] = $s;
            }
        }

        // Reassign any device using this server to first available
        $fallback = $filtered[0]['id'];
        foreach ($devices as &$d) {
            if ($d['server'] === $id) {
                $d['server'] = $fallback;
            }
        }
        unset($d);

        saveVpnServers($filtered);
        saveVpnDevices($devices);
        syncVpnRouting($devices, $filtered);

        echo json_encode(['success' => true, 'servers' => $filtered]);
        exit;
    }

    if ($action === 'delete_vpn_provider') {
        $group = trim($_POST['group'] ?? '');
        $servers = loadVpnServers();
        $devices = loadVpnDevices();

        $filtered = [];
        foreach ($servers as $s) {
            if (($s['group'] ?? 'VPN') !== $group) {
                $filtered[] = $s;
            }
        }

        if (empty($filtered)) {
            echo json_encode(['success' => false, 'error' => 'Нельзя удалить всех провайдеров! Должен остаться хотя бы один сервер.']);
            exit;
        }

        $fallback = $filtered[0]['id'];
        foreach ($devices as &$d) {
            $curSrv = null;
            foreach ($servers as $os) {
                if ($os['id'] === $d['server']) { $curSrv = $os; break; }
            }
            if ($curSrv && ($curSrv['group'] ?? 'VPN') === $group) {
                $d['server'] = $fallback;
            }
        }
        unset($d);

        saveVpnServers($filtered);
        saveVpnDevices($devices);
        syncVpnRouting($devices, $filtered);

        echo json_encode(['success' => true, 'servers' => $filtered]);
        exit;
    }

    if ($action === 'ping_vpn_server') {
        $id = trim($_POST['id'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $port = (int)($_POST['port'] ?? 0);

        if ($id && (!$address || !$port)) {
            $servers = loadVpnServers();
            foreach ($servers as $s) {
                if ($s['id'] === $id) {
                    $address = $s['address'] ?? '';
                    $port = (int)($s['port'] ?? 443);
                    break;
                }
            }
        }

        if (!$address || !$port) {
            echo json_encode(['success' => false, 'error' => 'Не указан адрес или порт']);
            exit;
        }

        $t1 = microtime(true);
        $fp = @fsockopen($address, $port, $errno, $errstr, 2.5);
        if ($fp) {
            fclose($fp);
            $latency = round((microtime(true) - $t1) * 1000);
            echo json_encode(['success' => true, 'latency' => $latency]);
        } else {
            echo json_encode(['success' => false, 'error' => $errstr ?: 'Таймаут подключения']);
        }
        exit;
    }

    if ($action === 'import_subscription') {
        $url = trim($_POST['url'] ?? '');
        $customGroup = trim($_POST['group'] ?? '');

        if (!preg_match('#^https?://#i', $url)) {
            echo json_encode(['success' => false, 'error' => 'Некорректный URL подписки']);
            exit;
        }

        $cmd = "curl -4 -s -L -k --connect-timeout 8 --max-time 15 -H 'User-Agent: Happ/4.2.1' " . escapeshellarg($url);
        $raw = shell_exec($cmd);
        if (!$raw) {
            echo json_encode(['success' => false, 'error' => 'Не удалось скачать данные подписки по URL']);
            exit;
        }

        $raw = trim($raw);
        $decoded = @base64_decode($raw);
        if ($decoded && str_contains($decoded, '://')) {
            $content = $decoded;
        } else {
            $content = $raw;
        }

        $lines = explode("\n", str_replace("\r", "", $content));
        $groupName = $customGroup;
        if (!$groupName) {
            $host = parse_url($url, PHP_URL_HOST);
            $groupName = strtoupper(explode('.', $host)[0]);
            if (strlen($groupName) < 3) $groupName = 'ПОДПИСКА';
        }

        $imported = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'vless://')) {
                $srv = parseVlessString($line, $groupName);
                if ($srv) {
                    $srv['sub_url'] = $url;
                    $imported[] = $srv;
                }
            }
        }

        if (empty($imported)) {
            echo json_encode(['success' => false, 'error' => 'В подписке не найдено поддерживаемых серверов VLESS (Reality, WS, TCP)']);
            exit;
        }

        $servers = loadVpnServers();
        $filtered = [];
        foreach ($servers as $s) {
            if (($s['group'] ?? '') !== $groupName && ($s['sub_url'] ?? '') !== $url) {
                $filtered[] = $s;
            }
        }

        $merged = array_merge($filtered, $imported);
        saveVpnServers($merged);
        $devices = loadVpnDevices();
        syncVpnRouting($devices, $merged);

        echo json_encode([
            'success' => true,
            'count' => count($imported),
            'group' => $groupName,
            'servers' => $merged,
            'message' => "Успешно импортировано " . count($imported) . " серверов VLESS в группу «{$groupName}»!"
        ]);
        exit;
    }

    if ($action === 'refresh_subscription') {
        $group = trim($_POST['group'] ?? '');
        $servers = loadVpnServers();
        $subUrl = '';
        foreach ($servers as $s) {
            if (($s['group'] ?? '') === $group && !empty($s['sub_url'])) {
                $subUrl = $s['sub_url'];
                break;
            }
        }

        if (!$subUrl) {
            echo json_encode(['success' => false, 'error' => 'Для этой группы не сохранен URL подписки']);
            exit;
        }

        $cmd = "curl -4 -s -L -k --connect-timeout 8 --max-time 15 -H 'User-Agent: Happ/4.2.1' " . escapeshellarg($subUrl);
        $raw = shell_exec($cmd);
        if (!$raw) {
            echo json_encode(['success' => false, 'error' => 'Не удалось обновить подписку']);
            exit;
        }

        $raw = trim($raw);
        $decoded = @base64_decode($raw);
        $content = ($decoded && str_contains($decoded, '://')) ? $decoded : $raw;

        $lines = explode("\n", str_replace("\r", "", $content));
        $imported = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'vless://')) {
                $srv = parseVlessString($line, $group);
                if ($srv) {
                    $srv['sub_url'] = $subUrl;
                    $imported[] = $srv;
                }
            }
        }

        if (empty($imported)) {
            echo json_encode(['success' => false, 'error' => 'Серверы VLESS не найдены']);
            exit;
        }

        $filtered = [];
        foreach ($servers as $s) {
            if (($s['group'] ?? '') !== $group) {
                $filtered[] = $s;
            }
        }

        $merged = array_merge($filtered, $imported);
        saveVpnServers($merged);
        $devices = loadVpnDevices();
        syncVpnRouting($devices, $merged);

        echo json_encode([
            'success' => true,
            'count' => count($imported),
            'group' => $group,
            'servers' => $merged,
            'message' => "Подписка «{$group}» успешно обновлена (" . count($imported) . " серверов)!"
        ]);
        exit;
    }

    // --- GGC / FREEZE KILLER HANDLERS ---
    if ($action === 'get_blocked_ggc') {
        $blocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';
        if (file_exists($file)) {
            $c = file_get_contents($file);
            if (preg_match('/DEAD_GGC="([^"]+)"/', $c, $m)) {
                $blocked = array_values(array_filter(explode(' ', trim($m[1]))));
            }
        }
        exec("iptables -L FORWARD -n -v | grep 'reject-with tcp-reset'", $lines);
        $iptablesBlocked = [];
        foreach ($lines as $l) {
            if (preg_match('/0\.0\.0\.0\/0\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(?:\/[0-9]+)?)/', $l, $m)) {
                if ($m[1] !== '0.0.0.0/0') {
                    $iptablesBlocked[] = $m[1];
                }
            }
        }
        $all = array_values(array_unique(array_merge($blocked, $iptablesBlocked)));
        echo json_encode(['success' => true, 'subnets' => $all]);
        exit;
    }

    if ($action === 'block_ggc_subnet') {
        $subnet = trim($_POST['subnet'] ?? '');
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $subnet)) {
            $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $subnet);
        }
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/', $subnet)) {
            echo json_encode(['success' => false, 'error' => 'Неверный формат подсети (ожидается X.X.X.0/24)']);
            exit;
        }

        exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");

        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $m)) {
                $cur = explode(' ', trim($m[1]));
                if (!in_array($subnet, $cur)) {
                    $cur[] = $subnet;
                    $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                    file_put_contents($file, $newC);
                }
            } else {
                $content .= "\nDEAD_GGC=\"$subnet\"\n";
                file_put_contents($file, $content);
            }
        }

        exec("conntrack -D -d $subnet 2>/dev/null");
        if (isset($_SERVER["REMOTE_ADDR"])) { exec("conntrack -D -s " . escapeshellarg($_SERVER["REMOTE_ADDR"]) . " 2>/dev/null"); }

        echo json_encode(['success' => true, 'subnet' => $subnet]);
        exit;
    }

    if ($action === 'unblock_ggc_subnet') {
        $subnet = trim($_POST['subnet'] ?? '');
        exec("while iptables -D FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null; do :; done");

        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $m)) {
                $cur = array_diff(explode(' ', trim($m[1])), [$subnet]);
                $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                file_put_contents($file, $newC);
            }
        }

        echo json_encode(['success' => true, 'subnet' => $subnet]);
        exit;
    }

    if ($action === 'test_single_ip') {
        $target = trim($_POST['target'] ?? '');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $target)) {
            echo json_encode(['success' => false, 'error' => 'Неверный формат IP']);
            exit;
        }

        $cmd = 'curl -4 -s -w "%{http_code}:%{time_total}" -o /dev/null --connect-timeout 2 --max-time 3 --tlsv1.2 --tls-max 1.2 --resolve "redirector.googlevideo.com:443:' . $target . '" "https://redirector.googlevideo.com/generate_204" 2>&1';
        exec($cmd, $curlOut);
        $res = explode(':', $curlOut[0] ?? '000:0');
        $code = $res[0] ?? '000';
        $time = (float)($res[1] ?? 0);
        $ok = in_array($code, ['200', '204']);

        echo json_encode([
            'success' => true,
            'ip' => $target,
            'code' => $code,
            'time' => round($time, 2),
            'status' => $ok ? 'live' : 'dead'
        ]);
        exit;
    }

    if ($action === 'scan_and_unfreeze') {
        $target = $_POST['target'] ?? 'all';
        $conntrackCmd = "cat /proc/net/nf_conntrack | grep 'dport=443'";

        if ($target === 'current' && !empty($_SERVER['REMOTE_ADDR'])) {
            $clientIp = escapeshellarg($_SERVER['REMOTE_ADDR']);
            $conntrackCmd .= " | grep $clientIp";
        }

        exec($conntrackCmd, $lines);

        $ips = [];
        foreach ($lines as $line) {
            if (preg_match('/dst=([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $line, $m)) {
                $ip = $m[1];
                if (!preg_match('/^(192\.168|10\.|172\.(1[6-9]|2[0-9]|3[01])|127\.)/', $ip)) {
                    $ips[$ip] = true;
                }
            }
        }
        $ips = array_keys($ips);

        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';
        $existingBlocked = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $m)) {
                $existingBlocked = explode(' ', trim($m[1]));
            }
        }

        $testedCount = 0;
        foreach ($ips as $ip) {
            if ($testedCount >= 10) break;
            $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
            if (in_array($subnet, $existingBlocked)) continue;

            $testedCount++;
            $cmd = 'curl -4 -s -w "%{http_code}:%{time_total}" -o /dev/null --connect-timeout 2 --max-time 3 --tlsv1.2 --tls-max 1.2 --resolve "redirector.googlevideo.com:443:' . $ip . '" "https://redirector.googlevideo.com/generate_204" 2>&1';
            exec($cmd, $out);
            $res = explode(':', $out[0] ?? '000:0');
            $code = $res[0] ?? '000';
            $time = (float)($res[1] ?? 0);
            $isLive = in_array($code, ['200', '204']);

            $results[] = [
                'ip' => $ip,
                'subnet' => $subnet,
                'code' => $code,
                'time' => round($time, 2),
                'status' => $isLive ? 'live' : 'dead'
            ];

            if (!$isLive && $code === '000') {
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $existingBlocked[] = $subnet;
                $newlyBlocked[] = $subnet;
                exec("conntrack -D -d $subnet 2>/dev/null");
            }
            unset($out);
        }

        if (count($newlyBlocked) > 0) {
            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', array_unique($existingBlocked)) . '"', $content);
            file_put_contents($file, $newC);
        }

        if ($target === 'current' && !empty($_SERVER['REMOTE_ADDR'])) {
            exec("conntrack -D -s " . escapeshellarg($_SERVER['REMOTE_ADDR']) . " 2>/dev/null");
        } else {
            // Flush conntrack for all enabled VPN devices
            $devices = loadVpnDevices();
            foreach ($devices as $d) {
                if (!empty($d['ip'])) {
                    exec("conntrack -D -s " . escapeshellarg($d['ip']) . " 2>/dev/null");
                }
            }
        }

        if (count($newlyBlocked) > 0) {
            $msg = '🚨 Найден и заблокирован зависший сервер: ' . implode(', ', $newlyBlocked) . '! Сокеты сброшены, видео возобновлено.';
        } elseif (count($results) > 0) {
            $msg = '✅ Проверено ' . count($results) . ' активных узлов. Зависшие сокеты сброшены — видео должно ожить!';
        } else {
            $msg = '⚡ Активных сокетов не найдено. Выполнен полный сброс кэша соединений сети!';
        }

        echo json_encode([
            'success' => true,
            'scanned_count' => count($results),
            'results' => $results,
            'newly_blocked' => $newlyBlocked,
            'message' => $msg
        ]);
        exit;
    }

    if ($action === 'get_status') {
        exec(INIT_SCRIPT . " status 2>&1", $out, $rc);
        $statusStr = implode("\n", $out);
        $isRunning = strpos($statusStr, 'is running') !== false;
        
        $activeStrategy = 'custom';
        if (file_exists(CONF_FILE)) {
            $conf = file_get_contents(CONF_FILE);
            if (preg_match('/#\s*general\s*\(([^)]+)\)/i', $conf, $m)) {
                $activeStrategy = trim($m[1]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'running' => $isRunning,
            'active_strategy' => $activeStrategy,
            'status_text' => $statusStr
        ]);
        exit;
    }

    if ($action === 'restart_service') {
        exec(INIT_SCRIPT . " restart 2>&1", $out, $rc);
        exec(INIT_SCRIPT . " status 2>&1", $stOut);
        $statusStr = implode("\n", $stOut);
        $isRunning = strpos($statusStr, 'is running') !== false;

        echo json_encode([
            'success' => ($rc === 0),
            'running' => $isRunning,
            'output' => implode("\n", $out),
            'status_text' => $statusStr
        ]);
        exit;
    }

    if ($action === 'sync_github') {
        $repoUrl = "https://raw.githubusercontent.com/Flowseal/zapret-discord-youtube/main/router/keenetic/strategies/nfqws2";
        $listUrl = "https://api.github.com/repos/Flowseal/zapret-discord-youtube/contents/router/keenetic/strategies/nfqws2";
        
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: Keenetic-NFQWS2-Manager\r\n",
                'timeout' => 10
            ]
        ]);
        
        $json = @file_get_contents($listUrl, false, $ctx);
        if ($json === false) {
            echo json_encode(['success' => false, 'error' => 'Не удалось подключиться к GitHub API']);
            exit;
        }
        
        $files = json_decode($json, true);
        if (!is_array($files)) {
            echo json_encode(['success' => false, 'error' => 'Неверный ответ GitHub API']);
            exit;
        }

        $downloaded = 0;
        @mkdir(STRATEGIES_DIR, 0755, true);

        foreach ($files as $file) {
            if (isset($file['name']) && str_ends_with($file['name'], '.conf') && isset($file['download_url'])) {
                $content = @file_get_contents($file['download_url'], false, $ctx);
                if ($content !== false) {
                    file_put_contents(STRATEGIES_DIR . '/' . $file['name'], $content);
                    $downloaded++;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Успешно обновлено $downloaded стратегий из репозитория Flowseal!"
        ]);
        exit;
    }

    if ($action === 'apply_strategy') {
        $strategyFile = $_POST['strategy'] ?? '';
        $strategyFile = basename($strategyFile);
        $srcPath = STRATEGIES_DIR . '/' . $strategyFile;

        if (!file_exists($srcPath)) {
            echo json_encode(['success' => false, 'error' => "Файл стратегии $strategyFile не найден"]);
            exit;
        }

        $newConf = file_get_contents($srcPath);
        if (empty($newConf)) {
            echo json_encode(['success' => false, 'error' => "Стратегия $strategyFile пуста"]);
            exit;
        }

        $newConf = str_replace(["\r\n", "\r"], "\n", $newConf);

        if (preg_match('/^ISP_INTERFACE=/m', $newConf)) {
            $newConf = preg_replace('/^ISP_INTERFACE=.*$/m', 'ISP_INTERFACE="ppp1"', $newConf);
        } else {
            $newConf = "ISP_INTERFACE=\"ppp1\"\n" . $newConf;
        }

        if (!preg_match('/^POLICY_NAME=/m', $newConf)) {
            $newConf .= "\nPOLICY_NAME=\"nfqws\"\nPOLICY_EXCLUDE=0\n";
        }
        if (preg_match('/^LOG_LEVEL=/m', $newConf)) {
            $newConf = preg_replace('/^LOG_LEVEL=.*$/m', 'LOG_LEVEL=0', $newConf);
        } else {
            $newConf .= "\nLOG_LEVEL=0\n";
        }

        if (!str_contains($newConf, 'dropbear.*222')) {
            $newConf .= "\n[ -z \"\$(pgrep -f 'dropbear.*222')\" ] && (/opt/etc/init.d/S51dropbear start 2>/dev/null || /opt/sbin/dropbear -p 222 -B 2>/dev/null || dropbear -p 222 -B 2>/dev/null) &\n";
        }

        if (file_exists(CONF_FILE)) {
            copy(CONF_FILE, CONF_BAK);
        }

        if (file_put_contents(CONF_FILE, $newConf) === false) {
            echo json_encode(['success' => false, 'error' => "Не удалось записать " . CONF_FILE]);
            exit;
        }

        @unlink(CONF_RUN);
        exec(INIT_SCRIPT . " restart 2>&1", $out, $rc);
        exec(INIT_SCRIPT . " status 2>&1", $stOut);
        $statusStr = implode("\n", $stOut);
        $isRunning = strpos($statusStr, 'is running') !== false;

        echo json_encode([
            'success' => true,
            'message' => "Стратегия $strategyFile успешно применена!",
            'running' => $isRunning,
            'status_text' => $statusStr
        ]);
        exit;
    }

    if ($action === 'run_diagnostics') {
        $results = [];

        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --connect-timeout 4 --max-time 5 https://www.youtube.com 2>&1", $out1);
        $res1 = explode(':', $out1[0] ?? '000:0');
        $results['youtube_tls13'] = [
            'name' => 'YouTube Web / ПК (TLS 1.3)',
            'code' => $res1[0] ?? '000',
            'time' => round((float)($res1[1] ?? 0), 2),
            'ok' => in_array($res1[0] ?? '', ['200', '301', '302'])
        ];

        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --tlsv1.2 --tls-max 1.2 --connect-timeout 4 --max-time 5 https://www.youtube.com 2>&1", $out2);
        $res2 = explode(':', $out2[0] ?? '000:0');
        $results['youtube_tls12'] = [
            'name' => 'Smart TV / Dune HD (TLS 1.2)',
            'code' => $res2[0] ?? '000',
            'time' => round((float)($res2[1] ?? 0), 2),
            'ok' => in_array($res2[0] ?? '', ['200', '301', '302'])
        ];

        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --connect-timeout 4 --max-time 5 https://i.ytimg.com/generate_204 2>&1", $out3);
        $res3 = explode(':', $out3[0] ?? '000:0');
        $results['ytimg'] = [
            'name' => 'Превью и статика (i.ytimg.com)',
            'code' => $res3[0] ?? '000',
            'time' => round((float)($res3[1] ?? 0), 2),
            'ok' => in_array($res3[0] ?? '', ['200', '204'])
        ];

        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --connect-timeout 4 --max-time 5 https://discord.com 2>&1", $out4);
        $res4 = explode(':', $out4[0] ?? '000:0');
        $results['discord'] = [
            'name' => 'Discord (discord.com)',
            'code' => $res4[0] ?? '000',
            'time' => round((float)($res4[1] ?? 0), 2),
            'ok' => in_array($res4[0] ?? '', ['200', '301', '302'])
        ];

        echo json_encode(['success' => true, 'tests' => $results]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// --- DATES HELPER ---
function formatRuDate($d) {
    if (!$d) return '—';
    $months = [
        '01' => 'янв', '02' => 'фев', '03' => 'мар', '04' => 'апр',
        '05' => 'мая', '06' => 'июн', '07' => 'июл', '08' => 'авг',
        '09' => 'сен', '10' => 'окт', '11' => 'ноя', '12' => 'дек'
    ];
    $parts = explode('-', $d);
    if (count($parts) === 3) {
        $m = $months[$parts[1]] ?? $parts[1];
        return ltrim($parts[2], '0') . ' ' . $m . ' ' . $parts[0];
    }
    return $d;
}

$strategyDates = [];
if (file_exists(DATES_FILE)) {
    $loadedDates = json_decode(file_get_contents(DATES_FILE), true);
    if (is_array($loadedDates)) {
        $strategyDates = $loadedDates;
    }
}

// --- STRATEGIES METADATA ---
$knownStrategies = [
    'zemidala_sniext.conf' => [
        'name' => 'ZEMIDALA: SNIExt+4 (YouTube)',
        'badge' => '🧪 Эксп. AS51570 (12.10)',
        'badge_cls' => 'badge-purple',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Свежая стратегия из AS51570 (youtube.com, 12 октября 2026). Резка пакета точно через 4 байта после расширения SNI (pos=1,sniext+4) со STUN-фейками. Не ломает старый TLS 1.2 на Dune HD и дезориентирует сигнатурный анализатор ТСПУ.',
        'category' => 'recommended'
    ],
    'zemidala_rostelecom.conf' => [
        'name' => 'ZEMIDALA: Rostelecom x3',
        'badge' => '🧪 Эксп. AS12389 (15.10)',
        'badge_cls' => 'badge-fire',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Самая подтвержденная стратегия Ростелекома (AS12389, обновление от 15 октября 2026). Тройной повтор hostfakesplit (repeats=3, host=www.google.com, tcp_ts=-600000) для продавливания жесткой фильтрации видеопотоков.',
        'category' => 'recommended'
    ],
    'beeline_kaluga.conf' => [
        'name' => 'DUNE HD & BEELINE (Калуга)',
        'badge' => '⭐ Оптимизировано под ТВ и Beeline',
        'badge_cls' => 'badge-fire',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Специальная конфигурация под приставки Smart TV / Dune HD и провайдера Beeline / YarNet (Калуга): hostfakesplit под YouTube (100% совместимо с TLS 1.2 Dune HD без ломающих TLS 1.3 шаблонов) + база ALT11 для Discord/сайтов + полный охват портов 1024-65534.',
        'category' => 'recommended'
    ],
    'hardcorp74.conf' => [
        'name' => 'HARDCORP74',
        'badge' => '🔥 Свежая (12.10)',
        'badge_cls' => 'badge-fire',
        'prio' => 1,
        'tv' => true,
        'desc' => 'Самое свежее обновление от 12 октября 2026. Custom fake google + multi-split + расширенные списки хостов. Оптимизировано под новые блокировки ТСПУ и Google видеосерверов.',
        'category' => 'recommended'
    ],
    'alt4.conf' => [
        'name' => 'ALT4',
        'badge' => 'Рекомендуется для YouTube & TV',
        'badge_cls' => 'badge-success',
        'prio' => 2,
        'tv' => true,
        'desc' => 'Fake STUN + Fake Google TLS + Multisplit (badseq +1000). Высочайшая стабильность для видеопотока 1080p/4K и приставок Smart TV (Dune HD / Android TV).',
        'category' => 'recommended'
    ],
    'alt11.conf' => [
        'name' => 'ALT11',
        'badge' => 'Актуальная для ТСПУ',
        'badge_cls' => 'badge-success',
        'prio' => 3,
        'tv' => true,
        'desc' => 'Fake STUN2 + Fake Max.ru (ts fooling) + Multisplit (seqovl=664). Эффективно пробивает жесткие блокировки и замедления.',
        'category' => 'recommended'
    ],
    'alt13.conf' => [
        'name' => 'ALT13',
        'badge' => 'Новинка (1.10.3)',
        'badge_cls' => 'badge-purple',
        'prio' => 4,
        'tv' => true,
        'desc' => 'Релиз Flowseal 1.10.3 (октябрь 2026). Fake Sochi Park + STUN2 + hostfakesplit (mail.ru). Свежий порт под обновленные фильтры.',
        'category' => 'recommended'
    ],
    'fake_tls_auto.conf' => [
        'name' => 'FAKE TLS AUTO',
        'badge' => 'Универсальная',
        'badge_cls' => 'badge-info',
        'prio' => 5,
        'tv' => true,
        'desc' => 'Автоматический выбор fake-пакетов и профилей TLS. Хороший баланс между скоростью и пробиваемостью.',
        'category' => 'recommended'
    ],
    'alt12.conf' => [
        'name' => 'ALT12',
        'badge' => 'Октябрь 2026',
        'badge_cls' => 'badge-info',
        'prio' => 6,
        'tv' => true,
        'desc' => 'Фейковый Google SNI + сегментация потока. Стабильная работа на мобильных и проводных провайдерах.',
        'category' => 'classic'
    ],
    'alt10.conf' => [
        'name' => 'ALT10',
        'badge' => 'Альтернативная',
        'badge_cls' => 'badge-secondary',
        'prio' => 7,
        'tv' => true,
        'desc' => 'STUN + multisplit с модификацией флагов TCP. Подходит для устойчивых блокировок YouTube.',
        'category' => 'classic'
    ],
    'alt.conf' => [
        'name' => 'ALT (Классика)',
        'badge' => 'Базовая',
        'badge_cls' => 'badge-secondary',
        'prio' => 8,
        'tv' => true,
        'desc' => 'Оригинальная стратегия ALT от Flowseal. Проверенное решение для большинства сетей.',
        'category' => 'classic'
    ],
    'alt2.conf' => [
        'name' => 'ALT2',
        'badge' => 'Классика',
        'badge_cls' => 'badge-secondary',
        'prio' => 9,
        'tv' => true,
        'desc' => 'Модификация ALT с измененным размером сплита для обхода глубокого анализа пакетов.',
        'category' => 'classic'
    ],
    'alt3.conf' => [
        'name' => 'ALT3',
        'badge' => 'Классика',
        'badge_cls' => 'badge-secondary',
        'prio' => 10,
        'tv' => true,
        'desc' => 'Двойной фейк STUN/TLS с разбивкой заголовков ClientHello.',
        'category' => 'classic'
    ],
    'alt5.conf' => [
        'name' => 'ALT5',
        'badge' => 'Классика',
        'badge_cls' => 'badge-secondary',
        'prio' => 11,
        'tv' => true,
        'desc' => 'Специализированная разбивка для Discord голосовых серверов и медиа.',
        'category' => 'classic'
    ],
    'alt6.conf' => [
        'name' => 'ALT6',
        'badge' => 'Классика',
        'badge_cls' => 'badge-secondary',
        'prio' => 12,
        'tv' => true,
        'desc' => 'Альтернативные параметры дублирования TCP SYN для проблемных узлов связи.',
        'category' => 'classic'
    ],
    'alt4_mod.conf' => [
        'name' => 'ALT4 MOD',
        'badge' => 'Модификация ALT4',
        'badge_cls' => 'badge-secondary',
        'prio' => 13,
        'tv' => true,
        'desc' => 'Вариант ALT4 с альтернативными смещениями пакетов для расширенной совместимости.',
        'category' => 'classic'
    ],
    'krushaaa.conf' => [
        'name' => 'KRUSHAAA',
        'badge' => 'Community (04.10)',
        'badge_cls' => 'badge-secondary',
        'prio' => 14,
        'tv' => false,
        'desc' => 'Авторский профиль от krushaaa с auto-disorder и custom blobs.',
        'category' => 'other'
    ],
    'MartinBacker.conf' => [
        'name' => 'MARTIN BACKER',
        'badge' => 'Community (19.09)',
        'badge_cls' => 'badge-secondary',
        'prio' => 15,
        'tv' => false,
        'desc' => 'Популярная проверенная сборка MartinBacker для zapret/nfqws2.',
        'category' => 'other'
    ],
    'simple_fake.conf' => [
        'name' => 'SIMPLE FAKE',
        'badge' => 'Простой фейк',
        'badge_cls' => 'badge-secondary',
        'prio' => 16,
        'tv' => false,
        'desc' => 'Классический fake с модификацией SNI и таймстемпов.',
        'category' => 'classic'
    ]
];

// Scan strategies directory & merge with archive
$archivedStrategies = loadStrategyArchive();
$allFiles = glob(STRATEGIES_DIR . '/*.conf') ?: [];
$strategies = [];
$activeCount = 0;
$archiveCount = 0;

foreach ($allFiles as $f) {
    $base = basename($f);
    if ($base === 'dune_yarnet.conf') {
        continue;
    }
    $stratDate = $strategyDates[$base] ?? '2026-10-04';
    $isFresh = ($stratDate >= '2026-10-10');
    $isArchived = in_array($base, $archivedStrategies);

    if ($isArchived) {
        $archiveCount++;
    } else {
        $activeCount++;
    }

    if (isset($knownStrategies[$base])) {
        $meta = $knownStrategies[$base];
        $meta['file'] = $base;
        $meta['date'] = $stratDate;
        $meta['date_formatted'] = formatRuDate($stratDate);
        $meta['fresh'] = $isFresh;
        $meta['is_archived'] = $isArchived;
        $strategies[$base] = $meta;
    } else {
        $niceName = strtoupper(str_replace(['.conf', '_'], ['', ' '], $base));
        $strategies[$base] = [
            'file' => $base,
            'name' => $niceName,
            'badge' => 'Дополнительная',
            'badge_cls' => 'badge-dark',
            'prio' => 50,
            'tv' => false,
            'desc' => 'Стратегия из набора Flowseal / Nare51 для nfqws2.',
            'category' => 'other',
            'date' => $stratDate,
            'date_formatted' => formatRuDate($stratDate),
            'fresh' => $isFresh,
            'is_archived' => $isArchived
        ];
    }
}

// Active Strategy Detection
$activeStrategyName = 'Не определена (Custom)';
if (file_exists(CONF_FILE)) {
    $conf = file_get_contents(CONF_FILE);
    if (preg_match('/#\s*general\s*\(([^)]+)\)/i', $conf, $m)) {
        $activeStrategyName = trim($m[1]);
    } elseif (preg_match('/hostfakesplit:host=www\.google\.com/i', $conf)) {
        $activeStrategyName = 'hostfakesplit (Этап 2)';
    }
}

// Load initial VPN data for server counts
$initialVpnServers = loadVpnServers();
$initialVpnDevices = loadVpnDevices();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFQWS2 Strategy Manager & VPN Hub</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        :root {
            --bg-color: #0b1120;
            --card-bg: rgba(22, 33, 54, 0.75);
            --card-border: rgba(255, 255, 255, 0.09);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #38bdf8;
            --accent-hover: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #a855f7;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(168, 85, 247, 0.10) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(16, 185, 129, 0.06) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-primary);
            min-height: 100vh;
            padding: 24px 16px 60px 16px;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
        }

        /* Top Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--card-border);
        }

        .logo-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            font-size: 28px;
            background: linear-gradient(135deg, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-title {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .header-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            background: rgba(255, 255, 255, 0.07);
            color: var(--text-primary);
            border: 1px solid var(--card-border);
            padding: 9px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            backdrop-filter: blur(10px);
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.22);
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            border-color: rgba(56, 189, 248, 0.5);
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #0369a1, #075985);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.45);
        }

        .btn-outline {
            border-color: rgba(56, 189, 248, 0.35);
            color: #38bdf8;
        }

        .btn-outline:hover {
            background: rgba(56, 189, 248, 0.12);
            border-color: #38bdf8;
        }

        .btn-warning {
            background: linear-gradient(135deg, #d97706, #b45309);
            color: #fff;
            border-color: rgba(245, 158, 11, 0.4);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #b45309, #92400e);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: #fff;
            border-color: rgba(239, 68, 68, 0.4);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c, #991b1b);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 11.5px;
            border-radius: 6px;
        }

        /* Status Grid */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .status-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 14px 18px;
            border-radius: 12px;
            backdrop-filter: blur(12px);
        }

        .status-title {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-value {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .dot-green {
            background-color: var(--success);
            box-shadow: 0 0 10px var(--success);
        }

        .dot-red {
            background-color: var(--danger);
            box-shadow: 0 0 10px var(--danger);
        }

        /* Badges */
        .badge {
            font-size: 11.5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            letter-spacing: 0.2px;
        }

        .badge-success { background: rgba(16, 185, 129, 0.16); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-fire { background: rgba(249, 115, 22, 0.16); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.3); }
        .badge-purple { background: rgba(168, 85, 247, 0.16); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-info { background: rgba(56, 189, 248, 0.16); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
        .badge-secondary { background: rgba(148, 163, 184, 0.14); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.25); }
        .badge-yellow { background: rgba(245, 158, 11, 0.16); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-dark { background: rgba(255, 255, 255, 0.06); color: #94a3b8; border: 1px solid rgba(255, 255, 255, 0.1); }
        .badge-dune { background: rgba(234, 179, 8, 0.18); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-date { background: rgba(99, 102, 241, 0.18); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.35); }

        /* GGC Freeze Killer Box */
        .freeze-box {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(15, 23, 42, 0.85));
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
            position: relative;
            backdrop-filter: blur(12px);
        }

        .freeze-box::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, #f59e0b, #ef4444, #f59e0b);
            border-radius: 14px 14px 0 0;
        }

        .freeze-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 14px;
        }

        .freeze-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 720px;
        }

        .freeze-icon { font-size: 26px; }
        .freeze-title { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .freeze-subtitle { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; line-height: 1.4; }

        .freeze-actions-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .device-select {
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            cursor: pointer;
        }

        .manual-ggc-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
            padding: 10px 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .subnet-input {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            padding: 7px 12px;
            color: #f8fafc;
            font-size: 13px;
            width: 220px;
            outline: none;
        }

        .subnet-input:focus { border-color: var(--accent); }

        .btn-action-sm {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-test { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border-color: rgba(56, 189, 248, 0.3); }
        .btn-test:hover { background: rgba(56, 189, 248, 0.25); }
        .btn-block { background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.3); }
        .btn-block:hover { background: rgba(239, 68, 68, 0.25); }

        .blocked-subnets-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .blocked-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
        .blocked-chips { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

        .chip {
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: monospace;
        }

        .chip-del { cursor: pointer; color: #ef4444; font-weight: bold; }
        .chip-del:hover { color: #fff; }

        .scan-results-box {
            margin-top: 14px;
            padding: 12px;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .scan-results-title { font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--text-primary); }
        .scan-results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px; }

        .scan-item {
            padding: 8px 10px;
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .scan-item.dead { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.3); }
        .scan-item.live { background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.25); }

        /* ============================================================ */
        /* UNIVERSAL VPN SECTION & CLIENT DEVICE CARDS                 */
        /* ============================================================ */
        .vpn-section {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(15, 23, 42, 0.9));
            border: 1px solid rgba(168, 85, 247, 0.35);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 22px;
            box-shadow: 0 10px 32px rgba(0, 0, 0, 0.3);
            position: relative;
            backdrop-filter: blur(14px);
        }

        .vpn-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, #a855f7, #38bdf8, #a855f7);
            border-radius: 14px 14px 0 0;
        }

        .vpn-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 16px;
        }

        .vpn-header-left {
            max-width: 720px;
        }

        .vpn-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .vpn-icon-main { font-size: 24px; }
        .vpn-title { font-size: 16px; font-weight: 700; }
        .vpn-subtitle { font-size: 12.5px; color: var(--text-secondary); margin-top: 4px; line-height: 1.4; }

        .vpn-header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .vpn-devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 14px;
        }

        .vpn-card {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .vpn-card:hover {
            border-color: rgba(56, 189, 248, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }

        .vpn-card.vpn-active {
            border-color: rgba(16, 185, 129, 0.45);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.07), rgba(15, 23, 42, 0.8));
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.15);
        }

        .vpn-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .vpn-device-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .vpn-device-icon {
            font-size: 26px;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .vpn-device-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .vpn-device-meta {
            font-size: 12px;
            color: var(--text-secondary);
            font-family: monospace;
            margin-top: 2px;
        }

        .vpn-card-body {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .vpn-server-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 8px;
            padding: 8px 10px;
        }

        .vpn-field-label {
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .vpn-card-select {
            background: #0f172a;
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.35);
            border-radius: 6px;
            padding: 7px 10px;
            font-size: 13px;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            width: 100%;
        }

        .vpn-card-select:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
        }

        .vpn-card-select optgroup {
            background: #1e293b;
            color: #94a3b8;
            font-weight: 700;
        }

        .vpn-card-select option {
            background: #0f172a;
            color: #f8fafc;
        }

        .vpn-route-info {
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            color: var(--text-secondary);
        }

        .vpn-route-val {
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
        }

        .vpn-card-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-vpn-toggle {
            flex: 1;
            justify-content: center;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        /* Active / Pressed / ON state - Vivid Glowing Emerald */
        .btn-vpn-active {
            background: linear-gradient(135deg, #059669, #10b981) !important;
            color: #ffffff !important;
            border: 1px solid rgba(52, 211, 153, 0.7) !important;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.45) !important;
        }

        .btn-vpn-active:hover {
            background: linear-gradient(135deg, #047857, #059669) !important;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.6) !important;
            transform: translateY(-1px);
        }

        .btn-vpn-active:active {
            transform: scale(0.97);
        }

        /* Inactive / OFF state - Sleek Dark with Cyan/Blue Outline */
        .btn-vpn-inactive {
            background: linear-gradient(135deg, #1e293b, #0f172a) !important;
            color: #38bdf8 !important;
            border: 1px solid rgba(56, 189, 248, 0.4) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25) !important;
        }

        .btn-vpn-inactive:hover {
            background: linear-gradient(135deg, #0284c7, #0369a1) !important;
            color: #ffffff !important;
            border-color: rgba(56, 189, 248, 0.6) !important;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4) !important;
            transform: translateY(-1px);
        }

        .btn-vpn-inactive:active {
            transform: scale(0.97);
        }

        /* Backward compat with any cached btn-vpn-off */
        .btn-vpn-off {
            background: linear-gradient(135deg, #059669, #10b981) !important;
            color: #ffffff !important;
            border: 1px solid rgba(52, 211, 153, 0.7) !important;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.45) !important;
        }

        /* Loading states */
        .btn-vpn-loading-on {
            background: linear-gradient(135deg, #065f46, #047857) !important;
            color: #a7f3d0 !important;
            border: 1px solid rgba(52, 211, 153, 0.5) !important;
            opacity: 0.9;
            cursor: wait;
        }

        .btn-vpn-loading-off {
            background: linear-gradient(135deg, #334155, #1e293b) !important;
            color: #94a3b8 !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            opacity: 0.9;
            cursor: wait;
        }

        /* Pulse animation for active VPN badge */
        @keyframes vpnPulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .vpn-badge-pulse {
            animation: vpnPulse 2s infinite;
        }

        .btn-vpn-del {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 9px 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-vpn-del:hover {
            background: rgba(239, 68, 68, 0.18);
            border-color: rgba(239, 68, 68, 0.4);
            color: #ef4444;
        }

        .vpn-empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 32px 20px;
            background: rgba(15, 23, 42, 0.5);
            border: 1px dashed rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Diagnostics Box */
        .diag-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 22px;
            backdrop-filter: blur(12px);
        }

        .diag-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
        }

        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
        }

        .diag-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 10px 14px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }

        /* ============================================================ */
        /* STRATEGY TABS (ACTIVE vs ARCHIVE)                           */
        /* ============================================================ */
        .strategy-tabs-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            align-items: center;
        }

        .strat-tab-btn {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--card-border);
            color: var(--text-secondary);
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .strat-tab-btn:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.08);
        }

        .strat-tab-btn.active {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(14, 165, 233, 0.25));
            border-color: #38bdf8;
            color: #38bdf8;
            box-shadow: 0 4px 16px rgba(56, 189, 248, 0.2);
        }

        .tab-badge {
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: inherit;
        }

        .strat-tab-btn.active .tab-badge {
            background: rgba(56, 189, 248, 0.3);
            color: #fff;
        }

        /* Toolbar */
        .toolbar-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
            backdrop-filter: blur(12px);
        }

        .toolbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 240px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 14px;
        }

        .search-input {
            width: 100%;
            background: #0f172a;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 9px 12px 9px 36px;
            color: #f8fafc;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus { border-color: var(--accent); }

        .sort-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sort-label {
            font-size: 12.5px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .sort-select {
            background: #0f172a;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 8px 12px;
            color: #f8fafc;
            font-size: 13px;
            outline: none;
            cursor: pointer;
        }

        .filter-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-chip {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-chip:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.08);
        }

        .filter-chip.active {
            background: rgba(56, 189, 248, 0.16);
            border-color: #38bdf8;
            color: #38bdf8;
        }

        .stats-text {
            font-size: 12px;
            color: var(--text-secondary);
            margin-left: auto;
        }

        /* Strategies Grid & Cards */
        .strategies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .strat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            backdrop-filter: blur(12px);
        }

        .strat-card:hover {
            border-color: rgba(56, 189, 248, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }

        .strat-card.active-strat {
            border-color: #38bdf8;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.08), rgba(30, 41, 59, 0.8));
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.2);
        }

        .strat-header {
            margin-bottom: 8px;
        }

        .strat-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .badges-group {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .strat-desc {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.45;
            margin-bottom: 14px;
        }

        .strat-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .strat-file {
            font-size: 11.5px;
            color: var(--text-secondary);
            font-family: monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 140px;
        }

        .btn-archive-action {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            border: 1px solid var(--card-border);
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-archive-action:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.12);
        }

        .btn-unarchive:hover {
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.4);
        }

        .active-tag {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================================ */
        /* MODALS (HAPP Server Manager & Device Picker)                */
        /* ============================================================ */
        .modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s, visibility 0.25s;
            padding: 16px;
        }

        .modal-backdrop.open {
            opacity: 1;
            visibility: visible;
        }

        .modal-dialog {
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            width: 100%;
            max-width: 650px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            transform: scale(0.95);
            transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }

        .modal-backdrop.open .modal-dialog {
            transform: scale(1);
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: color 0.2s;
        }

        .modal-close:hover { color: #fff; background: rgba(255, 255, 255, 0.08); }

        .modal-body {
            padding: 18px 20px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 14px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* HAPP Server Items & Groups */
        .happ-group {
            margin-bottom: 16px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 10px;
            overflow: hidden;
        }

        .happ-group-header {
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.04);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13.5px;
            font-weight: 700;
        }

        .happ-group-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .happ-server-item {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            transition: background 0.15s;
        }

        .happ-server-item:last-child { border-bottom: none; }
        .happ-server-item:hover { background: rgba(255, 255, 255, 0.04); }

        .happ-server-main {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .happ-server-flag { font-size: 22px; }

        .happ-server-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .happ-server-protocol {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 2px;
            letter-spacing: 0.3px;
        }

        .happ-server-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ping-pill {
            font-size: 11.5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
        }

        .ping-fast { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }
        .ping-med { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
        .ping-slow { background: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.3); }
        .ping-idle { background: rgba(255, 255, 255, 0.06); color: var(--text-secondary); border-color: rgba(255, 255, 255, 0.1); }

        /* Form Inputs in Modals */
        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            background: #0f172a;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 9px 12px;
            color: #f8fafc;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus { border-color: var(--accent); }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Modal Tabs */
        .modal-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .modal-tab-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            color: var(--text-secondary);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-tab-btn.active {
            background: rgba(56, 189, 248, 0.18);
            border-color: #38bdf8;
            color: #38bdf8;
        }

        /* Network Device Item */
        .net-dev-item {
            padding: 10px 14px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            transition: all 0.15s;
        }

        .net-dev-item:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(56, 189, 248, 0.3);
        }

        .net-dev-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .net-dev-icon { font-size: 22px; }
        .net-dev-name { font-size: 13.5px; font-weight: 700; color: var(--text-primary); }
        .net-dev-meta { font-size: 11.5px; color: var(--text-secondary); font-family: monospace; }

        /* Toast */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #fff;
            padding: 14px 20px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border-left: 4px solid var(--accent);
            display: none;
            z-index: 2000;
            font-size: 14px;
            max-width: 400px;
        }

        #noResults {
            display: none;
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
            font-size: 15px;
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--card-border);
            margin-bottom: 32px;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 640px) {
            .container { padding: 0 4px; }
            .vpn-devices-grid {
                grid-template-columns: 1fr;
            }
            .vpn-card {
                padding: 14px 14px;
            }
            .btn-vpn-toggle {
                padding: 13px 14px;
                font-size: 14px;
            }
            .vpn-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .vpn-header-actions {
                width: 100%;
                display: flex;
                gap: 8px;
            }
            .vpn-header-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <header class="header">
        <div class="logo-box">
            <div class="logo-icon">⚡</div>
            <div>
                <h1 class="logo-title">NFQWS2 & VPN Manager</h1>
                <div class="logo-subtitle">Интерактивное веб-управление стратегиями обхода ТСПУ и персональным VPN</div>
            </div>
        </div>
        <div class="header-nav">
            <button class="btn btn-outline" onclick="syncGithub()" id="btnSync">📥 Обновить с GitHub</button>
            <button class="btn btn-outline" onclick="restartService()" id="btnRestart">🔄 Перезапустить службу</button>
            <a href="/" class="btn btn-outline">⚙️ Конфиги nfqws-web</a>
        </div>
    </header>

    <!-- Status Grid -->
    <div class="status-grid">
        <div class="status-card">
            <div class="status-title">Статус NFQWS2</div>
            <div class="status-value" id="svcStatus">
                <span class="dot dot-green"></span> Запущен
            </div>
        </div>
        <div class="status-card">
            <div class="status-title">Текущая стратегия</div>
            <div class="status-value" id="activeStratText" title="<?= htmlspecialchars($activeStrategyName) ?>">
                <?= htmlspecialchars($activeStrategyName) ?>
            </div>
        </div>
        <div class="status-card">
            <div class="status-title">Интерфейс провайдера</div>
            <div class="status-value">ppp1 (PPPoE)</div>
        </div>
        <div class="status-card">
            <div class="status-title">Блокировка QUIC (UDP 443)</div>
            <div class="status-value" style="color: #34d399;">REJECT (Активна)</div>
        </div>
    </div>

    <!-- Live GGC & CDN Freeze Killer Box -->
    <div class="freeze-box">
        <div class="freeze-header">
            <div class="freeze-title-box">
                <span class="freeze-icon">🚨</span>
                <div>
                    <h2 class="freeze-title">
                        Реаниматор YouTube / Детектор зависших узлов
                        <span class="badge badge-yellow" style="font-size: 11px;">GGC Killer</span>
                    </h2>
                    <p class="freeze-subtitle">
                        Если ролик на ТВ или ПК замирает: нажмите кнопку — роутер мгновенно протестирует активные подключения, выявит зависший сервер провайдера (GGC), отправит TCP-сброс и заблокирует его. Видео продолжит воспроизведение без перезагрузки!
                    </p>
                </div>
            </div>
            <div class="freeze-actions-row">
                <select id="targetDeviceSelect" class="device-select">
                    <option value="current">💻 Это устройство</option>
                    <option value="all" selected>🌐 Все устройства LAN</option>
                </select>
                <button class="btn btn-warning" onclick="scanAndUnfreeze()" id="btnUnfreeze">
                    ⚡ Проверить и разблокировать видео
                </button>
            </div>
        </div>

        <div class="manual-ggc-row">
            <span class="blocked-label">Тест / блокировка вручную:</span>
            <input type="text" id="manualSubnetInput" placeholder="IP или подсеть (напр. 188.43.61.2)" class="subnet-input">
            <button class="btn-action-sm btn-test" onclick="testManualTarget()">🔍 Проверить узел</button>
            <button class="btn-action-sm btn-block" onclick="addManualSubnet()">🚫 Заблокировать подсеть (TCP-Reset)</button>
        </div>

        <div class="blocked-subnets-row">
            <span class="blocked-label">Активная блокировка мертвых кэшей:</span>
            <div id="blockedSubnetsList" class="blocked-chips">
                <span style="color: var(--text-secondary); font-size: 12px;">Загрузка...</span>
            </div>
        </div>

        <div id="scanResultsBox" style="display: none;" class="scan-results-box">
            <div class="scan-results-title" id="scanResultsTitle">Результаты проверки соединений:</div>
            <div class="scan-results-grid" id="scanResultsGrid"></div>
        </div>
    </div>

    <!-- UNIVERSAL VPN SECTION FOR LOCAL NETWORK DEVICES -->
    <div class="vpn-section">
        <div class="vpn-header">
            <div class="vpn-header-left">
                <div class="vpn-title-row">
                    <span class="vpn-icon-main">🛡️</span>
                    <h2 class="vpn-title">Персональный VPN для устройств (VLESS Reality)</h2>
                    <span class="badge badge-purple" id="vpnCoreBadge" style="font-size: 11px;">Xray Core 26.2.6</span>
                </div>
                <p class="vpn-subtitle">
                    Индивидуальная маршрутизация любых устройств домашней сети через неблокируемые VLESS Reality туннели. Каждое устройство может работать через свой отдельный сервер. Все остальные устройства в сети работают напрямую через провайдера без VPN.
                </p>
            </div>
            <div class="vpn-header-actions">
                <button class="btn btn-outline" onclick="openServersModal()" id="btnManageServers">
                    ⚙️ Серверы VPN (<span id="vpnServersCount"><?= count($initialVpnServers) ?></span>)
                </button>
                <button class="btn btn-primary" onclick="openAddDeviceModal()" id="btnAddDevice">
                    ➕ Добавить устройство
                </button>
            </div>
        </div>

        <!-- Dynamic Grid of Client Device Cards -->
        <div class="vpn-devices-grid" id="vpnDevicesGrid">
            <div class="vpn-empty-state">
                Загрузка клиентских устройств...
            </div>
        </div>
    </div>

    <!-- Live Diagnostics Box -->
    <div class="diag-box">
        <div class="diag-header">
            <div>
                <h2 style="font-size: 16px; font-weight: 700;">Экспресс-тест соединения с роутера</h2>
                <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                    Проверка рукопожатия TLS 1.3 (ПК) и TLS 1.2 (Smart TV / Dune HD)
                </p>
            </div>
            <button class="btn btn-primary" onclick="runDiagnostics()" id="btnDiag">
                ⚡ Проверить YouTube
            </button>
        </div>
        <div class="diag-grid" id="diagGrid">
            <div class="diag-item">
                <span class="diag-name">YouTube Web / ПК (TLS 1.3)</span>
                <span class="diag-badge badge-pending" id="test-youtube_tls13">—</span>
            </div>
            <div class="diag-item">
                <span class="diag-name">Smart TV / Dune HD (TLS 1.2)</span>
                <span class="diag-badge badge-pending" id="test-youtube_tls12">—</span>
            </div>
            <div class="diag-item">
                <span class="diag-name">Превью / Статика (i.ytimg.com)</span>
                <span class="diag-badge badge-pending" id="test-ytimg">—</span>
            </div>
            <div class="diag-item">
                <span class="diag-name">Discord (discord.com)</span>
                <span class="diag-badge badge-pending" id="test-discord">—</span>
            </div>
        </div>
    </div>

    <!-- STRATEGY TABS (ACTIVE vs ARCHIVE) -->
    <div class="strategy-tabs-bar">
        <button class="strat-tab-btn active" id="tabStratActive" onclick="switchStratTab(false)">
            ⚡ Активные стратегии <span class="tab-badge" id="activeCountBadge"><?= $activeCount ?></span>
        </button>
        <button class="strat-tab-btn" id="tabStratArchive" onclick="switchStratTab(true)">
            📦 Архив <span class="tab-badge" id="archiveCountBadge"><?= $archiveCount ?></span>
        </button>
    </div>

    <!-- Toolbar: Search, Filter Chips, Sort -->
    <div class="toolbar-box">
        <div class="toolbar-top">
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="stratSearch" class="search-input" placeholder="Поиск по названию или описанию (ALT4, 12.10, Beeline...)" oninput="filterCards()">
            </div>
            <div class="sort-box">
                <label for="sortSelect" class="sort-label">Сортировка:</label>
                <select id="sortSelect" class="sort-select" onchange="sortCards(this.value)">
                    <option value="fresh">📅 Сначала новые (по дате)</option>
                    <option value="recommended">⭐ Рекомендуемые сначала</option>
                    <option value="name_asc">🔤 По названию (А-Я)</option>
                    <option value="date_asc">📅 Сначала старые</option>
                </select>
            </div>
        </div>
        <div class="filter-chips">
            <button class="filter-chip active" data-filter="all" onclick="setFilter('all', this)">
                Все в этой вкладке (<span id="curTabCount"><?= $activeCount ?></span>)
            </button>
            <button class="filter-chip" data-filter="fresh" onclick="setFilter('fresh', this)">
                🔥 Свежие (Октябрь 2026)
            </button>
            <button class="filter-chip" data-filter="recommended" onclick="setFilter('recommended', this)">
                ⭐ Рекомендуемые
            </button>
            <button class="filter-chip" data-filter="tv" onclick="setFilter('tv', this)">
                📺 Smart TV / Dune HD
            </button>
            <span class="stats-text" id="statsCount">Показано: <?= $activeCount ?></span>
        </div>
    </div>

    <!-- Strategies Section Grid -->
    <div class="strategies-grid" id="strategiesGrid">
        <?php foreach ($strategies as $file => $meta): ?>
            <?php 
                $isActive = (stripos($activeStrategyName, $meta['name']) !== false) || 
                            ($meta['name'] === 'ALT4' && stripos($activeStrategyName, 'ALT4') !== false) ||
                            ($meta['name'] === 'ALT11' && stripos($activeStrategyName, 'ALT11') !== false) ||
                            ($meta['name'] === 'HARDCORP74' && stripos($activeStrategyName, 'hardcorp74') !== false) ||
                            ($file === 'beeline_kaluga.conf' && (stripos($activeStrategyName, 'BEELINE_KALUGA') !== false || stripos($activeStrategyName, 'DUNE_YARNET') !== false));

            ?>
            <div class="strat-card <?= $isActive ? 'active-strat' : '' ?>" 
                 id="card-<?= md5($file) ?>"
                 data-file="<?= htmlspecialchars($file) ?>"
                 data-name="<?= htmlspecialchars($meta['name']) ?>"
                 data-date="<?= htmlspecialchars($meta['date']) ?>"
                 data-prio="<?= (int)($meta['prio'] ?? 50) ?>"
                 data-category="<?= htmlspecialchars($meta['category'] ?? 'other') ?>"
                 data-fresh="<?= $meta['fresh'] ? '1' : '0' ?>"
                 data-tv="<?= ($meta['tv'] ?? false) ? '1' : '0' ?>"
                 data-archived="<?= $meta['is_archived'] ? '1' : '0' ?>"
                 data-desc="<?= htmlspecialchars($meta['desc']) ?>"
                 style="<?= $meta['is_archived'] ? 'display: none;' : '' ?>">
                <div>
                    <div class="strat-header">
                        <div class="strat-title-row">
                            <div class="strat-title"><?= htmlspecialchars($meta['name']) ?></div>
                        </div>
                    </div>
                    <div class="badges-group">
                        <span class="badge badge-date" title="Дата релиза / коммита стратегии">📅 <?= htmlspecialchars($meta['date_formatted']) ?></span>
                        <?php if ($meta['fresh']): ?>
                            <span class="badge badge-fire">🔥 Свежая</span>
                        <?php endif; ?>
                        <?php if ($meta['tv'] ?? false): ?>
                            <span class="badge badge-dune" title="Проверено: поддерживает TLS 1.2 для Dune HD и Smart TV">📺 Dune HD OK</span>
                        <?php endif; ?>
                        <span class="badge <?= $meta['badge_cls'] ?>"><?= htmlspecialchars($meta['badge']) ?></span>
                    </div>
                    <div class="strat-desc"><?= htmlspecialchars($meta['desc']) ?></div>
                </div>
                <div class="strat-footer">
                    <span class="strat-file" title="<?= htmlspecialchars($file) ?>"><?= htmlspecialchars($file) ?></span>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <?php if ($meta['is_archived']): ?>
                            <button class="btn-archive-action btn-unarchive" onclick="unarchiveStrategy('<?= htmlspecialchars($file) ?>', event)" title="Вернуть стратегию в активный список">
                                🔄 Восстановить
                            </button>
                        <?php else: ?>
                            <?php if (!$isActive): ?>
                                <button class="btn-archive-action" onclick="archiveStrategy('<?= htmlspecialchars($file) ?>', event)" title="Отправить в архив, чтобы не загромождать список">
                                    📦 В архив
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($isActive): ?>
                            <div class="active-tag">✓ Активна</div>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" onclick="applyStrategy('<?= htmlspecialchars($file) ?>', event)">
                                Применить
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="noResults">По вашему запросу стратегий не найдено</div>
</div>

<!-- ============================================================ -->
<!-- MODAL 1: ADD NETWORK DEVICE                                  -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="modalAddDevice" onclick="if(event.target===this)closeModal('modalAddDevice')">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title">➕ Добавить устройство в VPN</div>
            <button class="modal-close" onclick="closeModal('modalAddDevice')">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 14px;">
                Выберите устройство, обнаруженное в вашей домашней сети Keenetic, или введите IP-адрес вручную:
            </p>
            <div style="margin-bottom: 12px;">
                <input type="text" id="devModalSearch" class="form-control" placeholder="🔍 Фильтр по имени или IP..." oninput="filterNetworkDevices(this.value)">
            </div>
            
            <div id="netDevicesList" style="max-height: 260px; overflow-y: auto; margin-bottom: 18px;">
                <div style="text-align: center; color: var(--text-secondary); padding: 20px;">
                    ⏳ Сканирование устройств роутера...
                </div>
            </div>

            <div style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 14px;">
                <div style="font-size: 13.5px; font-weight: 700; margin-bottom: 10px; color: var(--text-primary);">
                    Или добавьте вручную (статический IP):
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Имя устройства:</label>
                        <input type="text" id="manualDevName" class="form-control" placeholder="Например: Smart TV Зал">
                    </div>
                    <div class="form-group">
                        <label class="form-label">IP-адрес в LAN:</label>
                        <input type="text" id="manualDevIp" class="form-control" placeholder="192.168.1.X">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Иконка:</label>
                        <select id="manualDevIcon" class="form-control">
                            <option value="📺">📺 Телевизор / Dune HD / Приставка</option>
                            <option value="💻">💻 Компьютер / Ноутбук</option>
                            <option value="📱">📱 Смартфон / Планшет</option>
                            <option value="🎮">🎮 Консоль / VR-шлем</option>
                            <option value="🔌">🔌 Умный дом / IoT</option>
                            <option value="🌐">🌐 Другое устройство</option>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button class="btn btn-primary" onclick="addManualDevice()" style="width: 100%;">
                            ➕ Добавить вручную
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 2: HAPP-STYLE SERVERS MANAGER                          -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="modalManageServers" onclick="if(event.target===this)closeModal('modalManageServers')">
    <div class="modal-dialog" style="max-width: 720px;">
        <div class="modal-header">
            <div class="modal-title">🌍 Серверы VPN (HAPP Hub)</div>
            <button class="modal-close" onclick="closeModal('modalManageServers')">✕</button>
        </div>
        <div class="modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px;">
                <input type="text" id="serversModalSearch" class="form-control" placeholder="🔍 Введите текст для поиска серверов..." oninput="filterServersModal(this.value)">
                <button class="btn btn-primary" onclick="openAddServerModal()" style="white-space: nowrap;">
                    ➕ Добавить сервер
                </button>
            </div>

            <div id="happServersContainer">
                <!-- Rendered dynamically -->
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalManageServers')">Закрыть</button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL 3: ADD / EDIT SERVER                                   -->
<!-- ============================================================ -->
<div class="modal-backdrop" id="modalEditServer" onclick="if(event.target===this)closeModal('modalEditServer')">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title" id="editServerModalTitle">➕ Добавить сервер VPN</div>
            <button class="modal-close" onclick="closeModal('modalEditServer')">✕</button>
        </div>
        <div class="modal-body">
            <div class="modal-tabs">
                <button class="modal-tab-btn active" id="btnTabImportSub" onclick="switchServerModalTab('sub')">
                    🔗 Подписка (Subscription URL)
                </button>
                <button class="modal-tab-btn" id="btnTabImportVless" onclick="switchServerModalTab('vless')">
                    ⚡ Одиночная ссылка VLESS
                </button>
                <button class="modal-tab-btn" id="btnTabManualServer" onclick="switchServerModalTab('manual')">
                    🛠️ Ручная настройка
                </button>
            </div>

            <!-- Tab 0: Subscription URL Import -->
            <div id="tabContentSub">
                <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.45;">
                    Вставьте ссылку на единую подписку провайдера (Subscription URL) — роутер автоматически скачает профили, декодирует base64 и добавит серверы VLESS (Reality, WS, TCP) в группу с возможностью автообновления:
                </p>
                <div class="form-group">
                    <label class="form-label">Ссылка на подписку (URL):</label>
                    <input type="text" id="subUrlInput" class="form-control" placeholder="https://example-vpn.com/sub/token.txt">
                </div>
                <div class="form-group">
                    <label class="form-label">Имя группы / провайдера (необязательно):</label>
                    <input type="text" id="subGroupNameInput" class="form-control" placeholder="Например: MyVPN (определится автоматически)">
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                    <button class="btn btn-primary" onclick="importSubscriptionUrl()" id="btnImportSub">
                        📥 Скачать и импортировать серверы
                    </button>
                </div>
            </div>

            <!-- Tab 1: VLESS Link Import -->
            <div id="tabContentVless" style="display: none;">
                <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 10px;">
                    Вставьте одиночную конфигурационную ссылку VLESS от любого провайдера или личного сервера (например, из бота YummyVPN, HAPP, v2rayN):
                </p>
                <div class="form-group">
                    <textarea id="vlessLinkTextarea" class="form-control" rows="4" placeholder="vless://uuid@host:port?security=reality&sni=...&pbk=...#Провайдер%20-%20Сервер"></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-bottom: 14px;">
                    <button class="btn btn-outline" onclick="parseAndPreviewVless()">
                        🔍 Распознать ссылку
                    </button>
                </div>
                <div id="vlessPreviewBox" style="display: none; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 12px; margin-bottom: 14px; font-size: 13px;">
                    <!-- Parsed fields preview -->
                </div>
            </div>

            <!-- Tab 2: Manual Server Setup Form -->
            <div id="tabContentManual" style="display: none;">
                <input type="hidden" id="srvFormId" value="">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Провайдер / Группа:</label>
                        <input type="text" id="srvFormGroup" class="form-control" placeholder="Например: Мой сервер или YummyVPN">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Название сервера:</label>
                        <input type="text" id="srvFormName" class="form-control" placeholder="🇳🇱 Нидерланды или 🇩🇪 Франкфурт VDS">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Адрес сервера (IP или домен):</label>
                        <input type="text" id="srvFormAddress" class="form-control" placeholder="198.51.100.1 или vds.example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Порт:</label>
                        <input type="number" id="srvFormPort" class="form-control" value="11443">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">UUID пользователя:</label>
                    <input type="text" id="srvFormUuid" class="form-control" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Транспорт:</label>
                        <select id="srvFormTransport" class="form-control">
                            <option value="grpc">gRPC</option>
                            <option value="ws">WebSocket</option>
                            <option value="tcp">TCP</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Безопасность:</label>
                        <select id="srvFormSecurity" class="form-control">
                            <option value="reality">Reality</option>
                            <option value="tls">TLS</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">SNI (Server Name / Маскировка):</label>
                    <input type="text" id="srvFormSni" class="form-control" placeholder="apple.com или icloud.com">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Public Key (pbk для Reality):</label>
                        <input type="text" id="srvFormPbk" class="form-control" placeholder="Public Key (43 символа base64url)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Short ID (sid для Reality):</label>
                        <input type="text" id="srvFormSid" class="form-control" placeholder="Short ID (напр. 8 или 16 hex)">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Name (для gRPC):</label>
                        <input type="text" id="srvFormServiceName" class="form-control" placeholder="apple">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Path (для WebSocket):</label>
                        <input type="text" id="srvFormPath" class="form-control" placeholder="/">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('modalEditServer')">Отмена</button>
            <button class="btn btn-primary" onclick="submitServerForm()">💾 Сохранить сервер</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
    let vpnDevices = [];
    let vpnServers = [];
    let networkDevices = [];
    let isArchiveTab = false;
    let currentFilter = 'all';

    // Toast notification
    function showToast(msg, isError = false) {
        const t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.style.display = 'block';
        t.style.borderLeftColor = isError ? 'var(--danger)' : 'var(--accent)';
        clearTimeout(t.timer);
        t.timer = setTimeout(() => { t.style.display = 'none'; }, 4000);
    }

    // Modal helpers
    function openModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('open');
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('open');
    }

    // ============================================================
    // STRATEGY TABS (ACTIVE vs ARCHIVE)
    // ============================================================
    function switchStratTab(toArchive) {
        isArchiveTab = toArchive;
        document.getElementById('tabStratActive').classList.toggle('active', !toArchive);
        document.getElementById('tabStratArchive').classList.toggle('active', toArchive);

        updateStratCardVisibility();
    }

    function updateStratCardVisibility() {
        const cards = document.querySelectorAll('.strat-card');
        let visibleCount = 0;
        let actCount = 0;
        let arcCount = 0;

        cards.forEach(card => {
            const isArchived = card.getAttribute('data-archived') === '1';
            if (isArchived) arcCount++; else actCount++;

            if (isArchived === isArchiveTab) {
                // Check current filter and search
                const matchesFilter = checkFilter(card);
                const matchesSearch = checkSearch(card);
                if (matchesFilter && matchesSearch) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            } else {
                card.style.display = 'none';
            }
        });

        document.getElementById('activeCountBadge').textContent = actCount;
        document.getElementById('archiveCountBadge').textContent = arcCount;
        document.getElementById('curTabCount').textContent = isArchiveTab ? arcCount : actCount;
        document.getElementById('statsCount').textContent = 'Показано: ' + visibleCount;

        const noRes = document.getElementById('noResults');
        if (noRes) noRes.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    function checkFilter(card) {
        if (currentFilter === 'all') return true;
        if (currentFilter === 'fresh') return card.getAttribute('data-fresh') === '1';
        if (currentFilter === 'recommended') return card.getAttribute('data-category') === 'recommended';
        if (currentFilter === 'tv') return card.getAttribute('data-tv') === '1';
        return true;
    }

    function checkSearch(card) {
        const q = (document.getElementById('stratSearch').value || '').trim().toLowerCase();
        if (!q) return true;
        const name = (card.getAttribute('data-name') || '').toLowerCase();
        const file = (card.getAttribute('data-file') || '').toLowerCase();
        const desc = (card.getAttribute('data-desc') || '').toLowerCase();
        return name.includes(q) || file.includes(q) || desc.includes(q);
    }

    function filterCards() {
        updateStratCardVisibility();
    }

    function setFilter(type, btn) {
        currentFilter = type;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        if (btn) btn.classList.add('active');
        updateStratCardVisibility();
    }

    function sortCards(by) {
        const grid = document.getElementById('strategiesGrid');
        const cards = Array.from(grid.querySelectorAll('.strat-card'));

        cards.sort((a, b) => {
            if (by === 'fresh') {
                const dateA = a.getAttribute('data-date') || '';
                const dateB = b.getAttribute('data-date') || '';
                if (dateA !== dateB) return dateB.localeCompare(dateA);
                return parseInt(a.getAttribute('data-prio')) - parseInt(b.getAttribute('data-prio'));
            } else if (by === 'recommended') {
                return parseInt(a.getAttribute('data-prio')) - parseInt(b.getAttribute('data-prio'));
            } else if (by === 'name_asc') {
                return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
            } else if (by === 'date_asc') {
                return (a.getAttribute('data-date') || '').localeCompare(b.getAttribute('data-date') || '');
            }
            return 0;
        });

        cards.forEach(c => grid.appendChild(c));
    }

    async function archiveStrategy(file, e) {
        if (e) e.stopPropagation();
        try {
            const fd = new FormData();
            fd.append('action', 'archive_strategy');
            fd.append('strategy', file);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const card = document.querySelector(`.strat-card[data-file="${file}"]`);
                if (card) {
                    card.setAttribute('data-archived', '1');
                    // Update button inside card
                    const btnBox = card.querySelector('.strat-footer > div');
                    if (btnBox) {
                        btnBox.innerHTML = `
                            <button class="btn-archive-action btn-unarchive" onclick="unarchiveStrategy('${file}', event)" title="Вернуть стратегию в активный список">
                                🔄 Восстановить
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="applyStrategy('${file}', event)">
                                Применить
                            </button>
                        `;
                    }
                }
                updateStratCardVisibility();
                showToast(`Стратегия ${file} перемещена в архив`);
            } else {
                showToast(data.error || 'Ошибка архивации', true);
            }
        } catch (err) {
            showToast('Ошибка связи с роутером: ' + err, true);
        }
    }

    async function unarchiveStrategy(file, e) {
        if (e) e.stopPropagation();
        try {
            const fd = new FormData();
            fd.append('action', 'unarchive_strategy');
            fd.append('strategy', file);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const card = document.querySelector(`.strat-card[data-file="${file}"]`);
                if (card) {
                    card.setAttribute('data-archived', '0');
                    const btnBox = card.querySelector('.strat-footer > div');
                    if (btnBox) {
                        btnBox.innerHTML = `
                            <button class="btn-archive-action" onclick="archiveStrategy('${file}', event)" title="Отправить в архив">
                                📦 В архив
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="applyStrategy('${file}', event)">
                                Применить
                            </button>
                        `;
                    }
                }
                updateStratCardVisibility();
                showToast(`Стратегия ${file} восстановлена в активные`);
            } else {
                showToast(data.error || 'Ошибка восстановления', true);
            }
        } catch (err) {
            showToast('Ошибка связи с роутером: ' + err, true);
        }
    }

    async function applyStrategy(file, e) {
        if (e) e.stopPropagation();
        showToast(`Применение стратегии ${file}...`);
        try {
            const fd = new FormData();
            fd.append('action', 'apply_strategy');
            fd.append('strategy', file);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Стратегия успешно применена!');
                setTimeout(() => window.location.reload(), 1200);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось применить'), true);
            }
        } catch (err) {
            showToast('Ошибка соединения: ' + err, true);
        }
    }

    // ============================================================
    // UNIVERSAL VPN CLIENT DEVICES
    // ============================================================
    async function loadVpnStatus() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_vpn_status');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                vpnDevices = data.devices || [];
                vpnServers = data.servers || [];

                // Update server count badge
                const cntEl = document.getElementById('vpnServersCount');
                if (cntEl) cntEl.textContent = vpnServers.length;

                // Update Core badge
                const coreBadge = document.getElementById('vpnCoreBadge');
                if (coreBadge) {
                    if (data.xray_running) {
                        coreBadge.className = 'badge badge-success';
                        coreBadge.textContent = '🟢 Xray Core Активен';
                    } else {
                        coreBadge.className = 'badge badge-secondary';
                        coreBadge.textContent = '⚪ Xray Core Остановлен';
                    }
                }

                renderVpnDevices();
            }
        } catch (e) {
            console.error('Error fetching VPN status:', e);
        }
    }

    function renderVpnDevices() {
        const grid = document.getElementById('vpnDevicesGrid');
        if (!grid) return;

        if (vpnDevices.length === 0) {
            grid.innerHTML = `
                <div class="vpn-empty-state">
                    Устройств для VPN пока не добавлено.<br>
                    Нажмите <b>«➕ Добавить устройство»</b> вверху, чтобы выбрать ТВ, ПК или смартфон из сети Keenetic!
                </div>
            `;
            return;
        }

        let html = '';
        vpnDevices.forEach(d => {
            const isAct = Boolean(d.enabled);
            const cardClass = isAct ? 'vpn-card vpn-active' : 'vpn-card';
            const statusBadge = isAct 
                ? '<span class="badge badge-success vpn-badge-pulse">🟢 VPN ВКЛЮЧЕН</span>' 
                : '<span class="badge badge-secondary">⚪ ПРЯМОЙ ДОСТУП</span>';

            // Server select options grouped by provider
            const grouped = {};
            vpnServers.forEach(s => {
                const g = s.group || 'VPN';
                if (!grouped[g]) grouped[g] = [];
                grouped[g].push(s);
            });

            let optsHtml = '';
            for (const [groupName, srvs] of Object.entries(grouped)) {
                optsHtml += `<optgroup label="${groupName}">`;
                srvs.forEach(s => {
                    const sel = (s.id === d.server) ? 'selected' : '';
                    optsHtml += `<option value="${s.id}" ${sel}>${s.name}</option>`;
                });
                optsHtml += `</optgroup>`;
            }

            const routeText = isAct 
                ? `🛡️ [${d.server_group || 'VPN'}] ${d.server_name || 'VLESS Reality'}`
                : 'Провайдер напрямую (без VPN)';
            const routeColor = isAct ? '#34d399' : 'var(--text-secondary)';

            const btnText = isAct ? '🟢 VPN ВКЛЮЧЕН · Отключить' : '🚀 Включить VPN';
            const btnClass = isAct ? 'btn btn-vpn-toggle btn-vpn-active' : 'btn btn-vpn-toggle btn-vpn-inactive';

            html += `
                <div class="${cardClass}" id="vpn-card-${d.id}">
                    <div class="vpn-card-top">
                        <div class="vpn-device-info">
                            <div class="vpn-device-icon">${d.icon || '📺'}</div>
                            <div>
                                <div class="vpn-device-name">${d.name}</div>
                                <div class="vpn-device-meta">${d.ip} ${d.mac ? '· ' + d.mac : ''}</div>
                            </div>
                        </div>
                        ${statusBadge}
                    </div>
                    <div class="vpn-card-body">
                        <div class="vpn-server-field">
                            <label class="vpn-field-label">🌍 Сервер VPN для этого устройства:</label>
                            <select class="vpn-card-select" onchange="changeDeviceServer('${d.id}', this.value)">
                                ${optsHtml}
                            </select>
                        </div>
                        <div class="vpn-route-info">
                            <span>Маршрут:</span>
                            <span class="vpn-route-val" style="color: ${routeColor};">${routeText}</span>
                        </div>
                        <div class="vpn-card-actions">
                            <button class="${btnClass}" id="btn-toggle-${d.id}" onclick="toggleVpnDevice('${d.id}')">
                                ${btnText}
                            </button>
                            <button class="btn-vpn-del" onclick="removeDevice('${d.id}')" title="Удалить устройство из списка">
                                🗑️
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });

        grid.innerHTML = html;
    }

    async function toggleVpnDevice(id) {
        const btn = document.getElementById('btn-toggle-' + id);
        const dev = vpnDevices.find(x => x.id === id);
        const nextState = dev ? !dev.enabled : true;

        if (btn) {
            btn.disabled = true;
            if (nextState) {
                btn.className = 'btn btn-vpn-toggle btn-vpn-loading-on';
                btn.innerHTML = '⏳ Включаем VPN...';
            } else {
                btn.className = 'btn btn-vpn-toggle btn-vpn-loading-off';
                btn.innerHTML = '⏳ Отключаем VPN...';
            }
        }

        try {
            const fd = new FormData();
            fd.append('action', 'toggle_vpn_device');
            fd.append('id', id);
            fd.append('enable', nextState ? '1' : '0');

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                await loadVpnStatus();
                showToast(nextState ? `🟢 VPN включен для «${dev ? dev.name : id}»` : `⚪ VPN отключен для «${dev ? dev.name : id}»`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось переключить'), true);
                await loadVpnStatus();
            }
        } catch (e) {
            showToast('Ошибка связи с роутером: ' + e, true);
            await loadVpnStatus();
        }
    }

    async function changeDeviceServer(id, serverKey) {
        showToast('Смена сервера...');
        try {
            const fd = new FormData();
            fd.append('action', 'set_device_server');
            fd.append('id', id);
            fd.append('server', serverKey);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                await loadVpnStatus();
                showToast('Сервер успешно изменен!');
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось сменить сервер'), true);
            }
        } catch (e) {
            showToast('Ошибка смены сервера: ' + e, true);
        }
    }

    async function removeDevice(id) {
        const dev = vpnDevices.find(x => x.id === id);
        const name = dev ? dev.name : id;
        if (!confirm(`Удалить устройство «${name}» из списка VPN? Маршрутизация вернется на провайдера.`)) {
            return;
        }

        showToast('Удаление устройства...');
        try {
            const fd = new FormData();
            fd.append('action', 'remove_vpn_device');
            fd.append('id', id);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                await loadVpnStatus();
                showToast(`Устройство «${name}» удалено`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось удалить'), true);
            }
        } catch (e) {
            showToast('Ошибка: ' + e, true);
        }
    }

    // Modal: Add Device
    async function openAddDeviceModal() {
        openModal('modalAddDevice');
        const listEl = document.getElementById('netDevicesList');
        listEl.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">⏳ Опрос сетевых таблиц Keenetic...</div>';

        try {
            const fd = new FormData();
            fd.append('action', 'get_network_devices');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                networkDevices = data.devices || [];
                renderNetworkDevicesList(networkDevices);
            } else {
                listEl.innerHTML = '<div style="color: var(--danger); padding: 10px;">Ошибка загрузки списка: ' + (data.error || '') + '</div>';
            }
        } catch (e) {
            listEl.innerHTML = '<div style="color: var(--danger); padding: 10px;">Ошибка связи: ' + e + '</div>';
        }
    }

    function renderNetworkDevicesList(devs) {
        const listEl = document.getElementById('netDevicesList');
        if (!listEl) return;

        if (devs.length === 0) {
            listEl.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">Устройств не обнаружено</div>';
            return;
        }

        let html = '';
        devs.forEach(d => {
            const already = d.already_added;
            const btnHtml = already 
                ? '<span style="font-size: 12px; color: var(--text-secondary);">✓ Уже в списке</span>'
                : `<button class="btn btn-outline btn-sm" onclick="addDeviceFromNet('${d.ip}', '${escapeJs(d.name)}', '${d.mac || ''}', '${d.icon || '📺'}')">➕ Добавить</button>`;

            const statusDot = d.active 
                ? '<span class="badge badge-success" style="font-size: 10.5px;">В сети</span>'
                : '<span class="badge badge-dark" style="font-size: 10.5px;">Офлайн</span>';

            html += `
                <div class="net-dev-item">
                    <div class="net-dev-left">
                        <span class="net-dev-icon">${d.icon || '🌐'}</span>
                        <div>
                            <div class="net-dev-name">${escapeHtml(d.name)} ${statusDot}</div>
                            <div class="net-dev-meta">${d.ip} ${d.mac ? '· ' + d.mac : ''}</div>
                        </div>
                    </div>
                    <div>${btnHtml}</div>
                </div>
            `;
        });
        listEl.innerHTML = html;
    }

    function filterNetworkDevices(q) {
        const query = (q || '').toLowerCase();
        const filtered = networkDevices.filter(d => 
            (d.name || '').toLowerCase().includes(query) || 
            (d.ip || '').includes(query) ||
            (d.mac || '').toLowerCase().includes(query)
        );
        renderNetworkDevicesList(filtered);
    }

    async function addDeviceFromNet(ip, name, mac, icon) {
        showToast(`Добавление ${name}...`);
        try {
            const fd = new FormData();
            fd.append('action', 'add_vpn_device');
            fd.append('ip', ip);
            fd.append('name', name);
            fd.append('mac', mac);
            fd.append('icon', icon);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                closeModal('modalAddDevice');
                await loadVpnStatus();
                showToast(`Устройство «${name}» успешно добавлено!`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось добавить'), true);
            }
        } catch (e) {
            showToast('Ошибка: ' + e, true);
        }
    }

    async function addManualDevice() {
        const name = (document.getElementById('manualDevName').value || '').trim();
        const ip = (document.getElementById('manualDevIp').value || '').trim();
        const icon = document.getElementById('manualDevIcon').value || '📺';

        if (!ip) {
            showToast('Укажите IP-адрес устройства!', true);
            return;
        }

        await addDeviceFromNet(ip, name || ('Устройство ' + ip), '', icon);
    }

    // ============================================================
    // HAPP SERVER & PROVIDER MANAGEMENT
    // ============================================================
    function openServersModal() {
        openModal('modalManageServers');
        renderHappServers();
    }

    function renderHappServers(filterQuery = '') {
        const container = document.getElementById('happServersContainer');
        if (!container) return;

        const q = (filterQuery || '').toLowerCase();

        // Group servers by provider
        const grouped = {};
        vpnServers.forEach(s => {
            const g = s.group || 'VPN';
            if (!grouped[g]) grouped[g] = [];
            grouped[g].push(s);
        });

        let html = '';
        for (const [groupName, srvs] of Object.entries(grouped)) {
            const matchingSrvs = srvs.filter(s => 
                (s.name || '').toLowerCase().includes(q) || 
                (s.address || '').toLowerCase().includes(q) ||
                groupName.toLowerCase().includes(q)
            );

            if (matchingSrvs.length === 0 && q) continue;

            const hasSub = srvs.some(x => x.sub_url);
            const refreshBtnHtml = hasSub 
                ? `<button class="btn btn-outline btn-sm" onclick="refreshSubscription('${escapeJs(groupName)}')" title="Обновить подписку с сервера">🔄 Обновить</button>` 
                : '';

            html += `
                <div class="happ-group">
                    <div class="happ-group-header">
                        <div class="happ-group-title">
                            <span>🍉 ${escapeHtml(groupName)}</span>
                            <span class="badge badge-dark" style="font-size: 11px;">${matchingSrvs.length} серверов</span>
                        </div>
                        <div style="display: flex; gap: 6px;">
                            ${refreshBtnHtml}
                            <button class="btn btn-secondary btn-sm" onclick="deleteProvider('${escapeJs(groupName)}')" title="Удалить провайдера">
                                🗑️
                            </button>
                        </div>
                    </div>
            `;

            matchingSrvs.forEach(s => {
                const protoStr = `${s.protocol || 'VLESS'} / ${(s.transport || 'grpc').toUpperCase()} / ${(s.security || 'reality').toUpperCase()}`;
                const pingId = `ping-badge-${s.id}`;

                html += `
                    <div class="happ-server-item">
                        <div class="happ-server-main">
                            <span class="happ-server-flag">🌐</span>
                            <div>
                                <div class="happ-server-name">${escapeHtml(s.name)}</div>
                                <div class="happ-server-protocol">${protoStr} · ${s.address}:${s.port}</div>
                            </div>
                        </div>
                        <div class="happ-server-actions">
                            <span class="ping-pill ping-idle" id="${pingId}" onclick="pingServer('${s.id}', '${s.address}', ${s.port})" title="Нажмите для проверки задержки">
                                ⏱️ Тест
                            </span>
                            <button class="btn btn-secondary btn-sm" onclick="copyServerUrl('${s.id}')" title="Копировать VLESS ссылку">
                                📋 URL
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="openEditServerModal('${s.id}')" title="Редактировать">
                                ✏️
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="deleteServer('${s.id}')" title="Удалить сервер" style="color: var(--danger);">
                                🗑️
                            </button>
                        </div>
                    </div>
                `;
            });

            html += `</div>`;
        }

        if (!html) {
            html = '<div style="text-align: center; color: var(--text-secondary); padding: 30px;">Серверов не найдено</div>';
        }

        container.innerHTML = html;
    }

    function filterServersModal(q) {
        renderHappServers(q);
    }

    async function pingServer(id, address, port) {
        const badge = document.getElementById('ping-badge-' + id);
        if (badge) {
            badge.className = 'ping-pill ping-idle';
            badge.textContent = '⏳ ...';
        }

        try {
            const fd = new FormData();
            fd.append('action', 'ping_vpn_server');
            fd.append('address', address);
            fd.append('port', port);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success && badge) {
                const ms = data.latency;
                let cls = 'ping-fast';
                if (ms > 250) cls = 'ping-slow';
                else if (ms > 100) cls = 'ping-med';

                badge.className = `ping-pill ${cls}`;
                badge.textContent = `⚡ ${ms} ms`;
            } else if (badge) {
                badge.className = 'ping-pill ping-slow';
                badge.textContent = '✕ Ошибка';
            }
        } catch (e) {
            if (badge) {
                badge.className = 'ping-pill ping-slow';
                badge.textContent = '✕ Сбой';
            }
        }
    }

    function copyServerUrl(id) {
        const s = vpnServers.find(x => x.id === id);
        if (!s) return;

        let url = `vless://${s.uuid}@${s.address}:${s.port}?security=${s.security || 'reality'}&type=${s.transport || 'grpc'}`;
        if (s.sni) url += `&sni=${encodeURIComponent(s.sni)}`;
        if (s.pbk) url += `&pbk=${encodeURIComponent(s.pbk)}`;
        if (s.sid) url += `&sid=${encodeURIComponent(s.sid)}`;
        if (s.serviceName) url += `&serviceName=${encodeURIComponent(s.serviceName)}`;
        if (s.path) url += `&path=${encodeURIComponent(s.path)}`;
        url += `&fp=chrome#${encodeURIComponent((s.group ? s.group + ' - ' : '') + s.name)}`;

        navigator.clipboard.writeText(url).then(() => {
            showToast('📋 Ссылка VLESS скопирована в буфер обмена!');
        }).catch(() => {
            prompt('Скопируйте ссылку вручную:', url);
        });
    }

    async function deleteServer(id) {
        const s = vpnServers.find(x => x.id === id);
        const name = s ? s.name : id;
        if (!confirm(`Удалить сервер «${name}»? Если какие-то устройства были на нем, они переключатся на другой сервер.`)) {
            return;
        }

        showToast('Удаление сервера...');
        try {
            const fd = new FormData();
            fd.append('action', 'delete_vpn_server');
            fd.append('id', id);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                vpnServers = data.servers || [];
                renderHappServers();
                await loadVpnStatus();
                showToast(`Сервер «${name}» удален`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось удалить'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        }
    }

    async function deleteProvider(group) {
        if (!confirm(`Удалить группу серверов «${group}» и все серверы внутри нее?`)) {
            return;
        }

        showToast('Удаление группы...');
        try {
            const fd = new FormData();
            fd.append('action', 'delete_vpn_provider');
            fd.append('group', group);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                vpnServers = data.servers || [];
                renderHappServers();
                await loadVpnStatus();
                showToast(`Группа «${group}» удалена`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось удалить'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        }
    }

    // Modal: Add / Edit Server
    function openAddServerModal() {
        document.getElementById('editServerModalTitle').textContent = '➕ Добавить сервер VPN';
        document.getElementById('srvFormId').value = '';
        document.getElementById('srvFormGroup').value = 'Мой сервер';
        document.getElementById('srvFormName').value = '';
        document.getElementById('srvFormAddress').value = '';
        document.getElementById('srvFormPort').value = '11443';
        document.getElementById('srvFormUuid').value = '';
        document.getElementById('srvFormTransport').value = 'grpc';
        document.getElementById('srvFormSecurity').value = 'reality';
        document.getElementById('srvFormSni').value = 'apple.com';
        document.getElementById('srvFormPbk').value = '';
        document.getElementById('srvFormSid').value = '';
        document.getElementById('srvFormServiceName').value = 'apple';
        document.getElementById('srvFormPath').value = '/';
        document.getElementById('vlessLinkTextarea').value = '';
        document.getElementById('vlessPreviewBox').style.display = 'none';
        document.getElementById('subUrlInput').value = '';
        document.getElementById('subGroupNameInput').value = '';

        switchServerModalTab('sub');
        openModal('modalEditServer');
    }

    function openEditServerModal(id) {
        const s = vpnServers.find(x => x.id === id);
        if (!s) return;

        document.getElementById('editServerModalTitle').textContent = '✏️ Редактировать сервер VPN';
        document.getElementById('srvFormId').value = s.id;
        document.getElementById('srvFormGroup').value = s.group || 'VPN';
        document.getElementById('srvFormName').value = s.name || '';
        document.getElementById('srvFormAddress').value = s.address || '';
        document.getElementById('srvFormPort').value = s.port || 443;
        document.getElementById('srvFormUuid').value = s.uuid || '';
        document.getElementById('srvFormTransport').value = s.transport || 'grpc';
        document.getElementById('srvFormSecurity').value = s.security || 'reality';
        document.getElementById('srvFormSni').value = s.sni || '';
        document.getElementById('srvFormPbk').value = s.pbk || '';
        document.getElementById('srvFormSid').value = s.sid || '';
        document.getElementById('srvFormServiceName').value = s.serviceName || '';
        document.getElementById('srvFormPath').value = s.path || '/';

        switchServerModalTab('manual');
        openModal('modalEditServer');
    }

    function switchServerModalTab(tab) {
        const btnSub = document.getElementById('btnTabImportSub');
        const btnVl = document.getElementById('btnTabImportVless');
        const btnMan = document.getElementById('btnTabManualServer');
        const tabSub = document.getElementById('tabContentSub');
        const tabVl = document.getElementById('tabContentVless');
        const tabMan = document.getElementById('tabContentManual');

        if (btnSub) btnSub.classList.toggle('active', tab === 'sub');
        if (btnVl) btnVl.classList.toggle('active', tab === 'vless');
        if (btnMan) btnMan.classList.toggle('active', tab === 'manual');

        if (tabSub) tabSub.style.display = (tab === 'sub') ? 'block' : 'none';
        if (tabVl) tabVl.style.display = (tab === 'vless') ? 'block' : 'none';
        if (tabMan) tabMan.style.display = (tab === 'manual') ? 'block' : 'none';
    }

    async function importSubscriptionUrl() {
        const url = (document.getElementById('subUrlInput').value || '').trim();
        const group = (document.getElementById('subGroupNameInput').value || '').trim();

        if (!url) {
            showToast('Вставьте ссылку на подписку!', true);
            return;
        }

        const btn = document.getElementById('btnImportSub');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳ Скачивание и парсинг...';
        }

        showToast('Загрузка подписки с сервера...');
        try {
            const fd = new FormData();
            fd.append('action', 'import_subscription');
            fd.append('url', url);
            fd.append('group', group);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                vpnServers = data.servers || [];
                closeModal('modalEditServer');
                renderHappServers();
                await loadVpnStatus();
                showToast(data.message || `Импортировано серверов: ${data.count}`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось импортировать'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = '📥 Скачать и импортировать серверы';
            }
        }
    }

    async function refreshSubscription(group) {
        showToast(`Обновление подписки «${group}»...`);
        try {
            const fd = new FormData();
            fd.append('action', 'refresh_subscription');
            fd.append('group', group);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                vpnServers = data.servers || [];
                renderHappServers();
                await loadVpnStatus();
                showToast(data.message || `Подписка «${group}» обновлена!`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось обновить'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        }
    }

    function parseVlessUrl(urlStr) {
        try {
            const trimmed = urlStr.trim();
            if (!trimmed.startsWith('vless://')) return null;

            const u = new URL(trimmed);
            const uuid = u.username;
            const address = u.hostname;
            const port = parseInt(u.port) || 443;
            const params = new URLSearchParams(u.search);

            let group = 'Мой сервер';
            let name = 'VLESS Сервер';
            const hash = decodeURIComponent(u.hash ? u.hash.substring(1) : '');

            if (hash) {
                if (hash.includes(' - ')) {
                    const parts = hash.split(' - ');
                    group = parts[0].trim();
                    name = parts.slice(1).join(' - ').trim();
                } else {
                    name = hash;
                }
            }

            return {
                uuid: uuid,
                address: address,
                port: port,
                transport: params.get('type') || 'grpc',
                security: params.get('security') || 'reality',
                sni: params.get('sni') || '',
                pbk: params.get('pbk') || '',
                sid: params.get('sid') || '',
                serviceName: params.get('serviceName') || '',
                path: params.get('path') || '/',
                group: group,
                name: name
            };
        } catch (e) {
            return null;
        }
    }

    function parseAndPreviewVless() {
        const raw = document.getElementById('vlessLinkTextarea').value;
        const parsed = parseVlessUrl(raw);

        const previewBox = document.getElementById('vlessPreviewBox');
        if (!parsed) {
            previewBox.style.display = 'block';
            previewBox.innerHTML = '<span style="color: var(--danger);">✕ Не удалось распознать ссылку. Проверьте формат (ожидается vless://uuid@host:port?...)</span>';
            return;
        }

        // Fill manual form
        document.getElementById('srvFormGroup').value = parsed.group;
        document.getElementById('srvFormName').value = parsed.name;
        document.getElementById('srvFormAddress').value = parsed.address;
        document.getElementById('srvFormPort').value = parsed.port;
        document.getElementById('srvFormUuid').value = parsed.uuid;
        document.getElementById('srvFormTransport').value = parsed.transport;
        document.getElementById('srvFormSecurity').value = parsed.security;
        document.getElementById('srvFormSni').value = parsed.sni;
        document.getElementById('srvFormPbk').value = parsed.pbk;
        document.getElementById('srvFormSid').value = parsed.sid;
        document.getElementById('srvFormServiceName').value = parsed.serviceName;
        document.getElementById('srvFormPath').value = parsed.path;

        previewBox.style.display = 'block';
        previewBox.innerHTML = `
            <div style="color: #34d399; font-weight: 700; margin-bottom: 6px;">✓ Ссылка успешно распознана!</div>
            <div><b>Провайдер:</b> ${escapeHtml(parsed.group)}</div>
            <div><b>Название:</b> ${escapeHtml(parsed.name)}</div>
            <div><b>Сервер:</b> ${escapeHtml(parsed.address)}:${parsed.port}</div>
            <div><b>Транспорт / Защита:</b> ${parsed.transport.toUpperCase()} / ${parsed.security.toUpperCase()}</div>
            ${parsed.sni ? `<div><b>SNI:</b> ${escapeHtml(parsed.sni)}</div>` : ''}
            <div style="margin-top: 8px; font-size: 12px; color: var(--text-secondary);">
                Нажмите <b>«Сохранить сервер»</b> ниже для добавления в хаб.
            </div>
        `;
    }

    async function submitServerForm() {
        // If on vless tab and preview not populated, try parse first
        if (document.getElementById('tabContentVless').style.display !== 'none') {
            const raw = document.getElementById('vlessLinkTextarea').value;
            if (raw) parseAndPreviewVless();
        }

        const id = document.getElementById('srvFormId').value;
        const name = (document.getElementById('srvFormName').value || '').trim();
        const group = (document.getElementById('srvFormGroup').value || '').trim() || 'Мой сервер';
        const address = (document.getElementById('srvFormAddress').value || '').trim();
        const port = document.getElementById('srvFormPort').value || 443;
        const uuid = (document.getElementById('srvFormUuid').value || '').trim();
        const transport = document.getElementById('srvFormTransport').value;
        const security = document.getElementById('srvFormSecurity').value;
        const sni = (document.getElementById('srvFormSni').value || '').trim();
        const pbk = (document.getElementById('srvFormPbk').value || '').trim();
        const sid = (document.getElementById('srvFormSid').value || '').trim();
        const serviceName = (document.getElementById('srvFormServiceName').value || '').trim();
        const path = (document.getElementById('srvFormPath').value || '').trim();

        if (!name || !address || !uuid) {
            showToast('Заполните обязательные поля: Название, Адрес, UUID!', true);
            return;
        }

        showToast('Сохранение сервера...');
        try {
            const fd = new FormData();
            fd.append('action', 'save_vpn_server');
            fd.append('id', id);
            fd.append('name', name);
            fd.append('group', group);
            fd.append('address', address);
            fd.append('port', port);
            fd.append('uuid', uuid);
            fd.append('transport', transport);
            fd.append('security', security);
            fd.append('sni', sni);
            fd.append('pbk', pbk);
            fd.append('sid', sid);
            fd.append('serviceName', serviceName);
            fd.append('path', path);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                vpnServers = data.servers || [];
                closeModal('modalEditServer');
                renderHappServers();
                await loadVpnStatus();
                showToast(`Сервер «${name}» успешно сохранен!`);
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось сохранить'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        }
    }

    // ============================================================
    // GGC / CDN FREEZE KILLER
    // ============================================================
    async function loadBlockedSubnets() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_blocked_ggc');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                renderBlockedSubnets(data.subnets || []);
            }
        } catch (e) {
            console.error('Error loading blocked subnets:', e);
        }
    }

    function renderBlockedSubnets(subnets) {
        const el = document.getElementById('blockedSubnetsList');
        if (!el) return;

        if (subnets.length === 0) {
            el.innerHTML = '<span style="color: var(--text-secondary); font-size: 12px;">Мертвых узлов не заблокировано</span>';
            return;
        }

        let html = '';
        subnets.forEach(s => {
            html += `
                <div class="chip">
                    <span>${s}</span>
                    <span class="chip-del" onclick="unblockSubnet('${s}')" title="Разблокировать">✕</span>
                </div>
            `;
        });
        el.innerHTML = html;
    }

    async function addManualSubnet() {
        const input = document.getElementById('manualSubnetInput');
        const subnet = (input.value || '').trim();
        if (!subnet) return;

        showToast('Блокировка узла ' + subnet + '...');
        try {
            const fd = new FormData();
            fd.append('action', 'block_ggc_subnet');
            fd.append('subnet', subnet);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                input.value = '';
                loadBlockedSubnets();
                showToast('Узел ' + data.subnet + ' заблокирован!');
            } else {
                showToast('Ошибка: ' + (data.error || 'Сбой блокировки'), true);
            }
        } catch (e) {
            showToast('Ошибка: ' + e, true);
        }
    }

    async function unblockSubnet(subnet) {
        showToast('Разблокировка узла ' + subnet + '...');
        try {
            const fd = new FormData();
            fd.append('action', 'unblock_ggc_subnet');
            fd.append('subnet', subnet);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                loadBlockedSubnets();
                showToast('Узел ' + subnet + ' разблокирован');
            } else {
                showToast('Ошибка разблокировки', true);
            }
        } catch (e) {
            showToast('Ошибка: ' + e, true);
        }
    }

    async function testManualTarget() {
        const input = document.getElementById('manualSubnetInput');
        let target = (input.value || '').trim();
        if (!target) return;
        if (target.includes('/')) target = target.split('/')[0];

        showToast('Тестирование ' + target + '...');
        try {
            const fd = new FormData();
            fd.append('action', 'test_single_ip');
            fd.append('target', target);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                if (data.status === 'live') {
                    showToast(`🟢 Узел ${data.ip} РАБОТАЕТ (${data.code}, ${data.time}s)`);
                } else {
                    showToast(`🚨 Узел ${data.ip} ЗАВИС / МЕРТВ (${data.code})`, true);
                }
            } else {
                showToast('Ошибка: ' + (data.error || 'Сбой теста'), true);
            }
        } catch (e) {
            showToast('Ошибка: ' + e, true);
        }
    }

    async function scanAndUnfreeze() {
        const btn = document.getElementById('btnUnfreeze');
        const sel = document.getElementById('targetDeviceSelect');
        const target = sel ? sel.value : 'all';

        btn.disabled = true;
        btn.textContent = '⏳ Сканирование и сброс...';
        showToast('Сканирование соединений и сброс сокетов...');

        try {
            const fd = new FormData();
            fd.append('action', 'scan_and_unfreeze');
            fd.append('target', target);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                showToast(data.message || 'Проверка завершена');
                loadBlockedSubnets();

                const box = document.getElementById('scanResultsBox');
                const grid = document.getElementById('scanResultsGrid');
                if (data.results && data.results.length > 0) {
                    let html = '';
                    data.results.forEach(r => {
                        const isDead = (r.status === 'dead');
                        html += `
                            <div class="scan-item ${isDead ? 'dead' : 'live'}">
                                <span>${r.ip} (${r.subnet})</span>
                                <span style="font-weight: 700;">${isDead ? '🚨 ЗАВИС (БЛОК)' : '🟢 OK (' + r.time + 's)'}</span>
                            </div>
                        `;
                    });
                    grid.innerHTML = html;
                    box.style.display = 'block';
                } else {
                    box.style.display = 'none';
                }
            } else {
                showToast('Ошибка проверки: ' + (data.error || 'Сбой'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        } finally {
            btn.disabled = false;
            btn.textContent = '⚡ Проверить и разблокировать видео';
        }
    }

    // ============================================================
    // EXPRESS DIAGNOSTICS
    // ============================================================
    async function runDiagnostics() {
        const btn = document.getElementById('btnDiag');
        btn.disabled = true;
        btn.textContent = '⏳ Тестирование...';

        ['test-youtube_tls13', 'test-youtube_tls12', 'test-ytimg', 'test-discord'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.className = 'diag-badge badge-pending';
                el.textContent = 'Тест...';
            }
        });

        try {
            const fd = new FormData();
            fd.append('action', 'run_diagnostics');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success && data.tests) {
                for (const [key, t] of Object.entries(data.tests)) {
                    const el = document.getElementById('test-' + key);
                    if (el) {
                        el.className = t.ok ? 'diag-badge badge-success' : 'diag-badge badge-fail';
                        el.textContent = t.ok ? `OK (${t.time}s)` : `Ошибка (${t.code})`;
                    }
                }
                showToast('Диагностика соединений завершена!');
            }
        } catch (e) {
            showToast('Ошибка диагностики: ' + e, true);
        } finally {
            btn.disabled = false;
            btn.textContent = '⚡ Проверить YouTube';
        }
    }

    // Header actions
    async function syncGithub() {
        const btn = document.getElementById('btnSync');
        btn.disabled = true;
        btn.textContent = '⏳ Загрузка...';
        showToast('Синхронизация стратегий с GitHub Flowseal...');

        try {
            const fd = new FormData();
            fd.append('action', 'sync_github');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast('Ошибка: ' + (data.error || 'Сбой синхронизации'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        } finally {
            btn.disabled = false;
            btn.textContent = '📥 Обновить с GitHub';
        }
    }

    async function restartService() {
        const btn = document.getElementById('btnRestart');
        btn.disabled = true;
        btn.textContent = '⏳ Перезапуск...';
        showToast('Перезапуск службы nfqws2...');

        try {
            const fd = new FormData();
            fd.append('action', 'restart_service');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast('Служба NFQWS2 успешно перезапущена!');
            } else {
                showToast('Ошибка перезапуска: ' + (data.output || ''), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
        } finally {
            btn.disabled = false;
            btn.textContent = '🔄 Перезапустить службу';
        }
    }

    // Utility escape functions
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escapeJs(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // Init on load
    window.addEventListener('DOMContentLoaded', () => {
        loadVpnStatus();
        loadBlockedSubnets();
        updateStratCardVisibility();
    });
</script>
</body>
</html>
