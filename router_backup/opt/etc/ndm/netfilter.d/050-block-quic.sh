#!/bin/sh
# Блокировка QUIC (UDP 443) для принудительного перехода YouTube и браузеров на стабильный TCP/TLS
[ "$table" != "filter" ] && exit 0

iptables -C FORWARD -p udp --dport 443 -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || \
iptables -I FORWARD -p udp --dport 443 -j REJECT --reject-with icmp-port-unreachable

iptables -C OUTPUT -p udp --dport 443 -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || \
iptables -I OUTPUT -p udp --dport 443 -j REJECT --reject-with icmp-port-unreachable

exit 0
