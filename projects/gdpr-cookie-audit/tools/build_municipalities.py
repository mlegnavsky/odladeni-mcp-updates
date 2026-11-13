"""Extract only municipality directory facts from the verified project snapshot."""
import hashlib,json,sys,unicodedata,zipfile
from pathlib import Path
package=Path(sys.argv[1]);expected='a5a40530a20f3bb13fc137ecbc4a748876e4723659f0c50db75c27a9bf2615f4'
assert hashlib.sha256(package.read_bytes()).hexdigest()==expected,'Source release SHA256 mismatch'
with zipfile.ZipFile(package) as archive:
    raw=archive.read('ps-precinct-registry/assets/units.jsonl')
units=[json.loads(line) for line in raw.decode('utf-8').splitlines() if line.strip()]
regions={u['source_id']:u['name'] for u in units if u['kind']=='region'}
districts={u['source_id']:{'name':u['name'],'region':u['parent_id'].rsplit(':',1)[-1]} for u in units if u['kind']=='district'}
excluded={'503941','545422','555177','592935'}
municipalities=[u for u in units if u['kind']=='municipality']
assert len(municipalities)==6258 and {u['source_id'] for u in municipalities if not u['data'].get('region_code')}==excluded
rows=[{'code':u['source_id'],'name':u['name'],'region':u['data']['region_code'],'district':u['data']['district_code']} for u in municipalities if u['source_id'] not in excluded]
assert len(rows)==len({r['code'] for r in rows})==6254 and len(regions)==14
assert all(len(r['code'])==6 and r['code'].isdigit() and r['region'] in regions and r['district'] in districts and districts[r['district']]['region']==r['region'] for r in rows)
fold=lambda s:''.join(c for c in unicodedata.normalize('NFKD',s.casefold()) if not unicodedata.combining(c))
rows.sort(key=lambda r:(fold(r['name']),fold(districts[r['district']]['name']),r['code']))
metadata={'project':'poslanecka-snemovna.cz','plugin':'ps-precinct-registry','version':'1.19.3','snapshot_date':'2026-09-08','imported_date':'2026-11-13','source_release_sha256':expected,'source_member_sha256':hashlib.sha256(raw).hexdigest(),'source_member':'assets/units.jsonl','attribution':'Český statistický úřad; projekt Poslanecká sněmovna','reference_url':'https://csu.gov.cz/kraje-spolecne/uzemni-jednotky','excluded_military_codes':sorted(excluded),'scope':'Municipalities only; no precincts, boroughs, election results or resident data.'}
content=json.dumps({'meta':metadata,'regions':regions,'districts':districts,'rows':rows},ensure_ascii=False,separators=(',',':'))
output=Path(__file__).resolve().parents[1]/'plugin/data/municipalities.php';output.parent.mkdir(exist_ok=True)
output.write_text("<?php\n/** Names/codes only. Source and reproducible extraction in tools/build_municipalities.py. */\nif(!defined('ABSPATH'))exit;\nreturn json_decode(<<<'GCA_MUNICIPALITIES'\n"+content+"\nGCA_MUNICIPALITIES,true,512,JSON_THROW_ON_ERROR);\n")
print(json.dumps({'municipalities':len(rows),'regions':len(regions),'district_entries':len(districts),'bytes':output.stat().st_size}))
