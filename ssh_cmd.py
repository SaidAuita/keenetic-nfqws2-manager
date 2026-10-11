import sys
import paramiko
from router_env import ROUTER_IP, ROUTER_PORT, ROUTER_USER, ROUTER_PASSWORD

def run_cmd(cmd):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(ROUTER_IP, port=ROUTER_PORT, username=ROUTER_USER, password=ROUTER_PASSWORD, timeout=10)
    stdin, stdout, stderr = client.exec_command(cmd, get_pty=True)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    client.close()
    sys.stdout.reconfigure(encoding='utf-8')
    print(out)
    if err:
        print('STDERR:', err)

if __name__ == '__main__':
    command = ' '.join(sys.argv[1:]) if len(sys.argv) > 1 else '/opt/etc/init.d/S51nfqws2 status'
    run_cmd(command)
