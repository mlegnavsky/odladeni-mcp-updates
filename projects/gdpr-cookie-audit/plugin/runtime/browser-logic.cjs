'use strict';
const {evaluateScenario}=require('./scenario-logic.cjs');
function requestMetadata(raw,method){const u=new URL(raw);let category='resource';
 if(/(^|\.)google-analytics\.com$/.test(u.hostname)&&/\/(?:g\/)?collect$/.test(u.pathname))category='analytics';
 if(/(^|\.)facebook\.com$/.test(u.hostname)&&u.pathname==='/tr/')category='marketing';
 if(/(^|\.)(?:doubleclick\.net|googleadservices\.com)$/.test(u.hostname)&&/conversion|activity/.test(u.pathname))category='marketing';
 return {url:u.origin+u.pathname,method,category,deniedSignal:u.searchParams.get('gcs')==='G100'};
}
function contrastRatio(a,b){const l=c=>c.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((v,x,i)=>v+x*[.2126,.7152,.0722][i],0);return (Math.max(l(a),l(b))+.05)/(Math.min(l(a),l(b))+.05);}
function resultFor(id,observed){const r=evaluateScenario(id,observed);
 if(['before','reject','persistence'].includes(id)&&r.status==='pass'){
  const network=(observed.requests||[]).filter(n=>['analytics','marketing'].includes(n.category)&&!n.deniedSignal);
  if(network.length){r.status='warning';r.evidence+=' Byly také pozorovány analytické/reklamní síťové požadavky bez rozpoznaného signálu odmítnutí. Požadavek sám neurčuje právní přípustnost.';}
 }
 return r;
}
module.exports={requestMetadata,contrastRatio,resultFor};
function consentChoices(cookies){const out={};for(const c of cookies){if(!/cookieyes-consent|CookieConsent|OptanonConsent|cmplz_/i.test(c.name))continue;let value;try{value=decodeURIComponent(c.value);}catch{value=c.value;}
 for(const m of value.matchAll(/["']?(analytics|advertisement|marketing|statistics|functional|preferences|performance|C0002|C0003|C0004)["']?\s*[:=]\s*["']?(yes|no|true|false|1|0|allow|deny)/gi)){const key=({advertisement:'marketing',statistics:'analytics',C0002:'analytics',C0003:'functional',C0004:'marketing',preferences:'functional'})[m[1]]||m[1].toLowerCase();out[key]=/^(yes|true|1|allow)$/i.test(m[2]);}
 if(/^cmplz_(statistics|marketing|preferences)$/.test(c.name))out[c.name.slice(6).replace('statistics','analytics')]=/^(allow|yes|true|1)$/.test(value);
 }return out;}
function withdrawalConfirmed(before,after){const a=consentChoices(before),b=consentChoices(after);return Object.values(a).some(Boolean)&&Object.keys(b).length>0&&Object.values(b).every(v=>v===false);}
function exclusiveChoice(cookies,category){const choices=consentChoices(cookies);return choices[category]===true&&Object.entries(choices).every(([k,v])=>k===category||v===false);}
function pageAvailable(status,text){return status>=200&&status<300&&String(text).trim().length>=40&&!/verify you are human|checking your browser|just a moment|access denied|request blocked|ověřte.{0,20}(?:člověk|člověkem)|captcha/i.test(String(text));}
function finalizeScenarios(report,google){const expected=['before','contrast','reject','persistence','accept','withdraw','analytics','marketing','mobile','storage',...(google?['consent-mode']:[])];for(const id of expected)if(!report.results.some(r=>r.id===id))report.results.push({id,title:({before:'Cookies před souhlasem',contrast:'Kontrast textu lišty',reject:'Respektování odmítnutí',persistence:'Zapamatování volby',accept:'Přijetí souhlasu',withdraw:'Odvolání souhlasu',analytics:'Pouze analytické cookies',marketing:'Pouze reklamní cookies',mobile:'Lišta na mobilu',storage:'Další úložiště','consent-mode':'Google Consent Mode v2'})[id],status:'unknown',evidence:'Test nebyl dokončen. '+(report.reason||'')});return report;}
Object.assign(module.exports,{consentChoices,withdrawalConfirmed,exclusiveChoice,pageAvailable,finalizeScenarios});
function mobileResult(measurement,known){if(!measurement)return {id:'mobile',title:'Lišta na mobilu',status:known?'error':'unknown',evidence:known?'Cookie lišta rozpoznaná na počítači nebyla v mobilním okně během pozorování viditelná. Viz skutečný snímek mobilu.':'Lištu se na mobilu nepodařilo rozpoznat; bez znalosti jejího typu nelze její nepřítomnost potvrdit.'};const r=measurement.rect,v=measurement.viewport,fits=r.x>=-1&&r.y>=-1&&r.x+r.width<=v.width+1&&r.y+r.height<=v.height+1;return {id:'mobile',title:'Lišta na mobilu',status:fits?'pass':'error',evidence:fits?'Rozpoznaná lišta se vejde do okna390×844px.':'Rozpoznaná lišta přesahuje viditelnou oblast mobilního okna.',measurement:r};}
module.exports.mobileResult=mobileResult;
