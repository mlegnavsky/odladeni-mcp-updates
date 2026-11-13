'use strict';
function cookieCategory(name){
 if(/^(?:cookieyes-consent|CookieConsent|Optanon|cmplz_|wordpress_|PHPSESSID|wp-settings)/i.test(name))return 'necessary';
 if(/^(?:_ga(?:_|$)|_gid$|_gat|_hj|_clck$|_clsk$)/i.test(name))return 'analytics';
 if(/^(?:_fbp$|_fbc$|_gcl_|IDE$|test_cookie$|_ttp$|_tt_enable_cookie$)/i.test(name))return 'marketing';
 return 'unknown';
}
function cookieMetadata(c,url){return {name:c.name,domain:c.domain,path:c.path||'/',secure:!!c.secure,httpOnly:!!c.httpOnly,sameSite:c.sameSite||'neuvedeno',lifetime:c.expires>0?new Date(c.expires*1000).toISOString():'relace / neuvedeno',category:cookieCategory(c.name),url,basis:'browser'};}
function evaluateScenario(id,observed){
 const known=(observed.cookies||[]).filter(c=>['analytics','marketing'].includes(c.category));
 const titles={before:'Cookies před souhlasem',reject:'Respektování odmítnutí',accept:'Přijetí souhlasu',withdraw:'Odvolání souhlasu',analytics:'Pouze analytické cookies',marketing:'Pouze reklamní cookies',persistence:'Zapamatování volby'};
 const result={id,title:titles[id]||id,status:'unknown',evidence:'Scénář se nepodařilo ověřit.'};
 if(id!=='before'&&!observed.action)return {...result,evidence:'Ovládací prvek cookie lišty se nepodařilo bezpečně použít; test neproveden.'};
 if(id==='before'||id==='reject'){
  result.status=known.length?'error':'pass';result.evidence=known.length?'V tomto scénáři byly zjištěny cookies běžně používané pro analytiku nebo reklamu: '+known.map(c=>c.name).join(', '):'V pozorované době nebyly zjištěny rozpoznané analytické ani reklamní cookies. Neznámé cookies vyžadují samostatné určení účelu.';
 }else if(id==='accept'){
  result.status='pass';result.evidence='Byl použit ovládací prvek pro přijetí souhlasu. Po volbě bylo zjištěno '+(observed.cookies||[]).length+' cookies.';
 }else if(id==='withdraw'){
  result.status=!observed.consentChanged?'unknown':known.length?'warning':'pass';result.evidence=!observed.consentChanged?'Nepodařilo se potvrdit změnu uložené volby.':known.length?'Volba byla změněna, ale v úložišti zůstaly dříve nastavené analytické nebo reklamní cookies. To samo o sobě neprokazuje další sledování.':'Byla ověřena změna uložené volby; rozpoznané analytické ani reklamní cookies poté nebyly nalezeny.';
 }else if(['analytics','marketing'].includes(id)){
  const disallowed=known.filter(c=>c.category!==id);result.status=disallowed.length?'error':'pass';result.evidence=disallowed.length?'Po výběru jedné kategorie byly zjištěny cookies jiné volitelné kategorie: '+disallowed.map(c=>c.name).join(', '):'Po výběru kategorie nebyly zjištěny rozpoznané cookies jiné volitelné kategorie.';
 }else if(id==='persistence'){
  result.status=known.length?'error':observed.consentPersisted?'pass':'unknown';result.evidence=known.length?'Po opětovném načtení byly přes odmítnutí zjištěny rozpoznané analytické nebo reklamní cookies.':observed.consentPersisted?'Uložená volba byla zachována i po dalším načtení stránky.':'Zapamatování volby nebylo možné potvrdit.';
 }
 return result;
}
module.exports={cookieCategory,cookieMetadata,evaluateScenario};
