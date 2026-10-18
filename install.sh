#!/bin/sh
# =============================================================================
#  Установщик Keenetic NFQWS2 Web Strategy Manager
#  Установка одной командой на роутере Keenetic (Entware):
#    sh -c "$(curl -fsSL https://raw.githubusercontent.com/SaidAuita/keenetic-nfqws2-manager/main/install.sh)"
# =============================================================================

set -e

echo "=== Установка Keenetic NFQWS2 Web Strategy Manager ==="

# Проверка Entware
if [ ! -d "/opt/share/www/nfqws" ]; then
    echo "[!] Ошибка: Не найден каталог /opt/share/www/nfqws!"
    echo "    Убедитесь, что на роутере установлен nfqws-keenetic и веб-интерфейс."
    exit 1
fi

DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_BASE="https://raw.githubusercontent.com/SaidAuita/keenetic-nfqws2-manager/main"

# 1. Установка menu.php
echo "[+] Установка menu.php в /opt/share/www/nfqws/..."
if [ -f "$DIR/menu.php" ]; then
    cp "$DIR/menu.php" /opt/share/www/nfqws/menu.php
else
    wget -qO /opt/share/www/nfqws/menu.php "$REPO_BASE/menu.php"
fi
chmod 644 /opt/share/www/nfqws/menu.php

# 2. Установка базы дат стратегий
echo "[+] Установка базы дат strategy_dates.json..."
mkdir -p /opt/etc/nfqws-menu
if [ -f "$DIR/strategy_dates.json" ]; then
    cp "$DIR/strategy_dates.json" /opt/etc/nfqws-menu/strategy_dates.json
else
    wget -qO /opt/etc/nfqws-menu/strategy_dates.json "$REPO_BASE/strategy_dates.json"
fi
chmod 644 /opt/etc/nfqws-menu/strategy_dates.json

# 3. Установка кастомной стратегии для Smart TV / Dune HD
echo "[+] Установка стратегии dune_yarnet.conf..."
mkdir -p /opt/etc/nfqws-menu/strategies/nfqws2
if [ -f "$DIR/nfqws-menu/strategies/nfqws2/dune_yarnet.conf" ]; then
    cp "$DIR/nfqws-menu/strategies/nfqws2/dune_yarnet.conf" /opt/etc/nfqws-menu/strategies/nfqws2/
elif [ -f "$DIR/strategies/dune_yarnet.conf" ]; then
    cp "$DIR/strategies/dune_yarnet.conf" /opt/etc/nfqws-menu/strategies/nfqws2/
fi

# 4. Установка скрипта сброса мертвых серверов GGC
echo "[+] Установка фикса мертвых GGC серверов (051-block-dead-ggc.sh)..."
mkdir -p /opt/etc/ndm/netfilter.d
if [ -f "$DIR/051-block-dead-ggc.sh" ]; then
    cp "$DIR/051-block-dead-ggc.sh" /opt/etc/ndm/netfilter.d/
    chmod +x /opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh
    type=ip table=filter /opt/etc/ndm/netfilter.d/051-block-dead-ggc.sh 2>/dev/null || true
fi

# 5. Встраивание кнопки в стандартный веб-интерфейс nfqws-web
INDEX_HTML="/opt/share/www/nfqws/index.html"
if [ -f "$INDEX_HTML" ] && ! grep -q "btn-strategy-manager" "$INDEX_HTML"; then
    echo "[+] Добавление кнопки 'Менеджер стратегий' в nfqws-web..."
    BUTTON_CODE='<style>#btn-strategy-manager{position:fixed;bottom:10px;left:50%;transform:translateX(-50%);z-index:999;display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,0.06);font-family:inherit;font-size:12px;font-weight:600;color:#0f172a;text-decoration:none;transition:all .15s ease}#btn-strategy-manager:hover{background:#f1f5f9;border-color:#94a3b8;color:#0284c7;box-shadow:0 2px 6px rgba(0,0,0,0.1)}@media (prefers-color-scheme:dark){#btn-strategy-manager{background:#1e293b;border-color:#334155;color:#cbd5e1}#btn-strategy-manager:hover{background:#334155;border-color:#475569;color:#f8fafc}}</style><a href="/menu.php" id="btn-strategy-manager" title="Открыть менеджер стратегий"><span style="color:#0284c7;font-size:11px;">⚡</span><span>Менеджер стратегий</span></a>'
    sed -i "s|</body>|${BUTTON_CODE}</body>|" "$INDEX_HTML"
fi

echo ""
echo "======================================================"
echo "  Установка успешно завершена!"
echo "  Откройте в браузере: http://192.168.1.1:90/menu.php"
echo "======================================================"
