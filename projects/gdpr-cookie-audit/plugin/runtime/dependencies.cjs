'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
// Playwright-core 1.65.0, packages/playwright-core/src/server/registry/nativeDeps.ts.
// Keep the supported hosting distribution explicit; bundled releases expose no nativeDeps module.
const hostingPackages={'ubuntu22.04-x64':{chromium:[
 'libasound2','libatk-bridge2.0-0','libatk1.0-0','libatspi2.0-0','libcairo2',
 'libcups2','libdbus-1-3','libdrm2','libgbm1','libglib2.0-0','libnspr4','libnss3',
 'libpango-1.0-0','libwayland-client0','libx11-6','libxcb1','libxcomposite1',
 'libxdamage1','libxext6','libxfixes3','libxkbcommon0','libxrandr2',
 // Additional unresolved libraries observed by ldd on this hosting after the first pass.
 'libwayland-server0','libxcb-randr0','libxi6'
],firefox:[
 'libasound2','libatk1.0-0','libcairo-gobject2','libcairo2','libdbus-1-3','libdbus-glib-1-2',
 'libfontconfig1','libfreetype6','libgdk-pixbuf-2.0-0','libglib2.0-0','libgtk-3-0',
 'libpango-1.0-0','libpangocairo-1.0-0','libx11-6','libx11-xcb1','libxcb-shm0','libxcb1',
 'libxcomposite1','libxcursor1','libxdamage1','libxext6','libxfixes3','libxi6','libxrandr2','libxrender1','libxtst6',
 'libpixman-1-0','libharfbuzz0b','libthai0','libwayland-client0','libwayland-cursor0','libwayland-egl1','libxinerama1','libepoxy0'
]}};
function platformKey(release){
 const id=(release.match(/^ID=(.+)$/m)?.[1]||'').replaceAll('"','');
 const version=(release.match(/^VERSION_ID=(.+)$/m)?.[1]||'').replaceAll('"','');
 if(!['ubuntu','debian'].includes(id)||!/^\d+(\.\d+)?$/.test(version))throw new Error('Unsupported hosting distribution');
 return id+version+'-x64';
}
function dependencyPackages(registry,key,engine='chromium'){
 if(!['chromium','firefox'].includes(engine))throw new Error('Unsupported browser');
 const packages=registry[key]?.[engine];if(!Array.isArray(packages))throw new Error('Unsupported hosting package set');
 if(packages.some(p=>!/^[a-z0-9][a-z0-9+.-]*$/.test(p)))throw new Error('Invalid package name');
 return packages.filter(p=>p.startsWith('lib'));
}
async function vendorDependencies(base,env,engine='chromium'){
 const packages=dependencyPackages(hostingPackages,platformKey(fs.readFileSync('/etc/os-release','utf8')),engine);
 const downloadDir=path.join(base,'dependency-archives'),destination=path.join(base,'libs');
 fs.mkdirSync(downloadDir,{recursive:true,mode:0o700});fs.mkdirSync(destination,{recursive:true,mode:0o700});
 const run=(exe,args)=>new Promise((resolve,reject)=>cp.execFile(exe,args,{cwd:downloadDir,env,timeout:180000,maxBuffer:1024*1024},(error,out,err)=>error?reject(Object.assign(error,{detail:err||out})):resolve(out)));
 // APT verifies packages against the host's repository metadata. No install/update/postinst.
 await run('/usr/bin/apt-get',['download',...packages]);
 const archives=fs.readdirSync(downloadDir).filter(f=>/^[a-zA-Z0-9_.+%:~-]+\.deb$/.test(f));
 if(!archives.length)throw new Error('No dependency archives were downloaded');
 for(const archive of archives)await run('/usr/bin/dpkg-deb',['--extract',path.join(downloadDir,archive),destination]);
 return packages;
}
module.exports={platformKey,dependencyPackages,vendorDependencies};
