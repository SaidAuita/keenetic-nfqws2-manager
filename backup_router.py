import os
import paramiko
from router_env import ROUTER_IP, ROUTER_PORT, ROUTER_USER, ROUTER_PASSWORD

def backup():
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(ROUTER_IP, port=ROUTER_PORT, username=ROUTER_USER, password=ROUTER_PASSWORD, timeout=10)

    def fetch_file(remote_path, local_path):
        os.makedirs(os.path.dirname(local_path), exist_ok=True)
        stdin, stdout, stderr = client.exec_command(f'cat {remote_path}')
        content = stdout.read()
        with open(local_path, 'wb') as f:
            f.write(content)
        print(f'Fetched {remote_path} -> {local_path} ({len(content)} bytes)')

    def fetch_dir(remote_dir, local_dir):
        stdin, stdout, stderr = client.exec_command(f'find {remote_dir} -type f')
        files = stdout.read().decode('utf-8', errors='replace').splitlines()
        for rf in files:
            rf = rf.strip()
            if not rf:
                continue
            rel = os.path.relpath(rf, remote_dir)
            lf = os.path.join(local_dir, rel)
            fetch_file(rf, lf)

    backup_base = os.path.join(os.path.dirname(__file__), 'router_backup')
    print('Starting backup to', backup_base)
    fetch_dir('/opt/etc/nfqws2', os.path.join(backup_base, 'opt', 'etc', 'nfqws2'))
    fetch_file('/opt/etc/init.d/S51nfqws2', os.path.join(backup_base, 'opt', 'etc', 'init.d', 'S51nfqws2'))
    fetch_file('/opt/etc/opkg.conf', os.path.join(backup_base, 'opt', 'etc', 'opkg.conf'))
    fetch_dir('/opt/etc/opkg', os.path.join(backup_base, 'opt', 'etc', 'opkg'))
    fetch_file('/opt/etc/wgetrc', os.path.join(backup_base, 'opt', 'etc', 'wgetrc'))
    fetch_file('/opt/etc/nfqws_web.conf', os.path.join(backup_base, 'opt', 'etc', 'nfqws_web.conf'))

    client.close()
    print('All files backed up successfully!')

if __name__ == '__main__':
    backup()
