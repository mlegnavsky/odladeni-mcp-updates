(function(root){
 'use strict';
 function summarize(report){
  const pages=(report.pages||[]).filter(p=>p.is_target!==false), policies=(report.policies||[]).map(p=>({...p,checks:Object.fromEntries(Object.entries(p.checks||{}).filter(([id])=>['purposes','retention','withdrawal','choice','inventory','implied_consent'].includes(id)))})), errors=report.errors||[];
  const services=[],cookies=[],findings=[];const seenS=new Set(),seenC=new Set();
  pages.forEach(p=>{
   (p.services||[]).forEach(s=>{if(!seenS.has(s.id)){seenS.add(s.id);services.push({...s,pages:pages.filter(x=>(x.services||[]).some(y=>y.id===s.id)).map(x=>x.url)});}});
   (p.cookies||[]).forEach(c=>{const key=[c.name,c.domain,c.path].join('|');if(!seenC.has(key)){seenC.add(key);cookies.push(c);}});
  });
  errors.forEach((e,i)=>findings.push({id:'fetch-'+i,status:'error',title:'Stránku se nepodařilo ověřit',evidence:e.message,url:e.url,fix:'Ověřte dostupnost stránky, případné blokování automatické kontroly a měření opakujte.'}));
  if(!policies.length)findings.push({id:'policy-missing',status:'warning',title:'Dokumentace cookies nebyla ověřena',evidence:pages.some(p=>(p.policies||[]).length)?'Odkaz byl nalezen, ale dokument se nepodařilo zpracovat.':'Na vstupní stránce nebyl rozpoznán odkaz na stránku se zásadami cookies. Dokument může existovat na jiném místě.',url:report.url,fix:'Zkontrolujte odkaz v liště a patičce a zajistěte srozumitelný dokument s přehledem skutečně používaných cookies.'});
  policies.forEach((p,i)=>{
   Object.entries(p.checks||{}).forEach(([id,c])=>findings.push({id:'policy-'+i+'-'+id,status:c.status,title:c.label+' — kontrola textu',evidence:c.evidence,url:c.source_url||p.url,outcome:c.outcome,fix:c.recommendation||(c.status==='pass'?'Informace byla v kontrolovaném textu nalezena.':'Doplňte uvedenou informaci do cookie dokumentace.')}));
   (p.services||[]).filter(s=>s.status!=='pass').forEach(s=>findings.push({id:'service-'+i+'-'+s.id,status:s.status,title:s.name+(s.status==='unknown'?' — dokument nedostupný':' — nenalezena zmínka v dokumentaci'),evidence:s.evidence,url:p.url,fix:'Ověřte, zda službu skutečně používáte. Pokud ano, zkontrolujte její účely a odpovídající informace v dokumentaci.'}));
   (p.cookies||[]).filter(c=>c.status!=='pass').forEach(c=>findings.push({id:'cookie-'+i+'-'+c.name,status:c.status,title:'Cookie '+c.name+' — nenalezena v dokumentaci',evidence:'Cookie byla vrácena v HTTP hlavičce stránky. Její název nebyl v tomto načteném dokumentu nalezen.',url:p.url,fix:'Doplňte tuto cookie do veřejného přehledu s jejím účelem a dobou platnosti. Samotný název neurčuje nutnost souhlasu.'}));
  });
  const scenarios=(report.browser?.results||[]).filter(x=>['pass','warning','error','unknown','not_applicable'].includes(x.status));
  scenarios.forEach(x=>findings.push({id:'dynamic-'+x.id,status:x.status==='not_applicable'?'pass':x.status,title:x.title,evidence:x.evidence||x.reason||'Bez dalšího důkazu.',fix:x.fix||'Výsledek se vztahuje k uvedenému scénáři, prohlížeči a době pozorování.',url:report.url}));
  if(!scenarios.length)findings.push({id:'browser-unavailable',status:'unknown',title:'Prohlížečové měření nebylo provedeno',evidence:report.browser?.reason||'V tomto měření nebyl spuštěn interaktivní prohlížeč. Z výsledků nelze vyvozovat, jak web respektuje souhlas.',fix:'Pro ověření chování lišty a cookies je potřeba prohlížečové měření.',url:report.url});
  if(pages.length)findings.push({id:'coverage',status:'pass',title:'Veřejné stránky byly načteny',evidence:'Úspěšně načtených HTML stránek: '+pages.length+'. Rozsah najdete v přehledu stránek.',fix:'Výsledek platí pro uvedené adresy a čas kontroly.',url:report.url});
  return {services,cookies,scenarios,cookiePolicies:policies,findings,errors:findings.filter(x=>x.status==='error').length,warnings:findings.filter(x=>x.status==='warning').length,passed:findings.filter(x=>x.status==='pass').length,unknown:findings.filter(x=>x.status==='unknown').length};
 }
 function csvCell(v){v=String(v??'');if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replaceAll('"','""')+'"';}
 function policyOutcomeLabel(c){return ({found:'Nalezeno v dokumentaci',missing:'Nenalezeno v dokumentaci',conflict:'Rozporná formulace',not_detected:'Problematická formulace nenalezena',unavailable:'Dokument nedostupný'})[c.outcome]||(c.status==='pass'?'Nalezeno v textu':'Automaticky nerozpoznáno');}
 root.GCAModel={summarize,csvCell,policyOutcomeLabel};
})(typeof window!=='undefined'?window:globalThis);
