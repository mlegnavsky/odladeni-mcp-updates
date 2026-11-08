"""Native Elementor parity candidate from the approved v17 HTML/CSS hierarchy.
Pure local conversion. Render verification is required before any parity claim.
"""
import json,copy,re,html,ast,hashlib
from pathlib import Path
from urllib.parse import urlparse,parse_qs
ROOT=Path(__file__).resolve().parent
base=(ROOT/'native_convert.py').read_text();exec(compile(base.split('parts=[convert_page')[0],str(ROOT/'native_convert.py'),'exec'))
N=40000
LIVE=json.loads((ROOT/'live-unbound.json').read_text());out=copy.deepcopy(LIVE)
ASSET=json.loads((ROOT/'native-asset-map.json').read_text())['by_source']
copy_fixes={}
for n in ast.parse((ROOT/'quality_convert.py').read_text()).body:
 if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='copy_fixes' for t in n.targets):copy_fixes=ast.literal_eval(n.value)
copy_fixes['Přehledy minulých voleb a průběžné analýzy aktuálních preferencí. Pro ty, kteří chtějí rozumět číslům i tomu, co stojí za nimi.']='Přehledy minulých voleb a naše denní modelové preference z vlastního měření GRP. Pro ty, kteří chtějí rozumět číslům i tomu, co stojí za nimi.'
copy_fixes['Sledujte vývoj podpory v čase. S jasným rozlišením zdrojů, období sběru a jednotlivých modelů.']='Sledujte naše denní preference z vlastního měření GRP. Vývoj podpory odvozujeme unikátním matematickým modelem.'
copy_fixes['Tato stránka je obsahový rámec metodiky. Konkrétní datové zdroje, frekvence aktualizace a výpočty doplňte podle skutečně používaných přehledů.']='Konkrétní zdroj, období a datum aktualizace sledujte vždy u příslušného přehledu. Výstupy modelu je nutné interpretovat v rámci popsaných předpokladů.'
INK='#102744';MUTED='#52637A';NAVY='#091C34';BG='#F3F6FA';RED='#E51936';WHITE='#FFFFFF'

def fixed(t):
 for a,b in copy_fixes.items():t=t.replace(a,b)
 return t.replace('U výsledků uvádějte','U výsledků sledujte').replace('zveřejněte vstupní data','sledujte vstupní data')
def margin(t=0,r=0,b=0,l=0):return dim(t,r,b,l)
def gap(row=0,col=None):return {'unit':'px','row':row,'column':row if col is None else col,'isLinked':col is None}
def typo(size=16,weight=400,line=1.6,family='Open Sans',spacing=0,unit='px'):
 return {'typography_typography':'custom','typography_font_family':family,'typography_font_size':sl(size,unit),'typography_font_weight':str(weight),'typography_line_height':sl(line,'em'),'typography_letter_spacing':sl(spacing)}
def widget(kind,s):return {'id':uid(),'elType':'widget','widgetType':kind,'settings':s,'elements':[]}
def textw(t,size=16,weight=400,color=MUTED,line=1.6,mb=16,title='Text',spacing=0):
 t=re.sub(r'\s(?:class|style)="[^"]*"','',fixed(t))
 if not t.startswith(('<p','<ul')):t='<p>'+t+'</p>'
 return widget('text-editor',{'editor':t,'text_color':color,'paragraph_spacing':sl(0),**typo(size,weight,line,spacing=spacing),'_margin':margin(0,0,mb,0),'_title':title})
def head(t,tag='h2',size=None,color=INK,mb=14,line=1.14,spacing=None,family='Montserrat'):
 size=size or {'h1':55.3,'h2':35.7,'h3':24}.get(tag,24)
 t=re.sub('<span[^>]*>','',fixed(t)).replace('</span>','')
 return widget('heading',{'title':t,'header_size':tag,'title_color':color,**typo(size,600,line,family,spacing if spacing is not None else -size*.045),'typography_font_size_mobile':sl(29 if tag=='h2' else 24 if tag=='h3' else 36),'_margin':margin(0,0,mb,0),'_title':'Nadpis: '+re.sub('<[^>]*>',' ',t)[:60]})
def btn(t,url,link=False,dark=False,size=13,pad=None):
 return widget('button',{'text':fixed(t),'link':{'url':url},'align':'left','background_color':'#FFFFFF00' if link else RED,'button_text_color':WHITE if dark or not link else INK,'button_background_hover_color':'#FFFFFF1C' if link and dark else '#BD132C','hover_color':WHITE if dark or not link else '#C41630','border_border':'none' if link else 'solid','border_width':dim(0 if link else 1),'border_color':RED,'border_radius':dim(4),**typo(size,600,1.5),'text_padding':pad or dim(0 if link else 13,0 if link else 20,0 if link else 13,0 if link else 20),'_margin':margin(0,0,16,0),'_title':'Odkaz: '+t})
def media(url):
 key=url
 if key not in ASSET:key=parse_qs(urlparse(url).query).get('file',[urlparse(url).path.split('/')[-1]])[0]
 return copy.deepcopy(ASSET.get(key,{'url':url,'id':0}))
def img(url,alt='',width=100,unit='%',mb=0):
 a=media(url)
 return widget('image',{'image':{'url':a['url'],'id':a.get('id',0),'alt':alt},'image_size':'full','align':'start','width':sl(width,unit),'_margin':margin(0,0,mb,0),'_title':alt or 'Ikona'})
def group(items,name='',row=False,width=100,pad=None,bg=None,spacing=0,boxed=False):
 s={'content_width':'boxed' if boxed else 'full','width':sl(width,'%'),'width_mobile':sl(100,'%'),'flex_direction':'row' if row else 'column','flex_direction_mobile':'column','flex_wrap':'nowrap','flex_gap':gap(spacing),'padding':pad or dim(0),'margin':dim(0),'_title':name,'flex_align_items':'stretch'}
 if boxed:s['boxed_width']=sl(1280)
 if row:s['flex_align_items']='center'
 if bg:s.update({'background_background':'classic','background_color':bg})
 return {'id':uid(),'elType':'container','settings':s,'elements':items}
def border(n,color='#DCE5F0',radius=8,pad=0,width=None):
 n['settings'].update({'border_border':'solid','border_width':width or dim(1),'border_color':color,'border_radius':dim(radius)})
 if pad:n['settings']['padding']=dim(pad)
 return n
def gradient(n,a,b,angle=90,stop_a=0,stop_b=100):
 n['settings'].update({'background_background':'gradient','background_color':a,'background_color_b':b,'background_gradient_type':'linear','background_gradient_angle':sl(angle,'deg'),'background_color_stop':sl(stop_a,'%'),'background_color_b_stop':sl(stop_b,'%')});return n

def serialize(n):
 if isinstance(n,str):return html.escape(n)
 return '<'+n.tag+''.join(' '+k+'="'+html.escape(v or '',quote=True)+'"' for k,v in n.attrs.items())+'>'+''.join(serialize(c) for c in n.children)+'</'+n.tag+'>'
def svg(n,color='#2377A2',size=28):
 raw=serialize(n).replace('viewbox=','viewBox=').replace('<svg ','<svg xmlns="http://www.w3.org/2000/svg" ',1).replace('currentColor','#2377A2')
 file='ps-'+hashlib.sha256(raw.encode()).hexdigest()[:12]+'.svg'
 return img('https://poslanecka-snemovna.cz/wp-content/uploads/ps-design/'+file,'Ilustrační graf – nikoli skutečná měření' if n.attrs.get('class')=='ps-chart' else 'Ikona',100 if n.attrs.get('class')=='ps-chart' else size,'%' if n.attrs.get('class')=='ps-chart' else 'px')
def tint_icon(node,role,index):
 # Optional manifest produced by the asset task; roles are stable original geometries.
 path=ROOT/'parity-asset-map.json'
 if path.exists():
  data=json.loads(path.read_text());m=data.get('by_role',data);rolekey=({'card':['home_archive','home_trends','home_models'],'workflow':['workflow_region','workflow_compare','workflow_sources']}[role])[index];entry=m.get(rolekey)
  if entry:node['settings']['image']={'url':entry['url'],'id':entry.get('id',0),'alt':'Ikona'}
 return node

def children(n,dark=False,ctx=''):
 result=[]
 for c in n.children:result+=convert(c,dark,ctx)
 return result

def convert(n,dark=False,ctx=''):
 if isinstance(n,str):return [textw(n,color=WHITE if dark else MUTED)] if n.strip() and n not in ['→','↗'] else []
 tag=n.tag;cl=n.attrs.get('class','');txt=n.plain();sub=[c for c in n.children if isinstance(c,Node)]
 if tag in ['button','script','style','dialog','title','desc']:return []
 color=WHITE if dark else INK
 if tag=='svg':return [svg(n)]
 if tag=='img':return [img(n.attrs.get('src',''),n.attrs.get('alt',''),mb=12)]
 if tag in ['h1','h2','h3','h4']:
  return [head(txt.replace('\n','<br>'),tag if tag!='h4' else 'h3',color=color)]
 if tag in ['p','figcaption']:
  size=11 if tag=='figcaption' else 16;line=1.6;mb=16
  if 'ps-chart-sub' in cl:size=12;mb=18
  if 'ps-chart-note' in cl:size=11;mb=0
  if 'ps-boundary-credit' in cl:size=10;mb=0
  if 'ps-access-help' in cl:size=12;mb=0
  node=textw(serialize(n) if tag=='p' else txt,size,color='#C3D3DD' if dark else MUTED,line=line,mb=mb,title=cl or 'Odstavec')
  if 'ps-chart-note' in cl:node['settings'].update({'_padding':margin(12,0,0,0),'_border_border':'solid','_border_width':margin(1,0,0,0),'_border_color':'#DCE3E7'})
  if 'ps-map-foot' in cl:node['settings'].update({'align':'center','text_color':'#647986','_margin':margin(18,0,0,0)})
  return [node]
 if tag=='a':
  if not txt:return []
  link='ps-button' not in cl and 'ps-access-button' not in cl
  return [btn(txt,n.attrs.get('href','/login/'),link,dark,size=12 if link else 13)]
 if cl=='ps-card-top':
  result=group(children(n,dark,cl),cl,True,spacing=12);result['settings']['flex_direction_mobile']='row';result['settings']['margin']=margin(0,0,9,0);return [result]
 if 'ps-icon-badge' in cl or 'ps-feature-icon' in cl or 'ps-work-icon' in cl or 'ps-access-icon' in cl:
  size=48 if cl=='ps-icon-badge' else 52 if cl=='ps-work-icon' else 48 if cl=='ps-access-icon' else 29
  icon=next((c for c in sub if c.tag=='svg'),None)
  im=svg(icon,size=29 if size==52 else 28 if size==48 else 20) if icon else textw(txt)
  node=group([im],cl,width=100,pad=dim((size-(29 if size==52 else 28 if size==48 else 20))/2),bg='#FFF0F3' if cl=='ps-icon-badge' else '#2B769733' if cl=='ps-work-icon' else '#DCE9F1' if cl=='ps-access-icon' else '#E6F2FC')
  node['settings'].update({'width':sl(size),'width_mobile':sl(size),'min_height':sl(size),'border_radius':dim(13 if size>=48 else 7),'flex_align_items':'center','flex_justify_content':'center'})
  im['settings']['_margin']=dim(0);return [node]
 if cl=='ps-benefit-icon':
  glyph=textw(txt,25,color='#1C638D',line=1,mb=0,title=cl);badge=group([glyph],cl,width=100,pad=dim(8.5),bg='#E9F2F7');badge['settings'].update({'width':sl(42),'width_mobile':sl(42),'min_height':sl(42),'border_radius':dim(10),'flex_align_items':'center'});return [badge]
 if cl in ['ps-entry-label','ps-card-arrow','ps-work-mark','ps-chart-kicker','ps-tag','ps-preference-step']:
  if cl in ['ps-card-arrow','ps-work-mark']:return [textw(txt,18 if cl=='ps-card-arrow' else 14,color='#B4BFCC',mb=0,title=cl)]
  size=9 if cl=='ps-entry-label' else 10 if cl=='ps-chart-kicker' else 12 if cl=='ps-preference-step' else 9 if cl=='ps-tag' else 25
  node=textw(txt,size,600,color='#7A8CA2' if cl=='ps-entry-label' else '#91C4DF' if dark else '#31799E' if cl=='ps-preference-step' else '#C41630',mb=8 if cl=='ps-chart-kicker' else 0,title=cl,spacing=.12*size if cl!='ps-tag' else 0)
  if cl=='ps-tag':node['settings'].update({'_background_background':'classic','_background_color':'#FFF2F4','_border_border':'solid','_border_width':dim(1),'_border_color':'#F3CCD3','_border_radius':dim(20),'_padding':dim(5,8,5,8)})
  return [node]
 if cl=='ps-card-data':
  node=border(group(children(n,dark,cl),cl,pad=dim(14),bg='#F5F8FC'),'#E8EEF6',6)
  node['settings']['margin']=margin(0,0,16,0)
  for c in node['elements']:
   if c.get('widgetType')=='text-editor':c['settings'].update(typo(10,400,1.6));c['settings']['_margin']=margin(0,0,10,0)
  return [node]
 if cl in ['ps-territories','ps-data-tags','ps-territory-levels']:
  items=[]
  for c in sub:
   if c.tag in ['i','b'] and c.plain() in ['→','↗','↓']:continue
   x=textw(c.plain(),10 if cl!='ps-territory-levels' else 13,600,color='#345265',mb=0,title='Územní úroveň' if cl=='ps-territory-levels' else 'Štítek')
   x['settings'].update({'_background_background':'classic','_background_color':'#F1F5F7','_padding':dim(3,7,3,7),'_border_radius':dim(3)})
   items.append(x)
  node=group(items,cl,row=cl!='ps-territory-levels',spacing=7);node['settings']['flex_direction_mobile']='column' if cl=='ps-territory-levels' else 'row';node['settings']['flex_wrap']='wrap';return [node]
 if cl=='ps-hero-meta':return [textw(txt,11,color='#BACADD',mb=0,title=cl)]
 if cl=='ps-access-caption':return [textw(txt,13,color='#D9E4EC',mb=0,title=cl)]
 if cl in ['ps-results-actions','ps-visual-head','ps-map-top','ps-proof','ps-results-cta','ps-cta','ps-territory-grid','ps-election-types']:
  node=group(children(n,dark,cl),cl,True,spacing={'ps-results-actions':20,'ps-visual-head':12,'ps-map-top':12,'ps-proof':25,'ps-results-cta':50,'ps-cta':35,'ps-territory-grid':48,'ps-election-types':18}[cl])
  if cl=='ps-results-actions':node['settings']['margin']=margin(26,0,0,0)
  if cl=='ps-proof':node['settings'].update({'border_border':'solid','border_width':margin(1,0,0,0),'border_color':'#DCE3E7','padding':margin(25,0,0,0)})
  if cl in ['ps-territory-grid','ps-election-types']:node['settings']['margin']=margin(30 if cl=='ps-territory-grid' else 28,0,0,0)
  if cl=='ps-cta':border(node,'#CCDAE9',15,40);gradient(node,'#EEF5FF',WHITE,115,0,65);node['settings']['min_height']=sl(225)
  if cl=='ps-election-types':
   node['settings']['flex_align_items']='stretch'
   for c in node['elements']:border(c,'#B5CBD7',0,width=margin(1,0,0,0));c['settings']['padding']=margin(18,0,0,0)
  return [node]
 if cl=='ps-visual-block':
  return [border(group(children(n,False,cl),cl,pad=dim(25,25,20,25),bg=WHITE),'#D2DFED',12)]
 if cl in ['ps-result-benefits','ps-result-benefits ps-preference-info','ps-workflow-cards','ps-feature-list']:
  node=group(children(n,dark,cl),cl,row=cl.startswith('ps-result-benefits'),spacing=24 if cl.startswith('ps-result-benefits') else 13 if cl=='ps-workflow-cards' else 10)
  if cl.startswith('ps-result-benefits'):node['settings']['margin']=margin(32,0,0,0);node['settings']['flex_align_items']='stretch'
  if cl=='ps-feature-list':node['settings']['margin']=margin(6,0,10,0)
  return [node]
 if cl=='ps-legend':return [textw('Řada A · Řada B · Řada C',12,color=MUTED,mb=15,title=cl)]
 if cl in ['ps-note','ps-slot']:
  node=group(children(n,dark,cl),cl,pad=dim(22 if cl=='ps-note' else 32),bg='#F2F5FA' if cl=='ps-note' else WHITE)
  border(node,RED if cl=='ps-note' else '#DCE3E7',0 if cl=='ps-note' else 8,width=margin(0,0,0,3) if cl=='ps-note' else dim(1));return [node]
 if cl=='ps-map-panel':
  node=border(group(children(n,dark,cl),cl,pad=dim(24),bg=WHITE),'#D6E1E7',12)
  for c in node['elements']:
   if c.get('widgetType')=='image':c['settings']['_margin']=margin(30,0,12,0)
  return [node]
 if tag in ['div','article','figure']:
  node=group(children(n,dark,cl),cl or 'Seskupený obsah')
  if ctx.startswith('ps-result-benefits') and tag=='article':
   border(node,'#DBE3E8',8,28,width=margin(3,1,1,1));node['settings']['background_background']='classic';node['settings']['background_color']=WHITE
   for c in node['elements']:
    if c.get('widgetType')=='heading':c['settings'].update(typo(20,600,1.14,'Montserrat',-.9));c['settings']['_margin']=margin(18,0,12,0)
    if c.get('widgetType')=='text-editor' and c['settings'].get('_title')!='ps-preference-step':c['settings'].update(typo(14,400,1.8))
  if ctx=='ps-feature-list':
   node['settings'].update({'flex_direction':'row','flex_direction_mobile':'row','flex_gap':gap(11),'padding':dim(10,12,10,12),'background_background':'classic','background_color':'#F6F9FD'})
   border(node,'#E3EBF6',8)
   for c in node['elements']:
    if c.get('widgetType')=='text-editor':c['settings'].update(typo(11,600,1.6));c['settings']['_margin']=dim(0)
  if ctx=='ps-workflow-cards' and tag=='article':
   # Icon beside a compact title/copy block, as in the approved HTML.
   es=node['elements'];icon=es[:1];copyblock=group(es[1:-1] if es and es[-1].get('settings',{}).get('_title')=='ps-work-mark' else es[1:],'Text kroku')
   node['elements']=icon+[copyblock];node['settings'].update({'flex_direction':'row','flex_direction_mobile':'row','flex_gap':gap(18),'padding':dim(23),'flex_align_items':'center'})
   border(node,'#FFFFFF20',10);gradient(node,'#FFFFFF0D','#FFFFFF04',115)
   for c in copyblock['elements']:
    if c.get('widgetType')=='heading':c['settings'].update(typo(15,600,1.4));c['settings']['_margin']=margin(0,20,7,0)
    if c.get('widgetType')=='text-editor':c['settings'].update(typo(12,400,1.7));c['settings'].update({'text_color':'#A9C0D6','_margin':dim(0)})
  return [node]
 if tag=='ul':return [textw('<ul>'+''.join('<li>'+html.escape(c.plain())+'</li>' for c in sub)+'</ul>',color=WHITE if dark else MUTED)]
 if tag in ['span','strong','b','small']:
  if ctx=='ps-map-top':return [textw(txt,10,600,mb=0,spacing=1,title='Mapový přehled')]
  if ctx=='ps-election-types' or txt in ['01','02','03','04','05']:return [textw(txt,11,400,color='#2475A2',mb=0,spacing=1.32,title='Číslo typu voleb')]
  return [textw(txt,23 if tag=='strong' and ctx=='ps-proof' else 10 if ctx=='ps-card-data' else 16,600 if tag=='strong' else 400,color=WHITE if dark else MUTED,mb=0 if ctx=='ps-proof' else 16,title=cl or tag)] if txt and txt not in ['→','↗'] else []
 return children(n,dark,ctx)

# Rebuild each approved source section and retain the public metadata.
parts=[]
for source in SOURCE['parts']:
 if source['key'] in ['cookies','ochrana-osobnich-udaju']:continue
 p=copy.deepcopy(source);sections=[];key=p['key']
 for original in p['template']['content']:
  cls=original['settings'].get('css_classes','');dark=any(c in cls for c in ['ps-home-hero','ps-results-hero','ps-results-access','ps-context']);cols=[]
  for col in original['elements']:
   es=[]
   for w in col['elements']:
    settings=w['settings'];kind=w.get('widgetType');c=settings.get('_css_classes','')
    if kind=='html':es+=children(Parse(settings['html']).root,dark)
    elif kind=='heading':es.append(head(settings['title'],settings.get('header_size','h2'),color=WHITE if dark else INK))
    elif kind=='text-editor':
     if 'ps-page-heading' in cls and 'ps-overline' in c:continue
     size=12 if 'breadcrumb' in c else 11 if ('kicker' in c or 'overline' in c) else 18 if 'lead' in c else 16
     wt=600 if 'kicker' in c or 'overline' in c else 400
     color='#A9D8ED' if dark and wt==600 else '#D9E4EC' if dark else '#2373A4' if wt==600 else MUTED
     es.append(textw(settings['editor'],size,wt,color,1.65 if size==18 else 1.6,18 if wt==600 else 16,c or 'Text',.14*size if wt==600 else 0))
    elif kind=='button':es.append(btn(settings['text'],settings['link']['url'],'ps-text-link' in c,dark))
   if es:es[-1]['settings']['_margin']=dim(0)
   cols.append(group(es,col['settings'].get('css_classes','') or 'Sloupec',width=col['settings'].get('_column_size',100)))
  section=group(cols,cls,row=True,boxed=True,pad=dim(52,40,52,40),bg=NAVY if dark else BG)
  section['settings'].update({'padding_tablet':dim(36,24,36,24),'padding_mobile':dim(34,20,34,20)})
  if original['settings'].get('_element_id'):section['settings']['_element_id']=original['settings']['_element_id']
  if 'ps-page-heading' in cls:gradient(section,'#E8EEF7','#F4F7FB',115)
  if len(cols)>1:section['settings']['flex_gap']=gap(24)
  if 'ps-home-hero' in cls or 'ps-results-hero' in cls:
   # Photo and transparent gradient are distinct native full-width containers.
   copycol=cols[0];layer=group([copycol],'Překrytí fotografie',boxed=True,pad=dim(215,40,145,40) if key=='uvod' else dim(50,40,70,40))
   gradient(layer,'#06152DED' if key=='uvod' else '#071D31F7','#06152D42' if key=='uvod' else '#071D3140',90,0,75 if key=='uvod' else 100)
   layer['settings'].update({'padding_tablet':dim(175,24,135,24) if key=='uvod' else dim(50,24,70,24),'padding_mobile':dim(155,20,105,20) if key=='uvod' else dim(40,20,50,20)})
   vertical=group([layer],'Svislé ztmavení fotografie');gradient(vertical,'#FFFFFF00','#07172DE6',180,45,100)
   photo=group([vertical],cls,bg=NAVY);photo['settings'].update({'background_image':media(PHOTO),'background_position':'center center','background_size':'cover'})
   section=photo
   if key=='uvod':
    section['settings'].update({'margin':margin(-113,0,0,0),'margin_tablet':margin(-103,0,0,0),'margin_mobile':margin(-84,0,0,0),'min_height':sl(731.3)})
    layer['settings']['min_height']=sl(731.3);layer['settings']['min_height_mobile']=sl(650)
    copycol['settings']['width']=sl(1120);copycol['settings']['width_mobile']=sl(100,'%')
    over,h1,lead,*rest=copycol['elements'];over['settings'].update({'_margin':margin(0,0,22,0),'text_color':'#F5F7FC'});over['settings'].update(typo(11,600,1.4,spacing=2.2))
    h1['settings'].update(typo(4,600,1.2,'Montserrat',-2.726,'vw'));h1['settings'].update({'typography_font_size_tablet':sl(46),'typography_font_size_mobile':sl(36),'_margin':margin(0,0,25,0)})
    lead['settings'].update(typo(16,400,1.75));lead['settings'].update({'text_color':'#DCE4EF','_element_width':'initial','_element_custom_width':sl(700),'_margin':margin(0,0,16,0)})
    buttons=[x for x in rest if x.get('widgetType')=='button'];other=[x for x in rest if x.get('widgetType')!='button']
    for i,b in enumerate(buttons):
     b['settings'].update({'text_padding':dim(15,23,15,23),'_margin':dim(0),'background_color':RED if i==0 else '#FFFFFF0C','border_border':'solid','border_width':dim(1),'border_color':RED if i==0 else '#FFFFFF60','button_text_color':WHITE})
    actions=group(buttons,'Dvě akce v jednom řádku',True,spacing=22);actions['settings'].update({'flex_direction_mobile':'row','flex_wrap':'wrap','margin':margin(12,0,25,0)})
    copycol['elements']=[over,h1,lead,actions]+other
   else:
    for e in copycol['elements']:
     if e.get('widgetType')=='heading':e['settings'].update(typo(4.1,600,1.12,'Montserrat',-2.49,'vw'));e['settings']['typography_font_size_mobile']=sl(34)
  if 'ps-entry-cards' in cls:
   section['settings'].update({'padding':dim(0,40,62,40),'margin':margin(-67,0,0,0),'margin_mobile':margin(-37,0,0,0),'z_index':2,'flex_align_items':'stretch'})
   for i,c in enumerate(cols):
    border(c,['#E51A36','#087CBB','#122B4C'][i],10,28,width=margin(3,1,1,1));gradient(c,WHITE,'#FCFDFF',145)
    c['settings']['min_height']=sl(370.4)
    for e in c['elements']:
     if e.get('widgetType')=='heading':e['settings'].update(typo(23,600,1.14,'Montserrat',-1.035));e['settings']['header_size']='h2'
     elif e.get('widgetType')=='text-editor':e['settings'].update(typo(14,400,1.7))
     elif e.get('widgetType')=='button':e['settings'].update({'background_color':'#FFFFFF00','button_text_color':INK,'text_padding':dim(0),'border_border':'none'});e['settings'].update(typo(12,600,1.5))
     elif e['settings'].get('_title')=='ps-card-top':
      badge=e['elements'][0];badge['settings']['background_color']=['#FFF0F3','#EAF7FF','#EEF2FA'][i];tint_icon(badge['elements'][0],'card',i)
      for child in e['elements'][1:]:child['settings']['_margin']=dim(0)
  if 'ps-data-story' in cls:
   inner=group(cols,'Složený panel souvislostí',True,pad=dim(48),spacing=48,bg=WHITE);border(inner,'#D5E1EF',18);inner['settings']['flex_align_items']='center';inner['settings']['padding_mobile']=dim(25,18,25,18)
   section['elements']=[inner];section['settings'].update({'padding':dim(10,40,70,40),'padding_mobile':dim(0,14,35,14)})
   for e in cols[0]['elements']:
    if e.get('widgetType')=='heading':e['settings'].update(typo(34,600,1.25,'Montserrat',-1.53))
    if e.get('widgetType')=='text-editor' and e['settings']['typography_font_size']['size']>11:e['settings'].update(typo(15,400,1.85))
   chart=cols[1]['elements'][0]
   for e in chart['elements']:
    if e.get('widgetType')=='heading':e['settings'].update(typo(22,600,1.25,'Montserrat',-.88))
  if 'ps-context' in cls:
   section['settings'].update({'padding':dim(67,40,67,40),'flex_gap':gap(65),'background_color':'#0B203A'})
   for e in cols[0]['elements']:
    if e.get('widgetType')=='heading':e['settings'].update(typo(34,600,1.3,'Montserrat',-1.53))
    if e.get('widgetType')=='text-editor':e['settings']['text_color']='#7FC5E9' if e['settings']['typography_font_size']['size']==11 else '#B6C8DC';e['settings'].update(typo(e['settings']['typography_font_size']['size'],400,1.9))
   workflow=cols[1]['elements'][0]
   for i,step in enumerate(workflow['elements']):
    badge=step['elements'][0];badge['settings']['background_color']=['#2B769733','#B2224430','#3E5B9830'][i];tint_icon(badge['elements'][0],'workflow',i)
  if 'ps-cta-section' in cls:section['settings']['padding']=dim(55,40,55,40)
  if 'ps-results-map' in cls:section['settings']['background_color']='#EAF0F3'
  sections.append(section)
 p['template']['content']=sections;p['template']['page_settings']={'hide_title':'yes'};parts.append(p)

# Shared header: exact logo/private label/menu/login hierarchy.
logo=img(LOGO,'Poslanecká sněmovna – Přehledy',137,'px');logo['settings'].update({'link_to':'custom','link':{'url':'https://poslanecka-snemovna.cz/'}})
private=textw('SOUKROMÝ<br>ANALYTICKÝ<br>PROJEKT',11,color='#D5DDEA',line=1.45,mb=0,spacing=.44,title='Označení soukromého projektu')
private['settings'].update({'_padding':margin(0,0,0,18),'_border_border':'solid','_border_width':margin(0,0,0,1),'_border_color':'#FFFFFF30','_element_width':'initial','_element_custom_width':sl(125)})
brand=group([logo,private],'Logo a označení projektu',True,spacing=22,width=30);brand['settings']['flex_direction_mobile']='row';brand['settings']['width_mobile']=sl(65,'%')
menu=next(x for x in LIVE['parts'] if x['key']=='header')['template']['content'][0]['elements'][1]['elements'][0];menu=copy.deepcopy(menu);menu['id']=uid();ms=menu['settings'];ms.update({'eael_simple_menu_background':'#FFFFFF00','eael_simple_menu_item_background':'#FFFFFF00','eael_simple_menu_item_background_hover':'#FFFFFF00','eael_simple_menu_item_background_active':'#FFFFFF00','eael_simple_menu_item_color':WHITE,'eael_simple_menu_item_color_hover':'#FF5C69','eael_simple_menu_item_padding':dim(8,11.5,8,11.5),'eael_simple_menu_item_typography_typography':'custom','eael_simple_menu_item_typography_font_family':'Open Sans','eael_simple_menu_item_typography_font_size':sl(12),'eael_simple_menu_item_typography_font_weight':'600'})
login=btn('Přihlášení','/login/',dark=True,size=12,pad=dim(10,18,10,18));login['settings']['_margin']=dim(0)
nav=group([menu,login],'Hlavní navigace a přihlášení',True,spacing=24,width=70);nav['settings']['flex_justify_content']='flex-end';nav['settings']['width_mobile']=sl(35,'%')
header=group([brand,nav],'Společná hlavička',True,boxed=True,pad=dim(17,40,17,40),bg='#06152DE6',spacing=22);header['settings'].update({'min_height':sl(113),'z_index':50,'flex_direction_mobile':'row','padding_mobile':dim(10,20,10,20)})
# Four column footer plus the original bottom row and real legal URLs.
flogo=img(LOGO,'Poslanecká sněmovna – Přehledy',145,'px',18);flogo['settings'].update({'link_to':'custom','link':{'url':'https://poslanecka-snemovna.cz/'}})
fcols=[group([flogo,textw('Volební data. Preference. Souvislosti.',12,color='#C4D2DB',mb=7),textw('Soukromý analytický projekt',10,color='#B5C8E3',mb=0,spacing=.5)],'Značka projektu',width=31.46)]
for title,links,width in [('Prozkoumejte data',[('Výsledky voleb ↗','/vysledky-voleb/'),('Aktuální preference ↗','/aktualni-preference/'),('Analýzy a srovnání ↗','/analyzy/')],22.47),('O projektu',[('Kdo jsme','/o-projektu/'),('Metodika a zdroje','/metodika/'),('Kontakt','/kontakty/')],17.98),('Soukromí a informace',[('Ochrana osobních údajů (GDPR)','/zasady-ochrany-osobnich-udaju/'),('Cookies','/zasady-cookies-eu/'),('Nastavení cookies','/zasady-cookies-eu/#nastaveni'),('ÚOOÚ ↗','https://uoou.gov.cz/')],28.09)]:
 h=head(title,'h2',13,WHITE,20,line=1.2,spacing=-.585,family='Open Sans');h['settings']['_margin']=margin(10,0,20,0)
 es=[h]
 for label,url in links:
  b=btn(label,url,True,True,12);b['settings'].update({'button_text_color':'#C4D2DB','_margin':margin(0,0,12,0),'text_padding':dim(0)});es.append(b)
 fcols.append(group(es,title,width=width))
fgrid=group(fcols,'Čtyři sloupce patičky',True,spacing=38);fgrid['settings']['flex_align_items']='flex-start'
bottom=group([textw('© 2026 Poslanecká sněmovna – Přehledy<br>Soukromý projekt. Nejde o web státní instituce.',10,color='#9AAFBD',line=1.8,mb=0),btn('Zpět nahoru ↑','#ps-main',True,True,11)],'Patička – autorská informace',True,spacing=25)
bottom['settings'].update({'margin':margin(35,0,0,0),'padding':margin(20,0,0,0),'border_border':'solid','border_width':margin(1,0,0,0),'border_color':'#FFFFFF20','flex_justify_content':'space-between'})
footer=group([fgrid,bottom],'Společná patička',boxed=True,pad=dim(44,40,20,40),bg='#081A30');footer['settings'].update({'border_border':'solid','border_width':margin(3,0,0,0),'border_color':'#183D60','padding_mobile':dim(34,20,20,20)})
for key,node in [('header',header),('footer',footer)]:
 p=copy.deepcopy(next(p for p in LIVE['parts'] if p['key']==key));p['template']['content']=[node];parts.append(p)

# Constrain generic HTML wrappers to their original layout proportions and typography.
def finish(n):
 s=n['settings'];title=s.get('_title','');es=n.get('elements',[])
 if n['elType']=='container' and title in ['ps-results-cta','ps-cta','ps-territory-grid','ps-visual-head','ps-map-top','ps-proof']:
  widths=[56.52,43.48] if title=='ps-results-cta' else [54.55,45.45] if title=='ps-territory-grid' else [70,30] if title=='ps-cta' else None
  if widths:
   for i,c in enumerate(es):c['settings']['width']=sl(widths[min(i,1)],'%')
 if title=='ps-visual-head':
  s['flex_align_items']='flex-start'
  for c in es:
   for e in c.get('elements',[]):
    if e.get('widgetType')=='heading':e['settings']['_margin']=dim(0)
 if title=='ps-map-top':s.update({'flex_justify_content':'space-between','flex_direction_mobile':'row'})
 if title=='ps-card-top':
  s['flex_justify_content']='space-between'
  if len(es)>2:
   es[-1]['settings'].update({'_element_width':'initial','_element_custom_width':sl(18)})
 if title=='ps-election-types':
  for c in es:
   c['settings']['width']=sl(20,'%')
   for e in c['elements']:
    if e.get('widgetType')=='heading':e['settings'].update(typo(17,600,1.14,'Montserrat',-.765));e['settings']['_margin']=margin(15,0,10,0)
    elif e.get('widgetType')=='text-editor':e['settings'].update(typo(11 if e['settings'].get('_title')=='Číslo typu voleb' else 13,400,1.6))
 if title=='ps-proof':
  for c in es:
   if c.get('widgetType')=='text-editor':c['settings'].update(typo(23 if c['settings'].get('_title')=='strong' else 13,600 if c['settings'].get('_title')=='strong' else 400,1.6,'Montserrat' if c['settings'].get('_title')=='strong' else 'Open Sans'));c['settings']['_margin']=dim(0)
   if c.get('widgetType')=='button':c['settings']['_margin']=dim(0)
 if title in ['ps-results-cta','ps-cta']:
  for c in es:
   for e in c.get('elements',[]):
    if e.get('widgetType')=='heading':e['settings'].update(typo(29 if title=='ps-cta' else 35.7,600,1.35 if title=='ps-cta' else 1.14,'Montserrat',-1.16))
    if e.get('widgetType')=='text-editor' and e['settings'].get('_title')=='Odstavec':e['settings'].update(typo(13 if title=='ps-cta' else 15,400,1.6))
 if title=='ps-map-copy':
  for e in es:
   if e.get('widgetType')=='heading':e['settings'].update(typo(25,600,1.14,'Montserrat',-1.125));e['settings']['_margin']=margin(12,0,20,0)
   if e.get('widgetType')=='text-editor' and e['settings'].get('_title')=='Odstavec':e['settings'].update(typo(14,400,1.8))
 if title=='ps-results-actions':
  for c in es:c['settings']['_margin']=dim(0)
 if n['elType']=='container' and es and es[-1].get('elType')=='widget':es[-1]['settings']['_margin']=dim(0)
 if n.get('widgetType')=='text-editor' and '<a ' in s.get('editor',''):
  anchor_color=s.get('text_color',INK)
  s['editor']=re.sub(r'<a\s+', '<a style="color:'+anchor_color+'" ', s['editor'])
 for c in es:finish(c)
for p in parts:
 for n in p['template']['content']:finish(n)
 # First content anchor used by original footer return link.
 if p['type']=='page' and p['template']['content']:p['template']['content'][0]['settings']['_element_id']='ps-main'
# Remove structural wrappers that carry no independent visual box. SafeValue's
# existing depth budget stays unchanged; native containers remain editable.
def container_title(n):return n.get('settings',{}).get('_title','')
def badge_to_widget(n):
 if n.get('elType')!='container' or container_title(n) not in ['ps-icon-badge','ps-feature-icon','ps-work-icon','ps-access-icon','ps-benefit-icon']:return n
 if len(n['elements'])!=1 or n['elements'][0].get('elType')!='widget':return n
 child=n['elements'][0];ss=n['settings'];cs=child['settings']
 for key,value in ss.items():
  if key.startswith('background_') or key.startswith('border_') or key=='padding':cs['_'+key]=copy.deepcopy(value)
 cs.update({'_element_width':'initial','_element_custom_width':copy.deepcopy(ss['width']),'_margin':dim(0),'_title':container_title(n)+' – ikona'})
 return child

def flatten(n):
 n['elements']=[flatten(c) for c in n.get('elements',[])]
 title=container_title(n)
 # Three photographic layers remain separate. The plain copy column is redundant.
 if title=='Překrytí fotografie' and len(n['elements'])==1 and container_title(n['elements'][0])=='Sloupec':
  copycol=n['elements'][0]
  for child in copycol['elements']:
   if child.get('widgetType')=='heading':child['settings'].update({'_element_width':'initial','_element_custom_width':sl(1120)})
  n['elements']=copycol['elements']
 # Empty legacy section columns only carry a direction, already represented here.
 if title.startswith('ps-section') and len(n['elements'])==1 and container_title(n['elements'][0]) in ['Sloupec','ps-reveal-up']:
  n['elements']=n['elements'][0]['elements'];n['settings']['flex_direction']='column'
 # Native image widgets carry their own exact badge background/padding.
 n=badge_to_widget(n)
 if n.get('elType')!='container':return n
 title=container_title(n)
 # Feature and workflow list wrappers have only spacing; transfer it to each row.
 result=[]
 for child in n['elements']:
  ct=container_title(child)
  if ct in ['ps-feature-list','ps-workflow-cards']:
   items=child['elements']
   for i,row in enumerate(items):
    row['settings']['margin']=margin(6 if ct=='ps-feature-list' and i==0 else 0,0,10 if ct=='ps-feature-list' else 13 if i<len(items)-1 else 0,0)
    result.append(row)
  else:result.append(child)
 n['elements']=result
 # Workflow's copy div and generated text wrapper represent one identical column.
 if title=='Text kroku' and len(n['elements'])==1 and n['elements'][0]['elType']=='container':
  n['elements']=n['elements'][0]['elements']
  for child in n['elements']:
   if child.get('widgetType')=='heading':child['settings'].update(typo(15,600,1.4));child['settings']['_margin']=margin(0,20,7,0)
   if child.get('widgetType')=='text-editor':child['settings'].update(typo(12,400,1.7));child['settings'].update({'text_color':'#A9C0D6','_margin':dim(0)})
 # The preview-chart column and visual block share one visual box.
 if 'ps-preview-chart' in title and len(n['elements'])==1 and container_title(n['elements'][0])=='ps-visual-block':
  visual=n['elements'][0];original_width=copy.deepcopy(n['settings']['width']);n['settings'].update(visual['settings']);n['settings']['width']=original_width;n['elements']=visual['elements'];title='ps-visual-block'
 # The chart header's left column and tag become its own first wrapped row.
 if title=='ps-visual-block' and n['elements'] and container_title(n['elements'][0])=='ps-visual-head':
  header=n['elements'][0];tail=n['elements'][1:];n['elements']=header['elements']+tail
  n['settings'].update({'flex_direction':'row','flex_direction_mobile':'row','flex_wrap':'wrap','flex_gap':gap(0),'flex_justify_content':'space-between','flex_align_items':'flex-start'})
  for child in n['elements']:
   if child['elType']=='container':child['settings']['width']=sl(70,'%')
   else:child['settings'].update({'_element_width':'initial','_element_custom_width':sl(100,'%')})
  if len(header['elements'])>1:header['elements'][1]['settings']['_element_custom_width']=sl(88)
 return n

def fix_native_keys(n):
 ss=n['settings']
 if n['elType']=='container' and '_margin' in ss:ss['margin']=ss.pop('_margin')
 if n.get('widgetType')=='text-editor' and ss.get('align')=='left':ss['align']='start'
 if n.get('widgetType')=='button' and 'width' in ss:
  ss['_element_width']='initial';ss['_element_custom_width']=ss.pop('width')
 for c in n.get('elements',[]):fix_native_keys(c)
for p in parts:
 p['template']['content']=[flatten(n) for n in p['template']['content']]
 for n in p['template']['content']:fix_native_keys(n)

# Correct the fills and alignments confirmed in the actual native render.
for p in parts:
 def visual_fixes(n):
  ss=n['settings'];title=ss.get('_title','')
  if n['elType']=='container' and 'background_color' in ss and 'background_background' not in ss:ss['background_background']='classic'
  if title=='ps-card-top':
   ss['flex_justify_content']='flex-start'
   for child in n['elements']:
    if child.get('widgetType')=='text-editor' and child['settings'].get('_title')=='ps-entry-label':child['settings']['align']='start'
  if n.get('widgetType')=='heading' and 'Preference pod lupou' in ss.get('title',''):ss.update(typo(22,600,1.25,'Montserrat',-.88))
  if title=='ps-visual-block':ss['padding']=dim(28)
  if title=='ps-chart-sub':ss['_margin']=margin(8,0,18,0)
  if title=='ps-legend':ss['_margin']=margin(10,0,15,0)
  if title=='ps-feature-icon – ikona':ss['_padding']=dim(2.5,4.5,2.5,4.5)
  if title=='ps-overline' and p['key']=='uvod' and 'ZA KAŽDÝM' in ss.get('editor',''):ss.update(typo(10,600,1.4,spacing=1.2))
  for child in n.get('elements',[]):visual_fixes(child)
 for n in p['template']['content']:visual_fixes(n)
 if p['key']=='uvod':
  # Original overline has a short red separator beside its text.
  hero=p['template']['content'][0]
  hero_copy=hero['elements'][0]['elements'][0]
  overline=hero_copy['elements'][0]
  overline_margin=copy.deepcopy(overline['settings'].get('_margin',dim(0)))
  overline['settings']['_margin']=dim(0)
  redbar=widget('divider',{'color':RED,'weight':sl(3),'width':sl(100,'%'),'gap':sl(0),'align':'left','_element_width':'initial','_element_custom_width':sl(38),'_margin':dim(0),'_title':'Červená linka označení projektu'})
  overline_row=group([redbar,overline],'Označení projektu s červenou linkou',True,spacing=13)
  overline_row['settings'].update({'flex_direction_mobile':'row','margin':overline_margin})
  hero_copy['elements'][0]=overline_row
  cards=p['template']['content'][1]
  for card in cards['elements']:card['settings'].update({'background_background':'classic','background_color':WHITE})
  gradient(cards,'#F3F6FA00','#F3F6FA',180,15.5,15.5)
  # The original mobile overlap is37px; the root uses the native fallback scale.
  cards['settings']['background_color_stop_mobile']=sl(3.2,'%');cards['settings']['background_color_b_stop_mobile']=sl(3.2,'%')

# Final measured refinements; desktop hero geometry and stable node IDs stay intact.
for p in parts:
 def measured_fixes(n,inside_footer=False,inside_card=False):
  ss=n['settings'];title=ss.get('_title','');inside_footer=inside_footer or p['key']=='footer'
  inside_card=inside_card or (p['key']=='uvod' and title.startswith('ps-entry-'))
  for device in ['mobile','tablet','desktop']:
   if ss.get('hide_'+device)=='yes':ss['hide_'+device]='hidden-'+device
  if inside_card:
   if title.startswith('ps-entry-') and n['elType']=='container':ss['padding']=dim(27)
   if title=='ps-card-top':ss['margin']=margin(0,0,25,0)
   if title=='Štítek':ss['_padding']=dim(5,7,5,7)
   if title=='ps-data-tags' and inside_card:
    original_labels=[x['settings'].get('editor','') for x in n.get('elements',[])]
    if any('Období sběru' in label for label in original_labels):
     for child,label in zip(n['elements'],['Vlastní GRP','Unikátní model','Denní aktualizace']):child['settings']['editor']='<p>'+label+'</p>'
   if n.get('widgetType')=='button':ss['_margin']=margin(6,0,0,0);ss['_padding']=margin(5,0,0,0)
   if n.get('widgetType')=='text-editor' and 'Sledujte naše denní preference z vlastního měření GRP.' in ss.get('editor',''):
    ss['editor']='<p>Denní preference z vlastního měření GRP, zpracované naším unikátním matematickým modelem.</p>'
  if inside_footer and n.get('widgetType')=='button':
   if ss.get('_margin',{}).get('bottom')=='12':ss['_margin']['bottom']='5'
   if 'Nastavení cookies' in ss.get('text',''):ss['link']={'url':'/zasady-cookies-eu/'}
  if p['key']=='header':
   if title=='Společná hlavička':ss['min_height_mobile']=sl(88)
   if n.get('widgetType')=='image':ss['width_mobile']=sl(110)
   if title=='Označení soukromého projektu':ss.update({'typography_font_size_mobile':sl(9),'_element_custom_width_mobile':sl(110),'_padding_mobile':margin(0,0,0,13)})
   if n.get('widgetType')=='button' and ss.get('text')=='Přihlášení':ss['hide_mobile']='hidden-mobile'
   if n.get('widgetType')=='eael-simple-menu':
    ss.update({'eael_simple_menu_hamburger_size':sl(14),'eael_simple_menu_hamburger_padding':dim(8),'eael_simple_menu_hamburger_min_height':sl(35),'eael_simple_menu_hamburger_bg':'#FFFFFF00','_border_border':'solid','_border_width':dim(0),'_border_width_mobile':dim(1),'_border_color':'#FFFFFF30','_border_radius':dim(4),'_element_width_mobile':'initial','_element_custom_width_mobile':sl(64)})
  if p['key']=='uvod':
   if title=='ps-section ps-hero ps-home-hero':ss.update({'margin_mobile':margin(-88,0,0,0),'min_height_mobile':sl(0)})
   if title=='Překrytí fotografie':ss.update({'padding_mobile':dim(155,20,135,20),'min_height_mobile':sl(0)})
   if title=='ps-overline' and 'SOUKROMÝ ANALYTICKÝ' in ss.get('editor',''):ss.update({'typography_font_size_mobile':sl(9),'typography_line_height_mobile':sl(1.4,'em')})
   if title=='ps-lead' and 'Přehledy minulých voleb' in ss.get('editor',''):ss.update({'typography_font_size_mobile':sl(14),'typography_line_height_mobile':sl(1.75,'em')})
   if title=='Dvě akce v jednom řádku':
    ss['margin_mobile']=margin(23,0,22,0)
    for child in n['elements']:child['settings'].update({'typography_font_size_mobile':sl(12),'typography_line_height_mobile':sl(1.5,'em'),'text_padding_mobile':dim(13,16,13,16)})
   if title=='ps-hero-meta':ss.update({'typography_font_size_mobile':sl(10),'typography_line_height_mobile':sl(1.6,'em'),'_element_width_mobile':'initial','_element_custom_width_mobile':sl(320)})
   if title=='ps-section ps-cards ps-entry-cards':ss['padding_mobile']=dim(0,20,40,20)
  for child in n.get('elements',[]):measured_fixes(child,inside_footer,inside_card)
 for n in p['template']['content']:measured_fixes(n)

# Explicit responsive photo inheritance and remove the desktop menu outline.
for p in parts:
 def responsive_photo_fix(n):
  ss=n['settings'];title=ss.get('_title','')
  if p['key']=='uvod' and title=='ps-section ps-hero ps-home-hero':
   ss.update({'background_image_mobile':copy.deepcopy(ss['background_image']),'background_position_mobile':'center center','background_size_mobile':'cover','min_height_mobile':sl(659.7)})
  if p['key']=='uvod' and title=='ps-lead' and 'Přehledy minulých voleb' in ss.get('editor',''):
   # The action row already supplies the measured23px mobile separation.
   ss['_margin_mobile']=dim(0)
  if n.get('widgetType')=='eael-simple-menu':
   for control in ['_border_border','_border_width','_border_width_mobile','_border_color','_border_radius']:ss.pop(control,None)
  for child in n.get('elements',[]):responsive_photo_fix(child)
 for n in p['template']['content']:responsive_photo_fix(n)

out['parts']=parts;out['styles']='';out.pop('content_hash',None);out['title']='Poslanecká sněmovna – nativní parita v17'
out['report']['elementor_import_verified']=False;out['report']['verification'].update({'browser_desktop':False,'browser_mobile':False,'elementor_import_verified':False})
out['report']['limitations']=['Kandidát vyžaduje kontrolu registrovaných ovládacích prvků a skutečné porovnání desktopového a mobilního vykreslení.','Nativní vodorovný přechod používá dva barevné body oproti třem původním; svislý přechod zůstává samostatnou nativní vrstvou. Výsledek vyžaduje vizuální porovnání.','Pohyb fotografie, dekorativní pseudo-elementy a dynamické otevření stávajícího nastavení cookies nejsou tímto JSON připojeny.']
(ROOT/'parity-design.json').write_text(json.dumps(out,ensure_ascii=False,indent=2))
print('Saved parity-design.json:',len(parts),'parts')
