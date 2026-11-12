from pathlib import Path
import subprocess
import yaml

base=Path('pnpm-lock.yaml')
new_text=base.read_text()
old_text=subprocess.check_output(['git','show','HEAD:pnpm-lock.yaml'],text=True)
old=yaml.safe_load(old_text)
new=yaml.safe_load(new_text)
assert new['importers']['.']['dependencies']['qrcode']['specifier']=='1.5.4'
assert 'qrcode' not in old['importers']['.']['dependencies']

# Traverse the new QR dependency graph, collecting only missing keys.
missing=set()
to_visit=['qrcode@1.5.4']
while to_visit:
    name=to_visit.pop()
    if name in old['snapshots'] or name in missing:
        continue
    assert name in new['snapshots'], name
    missing.add(name)
    for dep, version in (new['snapshots'][name] or {}).get('dependencies',{}).items():
        to_visit.append(f'{dep}@{version}')
print('R73_QR_REQUIRED_NEW_SNAPSHOTS',len(missing),sorted(missing))
assert len(missing)<=25, 'unexpectedly broad QR change'

# Keep previous peer dependency decisions exactly unchanged.
sections={}
for name in ['packages','snapshots']:
    start=new_text.index('\n'+name+':\n')+1
    end=new_text.find('\n'+('snapshots:' if name=='packages' else '__end__')+'\n', start)
    sections[name]=new_text[start:] if end<0 else new_text[start:end]

def entry(block,key):
    lines=block.splitlines(keepends=True)
    target=f'  {key}:'
    start=next((i for i,v in enumerate(lines) if v.startswith(target)),None)
    assert start is not None,(key,target)
    stop=next((i for i in range(start+1,len(lines)) if lines[i].startswith('  ') and not lines[i].startswith('    ') and lines[i].strip()!=''),len(lines))
    return ''.join(lines[start:stop]).rstrip()+'\n\n'

package_new=sorted(x for x in missing if x not in old['packages'])
assert set(package_new)==missing, 'Snapshot/package mismatch'
importer="      qrcode:\n        specifier: 1.5.4\n        version: 1.5.4\n"
assert old_text.count("      xlsx:\n")==1
edited=old_text.replace("      xlsx:\n",importer+"      xlsx:\n")
loc=edited.index('\nsnapshots:\n')
new_package_blocks=''.join(entry(sections['packages'],p) for p in package_new)
edited=edited[:loc]+'\n'+new_package_blocks+edited[loc:]
new_snapshot_blocks='\n'+''.join(entry(sections['snapshots'],p) for p in sorted(missing))
edited=edited.rstrip()+'\n'+new_snapshot_blocks

result=yaml.safe_load(edited)
assert result['packages'].keys()>=old['packages'].keys()
assert result['snapshots'].keys()>=old['snapshots'].keys()
assert all(result['snapshots'][x]==old['snapshots'][x] for x in old['snapshots'])
assert len(result['packages'])-len(old['packages'])==len(package_new)
assert len(result['snapshots'])-len(old['snapshots'])==len(missing)
assert result['importers']['.']['dependencies']['qrcode']=={'specifier':'1.5.4','version':'1.5.4'}
base.write_text(edited)
print('R73_MINIMAL_LOCK_DIFF_PASS',len(package_new),'packages',len(missing),'snapshots')
