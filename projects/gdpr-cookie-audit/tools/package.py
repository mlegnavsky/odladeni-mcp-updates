from pathlib import Path
import json,re,sys,zipfile,hashlib,subprocess,os,shutil
root=Path(__file__).resolve().parents[1];plugin=root/'plugin';version=sys.argv[1];assert re.fullmatch(r'\d+\.\d+\.\d+',version)
php_cli=os.environ.get('GCA_PHP_CLI') or shutil.which('php')
if not php_cli:raise SystemExit('Set GCA_PHP_CLI to a PHP CLI executable before packaging.')
for check_file in sorted(plugin.rglob('*.php')):
 result=subprocess.run([php_cli,'-l',str(check_file)],capture_output=True,text=True)
 if result.returncode or 'No syntax errors detected' not in result.stdout:raise SystemExit(result.stdout+result.stderr)
for check_file in sorted(plugin.rglob('*')):
 if check_file.suffix in ('.js','.cjs'):
  subprocess.run(['node','--check',str(check_file)],check=True,capture_output=True)
f=plugin/'gdpr-cookie-audit.php';s=f.read_text();s=re.sub(r'(?m)^( \* Version: )\d+\.\d+\.\d+',lambda m:m[1]+version,s);s=re.sub(r"define\('GCA_VERSION','[^']+'\)","define('GCA_VERSION','"+version+"')",s);f.write_text(s)
f=plugin/'manifest.json';m=json.loads(f.read_text());m['version']=version;f.write_text(json.dumps(m,ensure_ascii=False,indent=2))
f=plugin/'readme.txt';f.write_text(re.sub(r'(?m)^Stable tag: .*','Stable tag: '+version,f.read_text()))
output=Path('/tmp')/('gdpr-cookie-audit-v'+version+'.zip')
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as z:
 for f in sorted(plugin.rglob('*')):
  if f.is_file():z.write(f,'gdpr-cookie-audit/'+str(f.relative_to(plugin)))
print(json.dumps({'path':str(output),'bytes':output.stat().st_size,'sha256':hashlib.sha256(output.read_bytes()).hexdigest(),'chunks':(output.stat().st_size+24575)//24576}))
