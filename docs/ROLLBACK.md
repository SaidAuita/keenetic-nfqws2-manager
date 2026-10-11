# Инструкция по откату изменений и восстановлению (Rollback Guide)

Данное руководство описывает безопасный процесс отката конфигурации сервиса `nfqws2` на роутере Keenetic к предыдущему заведомо рабочему состоянию.

---

## 1. Просмотр истории изменений

Чтобы увидеть список всех зафиксированных состояний проекта с краткими хэшами коммитов:

```powershell
git log --oneline --decorate
```

Для подробного просмотра истории с перечнем изменённых файлов:

```powershell
git log --stat -n 5
```

---

## 2. Сравнение версий (Просмотр различий)

### Сравнить текущую локальную версию с последним коммитом:
```powershell
git diff
```

### Сравнить текущую версию с конкретным файлом в предыдущем коммите:
```powershell
git diff HEAD~1 router_backup/opt/etc/nfqws2/nfqws2.conf
```

### Сравнить два любых коммита:
```powershell
git diff <commit_id_old> <commit_id_new>
```

---

## 3. Безопасное восстановление локального файла из истории

Чтобы восстановить отдельный файл (например, `nfqws2.conf` или список хостов) из конкретного коммита, не затрагивая остальные файлы:

```powershell
# Способ 1 (современный git restore):
git restore --source=<commit_id> router_backup/opt/etc/nfqws2/nfqws2.conf

# Способ 2 (классический git checkout):
git checkout <commit_id> -- router_backup/opt/etc/nfqws2/nfqws2.conf
```

Файл будет возвращён к состоянию на момент указанного коммита в вашей локальной копии `router_backup`.

---

## 4. Перенос восстановленной конфигурации на роутер Keenetic

Перед загрузкой конфигурации на роутер **всегда делайте свежий резервный снимок**:

```powershell
python backup_router.py
```

После этого загрузите восстановленный файл `nfqws2.conf` на роутер. Для этого используйте безопасный однострочник через `ssh_cmd.py`:

```powershell
# Загрузка локального nfqws2.conf на роутер:
python -c "
import paramiko
from router_env import ROUTER_IP, ROUTER_PORT, ROUTER_USER, ROUTER_PASSWORD

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(ROUTER_IP, port=ROUTER_PORT, username=ROUTER_USER, password=ROUTER_PASSWORD, timeout=10)

with open(r'router_backup\opt\etc\nfqws2\nfqws2.conf', 'rb') as f:
    data = f.read()

sftp = client.open_sftp()
with sftp.open('/opt/etc/nfqws2/nfqws2.conf', 'wb') as rf:
    rf.write(data)
sftp.close()
client.close()
print('Конфигурация успешно загружена на роутер!')
"
```

---

## 5. Перезапуск службы NFQWS2 и проверка

После загрузки конфигурации перезапустите службу на роутере:

```powershell
python ssh_cmd.py /opt/etc/init.d/S51nfqws2 restart
```

Проверьте статус службы:

```powershell
python ssh_cmd.py /opt/etc/init.d/S51nfqws2 status
```

Запустите комплексную диагностику YouTube и сети:

```powershell
python diagnose_youtube.py
```

---

## 6. Фиксация отката в Git

После подтверждения того, что роутер снова стабильно работает, зафиксируйте факт отката в истории:

```powershell
git add router_backup/opt/etc/nfqws2/nfqws2.conf
git commit -m "rollback: возврат nfqws2.conf к стабильной версии <commit_id>"
```

И добавьте запись в `CHANGELOG.md`.
