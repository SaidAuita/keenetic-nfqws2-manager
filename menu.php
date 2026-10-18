<?php
/**
 * NFQWS2 Strategy Manager & Bypass Diagnostics Web GUI
 * URL: http://192.168.1.1:90/menu.php
 */

define('CONF_FILE', '/opt/etc/nfqws2/nfqws2.conf');
define('CONF_RUN', '/opt/etc/nfqws2/nfqws2.conf.run');
define('CONF_BAK', '/opt/etc/nfqws2/nfqws2.conf.bak');
define('STRATEGIES_DIR', '/opt/etc/nfqws-menu/strategies/nfqws2');
define('DATES_FILE', '/opt/etc/nfqws-menu/strategy_dates.json');
define('INIT_SCRIPT', '/opt/etc/init.d/S51nfqws2');

// --- AJAX API HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

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
        if (isset($_SERVER['REMOTE_ADDR'])) {
            exec("conntrack -D -s " . escapeshellarg($_SERVER['REMOTE_ADDR']) . " 2>/dev/null");
        }

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
        $cleanTarget = preg_replace('/^https?:\/\//', '', $target);
        $cleanTarget = preg_replace('/\/.*$/', '', $cleanTarget);
        $cleanTarget = preg_replace('/\/[0-9]+$/', '', $cleanTarget);
        
        $ip = $cleanTarget;
        $resolvedHost = '';
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip)) {
            $resolved = @gethostbyname($cleanTarget);
            if ($resolved && $resolved !== $cleanTarget) {
                $resolvedHost = $cleanTarget;
                $ip = $resolved;
            } else {
                echo json_encode(['success' => false, 'error' => 'Не удалось распознать IP или домен: ' . htmlspecialchars($cleanTarget)]);
                exit;
            }
        }

        if (preg_match('/\.0$/', $ip)) {
            $testIp = preg_replace('/\.0$/', '.2', $ip);
        } else {
            $testIp = $ip;
        }

        $t0 = microtime(true);
        $cOut = [];
        exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$testIp' 2>&1 | head -n 3", $cOut);
        $elapsed = round((microtime(true) - $t0) * 1000);
        $headerLine = trim($cOut[0] ?? '');
        $serverLine = '';
        foreach ($cOut as $cl) {
            if (stripos($cl, 'server:') !== false) $serverLine = trim($cl);
        }

        $isDead = (empty($headerLine) || stripos($headerLine, 'timed out') !== false || stripos($headerLine, 'refused') !== false);
        $suggestedSubnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);

        $bOut = [];
        exec("iptables -C FORWARD -d $suggestedSubnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null && echo 1 || echo 0", $bOut);
        $isBlocked = ($bOut[0] ?? '0') === '1';

        echo json_encode([
            'success' => true,
            'ip' => $ip,
            'test_ip' => $testIp,
            'resolved_host' => $resolvedHost,
            'suggested_subnet' => $suggestedSubnet,
            'latency_ms' => $elapsed,
            'dead' => $isDead,
            'blocked' => $isBlocked,
            'header' => $headerLine ?: 'Нет ответа (таймаут соединения)',
            'server' => $serverLine
        ]);
        exit;
    }

    if ($action === 'scan_and_unfreeze') {
        $targetDevice = $_POST['target_device'] ?? 'dune_kuh';
        $autoBlock = ($_POST['auto_block'] ?? '1') === '1';
        $data = @file_get_contents('/proc/net/nf_conntrack');
        $lines = explode("
", (string)$data);
        
        $ips = [];
        $clientIpsToFlush = [];

        foreach ($lines as $line) {
            if (strpos($line, 'dport=443') === false && strpos($line, 'dport=80') === false) continue;
            
            if ($targetDevice === 'dune_kuh') {
                if (strpos($line, 'src=192.168.1.100') === false) continue;
                $clientIpsToFlush['192.168.1.100'] = true;
            } elseif ($targetDevice === 'dune_komnata') {
                if (strpos($line, 'src=192.168.1.101') === false) continue;
                $clientIpsToFlush['192.168.1.101'] = true;
            } elseif ($targetDevice === 'current') {
                $cur = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                if (strpos($line, 'src=' . $cur) === false) continue;
                $clientIpsToFlush[$cur] = true;
            } else {
                if (!preg_match('/src=(192\.168\.\d+\.\d+)/', $line, $srcM)) continue;
                $clientIpsToFlush[$srcM[1]] = true;
            }

            if (preg_match('/src=192\.168\.\d+\.\d+\s+dst=([0-9.]+)/', $line, $dstM)) {
                $dst = $dstM[1];
                if (!preg_match('/^(192\.168|100\.64|127\.0|10\.|172\.(1[6-9]|2[0-9]|3[0-1])|224\.|239\.|255\.)/', $dst)) {
                    $ips[$dst] = true;
                }
            }
        }

        $candidateIps = array_keys($ips);
        $candidateIps = array_slice($candidateIps, 0, 8);

        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';

        foreach ($candidateIps as $ip) {
            $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
            $bOut = [];
            exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null && echo 1 || echo 0", $bOut);
            $isBlocked = ($bOut[0] ?? '0') === '1';

            $t0 = microtime(true);
            $cOut = [];
            exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$ip' 2>&1 | head -n 1", $cOut);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $info = trim($cOut[0] ?? '');

            $isDead = (empty($info) || stripos($info, 'timed out') !== false || stripos($info, 'refused') !== false);

            $isDune = in_array($targetDevice, ['dune_kuh', 'dune_komnata']);
            $isKnownSafe = preg_match('/^(142\.25[01]|172\.(217|253)|216\.58|74\.125|149\.154|91\.108|162\.159)\./', $ip);

            if ($isDead && !$isBlocked && $autoBlock && $isDune && !$isKnownSafe) {
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $newlyBlocked[] = $subnet;
                $isBlocked = true;

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $mm)) {
                        $cur = explode(' ', trim($mm[1]));
                        if (!in_array($subnet, $cur)) {
                            $cur[] = $subnet;
                            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                            file_put_contents($file, $newC);
                        }
                    }
                }
            }

            $results[] = [
                'ip' => $ip,
                'subnet' => $subnet,
                'latency_ms' => $elapsed,
                'dead' => $isDead,
                'blocked' => $isBlocked,
                'info' => $info ?: 'Нет ответа (таймаут)'
            ];
        }

        foreach (array_keys($clientIpsToFlush) as $cIp) {
            exec("conntrack -D -s " . escapeshellarg($cIp) . " 2>/dev/null");
        }
        if (isset($_SERVER["REMOTE_ADDR"])) { exec("conntrack -D -s " . escapeshellarg($_SERVER["REMOTE_ADDR"]) . " 2>/dev/null"); }

        $msg = '';
        if (count($newlyBlocked) > 0) {
            $msg = '🚨 Найден и заблокирован зависший сервер: ' . implode(', ', $newlyBlocked) . '! Сокеты сброшены, видео возобновлено.';
        } elseif (count($results) > 0) {
            $msg = '✅ Проверено ' . count($results) . ' активных узлов. Зависшие сокеты сброшены — видео должно ожить!';
        } else {
            $msg = '⚡ Активных сокетов не найдено. Выполнен полный сброс кэша соединений приставки!';
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
        if (isset($_SERVER['REMOTE_ADDR'])) {
            exec("conntrack -D -s " . escapeshellarg($_SERVER['REMOTE_ADDR']) . " 2>/dev/null");
        }

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
        $ip = preg_replace('/\/[0-9]+$/', '', $target);
        if (preg_match('/\.0$/', $ip)) {
            $testIp = preg_replace('/\.0$/', '.2', $ip);
        } else {
            $testIp = $ip;
        }

        $t0 = microtime(true);
        exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$testIp' 2>&1 | head -n 3", $cOut);
        $elapsed = round((microtime(true) - $t0) * 1000);
        $headerLine = trim($cOut[0] ?? '');
        $serverLine = '';
        foreach ($cOut as $cl) {
            if (stripos($cl, 'server:') !== false) $serverLine = trim($cl);
        }

        $isDead = (empty($headerLine) || stripos($headerLine, 'timed out') !== false || stripos($headerLine, 'refused') !== false);
        $suggestedSubnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);

        exec("iptables -C FORWARD -d $suggestedSubnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null && echo 1 || echo 0", $bOut);
        $isBlocked = ($bOut[0] ?? '0') === '1';

        echo json_encode([
            'success' => true,
            'ip' => $ip,
            'test_ip' => $testIp,
            'suggested_subnet' => $suggestedSubnet,
            'latency_ms' => $elapsed,
            'dead' => $isDead,
            'blocked' => $isBlocked,
            'header' => $headerLine ?: 'Нет ответа (таймаут соединения)',
            'server' => $serverLine
        ]);
        exit;
    }

    if ($action === 'scan_and_unfreeze') {
        $targetDevice = $_POST['target_device'] ?? 'all';
        $data = @file_get_contents('/proc/net/nf_conntrack');
        $lines = explode("\n", (string)$data);
        
        $ips = [];
        $clientIpsToFlush = [];

        foreach ($lines as $line) {
            if (strpos($line, 'dport=443') === false && strpos($line, 'dport=80') === false) continue;
            
            if ($targetDevice === 'dune_kuh') {
                if (strpos($line, 'src=192.168.1.100') === false) continue;
                $clientIpsToFlush['192.168.1.100'] = true;
            } elseif ($targetDevice === 'dune_komnata') {
                if (strpos($line, 'src=192.168.1.101') === false) continue;
                $clientIpsToFlush['192.168.1.101'] = true;
            } elseif ($targetDevice === 'current') {
                $cur = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                if (strpos($line, 'src=' . $cur) === false) continue;
                $clientIpsToFlush[$cur] = true;
            } else {
                if (!preg_match('/src=(192\.168\.\d+\.\d+)/', $line, $srcM)) continue;
                $clientIpsToFlush[$srcM[1]] = true;
            }

            if (preg_match('/src=192\.168\.\d+\.\d+\s+dst=([0-9.]+)/', $line, $dstM)) {
                $dst = $dstM[1];
                if (!preg_match('/^(192\.168|100\.64|127\.0|10\.|172\.(1[6-9]|2[0-9]|3[0-1])|224\.|239\.|255\.)/', $dst)) {
                    $ips[$dst] = true;
                }
            }
        }

        $candidateIps = array_keys($ips);
        $candidateIps = array_slice($candidateIps, 0, 8);

        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';

        foreach ($candidateIps as $ip) {
            $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
            exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null && echo 1 || echo 0", $bOut);
            $isBlocked = ($bOut[0] ?? '0') === '1';

            $t0 = microtime(true);
            exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$ip' 2>&1 | head -n 1", $cOut);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $info = trim($cOut[0] ?? '');

            $isDead = (empty($info) || stripos($info, 'timed out') !== false || stripos($info, 'refused') !== false);

            $isDune = in_array($targetDevice, ['dune_kuh', 'dune_komnata']);
            if ($isDead && !$isBlocked && $isDune) {
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $newlyBlocked[] = $subnet;
                $isBlocked = true;

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $mm)) {
                        $cur = explode(' ', trim($mm[1]));
                        if (!in_array($subnet, $cur)) {
                            $cur[] = $subnet;
                            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                            file_put_contents($file, $newC);
                        }
                    }
                }
            }

            $results[] = [
                'ip' => $ip,
                'subnet' => $subnet,
                'latency_ms' => $elapsed,
                'dead' => $isDead,
                'blocked' => $isBlocked,
                'info' => $info ?: 'Нет ответа (таймаут)'
            ];
        }

        foreach (array_keys($clientIpsToFlush) as $cIp) {
            exec("conntrack -D -s " . escapeshellarg($cIp) . " 2>/dev/null");
        }
        if (isset($_SERVER["REMOTE_ADDR"])) { exec("conntrack -D -s " . escapeshellarg($_SERVER["REMOTE_ADDR"]) . " 2>/dev/null"); }

        $msg = '';
        if (count($newlyBlocked) > 0) {
            $msg = '🚨 Найден и заблокирован зависший сервер: ' . implode(', ', $newlyBlocked) . '! Сокеты сброшены, видео возобновлено.';
        } elseif (count($results) > 0) {
            $msg = '✅ Проверено ' . count($results) . ' активных узлов. Зависшие сокеты сброшены — видео должно ожить!';
        } else {
            $msg = '⚡ Активных сокетов не найдено. Выполнен полный сброс кэша соединений приставки!';
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
        if (isset($_SERVER['REMOTE_ADDR'])) {
            exec("conntrack -D -s " . escapeshellarg($_SERVER['REMOTE_ADDR']) . " 2>/dev/null");
        }

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
        $ip = preg_replace('/\/[0-9]+$/', '', $target);
        if (preg_match('/\.0$/', $ip)) {
            $testIp = preg_replace('/\.0$/', '.2', $ip);
        } else {
            $testIp = $ip;
        }

        $t0 = microtime(true);
        exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$testIp' 2>&1 | head -n 3", $cOut);
        $elapsed = round((microtime(true) - $t0) * 1000);
        $headerLine = trim($cOut[0] ?? '');
        $serverLine = '';
        foreach ($cOut as $cl) {
            if (stripos($cl, 'server:') !== false) $serverLine = trim($cl);
        }

        $isDead = (empty($headerLine) || stripos($headerLine, 'timed out') !== false || stripos($headerLine, 'refused') !== false);
        $suggestedSubnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);

        exec("iptables -C FORWARD -d $suggestedSubnet -p tcp --dport 443 -j REJECT 2>/dev/null && echo 1 || echo 0", $bOut);
        $isBlocked = ($bOut[0] ?? '0') === '1';

        echo json_encode([
            'success' => true,
            'ip' => $ip,
            'test_ip' => $testIp,
            'suggested_subnet' => $suggestedSubnet,
            'latency_ms' => $elapsed,
            'dead' => $isDead,
            'blocked' => $isBlocked,
            'header' => $headerLine ?: 'Нет ответа (таймаут соединения)',
            'server' => $serverLine
        ]);
        exit;
    }

    if ($action === 'scan_and_unfreeze') {
        $targetDevice = $_POST['target_device'] ?? 'all';
        $data = @file_get_contents('/proc/net/nf_conntrack');
        $lines = explode("\n", (string)$data);
        
        $ips = [];
        $clientIpsToFlush = [];

        foreach ($lines as $line) {
            if (strpos($line, 'dport=443') === false && strpos($line, 'dport=80') === false) continue;
            
            if ($targetDevice === 'dune_kuh') {
                if (strpos($line, 'src=192.168.1.100') === false) continue;
                $clientIpsToFlush['192.168.1.100'] = true;
            } elseif ($targetDevice === 'dune_komnata') {
                if (strpos($line, 'src=192.168.1.101') === false) continue;
                $clientIpsToFlush['192.168.1.101'] = true;
            } elseif ($targetDevice === 'current') {
                $cur = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                if (strpos($line, 'src=' . $cur) === false) continue;
                $clientIpsToFlush[$cur] = true;
            } else {
                if (!preg_match('/src=(192\.168\.\d+\.\d+)/', $line, $srcM)) continue;
                $clientIpsToFlush[$srcM[1]] = true;
            }

            if (preg_match('/src=192\.168\.\d+\.\d+\s+dst=([0-9.]+)/', $line, $dstM)) {
                $dst = $dstM[1];
                if (!preg_match('/^(192\.168|100\.64|127\.0|10\.|172\.(1[6-9]|2[0-9]|3[0-1])|224\.|239\.|255\.)/', $dst)) {
                    $ips[$dst] = true;
                }
            }
        }

        $candidateIps = array_keys($ips);
        $candidateIps = array_slice($candidateIps, 0, 8);

        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';

        foreach ($candidateIps as $ip) {
            $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
            exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT 2>/dev/null && echo 1 || echo 0", $bOut);
            $isBlocked = ($bOut[0] ?? '0') === '1';

            $t0 = microtime(true);
            exec("curl -s -k -I -m 1 --connect-timeout 1 'https://$ip' 2>&1 | head -n 1", $cOut);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $info = trim($cOut[0] ?? '');

            $isDead = (empty($info) || stripos($info, 'timed out') !== false || stripos($info, 'refused') !== false);

            if ($isDead && !$isBlocked) {
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $newlyBlocked[] = $subnet;
                $isBlocked = true;

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $mm)) {
                        $cur = explode(' ', trim($mm[1]));
                        if (!in_array($subnet, $cur)) {
                            $cur[] = $subnet;
                            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                            file_put_contents($file, $newC);
                        }
                    }
                }
            }

            $results[] = [
                'ip' => $ip,
                'subnet' => $subnet,
                'latency_ms' => $elapsed,
                'dead' => $isDead,
                'blocked' => $isBlocked,
                'info' => $info ?: 'Нет ответа (таймаут)'
            ];
        }

        foreach (array_keys($clientIpsToFlush) as $cIp) {
            exec("conntrack -D -s " . escapeshellarg($cIp) . " 2>/dev/null");
        }
        if (isset($_SERVER["REMOTE_ADDR"])) { exec("conntrack -D -s " . escapeshellarg($_SERVER["REMOTE_ADDR"]) . " 2>/dev/null"); }

        $msg = '';
        if (count($newlyBlocked) > 0) {
            $msg = '🚨 Найден и заблокирован зависший сервер: ' . implode(', ', $newlyBlocked) . '! Сокеты сброшены, видео возобновлено.';
        } elseif (count($results) > 0) {
            $msg = '✅ Проверено ' . count($results) . ' активных узлов. Зависшие сокеты сброшены — видео должно ожить!';
        } else {
            $msg = '⚡ Активных сокетов не найдено. Выполнен полный сброс кэша соединений приставки!';
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

    if ($action === 'block_ggc_subnet') {
        $subnet = trim($_POST['subnet'] ?? '');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/[0-9]+)?$/', $subnet)) {
            echo json_encode(['success' => false, 'error' => 'Неверный формат подсети']);
            exit;
        }
        if (strpos($subnet, '/') === false) $subnet .= '/32';

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
            }
        }

        exec("conntrack -D -d $subnet 2>/dev/null");
        exec("conntrack -D -s 192.168.1.100 2>/dev/null");
        exec("conntrack -D -s 192.168.1.101 2>/dev/null");

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

    if ($action === 'scan_and_unfreeze') {
        $targetDevice = $_POST['target_device'] ?? 'all';
        $data = @file_get_contents('/proc/net/nf_conntrack');
        
        $srcRegex = 'src=192\.168\.1\.(145|53)';
        if ($targetDevice === 'dune_komnata') {
            $srcRegex = 'src=192\.168\.1\.53';
        } elseif ($targetDevice === 'dune_kuh') {
            $srcRegex = 'src=192\.168\.1\.145';
        }

        preg_match_all('/' . $srcRegex . '.*?dst=([0-9.]+).*?dport=443/', $data, $m);
        $rawIps = array_unique($m[1] ?? []);
        $ips = [];
        foreach ($rawIps as $ip) {
            if (!preg_match('/^(192\.168|100\.64|127\.0|10\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $ip)) {
                $ips[] = $ip;
            }
        }

        // Limit to max 6 IPs for quick response < 2s
        $ips = array_slice($ips, 0, 6);
        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';

        foreach ($ips as $ip) {
            exec("iptables -C FORWARD -d $ip -p tcp --dport 443 -j REJECT 2>/dev/null && echo 1 || echo 0", $bOut);
            $isBlocked = ($bOut[0] ?? '0') === '1';

            $t0 = microtime(true);
            exec("curl -k -I --max-time 0.8 https://$ip 2>&1 | head -n 1", $cOut);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $info = $cOut[0] ?? '';

            $isDead = (strpos($info, 'timed out') !== false || strpos($info, 'refused') !== false || empty($info));

            if ($isDead && !$isBlocked) {
                $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $newlyBlocked[] = $subnet;
                $isBlocked = true;

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $mm)) {
                        $cur = explode(' ', trim($mm[1]));
                        if (!in_array($subnet, $cur)) {
                            $cur[] = $subnet;
                            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                            file_put_contents($file, $newC);
                        }
                    }
                }
            }

            $results[] = [
                'ip' => $ip,
                'latency_ms' => $elapsed,
                'dead' => $isDead,
                'blocked' => $isBlocked,
                'info' => $info ? substr($info, 0, 50) : 'Нет ответа (таймаут)'
            ];
        }

        exec("conntrack -D -s 192.168.1.100 2>/dev/null");
        exec("conntrack -D -s 192.168.1.101 2>/dev/null");

        echo json_encode([
            'success' => true,
            'scanned_count' => count($ips),
            'results' => $results,
            'newly_blocked' => $newlyBlocked,
            'message' => count($newlyBlocked) > 0 
                ? 'Обнаружен и заблокирован зависший узел: ' . implode(', ', $newlyBlocked) . '. Соединения сброшены!' 
                : (count($ips) > 0 
                    ? 'Проверено ' . count($ips) . ' узлов приставки. Зависшие сокеты сброшены, видео возобновлено!' 
                    : 'Сброс сокетов приставки выполнен!')
        ]);
        exit;
    }

    if ($action === 'block_ggc_subnet') {
        $subnet = trim($_POST['subnet'] ?? '');
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/[0-9]+)?$/', $subnet)) {
            echo json_encode(['success' => false, 'error' => 'Неверный формат подсети']);
            exit;
        }
        if (strpos($subnet, '/') === false) $subnet .= '/32';

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
            }
        }

        exec("conntrack -D -d $subnet 2>/dev/null");
        exec("conntrack -D -s 192.168.1.100 2>/dev/null");
        exec("conntrack -D -s 192.168.1.101 2>/dev/null");

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

    if ($action === 'scan_and_unfreeze') {
        $data = @file_get_contents('/proc/net/nf_conntrack');
        preg_match_all('/src=192\.168\.1\.\d+.*?dst=([0-9.]+).*?dport=443/', $data, $m);
        $rawIps = array_unique($m[1] ?? []);
        $ips = [];
        foreach ($rawIps as $ip) {
            if (!preg_match('/^(192\.168|100\.64|127\.0|10\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $ip)) {
                $ips[] = $ip;
            }
        }

        $results = [];
        $newlyBlocked = [];
        $file = '/opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh';

        foreach ($ips as $ip) {
            exec("iptables -C FORWARD -d $ip -p tcp --dport 443 -j REJECT 2>/dev/null && echo 1 || echo 0", $bOut);
            $isBlocked = ($bOut[0] ?? '0') === '1';

            $t0 = microtime(true);
            exec("curl -k -I --max-time 1.2 https://$ip 2>&1 | head -n 1", $cOut);
            $elapsed = round((microtime(true) - $t0) * 1000);
            $info = $cOut[0] ?? '';

            $isDead = (strpos($info, 'timed out') !== false || strpos($info, 'refused') !== false || empty($info));

            if ($isDead && !$isBlocked) {
                $subnet = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/', '$1.0/24', $ip);
                exec("iptables -C FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset 2>/dev/null || iptables -I FORWARD -d $subnet -p tcp --dport 443 -j REJECT --reject-with tcp-reset");
                $newlyBlocked[] = $subnet;
                $isBlocked = true;

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (preg_match('/DEAD_GGC="([^"]+)"/', $content, $mm)) {
                        $cur = explode(' ', trim($mm[1]));
                        if (!in_array($subnet, $cur)) {
                            $cur[] = $subnet;
                            $newC = preg_replace('/DEAD_GGC="[^"]+"/', 'DEAD_GGC="' . implode(' ', $cur) . '"', $content);
                            file_put_contents($file, $newC);
                        }
                    }
                }
            }

            $results[] = [
                'ip' => $ip,
                'latency_ms' => $elapsed,
                'dead' => $isDead,
                'blocked' => $isBlocked,
                'info' => $info ? substr($info, 0, 50) : 'Нет ответа (таймаут)'
            ];
        }

        exec("conntrack -D -s 192.168.1.100 2>/dev/null");
        exec("conntrack -D -s 192.168.1.101 2>/dev/null");

        echo json_encode([
            'success' => true,
            'scanned_count' => count($ips),
            'results' => $results,
            'newly_blocked' => $newlyBlocked,
            'message' => count($newlyBlocked) > 0 
                ? 'Обнаружен и заблокирован зависший узел: ' . implode(', ', $newlyBlocked) . '. Соединения сброшены!' 
                : (count($ips) > 0 
                    ? 'Проверено узлов: ' . count($ips) . '. Зависшие сессии сброшены, видео возобновлено!'
                    : 'Сброс сокетов приставки выполнен!')
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
            } elseif (preg_match('/hostfakesplit:host=www\.google\.com/i', $conf)) {
                $activeStrategy = 'hostfakesplit (Stage 2)';
            }
        }
        
        // Check QUIC block in netfilter
        exec("iptables -w -S FORWARD 2>/dev/null | grep 'dport 443' | grep -q 'REJECT' && echo 1 || echo 0", $qOut);
        $quicBlocked = ($qOut[0] ?? '0') === '1';

        echo json_encode([
            'success' => true,
            'running' => $isRunning,
            'status_text' => $statusStr,
            'active_strategy' => $activeStrategy,
            'quic_blocked' => $quicBlocked
        ]);
        exit;
    }

    if ($action === 'restart_service') {
        @unlink(CONF_RUN);
        exec(INIT_SCRIPT . " restart 2>&1", $out, $rc);
        exec(INIT_SCRIPT . " status 2>&1", $stOut);
        $statusStr = implode("\n", $stOut);
        $isRunning = strpos($statusStr, 'is running') !== false;

        echo json_encode([
            'success' => true,
            'running' => $isRunning,
            'status_text' => $statusStr,
            'output' => implode("\n", $out)
        ]);
        exit;
    }

    if ($action === 'sync_github') {
        function fetchRemote($url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);
            return $res;
        }

        $baseUrl = "https://cdn.jsdelivr.net/gh/rndnaame/nfqws-menu@main";
        $apiList = [
            'MartinBacker.conf', 'Uvvi2.conf', 'alt.conf', 'alt10.conf', 'alt11.conf', 'alt12.conf', 'alt13.conf',
            'alt2.conf', 'alt3.conf', 'alt4.conf', 'alt4_mod.conf', 'alt5.conf', 'alt6.conf', 'alt7.conf', 'alt8.conf',
            'alt9.conf', 'eduncey.conf', 'exp.conf', 'fake_tls_auto.conf', 'fake_tls_auto_alt.conf',
            'fake_tls_auto_alt2.conf', 'fake_tls_auto_alt3.conf', 'hardcorp74.conf', 'krushaaa.conf',
            'simple_fake.conf', 'simple_fake_alt.conf', 'simple_fake_alt2.conf'
        ];
        
        $updated = 0;
        foreach ($apiList as $cfg) {
            $url = "$baseUrl/strategies/nfqws2/$cfg";
            $data = fetchRemote($url);
            if ($data && strlen($data) > 100) {
                file_put_contents(STRATEGIES_DIR . '/' . $cfg, $data);
                $updated++;
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Успешно синхронизировано $updated стратегий с GitHub!",
            'count' => $updated
        ]);
        exit;
    }

    if ($action === 'apply_strategy') {
        $strategyFile = $_POST['strategy'] ?? '';
        $strategyFile = basename($strategyFile); // Security sanitation
        $srcPath = STRATEGIES_DIR . '/' . $strategyFile;

        if (!file_exists($srcPath)) {
            echo json_encode(['success' => false, 'error' => "Файл стратегии $strategyFile не найден в $srcPath"]);
            exit;
        }

        $newConf = file_get_contents($srcPath);
        if (empty($newConf)) {
            echo json_encode(['success' => false, 'error' => "Стратегия $strategyFile пуста"]);
            exit;
        }

        // Normalize line endings
        $newConf = str_replace(["\r\n", "\r"], "\n", $newConf);

        // Ensure Router specific settings:
        // 1. ISP_INTERFACE="ppp1"
        if (preg_match('/^ISP_INTERFACE=/m', $newConf)) {
            $newConf = preg_replace('/^ISP_INTERFACE=.*$/m', 'ISP_INTERFACE="ppp1"', $newConf);
        } else {
            $newConf = "ISP_INTERFACE=\"ppp1\"\n" . $newConf;
        }

        // 2. POLICY_NAME="nfqws", POLICY_EXCLUDE=0, LOG_LEVEL=0
        if (!preg_match('/^POLICY_NAME=/m', $newConf)) {
            $newConf .= "\nPOLICY_NAME=\"nfqws\"\nPOLICY_EXCLUDE=0\n";
        }
        if (preg_match('/^LOG_LEVEL=/m', $newConf)) {
            $newConf = preg_replace('/^LOG_LEVEL=.*$/m', 'LOG_LEVEL=0', $newConf);
        } else {
            $newConf .= "\nLOG_LEVEL=0\n";
        }

        // 3. Keep dropbear auto-check if missing
        if (!str_contains($newConf, 'dropbear.*222')) {
            $newConf .= "\n[ -z \"$(pgrep -f 'dropbear.*222')\" ] && (/opt/etc/init.d/S51dropbear start 2>/dev/null || /opt/sbin/dropbear -p 222 -B 2>/dev/null || dropbear -p 222 -B 2>/dev/null) &\n";
        }

        // Backup existing conf
        if (file_exists(CONF_FILE)) {
            copy(CONF_FILE, CONF_BAK);
        }

        // Write new conf
        if (file_put_contents(CONF_FILE, $newConf) === false) {
            echo json_encode(['success' => false, 'error' => "Не удалось записать " . CONF_FILE]);
            exit;
        }

        // Remove old running conf and restart
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

        // 1. YouTube TLS 1.3
        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --connect-timeout 4 --max-time 5 https://www.youtube.com 2>&1", $out1);
        $res1 = explode(':', $out1[0] ?? '000:0');
        $results['youtube_tls13'] = [
            'name' => 'YouTube Web / ПК (TLS 1.3)',
            'code' => $res1[0] ?? '000',
            'time' => round((float)($res1[1] ?? 0), 2),
            'ok' => in_array($res1[0] ?? '', ['200', '301', '302'])
        ];

        // 2. YouTube TLS 1.2 (Smart TV / Dune HD)
        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --tlsv1.2 --tls-max 1.2 --connect-timeout 4 --max-time 5 https://www.youtube.com 2>&1", $out2);
        $res2 = explode(':', $out2[0] ?? '000:0');
        $results['youtube_tls12'] = [
            'name' => 'Smart TV / Dune HD (TLS 1.2)',
            'code' => $res2[0] ?? '000',
            'time' => round((float)($res2[1] ?? 0), 2),
            'ok' => in_array($res2[0] ?? '', ['200', '301', '302'])
        ];

        // 3. YouTube Images / Previews
        exec("curl -4 -s -w '%{http_code}:%{time_total}' -o /dev/null --connect-timeout 4 --max-time 5 https://i.ytimg.com/generate_204 2>&1", $out3);
        $res3 = explode(':', $out3[0] ?? '000:0');
        $results['ytimg'] = [
            'name' => 'Превью и статика (i.ytimg.com)',
            'code' => $res3[0] ?? '000',
            'time' => round((float)($res3[1] ?? 0), 2),
            'ok' => in_array($res3[0] ?? '', ['200', '204'])
        ];

        // 4. Discord
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

// Strategy release/commit dates mapping
$strategyDates = [
    'zemidala_rostelecom.conf' => '2026-10-15',
    'zemidala_sniext.conf' => '2026-10-12',
    'dune_yarnet.conf' => '2026-10-18',
    'hardcorp74.conf' => '2026-10-12',
    'alt13.conf' => '2026-10-04',
    'alt11.conf' => '2026-10-04',
    'alt4.conf' => '2026-10-04',
    'alt12.conf' => '2026-10-04',
    'alt10.conf' => '2026-10-04',
    'alt.conf' => '2026-10-04',
    'alt2.conf' => '2026-10-04',
    'alt3.conf' => '2026-10-04',
    'alt4_mod.conf' => '2026-10-04',
    'alt5.conf' => '2026-10-04',
    'alt6.conf' => '2026-10-04',
    'alt7.conf' => '2026-10-04',
    'alt8.conf' => '2026-10-04',
    'alt9.conf' => '2026-10-04',
    'eduncey.conf' => '2026-10-04',
    'exp.conf' => '2026-10-04',
    'fake_tls_auto.conf' => '2026-10-04',
    'fake_tls_auto_alt.conf' => '2026-10-04',
    'fake_tls_auto_alt2.conf' => '2026-10-04',
    'fake_tls_auto_alt3.conf' => '2026-10-04',
    'krushaaa.conf' => '2026-10-04',
    'simple_fake.conf' => '2026-10-04',
    'simple_fake_alt.conf' => '2026-10-04',
    'simple_fake_alt2.conf' => '2026-10-04',
    'Uvvi2.conf' => '2026-10-04',
    'MartinBacker.conf' => '2026-09-19'
];

if (file_exists(DATES_FILE)) {
    $loadedDates = json_decode(file_get_contents(DATES_FILE), true);
    if (is_array($loadedDates)) {
        $strategyDates = array_merge($strategyDates, $loadedDates);
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
    'dune_yarnet.conf' => [
        'name' => 'DUNE HD & YARNET (Калуга)',
        'badge' => '⭐ Оптимизировано под ТВ и YARNET',
        'badge_cls' => 'badge-fire',
        'prio' => 0,
        'tv' => true,
        'desc' => 'Специальная конфигурация под приставки Smart TV / Dune HD и провайдера YARNET (Калуга): hostfakesplit под YouTube (100% совместимо с TLS 1.2 Dune HD без ломающих TLS 1.3 шаблонов) + база ALT11 для Discord/сайтов + полный охват портов 1024-65534.',
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
        'desc' => 'Fake default TLS (sni=google.com) + Multidisorder (1,midsld). Быстрое и стабильное открытие веб-интерфейсов и видео.',
        'category' => 'recommended'
    ],
    'alt12.conf' => [
        'name' => 'ALT12',
        'badge' => 'ALT11 + Google Split',
        'badge_cls' => 'badge-info',
        'prio' => 6,
        'tv' => true,
        'desc' => 'База ALT11 с выделенным hostfakesplit профилем под домены и видеосерверы Google.',
        'category' => 'recommended'
    ],
    'Uvvi2.conf' => [
        'name' => 'UVVI2 (Discord Voice)',
        'badge' => 'Discord Voice Focus',
        'badge_cls' => 'badge-info',
        'prio' => 7,
        'tv' => false,
        'desc' => 'Стоковый nfqws2-keenetic + прицельный войс-профиль Discord (UDP blobs + переписанный NFQWS_ARGS_UDP).',
        'category' => 'other'
    ],
    'alt.conf' => [
        'name' => 'ALT (Классический)',
        'badge' => 'Базовая проверенная',
        'badge_cls' => 'badge-secondary',
        'prio' => 10,
        'tv' => true,
        'desc' => 'Fake Google TLS + Fakedsplit (0x00 pattern, ts fooling). Проверенная временем базовая стратегия.',
        'category' => 'classic'
    ],
    'alt2.conf' => [
        'name' => 'ALT2',
        'badge' => 'Multisplit seqovl=652',
        'badge_cls' => 'badge-secondary',
        'prio' => 11,
        'tv' => false,
        'desc' => 'Multisplit со сдвигом seqovl=652 на pos=2 без поддельных пакетов.',
        'category' => 'classic'
    ],
    'alt3.conf' => [
        'name' => 'ALT3',
        'badge' => 'Multisplit seqovl=681',
        'badge_cls' => 'badge-secondary',
        'prio' => 12,
        'tv' => false,
        'desc' => 'Fake TLS 4pda + Multisplit со сдвигом seqovl=681.',
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

// Scan strategies directory
$allFiles = glob(STRATEGIES_DIR . '/*.conf');
$strategies = [];
foreach ($allFiles as $f) {
    $base = basename($f);
    $stratDate = $strategyDates[$base] ?? '2026-10-04';
    $isFresh = ($stratDate >= '2026-10-10');

    if (isset($knownStrategies[$base])) {
        $meta = $knownStrategies[$base];
        $meta['file'] = $base;
        $meta['date'] = $stratDate;
        $meta['date_formatted'] = formatRuDate($stratDate);
        $meta['fresh'] = $isFresh;
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
            'fresh' => $isFresh
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NFQWS2 Strategy Manager & Diagnostics</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #38bdf8;
            --accent-hover: #0ea5e9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --purple: #a855f7;
            --fire: #f97316;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: var(--bg-color);
            background-image: radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.12) 0px, transparent 50%),
                              radial-gradient(at 100% 100%, rgba(168, 85, 247, 0.10) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-primary);
            min-height: 100vh;
            padding: 24px 16px;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
        }

        /* Top Bar */
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
            font-weight: 700;
            letter-spacing: -0.5px;
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

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .btn-outline {
            background: transparent;
            border-color: var(--card-border);
            color: var(--text-secondary);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
            border-color: var(--text-secondary);
        }

        .btn-primary {
            background: var(--accent);
            color: #0f172a;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-success {
            background: var(--success);
            color: #0f172a;
        }

        /* Status Grid */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .status-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 16px;
            position: relative;
            overflow: hidden;
        }

        .status-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--purple));
            opacity: 0.6;
        }

        .status-title {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .status-value {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-green { background: var(--success); box-shadow: 0 0 10px var(--success); }
        .dot-red { background: var(--danger); box-shadow: 0 0 10px var(--danger); }

        /* Diagnostics Box */
        /* Freeze Killer & GGC Detective Styles */
        .freeze-box {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.85), rgba(15, 23, 42, 0.95));
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(245, 158, 11, 0.08);
            position: relative;
            overflow: hidden;
        }

        .freeze-box::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, #f59e0b, #ef4444, #38bdf8);
        }

        .freeze-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .freeze-title-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            max-width: 680px;
        }

        .freeze-icon {
            font-size: 26px;
            line-height: 1;
        }

        .freeze-title {
            font-size: 17px;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .freeze-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
            line-height: 1.45;
        }

        .freeze-actions-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .device-select {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            cursor: pointer;
        }

        .device-select:focus {
            border-color: #f59e0b;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0f172a;
            font-weight: 700;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13.5px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
        }

        .blocked-subnets-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-top: 14px;
            margin-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .blocked-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .blocked-chips {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .blocked-chip {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            font-family: monospace;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .blocked-chip-del {
            color: #ef4444;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            line-height: 1;
        }

        .blocked-chip-del:hover {
            color: #f87171;
        }

        .manual-ggc-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
        }

        .subnet-input {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 6px;
            color: #f8fafc;
            padding: 6px 12px;
            font-size: 12.5px;
            font-family: monospace;
            width: 220px;
            outline: none;
        }

        .subnet-input:focus {
            border-color: #f59e0b;
        }

        .btn-action-sm {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .btn-test {
            background: rgba(56, 189, 248, 0.15);
            border-color: rgba(56, 189, 248, 0.4);
            color: #38bdf8;
        }
        .btn-test:hover {
            background: rgba(56, 189, 248, 0.25);
        }

        .btn-block {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #f87171;
        }
        .btn-block:hover {
            background: rgba(239, 68, 68, 0.25);
        }

        .scan-results-box {
            margin-top: 14px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 12px 14px;
        }

        .scan-results-title {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .scan-results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 8px;
        }

        .scan-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 6px;
            padding: 7px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            font-family: monospace;
        }

        .scan-item.dead {
            border-color: rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.08);
        }

        .scan-item.ok {
            border-color: rgba(52, 211, 153, 0.3);
        }

        .diag-box {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 28px;
        }

        .diag-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }

        .diag-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .diag-name {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .diag-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .badge-ok { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-fail { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .badge-pending { background: rgba(148, 163, 184, 0.1); color: var(--text-secondary); }

        /* Toolbar (Search, Filter, Sort) */
        .toolbar-box {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .toolbar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 260px;
        }

        .search-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 9px 14px 9px 36px;
            color: var(--text-primary);
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .search-input:focus {
            border-color: var(--accent);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 14px;
            pointer-events: none;
        }

        .sort-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sort-label {
            font-size: 13px;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .sort-select {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 13px;
            padding: 8px 12px;
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s ease;
        }

        .sort-select:focus {
            border-color: var(--accent);
        }

        .filter-chips {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-chip {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--card-border);
            color: var(--text-secondary);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .filter-chip:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text-primary);
        }

        .filter-chip.active {
            background: rgba(56, 189, 248, 0.15);
            border-color: var(--accent);
            color: var(--accent);
            font-weight: 600;
        }

        .stats-text {
            font-size: 12.5px;
            color: var(--text-secondary);
            margin-left: auto;
        }


        /* Strategy Cards Grid */
        .strategies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .strat-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            position: relative;
        }

        .strat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(56, 189, 248, 0.4);
        }

        .strat-card.active-strat {
            border-color: var(--accent);
            background: rgba(30, 41, 59, 0.95);
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.15);
        }

        .strat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .strat-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .strat-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .badges-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-date {
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.2);
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
        }

        .badge-fire {
            background: rgba(249, 115, 22, 0.2);
            color: #fb923c;
            border: 1px solid rgba(249, 115, 22, 0.35);
        }

        .badge-dune {
            background: rgba(6, 182, 212, 0.15);
            color: #22d3ee;
            border: 1px solid rgba(6, 182, 212, 0.3);
            text-transform: none;
            letter-spacing: 0;
            font-weight: 600;
        }

        .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-info { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .badge-purple { background: rgba(168, 85, 247, 0.2); color: #c084fc; }
        .badge-secondary { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }
        .badge-dark { background: rgba(255, 255, 255, 0.05); color: var(--text-secondary); }

        .strat-desc {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 18px;
            flex-grow: 1;
        }

        .strat-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            gap: 10px;
        }

        .strat-file {
            font-size: 11.5px;
            color: var(--text-secondary);
            font-family: monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .active-tag {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
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

        /* Toast / Notification */
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
            z-index: 1000;
            font-size: 14px;
            max-width: 400px;
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
                <h1 class="logo-title">NFQWS2 Strategy Manager</h1>
                <div class="logo-subtitle">Интерактивное веб-управление стратегиями обхода ТСПУ</div>
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

    <!-- Toolbar: Search, Filter Chips, Sort -->
    <div class="toolbar-box">
        <div class="toolbar-top">
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="stratSearch" class="search-input" placeholder="Поиск по названию или описанию (ALT4, 12.10, Dune...)" oninput="filterCards()">
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
                Все стратегии (<?= count($strategies) ?>)
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
            <span class="stats-text" id="statsCount">Показано: <?= count($strategies) ?></span>
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
                            (stripos($activeStrategyName, 'DUNE_YARNET') !== false && $file === 'dune_yarnet.conf');
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
                 data-desc="<?= htmlspecialchars($meta['desc']) ?>">
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
                    <?php if ($isActive): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div class="active-tag">✓ Активна</div>
                            <button class="btn btn-outline" style="font-size: 11.5px; padding: 5px 10px;" onclick="applyStrategy('<?= $file ?>', '<?= htmlspecialchars($meta['name']) ?>')">
                                Переприменить
                            </button>
                        </div>
                    <?php else: ?>
                        <button class="btn btn-primary" style="font-size: 12px; padding: 6px 14px;" onclick="applyStrategy('<?= $file ?>', '<?= htmlspecialchars($meta['name']) ?>')">
                            Применить
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="noResults">
        По вашему запросу стратегий не найдено. Попробуйте изменить параметры поиска или фильтра.
    </div>
</div>

<div id="toast"></div>

<script>
    let currentFilter = 'all';

    function showToast(msg, isError = false) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.style.display = 'block';
        toast.style.borderLeftColor = isError ? 'var(--danger)' : 'var(--accent)';
        setTimeout(() => { toast.style.display = 'none'; }, 4000);
    }

    function sortCards(type) {
        const grid = document.getElementById('strategiesGrid');
        const cards = Array.from(grid.querySelectorAll('.strat-card'));

        cards.sort((a, b) => {
            const dateA = a.dataset.date || '1970-01-01';
            const dateB = b.dataset.date || '1970-01-01';
            const nameA = a.dataset.name.toLowerCase();
            const nameB = b.dataset.name.toLowerCase();
            const prioA = parseInt(a.dataset.prio || '99', 10);
            const prioB = parseInt(b.dataset.prio || '99', 10);

            if (type === 'fresh') {
                if (dateA !== dateB) return dateB.localeCompare(dateA);
                if (prioA !== prioB) return prioA - prioB;
                return nameA.localeCompare(nameB);
            } else if (type === 'recommended') {
                if (prioA !== prioB) return prioA - prioB;
                if (dateA !== dateB) return dateB.localeCompare(dateA);
                return nameA.localeCompare(nameB);
            } else if (type === 'name_asc') {
                return nameA.localeCompare(nameB);
            } else if (type === 'date_asc') {
                if (dateA !== dateB) return dateA.localeCompare(dateB);
                return nameA.localeCompare(nameB);
            }
            return 0;
        });

        cards.forEach(c => grid.appendChild(c));
    }

    function setFilter(filter, el) {
        currentFilter = filter;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        el.classList.add('active');
        filterCards();
    }

    function filterCards() {
        const q = (document.getElementById('stratSearch').value || '').trim().toLowerCase();
        const cards = document.querySelectorAll('.strat-card');

        let visibleCount = 0;
        cards.forEach(card => {
            const name = (card.dataset.name || '').toLowerCase();
            const desc = (card.dataset.desc || '').toLowerCase();
            const file = (card.dataset.file || '').toLowerCase();
            const date = (card.dataset.date || '').toLowerCase();
            const category = card.dataset.category || '';
            const isFresh = card.dataset.fresh === '1';
            const isTv = card.dataset.tv === '1';

            let matchesSearch = !q || name.includes(q) || desc.includes(q) || file.includes(q) || date.includes(q);
            let matchesFilter = true;

            if (currentFilter === 'fresh') {
                matchesFilter = isFresh;
            } else if (currentFilter === 'recommended') {
                matchesFilter = (category === 'recommended');
            } else if (currentFilter === 'tv') {
                matchesFilter = isTv;
            }

            if (matchesSearch && matchesFilter) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        document.getElementById('statsCount').textContent = `Показано: ${visibleCount}`;
        const noResults = document.getElementById('noResults');
        if (noResults) {
            noResults.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    function applyStrategy(file, name) {
        if (!confirm(`Применить стратегию "${name}" (${file}) на роутере? Служба NFQWS2 будет автоматически перезапущена.`)) {
            return;
        }

        showToast(`Применяем стратегию ${name}... Пожалуйста, подождите.`);
        const fd = new FormData();
        fd.append('action', 'apply_strategy');
        fd.append('strategy', file);

        fetch('/menu.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message);
                    document.getElementById('activeStratText').textContent = name;
                    setTimeout(runDiagnostics, 1500);
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), true);
                }
            })
            .catch(err => {
                showToast('Сбой запроса: ' + err, true);
            });
    }

    function restartService() {
        const btn = document.getElementById('btnRestart');
        btn.textContent = '⏳ Перезапуск...';
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'restart_service');

        fetch('/menu.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.textContent = '🔄 Перезапустить службу';
                btn.disabled = false;
                if (data.success) {
                    showToast('Служба NFQWS2 успешно перезапущена');
                } else {
                    showToast('Ошибка при перезапуске', true);
                }
            })
            .catch(err => {
                btn.textContent = '🔄 Перезапустить службу';
                btn.disabled = false;
                showToast('Ошибка сети: ' + err, true);
            });
    }

    function runDiagnostics() {
        const btn = document.getElementById('btnDiag');
        btn.textContent = '⏳ Тестирование...';
        btn.disabled = true;

        ['youtube_tls13', 'youtube_tls12', 'ytimg', 'discord'].forEach(k => {
            const el = document.getElementById('test-' + k);
            if (el) {
                el.className = 'diag-badge badge-pending';
                el.textContent = 'Тест...';
            }
        });

        const fd = new FormData();
        fd.append('action', 'run_diagnostics');

        fetch('/menu.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.textContent = '⚡ Проверить YouTube';
                btn.disabled = false;

                if (data.success && data.tests) {
                    for (const [key, t] of Object.entries(data.tests)) {
                        const el = document.getElementById('test-' + key);
                        if (el) {
                            if (t.ok) {
                                el.className = 'diag-badge badge-ok';
                                el.textContent = `OK (${t.time}s)`;
                            } else {
                                el.className = 'diag-badge badge-fail';
                                el.textContent = `FAIL (HTTP ${t.code})`;
                            }
                        }
                    }
                    showToast('Диагностика завершена');
                }
            })
            .catch(err => {
                btn.textContent = '⚡ Проверить YouTube';
                btn.disabled = false;
                showToast('Ошибка диагностики: ' + err, true);
            });
    }


    async function loadBlockedSubnets() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_blocked_ggc');
            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            const listEl = document.getElementById('blockedSubnetsList');
            if (!listEl) return;

            if (data.success && data.subnets && data.subnets.length > 0) {
                listEl.innerHTML = data.subnets.map(s => `
                    <span class="blocked-chip">
                        <span>🛡️ ${s}</span>
                        <span class="blocked-chip-del" onclick="removeSubnet('${s}')" title="Разблокировать подсеть">&times;</span>
                    </span>
                `).join('');
            } else {
                listEl.innerHTML = '<span style="color: var(--text-secondary); font-size: 12px;">Нет заблокированных подсетей</span>';
            }
        } catch (e) {
            console.error('Error loading blocked subnets:', e);
        }
    }

    async function testManualTarget() {
        const inp = document.getElementById('manualSubnetInput');
        const target = inp ? inp.value.trim() : '';
        if (!target) {
            showToast('Введите IP адрес или подсеть для проверки', true);
            return;
        }

        showToast('Проверка доступности ' + target + ' с роутера...');
        try {
            const fd = new FormData();
            fd.append('action', 'test_single_ip');
            fd.append('target', target);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            const box = document.getElementById('scanResultsBox');
            const grid = document.getElementById('scanResultsGrid');
            const title = document.getElementById('scanResultsTitle');

            if (data.success) {
                if (box && grid) {
                    box.style.display = 'block';
                    title.textContent = `Результат проверки узла ${data.ip}:`;
                    const statusText = data.dead ? '🚨 МЕРТВ / ТАЙМАУТ' : '🟢 ЖИВОЙ (' + data.latency_ms + ' ms)';
                    grid.innerHTML = `
                        <div class="scan-item ${data.dead ? 'dead' : 'ok'}" style="grid-column: 1 / -1;">
                            <div>
                                <strong>${data.ip}</strong> &mdash; ${statusText}<br>
                                <small style="color: var(--text-secondary);">${data.header} ${data.server ? '| ' + data.server : ''}</small>
                            </div>
                            <div>
                                ${data.blocked ? '<span class="badge badge-red">Уже заблокирован</span>' : `
                                    <button class="btn-action-sm btn-block" onclick="addManualSubnet('${data.suggested_subnet}')">
                                        🚫 Заблокировать ${data.suggested_subnet}
                                    </button>
                                `}
                            </div>
                        </div>
                    `;
                }
                showToast(data.dead ? 'Узел ' + data.ip + ' не отвечает!' : 'Узел ' + data.ip + ' ответил за ' + data.latency_ms + ' мс');
            } else {
                showToast('Ошибка проверки: ' + (data.error || ''), true);
            }
        } catch (e) {
            showToast('Ошибка связи с роутером: ' + e, true);
        }
    }

    async function addManualSubnet(customSubnet) {
        const inp = document.getElementById('manualSubnetInput');
        const subnet = customSubnet || (inp ? inp.value.trim() : '');
        if (!subnet) return;

        showToast('Добавление блокировки ' + subnet + '...');
        try {
            const fd = new FormData();
            fd.append('action', 'block_ggc_subnet');
            fd.append('subnet', subnet);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                if (inp) inp.value = '';
                showToast('Подсеть ' + data.subnet + ' заблокирована через TCP-Reset!');
                await loadBlockedSubnets();
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось заблокировать'), true);
            }
        } catch (e) {
            showToast('Ошибка сети: ' + e, true);
        }
    }

    async function removeSubnet(subnet) {
        if (!confirm('Разблокировать подсеть ' + subnet + '?')) return;
        showToast('Удаление блокировки...');
        try {
            const fd = new FormData();
            fd.append('action', 'unblock_ggc_subnet');
            fd.append('subnet', subnet);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showToast('Подсеть ' + subnet + ' разблокирована');
                await loadBlockedSubnets();
            }
        } catch (e) {
            showToast('Ошибка сети: ' + e, true);
        }
    }

    async function scanAndUnfreeze() {
        const btn = document.getElementById('btnUnfreeze');
        const devSel = document.getElementById('targetDeviceSelect');
        const targetDev = devSel ? devSel.value : 'all';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏳ Проверка соединений...';
        }

        const box = document.getElementById('scanResultsBox');
        const grid = document.getElementById('scanResultsGrid');
        const title = document.getElementById('scanResultsTitle');

        showToast('Сканирование активных соединений с роутера...');
        try {
            const fd = new FormData();
            fd.append('action', 'scan_and_unfreeze');
            fd.append('target_device', targetDev);

            const res = await fetch('/menu.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                showToast(data.message, false);
                await loadBlockedSubnets();

                if (box && grid && data.results && data.results.length > 0) {
                    box.style.display = 'block';
                    title.textContent = `Проверено узлов: ${data.results.length}. Активные подключения:`;
                    grid.innerHTML = data.results.map(r => `
                        <div class="scan-item ${r.dead ? 'dead' : 'ok'}">
                            <div>
                                <span>${r.dead ? '🚨' : '🟢'} ${r.ip}</span>
                                <small style="color: var(--text-secondary); margin-left: 6px;">${r.info}</small>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: ${r.dead ? '#f87171' : '#34d399'}; font-size: 11px;">
                                    ${r.dead ? 'ТАЙМАУТ' : r.latency_ms + ' ms'}
                                </span>
                                ${r.blocked ? '<span class="badge badge-red" style="font-size: 10px;">Заблокирован</span>' : `
                                    <button class="btn-action-sm btn-block" style="padding: 2px 7px; font-size: 10.5px;" onclick="addManualSubnet('${r.subnet}')" title="Заблокировать подсеть ${r.subnet}">
                                        + Блок
                                    </button>
                                `}
                            </div>
                        </div>
                    `).join('');
                } else if (box) {
                    box.style.display = 'none';
                }
            } else {
                showToast('Ошибка проверки: ' + (data.error || ''), true);
            }
        } catch (e) {
            showToast('Ошибка связи с роутером: ' + e, true);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '⚡ Проверить и разблокировать видео';
            }
        }
    }

    function syncGithub() {
        const btn = document.getElementById('btnSync');
        btn.textContent = '⏳ Загрузка...';
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'sync_github');

        fetch('/menu.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.textContent = '📥 Обновить с GitHub';
                btn.disabled = false;
                if (data.success) {
                    showToast(data.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Ошибка при синхронизации: ' + (data.error || ''), true);
                }
            })
            .catch(err => {
                btn.textContent = '📥 Обновить с GitHub';
                btn.disabled = false;
                showToast('Ошибка сети: ' + err, true);
            });
    }

    // Initialize with fresh strategies first
    document.addEventListener('DOMContentLoaded', () => {
        sortCards('fresh');
            });
</script>

</body>
</html>
