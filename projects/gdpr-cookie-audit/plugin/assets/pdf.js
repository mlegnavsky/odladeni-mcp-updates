(function(root){'use strict';
function buildPdf(report){
 const p=report.presentation;if(!p)throw Error('Chybí vyhodnocení měření.');
 const d=new root.jspdf.jsPDF({unit:'mm',format:'a4',compress:true});root.GCAFont(d);
 const ink='#16364d',muted='#647b8d',blue='#476ed8',good='#147760',amber='#956609',bad='#b93648';
 const tone=n=>n===null?muted:n>=90?good:n>=65?amber:bad,score=n=>n===null?'—':n+' %';
 let y=20,page=1;
 function txt(t,x,y,size=10,color=ink,w=178){d.setFont('GCA','normal');d.setFontSize(size);d.setTextColor(color);const ls=d.splitTextToSize(String(t),w);d.text(ls,x,y);return ls.length*size*.45;}
 function box(x,y,w,h,color='#f5f7fb'){d.setFillColor(color);d.roundedRect(x,y,w,h,3,3,'F');}
 function footer(){txt('GDPR-COOKIES.CZ   /   Technické hodnocení   /   '+page,16,285,8,muted);txt('Výsledek platí pro uvedený rozsah a čas. Není právní certifikací.',16,290,7,muted);}
 function next(){footer();d.addPage();page++;y=22;}
 function heading(t){txt(t,16,y,18);y+=12;}
 d.setProperties({title:'Přehled měření cookies — '+report.url,author:'GDPR-Cookies.cz'});
 txt('COOKIE AUDIT / PŘEHLED VÝSLEDKŮ',16,20,9,blue);
 txt(new URL(report.url).hostname,16,34,25);txt(new Date(report.started_at).toLocaleString('cs-CZ'),16,43,9,muted);
 box(16,53,178,56,ink);txt('CELKOVÉ TECHNICKÉ HODNOCENÍ',23,64,8,'#c8d7eb');txt(score(p.percent),23,88,38,'#ffffff');txt(p.conclusion,91,70,15,'#ffffff',94);txt(p.critical+' zásadních problémů   /   '+p.performed+' testů lišty',91,97,9,'#c8d7eb',94);
 p.areas.forEach((a,i)=>{const x=16+i*45;box(x,117,42,43);txt(a.label,x+4,124,9,ink,34);txt(score(a.percent),x+4,143,22,tone(a.percent));txt(a.passed+' / '+a.total+' doloženo',x+4,154,7,muted,34);});
 y=176;heading('Co vyžaduje pozornost');
 const issues=p.issues.slice(0,3);if(!issues.length){txt('V provedených kontrolách nebyl zjištěn problém.',16,y,10,good);y+=12;}
 issues.forEach(it=>{box(16,y-4,178,14,it.severity==='critical'?'#ffeaee':'#f5f7fb');txt(it.title,20,y+2,10,it.severity==='critical'?bad:ink,169);y+=19;});
 if(p.issues.length>3)txt('Další nálezy najdete v uloženém přehledu měření.',16,251,8,muted);
 txt('Úplnost: '+p.performed+' provedených testů lišty, '+p.unfinished+' nedokončeno.',16,263,8,muted);
 next();heading('Kategorie cookies');txt('Doložené údaje a nezávisle rozpoznané zařazení',16,y,10,muted);y+=12;
 p.categories.forEach(c=>{txt(c.label+'   ·   '+c.count+' položek',16,y,11);txt(score(c.percent),175,y,11,tone(c.percent));box(16,y+4,178,4,'#edf1f7');if(c.percent){d.setFillColor(tone(c.percent));d.roundedRect(16,y+4,178*c.percent/100,4,1,1,'F');}y+=20;});
 y+=5;heading('Cookies podle stavu souhlasu');const max=Math.max(1,...p.phases.map(x=>x.cookies||0)),base=y+39;
 p.phases.forEach((o,i)=>{const x=22+i*44;const h=o.cookies===null?0:o.cookies/max*32;if(h){d.setFillColor(blue);d.roundedRect(x,base-h,24,h,2,2,'F');}txt(o.cookies===null?'—':o.cookies,x+8,base-h-4,15,blue);txt(o.label,x-3,base+7,8,ink,40);txt(o.storage===null?'Nezměřeno':o.storage+' záznamů úložišť',x-3,base+16,7,muted,40);});
 txt('Počet sám neurčuje chybu. Rozhoduje účel a povolené kategorie.',16,base+32,8,muted);
 next();heading('Snímky z měření');
 const images=report.screenshots||report.browser?.screenshots||[];
 images.slice(0,4).forEach((im,i)=>{if(!/^data:image\/jpeg;base64,[A-Za-z0-9+/=]+$/.test(im.data_url||''))return;const x=16+(i%2)*91,top=38+Math.floor(i/2)*75,prop=d.getImageProperties(im.data_url),w=Math.min(85,58*prop.width/prop.height),h=w*prop.height/prop.width;box(x,top,87,62);d.addImage(im.data_url,'JPEG',x+(87-w)/2,top+2,w,h);txt(im.title,x,top+68,8,muted,85);});
 y=196;heading('Rozsah a způsob hodnocení');
 const notes=[report.pages.length+' načtených stránek; nejvýše '+(report.scope||5)+' veřejných stránek. Zachyceno '+p.inventory.cookies+' cookies a '+p.inventory.storage+' položek dalších úložišť.', 'Celkové skóre je průměr čtyř oblastí. Kritéria mají po jednom bodu. Nerozpoznaný účel nemá bod za zařazení. Nedokončená oblast brání celkovému procentu. Metodika '+p.method+'.', 'Výsledek zachycuje uvedené scénáře v daném okamžiku, nikoli všechny možné cookies celého webu. Hodnoty cookies neukládáme.'];
 for(const n of notes)y+=txt(n,16,y,8,muted)+5;
 if(report.report_url){d.link(16,267,178,8,{url:report.report_url});txt('Otevřít uložené měření a aktuální přehled ↗',16,271,10,blue);}footer();return d;
}
root.GCAPdf={buildPdf};})(typeof window!=='undefined'?window:globalThis);
