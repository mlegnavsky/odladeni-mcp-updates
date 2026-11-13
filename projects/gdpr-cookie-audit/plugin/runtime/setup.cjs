'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const [mode,base,nodeFolder,version]=process.argv.slice(2);
if(!base||!/^node-v22\.\d+\.\d+-linux-x64$/.test(nodeFolder)||!/^\d+\.\d+\.\d+$/.test(version))process.exit(2);
if(mode==='--detach'){
 const child=cp.spawn(process.execPath,[__filename,'--install',base,nodeFolder,version],{cwd:base,detached:true,stdio:'ignore'});child.unref();process.exit(0);
}
if(mode!=='--install')process.exit(2);
const statusFile=path.join(base,'status.json');
function status(state,reason,extra={}){fs.writeFileSync(statusFile+'.tmp',JSON.stringify({state,reason,updated_at:new Date().toISOString(),...extra}),{mode:0o600});fs.renameSync(statusFile+'.tmp',statusFile);}
function run(exe,args,env){return new Promise((resolve,reject)=>cp.execFile(exe,args,{cwd:base,env,timeout:240000,maxBuffer:1024*1024},(error,stdout,stderr)=>error?reject(Object.assign(error,{detail:stderr||stdout})):resolve(stdout)));}
const watchdog=setTimeout(()=>{status('blocked','installation_timeout');process.exit(1);},720000);
(async()=>{
 const env={...process.env,LD_LIBRARY_PATH:[path.join(base,'libs/usr/lib/x86_64-linux-gnu'),path.join(base,'libs/lib/x86_64-linux-gnu'),process.env.LD_LIBRARY_PATH||''].filter(Boolean).join(':'),PATH:path.join(base,nodeFolder,'bin')+':'+(process.env.PATH||'/usr/local/bin:/usr/bin:/bin'),npm_config_cache:path.join(base,'npm-cache'),PLAYWRIGHT_BROWSERS_PATH:path.join(base,'browsers')};
 let browser;
 try{
  status('installing','browser_library');
  const npm=path.join(base,nodeFolder,'lib/node_modules/npm/bin/npm-cli.js');
  let installedVersion;try{installedVersion=JSON.parse(fs.readFileSync(path.join(base,'node_modules/playwright-core/package.json'),'utf8')).version;}catch{}
  if(installedVersion!==version)await run(process.execPath,[npm,'install','--prefix',base,'--no-audit','--no-fund','--ignore-scripts','--save-exact','playwright-core@'+version],env);
  status('installing','firefox_download');
  if(!fs.existsSync(path.join(base,'browsers'))||!fs.readdirSync(path.join(base,'browsers')).some(f=>/^firefox-\d+$/.test(f)))await run(process.execPath,[path.join(base,'node_modules/playwright-core/cli.js'),'install','firefox'],env);
  process.env.PLAYWRIGHT_BROWSERS_PATH=env.PLAYWRIGHT_BROWSERS_PATH;
  process.env.LD_LIBRARY_PATH=env.LD_LIBRARY_PATH;
  const {firefox}=require(path.join(base,'node_modules/playwright-core'));
  status('installing','sandbox_test');
  delete env.MOZ_DISABLE_CONTENT_SANDBOX;delete env.MOZ_DISABLE_GMP_SANDBOX;delete env.MOZ_DISABLE_RDD_SANDBOX;
  const launch=()=>firefox.launch({env,headless:true,timeout:25000});
  try{browser=await launch();}catch(error){
   if(!/shared libraries|\.so[: .]|missing dependencies/i.test(String(error.message)))throw error;
   status('installing','system_libraries');
   await require('./dependencies.cjs').vendorDependencies(base,env,'firefox');
   status('installing','sandbox_test');browser=await launch();
  }
  const page=await browser.newPage();await page.goto('data:text/html,<title>Browser verification</title><p>Private runtime test</p>');await page.screenshot();
  const verified=require('./sandbox.cjs').verifyContentSandbox();await browser.close();
  status('ready','verified',{...verified,version,engine:'firefox'});
 }catch(e){
  const detail=String(e.detail||e.message||e).slice(0,12000),lib=detail.match(/lib[\w.+-]+\.so[\w.]* /)?.[0]?.trim();
  const reason=/sandbox|namespace|Operation not permitted/i.test(detail)?'sandbox_unavailable':lib?'system_library_missing':/GLIBC_|not found|ENOENT/.test(detail)?'system_dependency_missing':'installation_failed';
  let missing_libraries=[],platform='unknown';
  try{
   const release=fs.readFileSync('/etc/os-release','utf8');platform=(release.match(/^ID=(.+)$/m)?.[1]||'unknown').replaceAll('"','')+' '+(release.match(/^VERSION_ID=(.+)$/m)?.[1]||'').replaceAll('"','');
   const folder=fs.readdirSync(path.join(base,'browsers')).find(f=>/^firefox-\d+$/.test(f));
   if(folder){
    const candidates=['firefox/libxul.so','firefox/firefox'];
    const binary=candidates.map(f=>path.join(base,'browsers',folder,f)).find(f=>fs.existsSync(f));
    if(binary){const ldd=cp.spawnSync('ldd',[binary],{env:{...env,LD_LIBRARY_PATH:path.dirname(binary)+':'+env.LD_LIBRARY_PATH},encoding:'utf8',timeout:5000});missing_libraries=[...String(ldd.stdout).matchAll(/^\s*(lib[\w.+-]+\.so[\w.]*)\s+=>\s+not found/gm)].map(m=>m[1]);}
   }
  }catch{}
  const installer_error_code=String(e.code||'').replace(/[^A-Z0-9_]/gi,'').slice(0,32);
  const installer_tool=['apt-get','dpkg-deb'].includes(path.basename(e.path||''))?path.basename(e.path):null;
  status('blocked',missing_libraries.length?'system_library_missing':reason,{detail,engine:'firefox',installer_error_code,installer_tool,missing_library:lib||null,missing_libraries,platform});
 }finally{
  try{if(browser)await browser.close();}finally{clearTimeout(watchdog);}
 }
})();
