(function(root){
'use strict';
function buildPdf(report){
 const {jsPDF}=root.jspdf, doc=new jsPDF({unit:'mm',format:'a4',compress:true});
 root.GCAFont(doc);const s=root.GCAModel.summarize(report);let y=24,page=1;
 const colors={ink:[24,54,76],muted:[87,107,123],blue:[50,103,220],error:[181,54,50],warning:[152,107,10],pass:[22,119,94],unknown:[98,115,132]};
 const labels={error:'Problém',warning:'K prověření',pass:'Zjištěno',unknown:'Neověřeno',not_applicable:'Netýká se tohoto webu'};
 function footer(){doc.setFontSize(8);doc.setTextColor(...colors.muted);doc.text('GDPR-COOKIES.CZ  /  Technická zpráva  /  '+page,16,286);doc.text('Automatická kontrola není právní certifikací.',16,291);}
 function space(h){if(y+h>274){footer();doc.addPage();page++;y=22;}}
 function text(t,size=10,color='ink',gap=4){doc.setFont('GCA','normal');doc.setFontSize(size);doc.setTextColor(...(colors[color]||colors.ink));const lines=doc.splitTextToSize(String(t||''),178);for(const line of lines){space(size*.45+gap);doc.text(line,16,y);y+=size*.45+1;}y+=gap;}
 function title(t){space(18);y+=5;text(t,16,'ink',5);}
 function rule(){space(5);doc.setDrawColor(220,229,237);doc.line(16,y,194,y);y+=7;}
 doc.setProperties({title:'Kontrola cookies — '+report.url,subject:'Technická kontrola veřejných stránek a zásad cookies',author:'GDPR-Cookies.cz',creator:'GDPR Cookies Audit'});
 text('GDPR COOKIES / ZPRÁVA O KONTROLE',9,'blue',8);
 text('Přehled cookies a dokumentace',25,'ink',8);
 text(report.url,13,'blue',4);
 text('Měření: '+new Date(report.started_at).toLocaleString('cs-CZ'),10,'muted');
 text('Stav: '+({complete:'zdrojová kontrola dokončena',partial:'částečný výsledek',failed:'kontrolu se nepodařilo provést',running:'nedokončená kontrola'}[report.state]||report.state),10,'muted');
 text('Rozsah: '+(report.pages||[]).length+' načtených stránek, '+s.cookiePolicies.length+' dokumentů. Verze metodiky 1.0.',10,'muted');
 rule();
 text('Kontrola cookies a cookie lišty',14,'ink');
 text(s.scenarios.length?'Zpráva spojuje kontrolu cookie dokumentace, zdrojů a uvedených skutečně provedených prohlížečových scénářů. Výsledek platí pro testované stránky a dobu pozorování.':'Zpráva vychází z HTTP odpovědí a zdroje stránek. Prohlížečové scénáře nebyly provedeny. Nalezená integrace neprokazuje její spuštění ani porušení pravidel.',10,'muted',8);
 text('Rozsah se týká pouze cookies. Obecné zásady ochrany osobních údajů nejsou předmětem tohoto měřáku.',9,'muted',6);
 const metrics=[['Problémy',s.errors,'error'],['K prověření',s.warnings,'warning'],['Zjištěno',s.passed,'pass'],['Neověřeno',s.unknown,'unknown']];
 space(32);metrics.forEach(([label,n,c],i)=>{const x=16+i*45;doc.setFillColor(244,247,250);doc.roundedRect(x,y,42,29,3,3,'F');doc.setTextColor(...colors[c]);doc.setFontSize(22);doc.text(String(n),x+4,y+12);doc.setFontSize(8);doc.setTextColor(...colors.muted);doc.text(label,x+4,y+22);});y+=39;
 text('Poměr výsledků jednotlivých kontrol',12,'ink');space(14);
 const total=metrics.reduce((a,m)=>a+m[1],0)||1;let x=16;metrics.forEach(([,n,c])=>{const w=n/total*178;if(w){doc.setFillColor(...colors[c]);doc.rect(x,y,w,5,'F');x+=w;}});y+=14;
 title('Co řešit jako první');
 const priorities=s.findings.filter(f=>['error','warning'].includes(f.status)).slice(0,3);
 if(!priorities.length)text('V dostupném zdrojovém auditu nebylo nalezeno upozornění. Neověřené kontroly zůstávají otevřené.');
 priorities.forEach((f,i)=>{text((i+1)+'. '+f.title,11);text(f.fix,9,'muted');});
 title('Nalezené integrace ve zdroji');
 text('Graf uvádí počet načtených stránek s nalezenou integrací. Nejde o počet spuštění ani skutečně odeslaných požadavků.',9,'muted');
 if(!s.services.length)text('Známé integrace nebyly v načteném zdrojovém kódu rozpoznány. To nevylučuje dynamicky načítané služby.');
 s.services.forEach(service=>{space(25);text(service.name+' — '+service.pages.length+' stránek',10);space(8);doc.setFillColor(...colors.blue);doc.roundedRect(16,y,Math.max(2,service.pages.length/Math.max(1,report.pages.length)*178),3,1,1,'F');y+=10;text('Zdrojová stopa: '+service.evidence,8,'muted');service.pages.forEach(url=>text('Stránka: '+url,8,'blue'));});
 title('Rozsah měření');
 (report.pages||[]).forEach(p=>{text(p.url,9,'blue');text((p.title||'Bez titulku')+' | HTTP '+p.status+' | integrace: '+p.services.length+' | HTTP cookies: '+p.cookies.length,8,'muted');});
 (report.errors||[]).forEach(e=>text('NEOVĚŘENO: '+e.url+' — '+e.message,9,'error'));
 title('Cookies z HTTP odpovědí');
 text(s.scenarios.length?'Hodnoty cookies se neukládají. Rozsah prohlížečového měření je uveden u jednotlivých scénářů.':'Hodnoty cookies se neukládají. JavaScriptem vytvořené cookies nebyly měřeny.',9,'muted');
 if(!s.cookies.length)text('V měřených HTTP odpovědích nebyla nalezena hlavička Set-Cookie. To neznamená, že web nepoužívá cookies.');
 s.cookies.forEach(c=>{space(26);text(c.name+' | '+c.domain,11);text('Cesta: '+c.path+' | Životnost: '+c.lifetime,9,'muted');text('Secure: '+(c.secure?'ano':'ne')+' | HttpOnly: '+(c.httpOnly?'ano':'ne')+' | SameSite: '+c.sameSite,9,'muted');text('Zdroj: '+c.url,8,'muted');});
 title('Dokumentace cookies');
 if(!s.cookiePolicies.length)text('Dokumentaci se nepodařilo automaticky ověřit. Viz doporučení v nálezech.');
 s.cookiePolicies.forEach(p=>{text(p.url,10,'blue');Object.values(p.checks||{}).forEach(c=>{text(c.label+' — '+(c.status==='pass'?'zmínka nalezena':c.status==='unknown'?'neověřeno':'k prověření'),10);text(c.evidence,9,'muted');});(p.services||[]).forEach(c=>{text(c.name+' — '+(c.status==='pass'?'zmínka nalezena':'zmínka nenalezena'),10);text(c.evidence,9,'muted');});});
 title('Podrobné nálezy a doporučené kroky');
 s.findings.forEach((f,i)=>{space(24);text((i+1)+'. '+f.title,12,f.status);text(labels[f.status],8,f.status);text('Zjištění: '+f.evidence,9);text('Doporučení: '+f.fix,9,'muted');if(f.url)text('Stránka: '+f.url,8,'blue');rule();});
 title('Prohlížečové scénáře');
 if(!s.scenarios.length)text('V tomto měření neprovedeno. Chování souhlasu a JavaScriptových cookies nebylo ověřeno.',10,'unknown');
 s.scenarios.forEach(x=>{text(x.title+' — '+(labels[x.status]||x.status),12,x.status);text(x.evidence||x.reason||'',9);});
 title('Metodika a omezení');
 text('Maximálně pět veřejných HTML stránek včetně dokumentace. Kontrola běží bez přihlášení a bez odesílání formulářů. Detekce známých služeb je založena na zdrojových stopách; samotné nalezení služby není důkazem jejího spuštění. Kontrola dokumentace vyhledává zmínky a nehodnotí jejich právní správnost. Rozdíl v dokumentaci je podnětem k ověření. Tento výsledek není posouzením úplného souladu s GDPR ani zárukou bezchybnosti.',9,'muted');
 text('Zdroje metodiky: ÚOOÚ — otázky a odpovědi k cookies; Google — Consent Mode overview.',9,'muted');
 text('https://uoou.gov.cz/verejnost/qa-otazky-a-odpovedi/cookies',8,'blue');
 text('https://developers.google.com/tag-platform/security/concepts/consent-mode',8,'blue');
 footer();return doc;
}
root.GCAPdf={buildPdf};
})(typeof window!=='undefined'?window:globalThis);
