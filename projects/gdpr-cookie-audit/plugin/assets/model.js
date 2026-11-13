(function(root){
 'use strict';
 function summarize(report){
  const pages=(report.pages||[]).filter(p=>p.is_target!==false), policies=report.policies||[], errors=report.errors||[];
  const services=[],cookies=[],findings=[];const seenS=new Set(),seenC=new Set();
  pages.forEach(p=>{
   (p.services||[]).forEach(s=>{if(!seenS.has(s.id)){seenS.add(s.id);services.push({...s,pages:pages.filter(x=>(x.services||[]).some(y=>y.id===s.id)).map(x=>x.url)});}});
   (p.cookies||[]).forEach(c=>{const key=[c.name,c.domain,c.path].join('|');if(!seenC.has(key)){seenC.add(key);cookies.push(c);}});
  });
  errors.forEach((e,i)=>findings.push({id:'fetch-'+i,status:'error',title:'Stránku se nepodařilo ověřit',evidence:e.message,url:e.url,fix:'Ověřte dostupnost stránky, případné blokování automatické kontroly a měření opakujte.'}));
  if(!policies.length)findings.push({id:'policy-missing',status:'warning',title:'Dokumentace cookies nebyla ověřena',evidence:pages.some(p=>(p.policies||[]).length)?'Odkaz byl nalezen, ale dokument se nepodařilo zpracovat.':'Na vstupní stránce nebyl rozpoznán odkaz na zásady cookies nebo ochrany osobních údajů. Dokument může existovat na jiném místě.',url:report.url,fix:'Zkontrolujte odkaz v liště a patičce a zajistěte srozumitelný dokument s přehledem skutečně používaných cookies.'});
  policies.forEach((p,i)=>{
   Object.entries(p.checks||{}).forEach(([id,c])=>findings.push({id:'policy-'+i+'-'+id,status:c.status,title:c.label+' — kontrola textu',evidence:c.evidence,url:p.url,fix:c.status==='pass'?'Zmínka je dohledatelná. Správnost a úplnost obsahu je potřeba posoudit podle skutečného provozu.':'Prověřte tuto část dokumentace a doplňte informace podle skutečného nastavení webu.'}));
   (p.services||[]).filter(s=>s.status!=='pass').forEach(s=>findings.push({id:'service-'+i+'-'+s.id,status:'warning',title:s.name+' — nenalezena zmínka v dokumentu',evidence:s.evidence,url:p.url,fix:'Ověřte, zda službu skutečně používáte. Pokud ano, zkontrolujte její účely a odpovídající informace v dokumentaci.'}));
   (p.cookies||[]).filter(c=>c.status!=='pass').forEach(c=>findings.push({id:'cookie-'+i+'-'+c.name,status:'warning',title:'Cookie '+c.name+' — nenalezena v dokumentu',evidence:'Cookie byla vrácena v HTTP hlavičce stránky. Její název nebyl v tomto načteném dokumentu nalezen.',url:p.url,fix:'Určete účel cookie a ověřte, zda je vysvětlena zde nebo v navazující tabulce cookies. Samotný název neprokazuje nutnost souhlasu.'}));
  });
  [
   ['before','Cookies a požadavky před souhlasem'],['reject','Respektování odmítnutí'],['accept','Přijetí a výběr kategorií'],['withdraw','Odvolání a zapamatování souhlasu'],['consent','Google Consent Mode v2 za běhu'],['visual','Vzhled lišty na mobilu a snímky'],['storage','LocalStorage a další úložiště']
  ].forEach(([id,title])=>findings.push({id:'dynamic-'+id,status:'unknown',title,evidence:'Tato kontrola vyžaduje interaktivní měření v prohlížeči. V tomto zdrojovém auditu nebyla provedena.',fix:'Pro ověření je potřeba připojit prohlížečové měření nebo provést cílený ruční test.',url:report.url}));
  if(pages.length)findings.push({id:'coverage',status:'pass',title:'Veřejné stránky byly načteny',evidence:'Úspěšně načtených HTML stránek: '+pages.length+'. Rozsah najdete v přehledu stránek.',fix:'Výsledek platí pro uvedené adresy a čas kontroly.',url:report.url});
  return {services,cookies,findings,errors:findings.filter(x=>x.status==='error').length,warnings:findings.filter(x=>x.status==='warning').length,passed:findings.filter(x=>x.status==='pass').length,unknown:findings.filter(x=>x.status==='unknown').length};
 }
 function csvCell(v){v=String(v??'');if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replaceAll('"','""')+'"';}
 root.GCAModel={summarize,csvCell};
})(typeof window!=='undefined'?window:globalThis);
