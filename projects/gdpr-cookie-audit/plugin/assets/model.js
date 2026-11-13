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
  const missingDocument=report.policy_discovery?.status==='missing'&&['complete','partial'].includes(report.state);
  if(!policies.length)findings.push({id:'policy-missing',status:missingDocument?'error':report.policy_discovery?'unknown':'warning',title:missingDocument?'Dokumentace cookies nebyla v kontrolovaných stránkách nalezena':'Dokumentaci cookies se nepodařilo vyhodnotit',evidence:missingDocument?'V '+pages.length+' úspěšně načtených stránkách nebyl nalezen dostupný cookie dokument. Šest dokumentačních kritérií proto nemá doložené splnění.':'Kontrola dokumentace není dokončená; příčinu najdete v chybách načítání.',url:report.url,fix:'Zajistěte funkční a viditelný odkaz na veřejný dokument s přehledem používaných cookies.'});
  (report.policy_discovery?.issues||[]).forEach((issue,i)=>{const unsupported=issue.reason==='unsupported';findings.push({id:'policy-link-'+i,status:unsupported?'unknown':'error',title:unsupported?'Odkaz na cookie dokument nelze tímto měřením načíst':'Odkaz na cookie dokument nevede na stránku',evidence:'Odkaz „'+issue.label+'“ má cíl „'+(issue.href||'(prázdný)')+'“. '+(unsupported?'Cíl vyžaduje dynamickou interakci nebo má nepodporovaný formát adresy. Obsah nebyl ověřen.':'V načteném zdroji neodkazuje na samostatný dostupný dokument; případný dynamický obsah nebyl měřen.'),url:issue.url,fix:unsupported?'Zajistěte také přímý veřejný odkaz na cookie dokument.':'Nastavte skutečnou veřejnou adresu dokumentu a ověřte jeho otevření z patičky i lišty.'});});
  pages.filter(p=>p.content_available===false).forEach((p,i)=>findings.push({id:'page-content-'+i,status:'unknown',title:'Obsah stránky nelze vyhodnotit',evidence:p.content_reason,url:p.url,fix:'Zdroj obsahuje výzvu nebo vyžaduje vykreslení JavaScriptu. Výsledek není důkazem chybějící dokumentace.'}));
  policies.forEach((p,i)=>{
   Object.entries(p.checks||{}).forEach(([id,c])=>findings.push({id:'policy-'+i+'-'+id,status:c.status,title:c.label+' — kontrola textu',evidence:c.evidence,url:c.source_url||p.url,outcome:c.outcome,fix:c.recommendation||(c.status==='pass'?'Informace byla v kontrolovaném textu nalezena.':'Doplňte uvedenou informaci do cookie dokumentace.')}));
   (p.services||[]).filter(s=>s.status!=='pass').forEach(s=>findings.push({id:'service-'+i+'-'+s.id,status:s.status,title:s.name+(s.status==='unknown'?' — dokument nedostupný':' — nenalezena zmínka v dokumentaci'),evidence:s.evidence,url:p.url,fix:'Ověřte, zda službu skutečně používáte. Pokud ano, zkontrolujte její účely a odpovídající informace v dokumentaci.'}));
   (p.cookies||[]).filter(c=>c.status!=='pass').forEach(c=>findings.push({id:'cookie-'+i+'-'+c.name,status:c.status,title:'Cookie '+c.name+' — nenalezena v dokumentaci',evidence:'Cookie byla vrácena v HTTP hlavičce stránky. Její název nebyl v tomto načteném dokumentu nalezen.',url:p.url,fix:'Doplňte tuto cookie do veřejného přehledu s jejím účelem a dobou platnosti. Samotný název neurčuje nutnost souhlasu.'}));
  });
  const scenarios=(report.browser?.results||[]).filter(x=>['pass','warning','error','unknown','not_applicable'].includes(x.status));
  scenarios.forEach(x=>findings.push({id:'dynamic-'+x.id,status:x.status==='not_applicable'?'pass':x.status,title:x.title,evidence:x.evidence||x.reason||'Bez dalšího důkazu.',fix:x.fix||'Výsledek se vztahuje k uvedenému scénáři, prohlížeči a době pozorování.',url:report.url}));
  if(!scenarios.length)findings.push({id:'browser-unavailable',status:'unknown',title:'Prohlížečové měření nebylo provedeno',evidence:report.browser?.reason||'V tomto měření nebyl spuštěn interaktivní prohlížeč. Z výsledků nelze vyvozovat, jak web respektuje souhlas.',fix:'Pro ověření chování lišty a cookies je potřeba prohlížečové měření.',url:report.url});
  if(pages.length)findings.push({id:'coverage',status:'pass',title:'Veřejné stránky byly načteny',evidence:'Úspěšně načtených HTML stránek: '+pages.length+'. Rozsah najdete v přehledu stránek.',fix:'Výsledek platí pro uvedené adresy a čas kontroly.',url:report.url});
  const declaredCookies=Array.from(new Map((report.declared_cookies||[]).map(c=>[[c.name,c.domain,c.category].join('|'),c])).values()),documentationScore=scoreDocumentation(report,policies),declarationAssessment=scoreDeclarations(report,declaredCookies);
  const total=documentationScore.total+declarationAssessment.total,passed=documentationScore.passed+declarationAssessment.passed;
  const complete=documentationScore.percent!==null&&!(report.declaration_errors||[]).length&&(!(report.declaration_sources||[]).length||declarationAssessment.percent!==null);
  const declarationCriteria=declarationAssessment.categories.flatMap(c=>c.records.flatMap(r=>r.checks.map(check=>({label:r.name+' · '+check.label,status:check.pass?'pass':'error',outcome:check.pass?'found':'missing'}))));
  const overviewScore={...documentationScore,scope:'public_evidence',criteria:[...documentationScore.criteria,...declarationCriteria],total,passed,percent:complete?(passed===total?100:Math.min(99,Math.round(passed/total*100))):null};
  return {overviewScore,declaredCookies,declarationAssessment,documentationScore,services,cookies,scenarios,cookiePolicies:policies,findings,errors:findings.filter(x=>x.status==='error').length,warnings:findings.filter(x=>x.status==='warning').length,passed:findings.filter(x=>x.status==='pass').length,unknown:findings.filter(x=>x.status==='unknown').length};
 }
 function scoreDocumentation(report,policies){
  const criteria=[],ids=['purposes','retention','withdrawal','choice','inventory','implied_consent'];
  const labels={purposes:'Účely používání cookies',retention:'Platnost a životnost cookies',withdrawal:'Změna a odvolání souhlasu',choice:'Odmítnutí volitelných cookies',inventory:'Přehled konkrétních cookies',implied_consent:'Formulace souhlasu pouhou návštěvou'};
  const rank={conflict:4,found:3,not_detected:3,missing:2,unavailable:0};
  for(const id of ids){
   const candidates=policies.map(p=>p.checks?.[id]).filter(Boolean).sort((a,b)=>(rank[b.outcome]??(b.status==='pass'?3:1))-(rank[a.outcome]??(a.status==='pass'?3:1)));
   const missing=report.policy_discovery?.status==='missing'&&['complete','partial'].includes(report.state);
   const c=candidates[0]||(missing?{status:'error',outcome:'missing',evidence:'Cookie dokument nebyl v kontrolovaných stránkách nalezen; splnění tohoto kritéria není doloženo.'}:{status:'unknown',outcome:'unavailable',evidence:'Obsah dokumentace nebyl dostupný pro tuto kontrolu.'});
   criteria.push({...c,id,label:c.label||labels[id]});
  }
  for(const type of ['services','cookies']){
   const seen=new Map();
   for(const p of policies)for(const entry of p[type]||[]){const key=entry.id||entry.name;const old=seen.get(key);if(!old||entry.status==='pass'||(old.status==='unknown'&&['warning','error'].includes(entry.status)))seen.set(key,entry);}
   for(const [key,c] of seen)criteria.push({...c,id:type+'-'+key,label:(type==='services'?'Popis služby: ':'Popis cookie: ')+c.name,outcome:c.status==='pass'?'found':c.status==='unknown'?'unavailable':'missing'});
  }
  const unavailableDocuments=new Map();
  for(const p of policies)for(const d of p.documents||[])if(d.available===false||d.text_truncated||d.dynamic_content_available===false)unavailableDocuments.set(d.url,{url:d.url,message:d.text_truncated?'Text dokumentu překročil limit kontroly.':'Text dokumentu nebyl dostupný.'});
  for(const e of (report.errors||[]).filter(e=>e.kind==='policy'))unavailableDocuments.set(e.url,e);
  for(const [url,error] of unavailableDocuments)criteria.push({id:'document-load-'+url,label:'Dostupnost celého dokumentu: '+url,status:'unknown',outcome:'unavailable',evidence:error.message});
  const passed=criteria.filter(c=>c.status==='pass').length,unavailable=criteria.filter(c=>!['pass','warning','error'].includes(c.status)).length;
  const complete=['complete','partial'].includes(report.state)&&(policies.length>0||report.policy_discovery?.status==='missing')&&unavailable===0;
  return {scope:'documentation',percent:complete?(passed===criteria.length?100:Math.min(99,Math.round(passed/criteria.length*100))):null,passed,total:criteria.length,unavailable,criteria};
 }
 function scoreDeclarations(report,records){
  const labels={necessary:'Nezbytné',functional:'Funkční',analytics:'Analytické',performance:'Výkonnostní',advertisement:'Reklamní',other:'Nezařazené'};
  const categories=Object.entries(labels).map(([id,label])=>({id,label,records:[],passed:0,total:0,percent:null}));
  const has=v=>typeof v==='string'&&v.trim()!==''&&!/^(?:neuvedeno|není uvedeno|unknown|not specified|description is currently not available)[.!]?$/i.test(v.trim());
  for(const record of records){
   const category=categories.find(c=>c.id===record.category)||categories.find(c=>c.id==='other');
   const checks=[['Název položky',typeof record.name==='string'&&record.name.trim()!==''],['Doména',has(record.domain)],['Doba trvání',has(record.lifetime)],['Popis účelu',has(record.description)],['Zařazení do známé kategorie',record.category!=='other'&&Object.hasOwn(labels,record.category)]];
   const row={...record,checks:checks.map(([label,pass])=>({label,pass})),missing:checks.filter(([,pass])=>!pass).map(([label])=>label)};
   category.records.push(row);category.total+=checks.length;category.passed+=checks.filter(([,pass])=>pass).length;
  }
  const failed=!!(report.declaration_errors||[]).length;
  for(const c of categories)if(c.total&&!failed)c.percent=c.passed===c.total?100:Math.min(99,Math.round(c.passed/c.total*100));
  const total=categories.reduce((n,c)=>n+c.total,0),passed=categories.reduce((n,c)=>n+c.passed,0);
  return {scope:'declared_metadata',categories,passed,total,percent:failed||!total?null:passed===total?100:Math.min(99,Math.round(passed/total*100))};
 }
 function csvCell(v){v=String(v??'');if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replaceAll('"','""')+'"';}
 function policyOutcomeLabel(c){return ({found:'Nalezeno v dokumentaci',missing:'Chybí v dostupné dokumentaci',conflict:'Rozporná formulace',not_detected:'Problematická formulace nenalezena',unavailable:'Dokument nedostupný'})[c.outcome]||(c.status==='pass'?'Nalezeno v textu':'Automaticky nerozpoznáno');}
 root.GCAModel={summarize,csvCell,policyOutcomeLabel};
})(typeof window!=='undefined'?window:globalThis);
