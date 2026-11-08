"""Independent native conversion of the approved v17 public design. No network writes."""
import json,re,copy,hashlib,html
from pathlib import Path
ROOT=Path(__file__).resolve().parent
base=(ROOT/'native_convert.py').read_text()
exec(compile(base.split('parts=[convert_page')[0],str(ROOT/'native_convert.py'),'exec'))
ASSETS=ROOT/'assets';ASSETS.mkdir(exist_ok=True)
asset_manifest=[]

def serialize(n):
 if isinstance(n,str):return html.escape(n)
 return '<'+n.tag+''.join(' '+k+'="'+html.escape(v or '',quote=True)+'"' for k,v in n.attrs.items())+'>'+''.join(serialize(c) for c in n.children)+'</'+n.tag+'>'

def svg_image(n):
 svg=serialize(n).replace('viewbox=', 'viewBox=').replace('<svg ','<svg xmlns="http://www.w3.org/2000/svg" ',1)
 # SVGs use currentColor in the approved source; set the original blue explicitly.
 svg=svg.replace('currentColor','#2377A2')
 filename='ps-'+hashlib.sha256(svg.encode()).hexdigest()[:12]+'.svg'
 (ASSETS/filename).write_text(svg)
 url='https://poslanecka-snemovna.cz/wp-content/uploads/ps-design/'+filename
 if not any(a['file']==filename for a in asset_manifest):asset_manifest.append({'file':filename,'local_path':str(ASSETS/filename),'url':url,'requires_upload':True})
 node=image(url,'Ilustrační vývoj preferencí – nejde o skutečný průzkum' if n.attrs.get('class')=='ps-chart' else 'Ilustrační ikona',100 if n.attrs.get('class')=='ps-chart' else 7)
 if n.attrs.get('class')!='ps-chart':node['settings'].update({'width':sl(32),'width_mobile':sl(28)})
 return node

def native_group(items,row=False,card=False,dark=False,width=100,name='Obsah'):
 s={'content_width':'full','flex_direction':'row' if row else 'column','flex_direction_mobile':'column','flex_gap':{'unit':'px','row':20 if row else 8,'column':24 if row else 0,'isLinked':False},'padding':dim(0),'width':sl(width,'%'),'width_mobile':sl(100,'%'),'_title':name}
 if row:s.update({'flex_wrap':'nowrap','flex_align_items':'center'})
 if card:s.update({'background_background':'classic','background_color':'#15354F' if dark else WHITE,'border_border':'solid','border_width':dim(1),'border_color':'#34536B' if dark else '#D9E3EB','border_radius':dim(10),'padding':dim(26),'padding_mobile':dim(20)})
 return {'id':uid(),'elType':'container','settings':s,'elements':items}

oldtext=text

def text(txt,dark=False,small=False):
 node=oldtext(txt,dark,small)
 # Preserve paragraph structure and inline links without arbitrary CSS or classes.
 node['settings']['editor']=re.sub(r'\s(?:class|style)="[^"]*"','',node['settings']['editor'])
 return node


def raw_nodes(markup,dark=False):
 root=Parse(markup).root
 def conv(n):
  if isinstance(n,str):return [text(n,dark)] if n.strip() and n.strip() not in ['→','↗'] else []
  tag=n.tag;cl=n.attrs.get('class','');txt=n.plain();kids=[c for c in n.children if isinstance(c,Node)]
  if tag in ['script','style','dialog','button','title','desc']:return []
  if tag=='svg':return [svg_image(n)]
  if tag=='img':return [image(n.attrs.get('src',''),n.attrs.get('alt',''))]
  if tag in ['h1','h2','h3','h4']:return [heading(txt,tag if tag!='h4' else 'h3',dark)]
  if tag=='a':
   if not txt:return []
   b=button(txt,n.attrs.get('href','/login/'))
   if 'ps-button' not in cl and 'ps-access-button' not in cl:b['settings'].update({'background_color':'#FFFFFF00','button_text_color':WHITE if dark else BLUE,'text_padding':dim(0)})
   return [b]
  if tag in ['p','figcaption']:return [text(serialize(n) if tag=='p' else txt,dark,tag=='figcaption')]
  if cl in ['ps-card-top','ps-feature-list']:
   if cl=='ps-card-top':
    svg=next((x for x in descendants(n) if x.tag=='svg'),None)
    label=next((x.plain() for x in kids if 'ps-entry-label' in x.attrs.get('class','')),'')
    return [native_group(([svg_image(svg)] if svg else [])+[text(label,dark,True)],row=True,name='Ikona a označení oblasti')]
   return [native_group([native_group(sum((conv(x) for x in c.children),[]),row=True,card=True,dark=dark,name='Vlastnost přehledů') for c in kids],name='Vlastnosti')]
  if cl=='ps-territory-grid':
   groups=[native_group(sum((conv(x) for x in c.children),[]),width=55 if i==0 else 45,card=i==0,name='Ilustrační mapa krajů' if i==0 else 'Přístup k volebním datům') for i,c in enumerate(kids)]
   return [native_group(groups,row=True,name='Mapa a přístup do archivu')]
  if cl=='ps-election-types':
   groups=[native_group(sum((conv(x) for x in c.children),[]),width=20,name=c.plain()[:45]) for c in kids]
   result=native_group(groups,row=True,name='Pět typů voleb');result['settings']['flex_align_items']='stretch';return [result]
  if 'ps-result-benefits' in cl or cl=='ps-workflow-cards':
   stack=cl=='ps-workflow-cards'
   groups=[native_group(sum((conv(x) for x in c.children),[]),width=100 if stack else 100/len(kids),card=True,dark=dark,name='Krok přehledu' if stack else 'Přínos přehledu') for c in kids]
   result=native_group(groups,row=not stack,name='Jak přehledy fungují');result['settings']['flex_align_items']='stretch';return [result]
  if cl in ['ps-results-cta','ps-cta']:
   return [native_group([native_group(conv(c),width=65 if i==0 else 35,name='Výzva k přístupu') for i,c in enumerate(kids)],row=True,card=cl=='ps-cta',dark=dark,name='Přístup do projektu')]
  if cl=='ps-visual-block':return [native_group(sum((conv(c) for c in n.children),[]),card=True,name='Ilustrační graf – nikoli skutečné měření')]
  if cl=='ps-legend':return [text('Řada A · Řada B · Řada C',dark,True)]
  if cl in ['ps-territories','ps-data-tags','ps-territory-levels']:
   return [text(' · '.join(c.plain() for c in kids if c.plain() not in ['→','↗','↓']),dark,True)]
  if tag=='ul':return [text('<ul>'+''.join('<li>'+html.escape(c.plain())+'</li>' for c in kids)+'</ul>',dark)]
  if tag in ['span','strong','b','small']:
   if any(x.tag=='svg' for x in kids):return sum((conv(x) for x in kids),[])
   return [text(txt,dark,True)] if txt and txt not in ['→','↗','⌖','◉'] else []
  if not kids:return [text(txt,dark)] if txt else []
  # Inline-only blocks become a single editable text widget.
  if all(c.tag in ['span','strong','b','small','i','br'] and not any(x.tag=='svg' for x in descendants(c)) for c in kids):return [text(txt,dark,True)] if txt else []
  return sum((conv(c) for c in n.children),[])
 return conv(root)

def descendants(n):
 for c in n.children:
  if isinstance(c,Node):yield c;yield from descendants(c)

# Original converter constructs the public page/template metadata and kit.
tail=base[base.index('parts=[convert_page'):]
tail=tail.replace("ROOT/'native-design-1.8.json'","ROOT/'quality-design.json'")
exec(compile(tail,str(ROOT/'native_convert.py'),'exec'))

# Normalize all legacy wrapper nodes into native flex containers.
def normalize(n):
 s=n['settings'];typ=n['elType']
 if typ=='section':
  n['elType']='container';s.pop('layout',None);s.pop('gap',None)
  v=s.pop('content_width',sl(1280));s.update({'content_width':'boxed','boxed_width':v,'flex_direction':'row','flex_direction_mobile':'column','flex_wrap':'nowrap','flex_gap':{'unit':'px','row':24,'column':24,'isLinked':True},'flex_align_items':'stretch'})
 if typ=='column':
  n['elType']='container';w=s.pop('_column_size',100);s.pop('_inline_size',None)
  s.update({'content_width':'full','width':sl(w,'%'),'width_mobile':sl(100,'%'),'flex_direction':'column','flex_gap':{'unit':'px','row':0,'column':0,'isLinked':True}})
 n.pop('isInner',None)
 s.pop('text_padding',None)
 for c in n.get('elements',[]):normalize(c)

for p in out['parts']:
 for n in p['template']['content']:normalize(n)

# EA menu has its own default backgrounds and does not inherit the container color.
for p in out['parts']:
 if p['key']=='header':
  def fixmenu(n):
   if n.get('widgetType')=='eael-simple-menu':
    n['settings'].update({'eael_simple_menu_background':NAVY,'eael_simple_menu_item_background':NAVY,'eael_simple_menu_item_background_hover':'#153E5B','eael_simple_menu_item_background_active':'#153E5B','eael_simple_menu_item_color_active':WHITE})
   for c in n.get('elements',[]):fixmenu(c)
  for n in p['template']['content']:fixmenu(n)
 if p['key']=='footer':
  def fixfooter(n):
   if n.get('widgetType')=='eael-simple-menu':
    n['settings'].update({'eael_simple_menu_menu':'7','eael_simple_menu_background':NAVY,'eael_simple_menu_item_background':NAVY,'eael_simple_menu_item_background_hover':'#153E5B','eael_simple_menu_item_background_active':'#153E5B','eael_simple_menu_item_color_active':WHITE,'_title':'Projektové menu – přemapovat na nové footer menu'})
   for c in n.get('elements',[]):fixfooter(c)
  for n in p['template']['content']:fixfooter(n)
 if p['key']=='metodika':
  for n in p['template']['content']:
   def editorial(e):
    s=e['settings']
    if e.get('widgetType')=='text-editor':
     t=s['editor']
     if 'obsahový rámec' in t:s['editor']='<p>Konkrétní zdroj, období a datum aktualizace sledujte vždy u příslušného přehledu. Výstupy modelu je nutné interpretovat v rámci popsaných předpokladů.</p>'
     else:s['editor']=t.replace('U výsledků uvádějte','U výsledků sledujte').replace('zveřejněte vstupní data','sledujte vstupní data')
    for c in e.get('elements',[]):editorial(c)
   editorial(n)

# Restore the approved homepage's generous photographic opening.
for p in out['parts']:
 if p['key']=='uvod':
  hero=p['template']['content'][0]
  for c in hero['elements']:
   c['settings'].update({'padding':dim(175,32,140,32),'padding_tablet':dim(140,24,100,24),'padding_mobile':dim(105,20,85,20)})

# Align public copy with the project's own daily measurement and unique model.
copy_fixes={
 'Sledujte vývoj podpory v čase. S jasným rozlišením zdrojů, období sběru a jednotlivých modelů.':'Sledujte naše denně aktualizované modelové preference. Vycházejí z vlastního měření GRP a našeho unikátního matematického modelu.',
 'Zdroj · Období sběru · Vývoj v čase':'Vlastní GRP · Unikátní model · Denní aktualizace',
 'Preference se zdrojem a obdobím':'Vlastní denní GRP a modelové preference',
 'Správná interpretace začíná u zdroje. Výsledky, průzkumy a simulace proto vždy oddělujeme.':'Historické výsledky, naše modelové preference a simulace mají odlišný význam. U každého přehledu vysvětlujeme jeho základ a způsob výpočtu.',
 'Rozlište skutečný výsledek, průzkum a modelový scénář.':'Rozlište historický výsledek voleb, naše modelové preference a simulovaný scénář.',
 'Přehledy slouží k odborné interpretaci dat. Výsledky voleb, průzkumy preferencí a modelové scénáře je nutné posuzovat odděleně.':'Přehledy slouží k odborné interpretaci dat. Historické výsledky voleb z dat ČSÚ, naše modelové preference z vlastního denního měření GRP a modelové scénáře posuzujeme odděleně.',
 'Jasné rozlišení mezi volebním výsledkem, průzkumem a modelovým scénářem.':'Jasné rozlišení mezi historickými výsledky voleb, našimi modelovými preferencemi a simulacemi.',
 'Poslanecká sněmovna – Přehledy je soukromý analytický projekt zaměřený na přehledy proběhlých voleb, průběžné analýzy volebních preferencí a srovnávání volebních dat.':'Poslanecká sněmovna – Přehledy je soukromý analytický projekt. Propojuje přehledy proběhlých voleb z dat ČSÚ s vlastními denními modelovými preferencemi, které odvozujeme z vlastního měření GRP pomocí našeho unikátního matematického modelu.',
 'Historické výsledky ukazují, jak volby dopadly. Analýzy preferencí sledují aktuální vývoj. Srovnání a modelové scénáře pomáhají zkoumat souvislosti a formulovat otázky pro další analýzu.':'Historické výsledky z dat ČSÚ ukazují, jak volby dopadly. Naše preference vycházejí z vlastního denního měření GRP a unikátního matematického modelu a aktualizujeme je každý den. Modelové scénáře popisují výsledek zvolených předpokladů a slouží ke zkoumání souvislostí.',
 'Přehledy minulých voleb a průběžné analýzy aktuálních preferencí. Pro ty, kteří chtějí rozumět číslům i tomu, co stojí za nimi.':'Přehledy minulých voleb a naše denně aktualizované modelové preference z vlastního měření GRP. Pro ty, kteří chtějí rozumět číslům i tomu, co stojí za nimi.',
 'Ilustrační data pro náhled designu. Nejde o skutečný průzkum.':'Ilustrační data pro náhled designu. Nejde o skutečné měření ani výstup našeho modelu.'
}
for p in out['parts']:
 def fix_copy(n):
  settings=n['settings']
  for field in ['editor','title']:
   if field in settings:
    for old,new in copy_fixes.items():settings[field]=settings[field].replace(old,new)
  for child in n.get('elements',[]):fix_copy(child)
 for n in p['template']['content']:fix_copy(n)
 if p['key']=='uvod':
  cards=p['template']['content'][1]
  for card in cards['elements']:
   for n in card['elements']:
    if n.get('widgetType')=='heading':
     # Semantic H2 retains the approved card's 22px H3 visual typography.
     n['settings']['header_size']='h2'
     n['settings']['__globals__']['typography_typography']='globals/typography?id=psh3'

# Every native local color participates in the global kit.
colors={x['color'].upper():x['_id'] for key in ['system_colors','custom_colors'] for x in out['site_settings'][key]}
for p in out['parts']:
 def bind(n):
  s=n['settings'];g=s.setdefault('__globals__',{})
  for k,v in list(s.items()):
   if isinstance(v,str) and re.fullmatch('#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?',v):
    v=v.upper();s[k]=v
    if v not in colors:
     cid='psc'+v[1:].lower();colors[v]=cid;out['site_settings']['custom_colors'].append({'_id':cid,'title':'PS '+v,'color':v})
    g[k]='globals/colors?id='+colors[v]
  if n.get('widgetType')=='eael-simple-menu':
   g['eael_simple_menu_item_typography_typography']='globals/typography?id=psbutton'
   for k in list(s):
    if k.startswith('eael_simple_menu_item_typography_'):s.pop(k)
  if not g:s.pop('__globals__',None)
  for c in n.get('elements',[]):bind(c)
 for n in p['template']['content']:bind(n)

out['title']='Poslanecká sněmovna – nativní schválený design'
out['styles']=''
out['report']['limitations']=['Nativní struktura je připravená pro ověření registrovaných ovládacích prvků a skutečný import.','SVG prostředky uvedené v quality-assets.json je nutné před publikováním nahrát a přemapovat na vrácené adresy.','Přesnou vizuální shodu je nutné ověřit po importu na počítači a mobilu.']
(ROOT/'quality-design.json').write_text(json.dumps(out,ensure_ascii=False,indent=2))
(ROOT/'quality-assets.json').write_text(json.dumps(asset_manifest,ensure_ascii=False,indent=2))
print('Quality candidate saved:',len(out['parts']),'parts;',len(asset_manifest),'SVG assets;',len(colors),'globally bound colors')

# Local-style fallback is solely for render probes before global-kit remapping.
fallback=copy.deepcopy(out)
typographies={x['_id']:x for key in ['system_typography','custom_typography'] for x in out['site_settings'][key]}
def unbind(n):
 s=n['settings']
 for control,ref in s.pop('__globals__',{}).items():
  if ref.startswith('globals/typography?id='):
   tid=ref.split('=',1)[1];prefix=control[:-len('typography')]
   for k,v in typographies[tid].items():
    if k.startswith('typography_'):s[prefix+k[len('typography_'):]]=copy.deepcopy(v)
 for c in n.get('elements',[]):unbind(c)
for p in fallback['parts']:
 for n in p['template']['content']:unbind(n)
(ROOT/'quality-design-unbound.json').write_text(json.dumps(fallback,ensure_ascii=False,indent=2))
