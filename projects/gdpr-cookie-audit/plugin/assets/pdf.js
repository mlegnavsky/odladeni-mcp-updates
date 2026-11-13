(function(root){'use strict';
function buildPdf(report){
 const p=report.presentation;if(!p)throw Error('Chybí vyhodnocení měření.');
 const d=new root.jspdf.jsPDF({unit:'mm',format:'a4',compress:true});root.GCAFont(d);d.setLineHeightFactor(1.18);
 const C={ink:'#10213e',muted:'#64748b',blue:'#1254e8',good:'#15956f',amber:'#bc7909',bad:'#c83949',line:'#dce4f0',paper:'#f1f5fc'};
 const score=n=>typeof n==='number'&&Number.isFinite(n)&&n>=0&&n<=100?n:null,tone=n=>n===null?C.muted:n>=90?C.good:n>=65?C.amber:C.bad;
 const labels={documentation:'Dokumentace cookies',behavior:'Chování souhlasu',classification:'Zařazení cookies',appearance:'Vzhled lišty'};
 const critical=p.critical||0,errors=p.totals?.errors||0,warnings=p.totals?.warnings||0,overall=score(p.percent),coverage=p.coverage||{completed:0,total:4,percent:0},incomplete=overall===null;
 let page=1,y=20;const W=178;
 function text(t,x,y,size=10,color=C.ink,width=W){d.setFont('GCA','normal');d.setFontSize(size);d.setTextColor(color);const lines=d.splitTextToSize(String(t??''),width);d.text(lines,x,y);return lines.length*size*.416;}
 function box(x,y,w,h,color='#ffffff',border=true){d.setLineWidth(.2);d.setFillColor(color);d.setDrawColor(C.line);d.roundedRect(x,y,w,h,3,3,border?'FD':'F');}
 function footer(){text('GDPR COOKIES  /  Přehled měření  /  '+page,16,285,7,C.muted);text('Výsledek platí pro uvedený rozsah a čas. Není právní certifikací.',16,290,7,C.muted);}
 function next(){footer();d.addPage();page++;y=22;}
 function space(height){if(y+height>275)next();}
 function link(){if(report.report_url){d.link(16,267,W,9,{url:report.report_url});text('Otevřít uložené měření a zvětšit snímky >',16,272,10,C.blue);}}
 function ring(x,y,n,color){d.setDrawColor('#e5eaf3');d.setLineWidth(3);d.circle(x,y,15,'S');if(n>0){d.setDrawColor(color);const parts=Math.max(1,Math.round(n*1.8)),points=[];let px=x,py=y-15;for(let i=1;i<=parts;i++){const angle=(-90+i/parts*n*3.6)*Math.PI/180,nx=x+15*Math.cos(angle),ny=y+15*Math.sin(angle);points.push([nx-px,ny-py]);px=nx;py=ny;}d.lines(points,x,y-15,[1,1],'S',false);}text(n+'%',x-11,y+3,23,C.ink,28);}
 d.setProperties({title:'Výsledek kontroly cookies - '+report.url,author:'GDPR-Cookies.cz'});
 text('GDPR COOKIES',16,17,10,C.blue);text('VÝSLEDEK MĚŘENÍ',148,17,8,C.muted,46);
 text('Výsledek kontroly cookies',16,31,22);let host;try{host=new URL(report.url).hostname;}catch{host=report.url;}
 const hh=text(host,16,40,11,C.ink);text(new Date(report.started_at).toLocaleString('cs-CZ'),16,42+hh,8,C.muted);y=49+hh;
 box(16,y,W,46);ring(39,y+23,incomplete?coverage.percent:overall,incomplete?C.muted:critical?C.bad:tone(overall));
 text(incomplete?'ROZSAH VYHODNOCENÍ':'CELKOVÉ HODNOCENÍ',62,y+10,7,C.muted,77);
 text(incomplete?'Hodnocení nedokončené':critical?'Zásadní problém':p.issues.length?'Vyžaduje úpravy':'V pořádku',62,y+21,14,critical?C.bad:incomplete?C.muted:p.issues.length?C.amber:C.good,77);
 text(coverage.completed+' ze 4 oblastí vyhodnoceno'+(incomplete?'. Celkové skóre zatím nelze určit.':''),62,y+34,8,C.muted,74);
 box(145,y+8,41,14,critical||errors?'#ffedf0':'#eaf8f1',false);text(critical||errors,149,y+18,17,critical||errors?C.bad:C.good,10);text(critical?'Zásadní\nproblémy':'Problémy\nk opravě',161,y+13,7,C.ink,22);
 box(145,y+25,41,14,'#fff3da',false);text(warnings,149,y+35,17,C.amber,10);text('Doporučení',161,y+33,7,C.ink,22);y+=53;
 p.areas.forEach((a,i)=>{const x=16+i*45.5,n=score(a.percent);box(x,y,41.5,34);text(labels[a.id]||a.label,x+3,y+6,8,C.ink,35.5);text(n===null?'Nezměřeno':n+' %',x+3,y+22,n===null?12:22,tone(n),35.5);d.setFillColor('#e5eaf2');d.roundedRect(x+3,y+26,35.5,1.7,.8,.8,'F');if(n){d.setFillColor(tone(n));d.roundedRect(x+3,y+26,35.5*n/100,1.7,.8,.8,'F');}text(n===null?'Nedokončené hodnocení':a.passed+' z '+a.total+' doloženo',x+3,y+31,6.5,C.muted,35.5);});y+=43;
 const top=y;box(16,top,86,68);box(108,top,86,68);text('Co potřebuje pozornost',20,top+8,11,C.ink,78);text('Co web skutečně ukládá',112,top+8,10,C.ink,78);
 const issues=p.issues.slice(0,3);if(!issues.length)text(incomplete?'Nejprve dokončete měření.':'V provedených kontrolách nebyl zjištěn problém.',20,top+20,8,C.muted,78);
 let iy=top+18;for(const issue of issues){d.setFillColor(issue.severity==='warning'?C.amber:C.bad);d.circle(21,iy-1,1.2,'F');const h=text(issue.title,25,iy,8,C.ink,71);iy+=Math.max(12,h+5);}
 if(p.issues.length>3)text('Další nálezy jsou v uloženém přehledu.',20,top+61,7,C.muted,78);
 const phases=p.phases||[],max=Math.max(1,...phases.map(o=>o.cookies||0)),colors={necessary:'#b8c0ce',analytics:'#5484f4',advertisement:'#f07482',other:'#9b89c9'};
 phases.slice(0,4).forEach((o,i)=>{const yy=top+20+i*9;text(o.label,112,yy,7,C.ink,29);d.setFillColor('#edf1f7');d.roundedRect(141,yy-3,39,3,1,1,'F');let x=141;for(const category of Object.keys(colors)){const count=o.breakdown?.[category]||0,w=count/max*39;if(w){d.setFillColor(colors[category]);d.rect(x,yy-3,w,3,'F');x+=w;}}text(o.cookies===null?'n/a':o.cookies,183,yy,8,o.cookies===null?C.muted:C.ink,8);});
 [['necessary','Nezbytné'],['analytics','Analytické'],['advertisement','Reklamní'],['other','Další']].forEach(([key,label],i)=>{const x=112+(i%2)*39,yy=top+58+Math.floor(i/2)*5;d.setFillColor(colors[key]);d.roundedRect(x,yy-2,2.3,2.3,.5,.5,'F');text(label,x+3.5,yy,6,C.muted,32);});y=top+77;
 space(38);text('Hodnocení kategorií cookies',16,y,10);y+=7;
 p.categories.forEach((c,i)=>{const x=16+(i%2)*92,yy=y+Math.floor(i/2)*11,n=score(c.percent);text(c.label,x,yy,8,C.ink,56);text(n===null?'Bez položek':n+' %',x+58,yy,7,tone(n),28);d.setFillColor('#e5eaf2');d.roundedRect(x,yy+2,84,1.5,.7,.7,'F');if(n){d.setFillColor(tone(n));d.roundedRect(x,yy+2,84*n/100,1.5,.7,.7,'F');}});
 next();text('Snímky z měření',16,y,20);y+=9;text('Malé náhledy. Plnou velikost otevřete v uloženém měření.',16,y,9,C.muted);y+=9;
 const images=(report.screenshots||report.browser?.screenshots||[]).filter(im=>/^data:image\/jpeg;base64,[A-Za-z0-9+/=]+$/.test(im.data_url||'')).slice(0,4);
 if(!images.length){box(16,y,W,24,C.paper);text('Toto měření neobsahuje snímky z prohlížeče.',21,y+13,10,C.muted);y+=36;}
 else{const start=y;images.forEach((im,i)=>{const x=16+(i%2)*92,yy=start+Math.floor(i/2)*69,properties=d.getImageProperties(im.data_url),w=Math.min(81,47*properties.width/properties.height),h=w*properties.height/properties.width;box(x,yy,86,51,C.paper);d.addImage(im.data_url,'JPEG',x+(86-w)/2,yy+(51-h)/2,w,h);text(im.title,x,yy+57,8,C.ink,86);});y=start+Math.ceil(images.length/2)*69+5;}
 if(p.eu){space(150);text('Podmínky EU pro cookies',16,y,13);y+=8;y+=text(p.eu.label,16,y,10,p.eu.status==='fail'?C.bad:p.eu.status==='pass'?C.good:C.amber)+3;for(const c of p.eu.checks){space(10);const status=({pass:'Doloženo',fail:'Problém',not_documented:'Nedoloženo',unknown:'Nezměřeno',warning:'Upozornění'})[c.status]||'Nezměřeno';const h=text(c.label,16,y,8,C.ink,130);text(status,151,y,7,c.status==='fail'?C.bad:c.status==='pass'?C.good:C.amber,42);y+=Math.max(5,h+1);}space(19);y+=text(p.eu.scope,16,y+2,7,C.muted)+6;}
 space(58);text('Jak číst výsledek',16,y,13);y+=9;
 const notes=[incomplete?'Celkové hodnocení není dokončené. Zobrazené procento rozsahu udává pouze podíl vyhodnocených oblastí.':'Celkové skóre je průměr čtyř oblastí se stejnou vahou. Zásadní problém je zvýrazněný bez ohledu na průměr.', 'Načteno '+report.pages.length+' stránek. Prohlížečové testy: '+p.performed+' provedeno, '+p.unfinished+' nedokončeno. Metodika '+p.method+'.', 'Skóre hodnotí doložená kritéria v daném měření. Nerozpoznaný účel není potvrzením správného zařazení. Hodnoty cookies neukládáme. Podrobné důkazy a doporučené úpravy jsou v interní analýze správce.'];
 for(const note of notes){const h=text(note,16,y,8,C.muted);y+=h+5;}
 if(y>260)next();link();footer();return d;
}
root.GCAPdf={buildPdf};})(typeof window!=='undefined'?window:globalThis);
