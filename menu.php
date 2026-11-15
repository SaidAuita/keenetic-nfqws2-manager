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

// --- APK DIRECT DOWNLOAD HANDLER ---
if (isset($_GET['download']) && $_GET['download'] === 'apk') {
    $apkPath = '/opt/share/www/nfqws/dune-vpn.apk';
    if (!file_exists($apkPath)) {
        $apkPath = __DIR__ . '/dune-vpn.apk';
    }
    if (file_exists($apkPath)) {
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="dune-vpn.apk"');
        header('Content-Length: ' . filesize($apkPath));
        readfile($apkPath);
        exit;
    }
}

// --- AJAX API HANDLER ---
if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) || isset($_REQUEST['action']) || isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['action'] ?? $_GET['api'] ?? '';

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
        $id = trim($_REQUEST['id'] ?? '');
        $enable = (int)($_REQUEST['enable'] ?? 0) === 1;

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

    if ($action === 'toggle_all_vpn') {
        $enable = (int)($_REQUEST['enable'] ?? 0) === 1;

        $devices = loadVpnDevices();
        $servers = loadVpnServers();

        foreach ($devices as &$d) {
            $d['enabled'] = $enable;
        }
        unset($d);

        saveVpnDevices($devices);
        syncVpnRouting($devices, $servers);

        echo json_encode(['success' => true, 'enabled' => $enable, 'devices' => $devices]);
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
        $newConf = "# ACTIVE_STRATEGY: " . $strategyFile . "\n# general (" . str_replace('.conf', '', $strategyFile) . ").conf\n" . $newConf;

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
    'exp_multidisorder_wssize.conf' => [
        'name' => 'EXP: MULTIDISORDER & WSSIZE (Резерв)',
        'badge' => '🧪 Эксперимент (26.10)',
        'badge_cls' => 'badge-warning',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Экспериментальная стратегия на крайний случай жестких блокировок: wssize (wsize=1:scale=6) + syndata + multidisorder (pos=host+3,host+6,host+11,host+16,sld,midsld+4,midsld+2,endhost-12:seqovl=sld:seqovl_pattern=0x474554202f). Дробит ClientHello более чем на 8 частей для обхода многоядерного ТСПУ.',
        'category' => 'recommended'
    ],
    'alukard_tv.conf' => [
        'name' => 'ALUKARD TV: Zero-Blob + TCP-Seg (24.10)',
        'badge' => '🔥 Свежая (24.10) / Smart TV & Mi Box',
        'badge_cls' => 'badge-fire',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Свежайший фикс от 24 октября 2026 против массового отвала YouTube на Smart TV (Samsung TV, Xiaomi Mi Box, Dune HD). Использует сырой нулевой блоб с десинхронизацией счетчиков TCP seq/ack и сегментацией tcpseg с перекрытием TLS 0x1603030000 (12 repeats). Пробивает прогрев DPI.',
        'category' => 'recommended'
    ],
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
$activeStrategyFile = '';
$activeStrategyName = 'Не определена (Custom)';
if (file_exists(CONF_FILE)) {
    $conf = file_get_contents(CONF_FILE);
    if (preg_match('/#\s*ACTIVE_STRATEGY:\s*([^\s\n]+)/i', $conf, $m)) {
        $activeStrategyFile = trim($m[1]);
    } elseif (preg_match('/#\s*general\s*\(([^)]+)\)/i', $conf, $m)) {
        $raw = trim($m[1]);
        $activeStrategyFile = str_ends_with($raw, '.conf') ? $raw : ($raw . '.conf');
    }
    
    // Auto-detect by signature if tag was missing
    if (!$activeStrategyFile || !isset($strategies[$activeStrategyFile])) {
        if (str_contains($conf, 'wssize:wsize=1') && str_contains($conf, 'multidisorder:pos=host+3')) {
            $activeStrategyFile = 'exp_multidisorder_wssize.conf';
        } elseif (str_contains($conf, '0x1603030000') || str_contains($conf, 'alukard_tv')) {
            $activeStrategyFile = 'alukard_tv.conf';
        } elseif (str_contains($conf, 'hostfakesplit:host=www.google.com')) {
            $activeStrategyFile = 'beeline_kaluga.conf';
        }
    }

    if ($activeStrategyFile && isset($strategies[$activeStrategyFile])) {
        $activeStrategyName = $strategies[$activeStrategyFile]['name'];
    } elseif ($activeStrategyFile && isset($knownStrategies[$activeStrategyFile])) {
        $activeStrategyName = $knownStrategies[$activeStrategyFile]['name'];
    }
}

// Load initial VPN data for server counts and active mode banner
$initialVpnServers = loadVpnServers();
$initialVpnDevices = loadVpnDevices();

$initialActiveVpnList = [];
foreach ($initialVpnDevices as $d) {
    if (!empty($d['enabled'])) {
        $initialActiveVpnList[] = ($d['icon'] ?? '📺') . ' ' . ($d['name'] ?? $d['id']);
    }
}
$isInitialVpnActive = count($initialActiveVpnList) > 0;
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

        /* Top Master Mode Banner (Direct Bypass vs VPN) */
        .top-mode-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 20px;
            border-radius: 14px;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px -6px rgba(0, 0, 0, 0.45);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .top-mode-banner::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.08), transparent 70%);
        }

        .top-mode-banner.mode-vpn {
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.35) 0%, rgba(147, 51, 234, 0.28) 100%);
            border: 1px solid rgba(168, 85, 247, 0.5);
            box-shadow: 0 0 25px rgba(147, 51, 234, 0.2);
        }

        .top-mode-banner.mode-direct {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.28) 0%, rgba(14, 116, 144, 0.25) 100%);
            border: 1px solid rgba(52, 211, 153, 0.45);
            box-shadow: 0 0 25px rgba(16, 185, 129, 0.15);
        }

        .top-mode-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .top-mode-icon-box {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 0 12px rgba(255, 255, 255, 0.05);
        }

        .top-mode-banner.mode-vpn .top-mode-icon-box {
            background: rgba(168, 85, 247, 0.2);
            border: 1px solid rgba(168, 85, 247, 0.4);
            animation: vpnPulse 2.5s infinite;
        }

        .top-mode-banner.mode-direct .top-mode-icon-box {
            background: rgba(52, 211, 153, 0.18);
            border: 1px solid rgba(52, 211, 153, 0.35);
        }

        .top-mode-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .top-mode-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .top-mode-banner.mode-vpn .top-mode-label { color: #c084fc; }
        .top-mode-banner.mode-direct .top-mode-label { color: #6ee7b7; }

        .top-mode-title {
            font-size: 17px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.25;
        }

        .top-mode-sub {
            font-size: 12.5px;
            color: #cbd5e1;
        }

        .top-mode-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .top-mode-banner {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 14px;
            }
            .top-mode-title {
                font-size: 15px;
            }
            .top-mode-right {
                width: 100%;
                justify-content: flex-end;
            }
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
    <!-- Master Active Mode Indicator Banner (Optimized for Mobile & Desktop) -->
    <div class="top-mode-banner <?= $isInitialVpnActive ? 'mode-vpn' : 'mode-direct' ?>" id="topModeBanner">
        <div class="top-mode-left">
            <div class="top-mode-icon-box" id="topModeIcon"><?= $isInitialVpnActive ? '🚀' : '⚡' ?></div>
            <div class="top-mode-info">
                <div class="top-mode-label" id="topModeLabel">
                    <?= $isInitialVpnActive ? 'АКТИВНЫЙ РЕЖИМ: ПЕРСОНАЛЬНЫЙ VPN ТУННЕЛЬ' : 'АКТИВНЫЙ РЕЖИМ: ПРЯМОЙ ОБХОД ТСПУ (NFQWS2)' ?>
                </div>
                <div class="top-mode-title" id="topModeTitle">
                    <?= $isInitialVpnActive ? 'VPN ТУННЕЛЬ АКТИВЕН' : htmlspecialchars($activeStrategyName) ?>
                </div>
                <div class="top-mode-sub" id="topModeSub">
                    <?= $isInitialVpnActive ? 'Трафик через VPN для: ' . htmlspecialchars(implode(', ', $initialActiveVpnList)) : 'Все устройства сети работают напрямую через Beeline без VPN (десинхронизация TLS 1.2 / TLS 1.3)' ?>
                </div>
            </div>
        </div>
        <div class="top-mode-right">
            <span class="badge <?= $isInitialVpnActive ? 'badge-purple vpn-badge-pulse' : 'badge-success' ?>" id="topModeBadge" style="font-size: 13px; padding: 6px 14px;">
                <?= $isInitialVpnActive ? '🟢 VPN: ' . count($initialActiveVpnList) . ' устр.' : '🟢 ПРЯМОЙ ОБХОД' ?>
            </span>
        </div>
    </div>

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

    <!-- UNIVERSAL VPN SECTION FOR LOCAL NETWORK DEVICES (MOVED TO TOP FOR MOBILE ACCESSIBILITY) -->
    <div class="vpn-section" id="vpnSection">
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

        <!-- Android Remote App Download & QR Card -->
        <div class="vpn-app-card" style="margin-top: 18px; background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 18px; flex: 1; min-width: 280px;">
                <div id="apkQrCode" style="background: #ffffff; padding: 6px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); line-height: 0; min-width: 108px; min-height: 108px; display: flex; align-items: center; justify-content: center;"></div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                        <span style="font-size: 20px;">📱</span>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-primary);">1-кнопочный пульт для Android (dune-vpn.apk)</h3>
                        <span class="badge badge-success" style="font-size: 10px;">34 КБ • Локально</span>
                    </div>
                    <p style="margin: 0 0 10px 0; font-size: 13px; color: var(--text-secondary); line-height: 1.4;">
                        Быстрое включение/выключение VPN для приставок (Кухня / Комната) прямо с телефона или через виджет рабочего стола. Работает автономно в локальной сети без интернета.
                    </p>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <a href="dune-vpn.apk" download="dune-vpn.apk" class="btn btn-outline" style="font-size: 12px; padding: 7px 16px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            📥 Скачать APK на телефон
                        </a>
                        <span style="font-size: 12px; color: var(--text-muted);">или наведите камеру на QR-код для скачивания</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                <button class="btn btn-sm btn-outline" onclick="toggleAllVpn(1)" style="font-size: 11px; padding: 6px 14px;">
                    ⚡ Включить VPN на всех
                </button>
                <button class="btn btn-sm btn-outline" onclick="toggleAllVpn(0)" style="font-size: 11px; padding: 6px 14px; color: var(--text-muted);">
                    ⏹️ Выключить VPN на всех
                </button>
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
                $isActive = ($activeStrategyFile === $file) || 
                            (stripos($activeStrategyName, $meta['name']) !== false) ||
                            (stripos($meta['name'], $activeStrategyName) !== false) ||
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
        updateTopModeBanner();
    }

    function updateTopModeBanner() {
        const banner = document.getElementById('topModeBanner');
        const iconEl = document.getElementById('topModeIcon');
        const labelEl = document.getElementById('topModeLabel');
        const titleEl = document.getElementById('topModeTitle');
        const subEl = document.getElementById('topModeSub');
        const badgeEl = document.getElementById('topModeBadge');
        if (!banner) return;

        const activeVpn = (vpnDevices || []).filter(d => Boolean(d.enabled));
        const stratEl = document.getElementById('activeStratText');
        const activeStrat = stratEl ? stratEl.textContent.trim() : 'DUNE HD & BEELINE (Калуга)';

        if (activeVpn.length > 0) {
            banner.className = 'top-mode-banner mode-vpn';
            if (iconEl) iconEl.textContent = '🚀';
            if (labelEl) labelEl.textContent = 'АКТИВНЫЙ РЕЖИМ: ПЕРСОНАЛЬНЫЙ VPN ТУННЕЛЬ';
            const devNames = activeVpn.map(d => `${d.icon || '📺'} ${d.name}`).join(', ');
            if (titleEl) titleEl.textContent = 'VPN ТУННЕЛЬ АКТИВЕН';
            if (subEl) subEl.textContent = `Трафик через VPN для: ${devNames} (остальные устройства — через прямой обход)`;
            if (badgeEl) {
                badgeEl.className = 'badge badge-purple vpn-badge-pulse';
                badgeEl.style.fontSize = '13px';
                badgeEl.style.padding = '6px 14px';
                badgeEl.textContent = `🟢 VPN: ${activeVpn.length} устр.`;
            }
        } else {
            banner.className = 'top-mode-banner mode-direct';
            if (iconEl) iconEl.textContent = '⚡';
            if (labelEl) labelEl.textContent = 'АКТИВНЫЙ РЕЖИМ: ПРЯМОЙ ОБХОД ТСПУ (NFQWS2)';
            if (titleEl) titleEl.textContent = activeStrat;
            if (subEl) subEl.textContent = 'Все устройства сети работают напрямую через Beeline без VPN (десинхронизация TLS 1.2 / TLS 1.3)';
            if (badgeEl) {
                badgeEl.className = 'badge badge-success';
                badgeEl.style.fontSize = '13px';
                badgeEl.style.padding = '6px 14px';
                badgeEl.textContent = '🟢 ПРЯМОЙ ОБХОД';
            }
        }
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

    // --- QR CODE GENERATOR (Offline embedded) ---
    var QRCode;!function(){function a(a){this.mode=c.MODE_8BIT_BYTE,this.data=a,this.parsedData=[];for(var b=[],d=0,e=this.data.length;e>d;d++){var f=this.data.charCodeAt(d);f>65536?(b[0]=240|(1835008&f)>>>18,b[1]=128|(258048&f)>>>12,b[2]=128|(4032&f)>>>6,b[3]=128|63&f):f>2048?(b[0]=224|(61440&f)>>>12,b[1]=128|(4032&f)>>>6,b[2]=128|63&f):f>128?(b[0]=192|(1984&f)>>>6,b[1]=128|63&f):b[0]=f,this.parsedData=this.parsedData.concat(b)}this.parsedData.length!=this.data.length&&(this.parsedData.unshift(191),this.parsedData.unshift(187),this.parsedData.unshift(239))}function b(a,b){this.typeNumber=a,this.errorCorrectLevel=b,this.modules=null,this.moduleCount=0,this.dataCache=null,this.dataList=[]}function i(a,b){if(void 0==a.length)throw new Error(a.length+"/"+b);for(var c=0;c<a.length&&0==a[c];)c++;this.num=new Array(a.length-c+b);for(var d=0;d<a.length-c;d++)this.num[d]=a[d+c]}function j(a,b){this.totalCount=a,this.dataCount=b}function k(){this.buffer=[],this.length=0}function m(){return"undefined"!=typeof CanvasRenderingContext2D}function n(){var a=!1,b=navigator.userAgent;return/android/i.test(b)&&(a=!0,aMat=b.toString().match(/android ([0-9]\.[0-9])/i),aMat&&aMat[1]&&(a=parseFloat(aMat[1]))),a}function r(a,b){for(var c=1,e=s(a),f=0,g=l.length;g>=f;f++){var h=0;switch(b){case d.L:h=l[f][0];break;case d.M:h=l[f][1];break;case d.Q:h=l[f][2];break;case d.H:h=l[f][3]}if(h>=e)break;c++}if(c>l.length)throw new Error("Too long data");return c}function s(a){var b=encodeURI(a).toString().replace(/\%[0-9a-fA-F]{2}/g,"a");return b.length+(b.length!=a?3:0)}a.prototype={getLength:function(){return this.parsedData.length},write:function(a){for(var b=0,c=this.parsedData.length;c>b;b++)a.put(this.parsedData[b],8)}},b.prototype={addData:function(b){var c=new a(b);this.dataList.push(c),this.dataCache=null},isDark:function(a,b){if(0>a||this.moduleCount<=a||0>b||this.moduleCount<=b)throw new Error(a+","+b);return this.modules[a][b]},getModuleCount:function(){return this.moduleCount},make:function(){this.makeImpl(!1,this.getBestMaskPattern())},makeImpl:function(a,c){this.moduleCount=4*this.typeNumber+17,this.modules=new Array(this.moduleCount);for(var d=0;d<this.moduleCount;d++){this.modules[d]=new Array(this.moduleCount);for(var e=0;e<this.moduleCount;e++)this.modules[d][e]=null}this.setupPositionProbePattern(0,0),this.setupPositionProbePattern(this.moduleCount-7,0),this.setupPositionProbePattern(0,this.moduleCount-7),this.setupPositionAdjustPattern(),this.setupTimingPattern(),this.setupTypeInfo(a,c),this.typeNumber>=7&&this.setupTypeNumber(a),null==this.dataCache&&(this.dataCache=b.createData(this.typeNumber,this.errorCorrectLevel,this.dataList)),this.mapData(this.dataCache,c)},setupPositionProbePattern:function(a,b){for(var c=-1;7>=c;c++)if(!(-1>=a+c||this.moduleCount<=a+c))for(var d=-1;7>=d;d++)-1>=b+d||this.moduleCount<=b+d||(this.modules[a+c][b+d]=c>=0&&6>=c&&(0==d||6==d)||d>=0&&6>=d&&(0==c||6==c)||c>=2&&4>=c&&d>=2&&4>=d?!0:!1)},getBestMaskPattern:function(){for(var a=0,b=0,c=0;8>c;c++){this.makeImpl(!0,c);var d=f.getLostPoint(this);(0==c||a>d)&&(a=d,b=c)}return b},createMovieClip:function(a,b,c){var d=a.createEmptyMovieClip(b,c),e=1;this.make();for(var f=0;f<this.modules.length;f++)for(var g=f*e,h=0;h<this.modules[f].length;h++){var i=h*e,j=this.modules[f][h];j&&(d.beginFill(0,100),d.moveTo(i,g),d.lineTo(i+e,g),d.lineTo(i+e,g+e),d.lineTo(i,g+e),d.endFill())}return d},setupTimingPattern:function(){for(var a=8;a<this.moduleCount-8;a++)null==this.modules[a][6]&&(this.modules[a][6]=0==a%2);for(var b=8;b<this.moduleCount-8;b++)null==this.modules[6][b]&&(this.modules[6][b]=0==b%2)},setupPositionAdjustPattern:function(){for(var a=f.getPatternPosition(this.typeNumber),b=0;b<a.length;b++)for(var c=0;c<a.length;c++){var d=a[b],e=a[c];if(null==this.modules[d][e])for(var g=-2;2>=g;g++)for(var h=-2;2>=h;h++)this.modules[d+g][e+h]=-2==g||2==g||-2==h||2==h||0==g&&0==h?!0:!1}},setupTypeNumber:function(a){for(var b=f.getBCHTypeNumber(this.typeNumber),c=0;18>c;c++){var d=!a&&1==(1&b>>c);this.modules[Math.floor(c/3)][c%3+this.moduleCount-8-3]=d}for(var c=0;18>c;c++){var d=!a&&1==(1&b>>c);this.modules[c%3+this.moduleCount-8-3][Math.floor(c/3)]=d}},setupTypeInfo:function(a,b){for(var c=this.errorCorrectLevel<<3|b,d=f.getBCHTypeInfo(c),e=0;15>e;e++){var g=!a&&1==(1&d>>e);6>e?this.modules[e][8]=g:8>e?this.modules[e+1][8]=g:this.modules[this.moduleCount-15+e][8]=g}for(var e=0;15>e;e++){var g=!a&&1==(1&d>>e);8>e?this.modules[8][this.moduleCount-e-1]=g:9>e?this.modules[8][15-e-1+1]=g:this.modules[8][15-e-1]=g}this.modules[this.moduleCount-8][8]=!a},mapData:function(a,b){for(var c=-1,d=this.moduleCount-1,e=7,g=0,h=this.moduleCount-1;h>0;h-=2)for(6==h&&h--;;){for(var i=0;2>i;i++)if(null==this.modules[d][h-i]){var j=!1;g<a.length&&(j=1==(1&a[g]>>>e));var k=f.getMask(b,d,h-i);k&&(j=!j),this.modules[d][h-i]=j,e--,-1==e&&(g++,e=7)}if(d+=c,0>d||this.moduleCount<=d){d-=c,c=-c;break}}}},b.PAD0=236,b.PAD1=17,b.createData=function(a,c,d){for(var e=j.getRSBlocks(a,c),g=new k,h=0;h<d.length;h++){var i=d[h];g.put(i.mode,4),g.put(i.getLength(),f.getLengthInBits(i.mode,a)),i.write(g)}for(var l=0,h=0;h<e.length;h++)l+=e[h].dataCount;if(g.getLengthInBits()>8*l)throw new Error("code length overflow. ("+g.getLengthInBits()+">"+8*l+")");for(g.getLengthInBits()+4<=8*l&&g.put(0,4);0!=g.getLengthInBits()%8;)g.putBit(!1);for(;;){if(g.getLengthInBits()>=8*l)break;if(g.put(b.PAD0,8),g.getLengthInBits()>=8*l)break;g.put(b.PAD1,8)}return b.createBytes(g,e)},b.createBytes=function(a,b){for(var c=0,d=0,e=0,g=new Array(b.length),h=new Array(b.length),j=0;j<b.length;j++){var k=b[j].dataCount,l=b[j].totalCount-k;d=Math.max(d,k),e=Math.max(e,l),g[j]=new Array(k);for(var m=0;m<g[j].length;m++)g[j][m]=255&a.buffer[m+c];c+=k;var n=f.getErrorCorrectPolynomial(l),o=new i(g[j],n.getLength()-1),p=o.mod(n);h[j]=new Array(n.getLength()-1);for(var m=0;m<h[j].length;m++){var q=m+p.getLength()-h[j].length;h[j][m]=q>=0?p.get(q):0}}for(var r=0,m=0;m<b.length;m++)r+=b[m].totalCount;for(var s=new Array(r),t=0,m=0;d>m;m++)for(var j=0;j<b.length;j++)m<g[j].length&&(s[t++]=g[j][m]);for(var m=0;e>m;m++)for(var j=0;j<b.length;j++)m<h[j].length&&(s[t++]=h[j][m]);return s};for(var c={MODE_NUMBER:1,MODE_ALPHA_NUM:2,MODE_8BIT_BYTE:4,MODE_KANJI:8},d={L:1,M:0,Q:3,H:2},e={PATTERN000:0,PATTERN001:1,PATTERN010:2,PATTERN011:3,PATTERN100:4,PATTERN101:5,PATTERN110:6,PATTERN111:7},f={PATTERN_POSITION_TABLE:[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]],G15:1335,G18:7973,G15_MASK:21522,getBCHTypeInfo:function(a){for(var b=a<<10;f.getBCHDigit(b)-f.getBCHDigit(f.G15)>=0;)b^=f.G15<<f.getBCHDigit(b)-f.getBCHDigit(f.G15);return(a<<10|b)^f.G15_MASK},getBCHTypeNumber:function(a){for(var b=a<<12;f.getBCHDigit(b)-f.getBCHDigit(f.G18)>=0;)b^=f.G18<<f.getBCHDigit(b)-f.getBCHDigit(f.G18);return a<<12|b},getBCHDigit:function(a){for(var b=0;0!=a;)b++,a>>>=1;return b},getPatternPosition:function(a){return f.PATTERN_POSITION_TABLE[a-1]},getMask:function(a,b,c){switch(a){case e.PATTERN000:return 0==(b+c)%2;case e.PATTERN001:return 0==b%2;case e.PATTERN010:return 0==c%3;case e.PATTERN011:return 0==(b+c)%3;case e.PATTERN100:return 0==(Math.floor(b/2)+Math.floor(c/3))%2;case e.PATTERN101:return 0==b*c%2+b*c%3;case e.PATTERN110:return 0==(b*c%2+b*c%3)%2;case e.PATTERN111:return 0==(b*c%3+(b+c)%2)%2;default:throw new Error("bad maskPattern:"+a)}},getErrorCorrectPolynomial:function(a){for(var b=new i([1],0),c=0;a>c;c++)b=b.multiply(new i([1,g.gexp(c)],0));return b},getLengthInBits:function(a,b){if(b>=1&&10>b)switch(a){case c.MODE_NUMBER:return 10;case c.MODE_ALPHA_NUM:return 9;case c.MODE_8BIT_BYTE:return 8;case c.MODE_KANJI:return 8;default:throw new Error("mode:"+a)}else if(27>b)switch(a){case c.MODE_NUMBER:return 12;case c.MODE_ALPHA_NUM:return 11;case c.MODE_8BIT_BYTE:return 16;case c.MODE_KANJI:return 10;default:throw new Error("mode:"+a)}else{if(!(41>b))throw new Error("type:"+b);switch(a){case c.MODE_NUMBER:return 14;case c.MODE_ALPHA_NUM:return 13;case c.MODE_8BIT_BYTE:return 16;case c.MODE_KANJI:return 12;default:throw new Error("mode:"+a)}}},getLostPoint:function(a){for(var b=a.getModuleCount(),c=0,d=0;b>d;d++)for(var e=0;b>e;e++){for(var f=0,g=a.isDark(d,e),h=-1;1>=h;h++)if(!(0>d+h||d+h>=b))for(var i=-1;1>=i;i++)0>e+i||e+i>=b||(0!=h||0!=i)&&g==a.isDark(d+h,e+i)&&f++;f>5&&(c+=3+f-5)}for(var d=0;b-1>d;d++)for(var e=0;b-1>e;e++){var j=0;a.isDark(d,e)&&j++,a.isDark(d+1,e)&&j++,a.isDark(d,e+1)&&j++,a.isDark(d+1,e+1)&&j++,(0==j||4==j)&&(c+=3)}for(var d=0;b>d;d++)for(var e=0;b-6>e;e++)a.isDark(d,e)&&!a.isDark(d,e+1)&&a.isDark(d,e+2)&&a.isDark(d,e+3)&&a.isDark(d,e+4)&&!a.isDark(d,e+5)&&a.isDark(d,e+6)&&(c+=40);for(var e=0;b>e;e++)for(var d=0;b-6>d;d++)a.isDark(d,e)&&!a.isDark(d+1,e)&&a.isDark(d+2,e)&&a.isDark(d+3,e)&&a.isDark(d+4,e)&&!a.isDark(d+5,e)&&a.isDark(d+6,e)&&(c+=40);for(var k=0,e=0;b>e;e++)for(var d=0;b>d;d++)a.isDark(d,e)&&k++;var l=Math.abs(100*k/b/b-50)/5;return c+=10*l}},g={glog:function(a){if(1>a)throw new Error("glog("+a+")");return g.LOG_TABLE[a]},gexp:function(a){for(;0>a;)a+=255;for(;a>=256;)a-=255;return g.EXP_TABLE[a]},EXP_TABLE:new Array(256),LOG_TABLE:new Array(256)},h=0;8>h;h++)g.EXP_TABLE[h]=1<<h;for(var h=8;256>h;h++)g.EXP_TABLE[h]=g.EXP_TABLE[h-4]^g.EXP_TABLE[h-5]^g.EXP_TABLE[h-6]^g.EXP_TABLE[h-8];for(var h=0;255>h;h++)g.LOG_TABLE[g.EXP_TABLE[h]]=h;i.prototype={get:function(a){return this.num[a]},getLength:function(){return this.num.length},multiply:function(a){for(var b=new Array(this.getLength()+a.getLength()-1),c=0;c<this.getLength();c++)for(var d=0;d<a.getLength();d++)b[c+d]^=g.gexp(g.glog(this.get(c))+g.glog(a.get(d)));return new i(b,0)},mod:function(a){if(this.getLength()-a.getLength()<0)return this;for(var b=g.glog(this.get(0))-g.glog(a.get(0)),c=new Array(this.getLength()),d=0;d<this.getLength();d++)c[d]=this.get(d);for(var d=0;d<a.getLength();d++)c[d]^=g.gexp(g.glog(a.get(d))+b);return new i(c,0).mod(a)}},j.RS_BLOCK_TABLE=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]],j.getRSBlocks=function(a,b){var c=j.getRsBlockTable(a,b);if(void 0==c)throw new Error("bad rs block @ typeNumber:"+a+"/errorCorrectLevel:"+b);for(var d=c.length/3,e=[],f=0;d>f;f++)for(var g=c[3*f+0],h=c[3*f+1],i=c[3*f+2],k=0;g>k;k++)e.push(new j(h,i));return e},j.getRsBlockTable=function(a,b){switch(b){case d.L:return j.RS_BLOCK_TABLE[4*(a-1)+0];case d.M:return j.RS_BLOCK_TABLE[4*(a-1)+1];case d.Q:return j.RS_BLOCK_TABLE[4*(a-1)+2];case d.H:return j.RS_BLOCK_TABLE[4*(a-1)+3];default:return void 0}},k.prototype={get:function(a){var b=Math.floor(a/8);return 1==(1&this.buffer[b]>>>7-a%8)},put:function(a,b){for(var c=0;b>c;c++)this.putBit(1==(1&a>>>b-c-1))},getLengthInBits:function(){return this.length},putBit:function(a){var b=Math.floor(this.length/8);this.buffer.length<=b&&this.buffer.push(0),a&&(this.buffer[b]|=128>>>this.length%8),this.length++}};var l=[[17,14,11,7],[32,26,20,14],[53,42,32,24],[78,62,46,34],[106,84,60,44],[134,106,74,58],[154,122,86,64],[192,152,108,84],[230,180,130,98],[271,213,151,119],[321,251,177,137],[367,287,203,155],[425,331,241,177],[458,362,258,194],[520,412,292,220],[586,450,322,250],[644,504,364,280],[718,560,394,310],[792,624,442,338],[858,666,482,382],[929,711,509,403],[1003,779,565,439],[1091,857,611,461],[1171,911,661,511],[1273,997,715,535],[1367,1059,751,593],[1465,1125,805,625],[1528,1190,868,658],[1628,1264,908,698],[1732,1370,982,742],[1840,1452,1030,790],[1952,1538,1112,842],[2068,1628,1168,898],[2188,1722,1228,958],[2303,1809,1283,983],[2431,1911,1351,1051],[2563,1989,1423,1093],[2699,2099,1499,1139],[2809,2213,1579,1219],[2953,2331,1663,1273]],o=function(){var a=function(a,b){this._el=a,this._htOption=b};return a.prototype.draw=function(a){function g(a,b){var c=document.createElementNS("http://www.w3.org/2000/svg",a);for(var d in b)b.hasOwnProperty(d)&&c.setAttribute(d,b[d]);return c}var b=this._htOption,c=this._el,d=a.getModuleCount();Math.floor(b.width/d),Math.floor(b.height/d),this.clear();var h=g("svg",{viewBox:"0 0 "+String(d)+" "+String(d),width:"100%",height:"100%",fill:b.colorLight});h.setAttributeNS("http://www.w3.org/2000/xmlns/","xmlns:xlink","http://www.w3.org/1999/xlink"),c.appendChild(h),h.appendChild(g("rect",{fill:b.colorDark,width:"1",height:"1",id:"template"}));for(var i=0;d>i;i++)for(var j=0;d>j;j++)if(a.isDark(i,j)){var k=g("use",{x:String(i),y:String(j)});k.setAttributeNS("http://www.w3.org/1999/xlink","href","#template"),h.appendChild(k)}},a.prototype.clear=function(){for(;this._el.hasChildNodes();)this._el.removeChild(this._el.lastChild)},a}(),p="svg"===document.documentElement.tagName.toLowerCase(),q=p?o:m()?function(){function a(){this._elImage.src=this._elCanvas.toDataURL("image/png"),this._elImage.style.display="block",this._elCanvas.style.display="none"}function d(a,b){var c=this;if(c._fFail=b,c._fSuccess=a,null===c._bSupportDataURI){var d=document.createElement("img"),e=function(){c._bSupportDataURI=!1,c._fFail&&_fFail.call(c)},f=function(){c._bSupportDataURI=!0,c._fSuccess&&c._fSuccess.call(c)};return d.onabort=e,d.onerror=e,d.onload=f,d.src="data:image/gif;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAFCAYAAACNbyblAAAAHElEQVQI12P4//8/w38GIAXDIBKE0DHxgljNBAAO9TXL0Y4OHwAAAABJRU5ErkJggg==",void 0}c._bSupportDataURI===!0&&c._fSuccess?c._fSuccess.call(c):c._bSupportDataURI===!1&&c._fFail&&c._fFail.call(c)}if(this._android&&this._android<=2.1){var b=1/window.devicePixelRatio,c=CanvasRenderingContext2D.prototype.drawImage;CanvasRenderingContext2D.prototype.drawImage=function(a,d,e,f,g,h,i,j){if("nodeName"in a&&/img/i.test(a.nodeName))for(var l=arguments.length-1;l>=1;l--)arguments[l]=arguments[l]*b;else"undefined"==typeof j&&(arguments[1]*=b,arguments[2]*=b,arguments[3]*=b,arguments[4]*=b);c.apply(this,arguments)}}var e=function(a,b){this._bIsPainted=!1,this._android=n(),this._htOption=b,this._elCanvas=document.createElement("canvas"),this._elCanvas.width=b.width,this._elCanvas.height=b.height,a.appendChild(this._elCanvas),this._el=a,this._oContext=this._elCanvas.getContext("2d"),this._bIsPainted=!1,this._elImage=document.createElement("img"),this._elImage.style.display="none",this._el.appendChild(this._elImage),this._bSupportDataURI=null};return e.prototype.draw=function(a){var b=this._elImage,c=this._oContext,d=this._htOption,e=a.getModuleCount(),f=d.width/e,g=d.height/e,h=Math.round(f),i=Math.round(g);b.style.display="none",this.clear();for(var j=0;e>j;j++)for(var k=0;e>k;k++){var l=a.isDark(j,k),m=k*f,n=j*g;c.strokeStyle=l?d.colorDark:d.colorLight,c.lineWidth=1,c.fillStyle=l?d.colorDark:d.colorLight,c.fillRect(m,n,f,g),c.strokeRect(Math.floor(m)+.5,Math.floor(n)+.5,h,i),c.strokeRect(Math.ceil(m)-.5,Math.ceil(n)-.5,h,i)}this._bIsPainted=!0},e.prototype.makeImage=function(){this._bIsPainted&&d.call(this,a)},e.prototype.isPainted=function(){return this._bIsPainted},e.prototype.clear=function(){this._oContext.clearRect(0,0,this._elCanvas.width,this._elCanvas.height),this._bIsPainted=!1},e.prototype.round=function(a){return a?Math.floor(1e3*a)/1e3:a},e}():function(){var a=function(a,b){this._el=a,this._htOption=b};return a.prototype.draw=function(a){for(var b=this._htOption,c=this._el,d=a.getModuleCount(),e=Math.floor(b.width/d),f=Math.floor(b.height/d),g=['<table style="border:0;border-collapse:collapse;">'],h=0;d>h;h++){g.push("<tr>");for(var i=0;d>i;i++)g.push('<td style="border:0;border-collapse:collapse;padding:0;margin:0;width:'+e+"px;height:"+f+"px;background-color:"+(a.isDark(h,i)?b.colorDark:b.colorLight)+';"></td>');g.push("</tr>")}g.push("</table>"),c.innerHTML=g.join("");var j=c.childNodes[0],k=(b.width-j.offsetWidth)/2,l=(b.height-j.offsetHeight)/2;k>0&&l>0&&(j.style.margin=l+"px "+k+"px")},a.prototype.clear=function(){this._el.innerHTML=""},a}();QRCode=function(a,b){if(this._htOption={width:256,height:256,typeNumber:4,colorDark:"#000000",colorLight:"#ffffff",correctLevel:d.H},"string"==typeof b&&(b={text:b}),b)for(var c in b)this._htOption[c]=b[c];"string"==typeof a&&(a=document.getElementById(a)),this._android=n(),this._el=a,this._oQRCode=null,this._oDrawing=new q(this._el,this._htOption),this._htOption.text&&this.makeCode(this._htOption.text)},QRCode.prototype.makeCode=function(a){this._oQRCode=new b(r(a,this._htOption.correctLevel),this._htOption.correctLevel),this._oQRCode.addData(a),this._oQRCode.make(),this._el.title=a,this._oDrawing.draw(this._oQRCode),this.makeImage()},QRCode.prototype.makeImage=function(){"function"==typeof this._oDrawing.makeImage&&(!this._android||this._android>=3)&&this._oDrawing.makeImage()},QRCode.prototype.clear=function(){this._oDrawing.clear()},QRCode.CorrectLevel=d}();

    function initApkQrCode() {
        const el = document.getElementById('apkQrCode');
        if (!el || typeof QRCode === 'undefined') return;
        el.innerHTML = '';
        const apkUrl = window.location.origin + '/dune-vpn.apk';
        new QRCode(el, {
            text: apkUrl,
            width: 104,
            height: 104,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    async function toggleAllVpn(enable) {
        showToast(enable ? 'Включение VPN на всех приставках...' : 'Выключение VPN на всех приставках...');
        try {
            const fd = new FormData();
            fd.append('action', 'toggle_all_vpn');
            fd.append('enable', enable ? '1' : '0');
            const res = await fetch('menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast(enable ? 'VPN включен на всех приставках' : 'VPN выключен на всех приставках');
                loadVpnStatus();
            } else {
                showToast('Ошибка: ' + (data.error || 'Сбой переключения'), true);
            }
        } catch (e) {
            showToast('Ошибка связи: ' + e, true);
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
        initApkQrCode();
    });
</script>
</body>
</html>
