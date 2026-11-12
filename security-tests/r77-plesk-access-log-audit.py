#!/usr/bin/env python3
"""Read only: inspect GST vhost access_log formatting, never read log entries."""
from pathlib import Path
import re

root=Path('/etc/nginx/plesk.conf.d/vhosts')
vhosts=[
    'api-fleet.globalsentransport.fr.conf',
    'fleet.globalsentransport.fr.conf',
    'ws-fleet.globalsentransport.fr.conf',
]
unredacted=0
total=0
for name in vhosts:
    path=root/name
    if not path.is_file():
        print('R77_LOG_VHOST_MISSING='+name)
        continue
    data=path.read_text()
    active=[
        x.strip() for x in re.findall(r'^\s*access_log\s+([^;]+);',data,re.M)
        if x.strip()!='off'
    ]
    missing=sum(len(item.split())<2 for item in active)
    unredacted+=missing
    total+=len(active)
    print('R77_LOG_VHOST='+name+' ACCESS_LOGS='+str(len(active))
          +' FORMAT_MISSING='+str(missing))
print('R77_LOG_CONFIG_ENTRIES='+str(total))
print('R77_LOG_DEFAULT_COMBINED_RISK='+str(unredacted))
print('R77_LOG_QUERY_REDACTION_GATE='+('OPEN_NO_GO' if unredacted else 'FORMAT_CHECK_NEEDED'))
