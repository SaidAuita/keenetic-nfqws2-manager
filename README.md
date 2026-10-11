# Keenetic NFQWS2 & YouTube / Discord Bypass Guide

Полное руководство по настройке, восстановлению, диагностике и управлению сервисом обхода блокировок ТСПУ (nfqws2-keenetic) на роутере Keenetic.

---

## 📌 Данные доступа к оборудованию

| Интерфейс | Адрес | Логин / Пароль | Назначение |
|---|---|---|---|
| **Keenetic Web UI** | http://192.168.1.1/ | said / (см. .env) | Основная админка роутера KeeneticOS |
| **NFQWS Web UI** | http://192.168.1.1:90/ | 
root / (см. .env) | Веб-интерфейс управления 
fqws-keenetic-web |
| **Entware SSH** | ssh root@192.168.1.1 -p 222 | 
root / (см. .env) | Консольный доступ к среде Entware (Linux) |

---

## ⚡ Интерактивное меню menu (21 стратегия)

На роутере установлен инструмент 
fqws-menu (на базе Flowseal / Nare51) с 21 адаптированной стратегией для NFQWS2.

### Как запустить:
1. Подключитесь по SSH:
   `ash
   ssh root@192.168.1.1 -p 222
   `
2. Введите команду:
   `ash
   menu
   `
3. Откроется интерактивное меню:
   - **Выбор стратегии** (например: lt, lt4, lt11, ake_tls_auto, simple_fake).
   - **Проверка и обновление списков** (user.list, exclude.list, ipset.list).
   - **Проверка blobs** (все 28 необходимых бинарников уже скопированы в /opt/etc/nfqws2/blobs/).
   - **Резервное копирование и откат** конфигурации.
   - **Перезапуск службы**.

---

## 🛠️ Что было исправлено при инциденте (25.09.2026)

### 1. Остановка демона NFQWS2
* Сервис находился в состоянии: Service NFQWS2 is stopped.
* Запущен через /opt/etc/init.d/S51nfqws2 start.

### 2. Сбой opkg update при обращении к HTTPS
* **Проблема:** системный симлинк /opt/usr/bin/wget вел на урезанный BusyBox, не знающий протокола HTTPS. При попытке обновления списков пакетов с GitHub возникала ошибка:
  `	ext
  wget: not an http or ftp url: https://nfqws.github.io/...
  `
* **Решение:** симлинк перенаправлен на полноценный SSL-клиент:
  `ash
  ln -sf /opt/libexec/wget-ssl /opt/usr/bin/wget
  `
  После этого opkg update работает без ошибок.

### 3. Главная причина блокировки YouTube (Критический баг пакета)
* **Проблема:** В каталоге /opt/etc/nfqws2/lua/ модули десинхронизации находились в сжатом виде: zapret-lib.lua.gz, zapret-antidpi.lua.gz.
* Демон 
fqws2 пытался загрузить файлы по путям без расширения .gz. Не находя их, он писал в лог /opt/var/log/nfqws2-debug.log:
  `	ext
  no lua functions in this profile
  packet: id=... pass unmodified
  `
  Из-за этого **ни одна Lua-функция десинхронизации (fake, multisplit и т.д.) не выполнялась**, и весь трафик шёл провайдеру «голым».
* **Решение:** Все архивы были распакованы на роутере:
  `ash
  gzip -d -k /opt/etc/nfqws2/lua/*.gz
  chmod 644 /opt/etc/nfqws2/lua/*.lua
  /opt/etc/init.d/S51nfqws2 restart
  `
  Сразу после этого YouTube и Google Video вернули HTTP 200 OK и восстановили нормальную работу.

### 4. Загрузка недостающих Blobs и Стратегий
* Все 28 бинарных blob-файлов скопированы в /opt/etc/nfqws2/blobs/.
* Все 21 стратегия скопированы в локальный кэш /opt/etc/nfqws-menu/strategies/nfqws2/.

---

## 🖥️ Особенности клиентов (Windows)

1. **TCP Timestamps (RFC 1323):**
   * Многие продвинутые стратегии десинхронизации используют подделку 	s (TCP timestamps).
   * На Windows метки времени должны быть включены:
     `cmd
     netsh interface tcp set global timestamps=enabled
     `
     *(На текущем ПК они уже включены)*.
2. **Прокси / Виртуальные адаптеры (Happ / SocksTunnel):**
   * Виртуальный сетевой адаптер happ-default-tun прописывает DNS 10.6.7.2.
   * Если приложение Happ выключено или зависло, система пытается обращаться к мертвому DNS 10.6.7.2 и падает по таймауту. При работе через роутер туннель Happ должен быть отключен, чтобы запросы шли напрямую на 192.168.1.1.

---

## 📂 Структура папки c:\_CODE\Utilites\keenetic

`	ext
c:\_CODE\Utilites\keenetic\
├── README.md                 # Данный документ
├── ssh_cmd.py                # Быстрый запуск команд на роутере через Python (python ssh_cmd.py <cmd>)
├── backup_router.py          # Скрипт синхронизации и бэкапа настроек с роутера
├── router_backup\            # Актуальная выгрузка конфигураций с роутера (nfqws2.conf, lists, init, etc.)
└── nfqws-menu\               # Полный исходный репозиторий nfqws-menu (скрипты, 21 стратегия, blobs, lists)
`

---

## 🚀 Полезные команды

### Проверить статус службы с ПК:
`powershell
python c:\_CODE\Utilites\keenetic\ssh_cmd.py /opt/etc/init.d/S51nfqws2 status
`

### Проверить отладку nfqws2 (последние строки):
`powershell
python c:\_CODE\Utilites\keenetic\ssh_cmd.py tail -n 30 /opt/var/log/nfqws2-debug.log
`

### Перезапустить службу:
`powershell
python c:\_CODE\Utilites\keenetic\ssh_cmd.py /opt/etc/init.d/S51nfqws2 restart
`

### Сделать свежий бэкап с роутера на диск ПК:
`powershell
python c:\_CODE\Utilites\keenetic\backup_router.py
`
