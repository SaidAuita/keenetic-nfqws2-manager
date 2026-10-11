"""
Автоматизированная безопасная диагностика NFQWS2 и YouTube.
Не выводит пароли и секретные данные.
"""
import sys
import datetime
import paramiko
from router_env import ROUTER_IP, ROUTER_PORT, ROUTER_USER, ROUTER_PASSWORD

def run_diagnosis():
    sys.stdout.reconfigure(encoding='utf-8')
    now_str = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"=== ДИАГНОСТИКА NFQWS2 & YOUTUBE [{now_str}] ===")
    
    # 1. SSH Connection
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        client.connect(ROUTER_IP, port=ROUTER_PORT, username=ROUTER_USER, password=ROUTER_PASSWORD, timeout=5)
        print(f"[✓] SSH подключение (порт {ROUTER_PORT}): УСПЕШНО")
    except Exception as e:
        print(f"[✗] SSH подключение (порт {ROUTER_PORT}): ОШИБКА ({e})")
        return

    def cmd(c):
        stdin, stdout, stderr = client.exec_command(c)
        return stdout.read().decode('utf-8', errors='replace').strip()

    # 2. Service Status
    svc_status = cmd('/opt/etc/init.d/S51nfqws2 status')
    is_running = 'is running' in svc_status
    print(f"[{'✓' if is_running else '✗'}] Служба NFQWS2: {svc_status}")

    # 3. Opkg Package Version
    pkg_ver = cmd("opkg status nfqws2-keenetic | awk -F': ' '/^Version:/ {print $2}'")
    print(f"[i] Версия nfqws2-keenetic: {pkg_ver if pkg_ver else 'Не определена'}")

    # 4. Lock Status
    lock_status = cmd("ls -la /opt/tmp/opkg.lock /opt/var/lock/opkg.lock 2>/dev/null")
    if lock_status:
        print(f"[!] ВНИМАНИЕ: Обнаружен файл блокировки opkg: {lock_status}")
    else:
        print("[✓] Файлы блокировки opkg.lock: ОТСУТСТВУЮТ (норма)")

    # 5. Wgetrc Status
    wgetrc = cmd("cat /opt/etc/wgetrc 2>/dev/null")
    if wgetrc:
        print("[✓] Конфиг таймаутов /opt/etc/wgetrc: АКТИВЕН")
    else:
        print("[!] /opt/etc/wgetrc: Не найден (рекомендуется настроить таймауты)")

    # 6. YouTube connectivity check from router
    print("\n--- Проверка доступности доменов с роутера (через wget) ---")
    yt_test = cmd("wget -q --spider --timeout=5 https://www.youtube.com && echo 'OK' || echo 'FAIL'")
    print(f"[{'✓' if 'OK' in yt_test else '✗'}] Доступ к https://www.youtube.com: {yt_test}")

    gv_test = cmd("wget -q --spider --no-check-certificate --timeout=5 https://googlevideo.com && echo 'OK' || echo 'FAIL'")
    print(f"[{'✓' if 'OK' in gv_test else '✗'}] Доступ к https://googlevideo.com: {gv_test}")

    # i.ytimg.com returns 404 on root, but connection succeeds
    ytimg_test = cmd("wget -S --spider --timeout=5 https://i.ytimg.com/generate_204 2>&1 | grep -q 'HTTP/' && echo 'OK' || echo 'FAIL'")
    print(f"[{'✓' if 'OK' in ytimg_test else '✗'}] Доступ к https://i.ytimg.com (CDN): {ytimg_test}")

    # 7. Check debug log size
    log_size = cmd("ls -lh /opt/var/log/nfqws2-debug.log 2>/dev/null | awk '{print $5}'")
    if log_size:
        print(f"[i] Размер файла отладки nfqws2-debug.log: {log_size}")

    client.close()
    print("==================================================")

if __name__ == '__main__':
    run_diagnosis()
