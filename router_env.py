"""
Helper module to securely load router credentials from .env
"""
import os

def load_env(env_path=None):
    if env_path is None:
        env_path = os.path.join(os.path.dirname(__file__), '.env')
    if os.path.isfile(env_path):
        with open(env_path, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    k, v = line.split('=', 1)
                    k = k.strip()
                    v = v.strip().strip("'\"")
                    if k not in os.environ:
                        os.environ[k] = v

load_env()

ROUTER_IP = os.getenv('ROUTER_IP', '192.168.1.1')
ROUTER_PORT = int(os.getenv('ROUTER_PORT', '222'))
ROUTER_USER = os.getenv('ROUTER_USER', 'root')
ROUTER_PASSWORD = os.getenv('ROUTER_PASSWORD', '')
