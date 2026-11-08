import json,re,copy
from pathlib import Path
from html.parser import HTMLParser
ROOT=Path(__file__).parent
SOURCE=json.load(open('v17/catalog-new.json'))
N=8000
PHOTO='https://plaginy.odladeni.cz/assets/poslanecka-template-preview-20261108.php?file=prague-hero.jpg'
LOGO='https://plaginy.odladeni.cz/assets/poslanecka-template-preview-20261108.php?file=logo.png'
WHITE='#FFFFFF';NAVY='#0C243B';INK='#17374C';MUTED='#536B7B';PALE='#F3F6FA';BLUE='#2377A2';RED='#C12F45'
def uid():
 global N
 N+=1;return f'{N:07x}'
def dim(t=0,r=None,b=None,l=None):
 return {'unit':'px','top':str(t),'right':str(t if r is None else r),'bottom':str(t if b is None else b),'left':str(t if l is None else l),'isLinked':r is None}
def sl(n,unit='px'):return {'unit':unit,'size':n}
def wid(k,s):return {'id':uid(),'elType':'widget','widgetType':k,'settings':s,'elements':[]}
def heading(txt,tag='h2',dark=False):
 return wid('heading',{'title':txt,'header_size':tag,'title_color':WHITE if dark else INK,'__globals__':{'typography_typography':'globals/typography?id=ps'+tag},'_margin':dim(0,0,18,0),'_title':'Nadpis: '+re.sub('<[^>]*>',' ',txt)[:55]})
def text(txt,dark=False,small=False):
 return wid('text-editor',{'editor':txt if txt.startswith('<p') else '<p>'+txt+'</p>','text_color':'#CDDCE7' if dark else MUTED,'__globals__':{'typography_typography':'globals/typography?id='+('pssmall' if small else 'text')},'_margin':dim(0,0,16,0)})
def button(txt,url):return wid('button',{'text':txt,'link':{'url':url},'align':'left','background_color':RED,'button_text_color':WHITE,'border_radius':dim(4),'__globals__':{'typography_typography':'globals/typography?id=psbutton'},'_margin':dim(10,0,10,0)})
def image(url,alt='',width=100):
 return wid('image',{'image':{'url':url,'id':0,'alt':alt},'image_size':'full','width':sl(width,'%'),'align':'start','_title':alt or 'Ilustrační obrázek','_margin':dim(0,0,20,0)})
def column(items,width=100,card=False,dark=False):
 s={'_column_size':width,'_inline_size':None,'padding':dim(0),'padding_mobile':dim(0),'_title':'Obsah'}
 if card:s.update({'background_background':'classic','background_color':NAVY if dark else WHITE,'border_border':'solid','border_width':dim(1),'border_color':'#536B7B' if dark else '#D9E3EB','border_radius':dim(8),'padding':dim(26),'padding_mobile':dim(22)})
 return {'id':uid(),'elType':'column','settings':s,'elements':items,'isInner':False}
def section(cols,name='',dark=False,photo=False,inner=False):
 s={'layout':'boxed','content_width':sl(1280),'gap':'extended','padding':dim(48,32,48,32),'padding_tablet':dim(36,24,36,24),'padding_mobile':dim(30,20,30,20),'background_background':'classic','background_color':NAVY if dark else PALE,'_title':'Sekce: '+name}
 if photo:
  s.update({'background_image':{'url':PHOTO,'id':0},'background_position':'center center','background_size':'cover','padding':dim(0),'padding_mobile':dim(0)})
  for c in cols:c['settings'].update({'background_background':'classic','background_color':'#0C243BE0','padding':dim(70,32,70,32),'padding_mobile':dim(40,20,40,20)})
 if inner:s['padding']=dim(12,0,12,0);s['padding_mobile']=dim(10,0,10,0);s['padding_tablet']=dim(12,0,12,0);s['background_color']=NAVY if dark else PALE
 if inner:
  for c in cols:c['isInner']=True
 return {'id':uid(),'elType':'section','settings':s,'elements':cols,'isInner':inner}
class Node:
 def __init__(self,tag='root',attrs=()):self.tag=tag;self.attrs=dict(attrs);self.children=[]
 def plain(self):return ''.join(c if isinstance(c,str) else ('\n' if c.tag=='br' else c.plain()) for c in self.children).strip()
class Parse(HTMLParser):
 def __init__(self,s):super().__init__(convert_charrefs=True);self.root=Node();self.stack=[self.root];self.feed(s)
 def handle_starttag(self,tag,attrs):
  n=Node(tag,attrs);self.stack[-1].children.append(n)
  if tag not in ['br','img','hr','input','meta','link']:self.stack.append(n)
 def handle_endtag(self,tag):
  for i in range(len(self.stack)-1,0,-1):
   if self.stack[i].tag==tag:self.stack=self.stack[:i];break
 def handle_data(self,s):
  if s.strip():self.stack[-1].children.append(s)
def raw_nodes(markup,dark=False):
 root=Parse(markup).root
 def convert(n):
  if isinstance(n,str):return [text(n,dark)] if n.strip() else []
  txt=n.plain();tag=n.tag;cl=n.attrs.get('class','')
  if tag in ['script','style','dialog']:return []
  if tag in ['span','strong','b','small']:
   return [text(txt,dark,True)] if txt and txt not in ['→','↗'] else []
  if cl in ['ps-territories','ps-data-tags','ps-territory-levels']:
   labels=[c.plain() for c in n.children if isinstance(c,Node) and c.tag not in ['i','b'] or isinstance(c,Node) and c.tag=='b' and c.plain() not in ['→','↗']]
   return [text(' · '.join(labels),dark,True)] if labels else []
  if tag=='svg':return [] # Decorative inline graphics are replaced by native icons below.
  if tag in ['h1','h2','h3','h4']:return [heading(txt,tag if tag!='h4' else 'h3',dark)]
  if tag=='img':return [image(n.attrs.get('src',''),n.attrs.get('alt',''))]
  if tag=='a':return [button(txt,n.attrs.get('href','/login/'))] if txt else []
  if tag in ['p','figcaption','small']:return [text(txt,dark,tag!='p')] if txt else []
  if tag=='button':return [] # Demo animation/cookie controls are not deployed.
  articles=[c for c in n.children if isinstance(c,Node) and c.tag=='article']
  if articles:
   return [section([column(sum((convert(x) for x in a.children),[]),100/len(articles),card=True,dark=dark) for a in articles], 'Přehledy',dark=dark,inner=True)]
  if tag=='ul':return sum(([text('• '+c.plain(),dark)] for c in n.children if isinstance(c,Node) and c.tag=='li'),[])
  return sum((convert(c) for c in n.children),[])
 return convert(root)
def convert_page(part):
 out=copy.deepcopy(part);es=[]
 for src in part['template']['content']:
  cls=src['settings'].get('css_classes','');dark=any(c in cls for c in ['ps-home-hero','ps-results-hero','ps-results-access','ps-context'])
  photo='hero' in cls and ('home' in cls or 'results' in cls)
  cols=[];section_name='Obsah'
  for c in src['elements']:
   items=[]
   for e in c['elements']:
    k=e.get('widgetType');s=e['settings'];cl=s.get('_css_classes','')
    if k=='heading':
     items.append(heading(s['title'],s.get('header_size','h2'),dark));section_name=re.sub('<[^>]*>',' ',s['title'])[:60]
    elif k=='text-editor':
     v=s['editor']
     if 'breadcrumb' in cl:items.append(text(v,dark,True))
     elif 'overline' in cl or 'kicker' in cl:items.append(text(v,dark,True))
     else:items.append(text(v,dark))
    elif k=='button':items.append(button(s['text'],s['link']['url']))
    elif k=='html':items.extend(raw_nodes(s['html'],dark))
   if items:cols.append(column(items,c['settings'].get('_column_size',100),'ps-entry-cards' in cls))
  if cols:
   node=section(cols,section_name,dark,photo)
   if src['settings'].get('_element_id'):node['settings']['_element_id']=src['settings']['_element_id']
   es.append(node)
 out['template']['content']=es;out['template']['page_settings']={'hide_title':'yes'}
 return out
parts=[convert_page(p) for p in SOURCE['parts'] if p['key'] not in ['cookies','ochrana-osobnich-udaju']]
# The genuine WordPress menu is remapped by the native importer.
menu=wid('eael-simple-menu',{'eael_simple_menu_menu':'7','eael_simple_menu_layout':'horizontal','eael_simple_menu_preset':'preset-1','eael_simple_menu_item_color':WHITE,'eael_simple_menu_item_color_hover':'#A9D4ED','eael_simple_menu_item_padding':dim(12,12,12,12),'eael_simple_menu_item_typography_typography':'custom','eael_simple_menu_item_typography_font_family':'Montserrat','eael_simple_menu_item_typography_font_size':sl(12),'eael_simple_menu_item_typography_font_weight':'600'})
header=section([column([image(LOGO,'Poslanecká sněmovna – Přehledy',75)],18),column([menu],65),column([button('Přihlášení','/login/')],17)],'Společná hlavička',True)
header['settings']['padding']=dim(12,24,12,24)
footer_menu=copy.deepcopy(menu);footer_menu['id']=uid();footer_menu['settings']['eael_simple_menu_menu']='10';footer_menu['settings']['eael_simple_menu_layout']='vertical'
footer=section([column([image(LOGO,'Poslanecká sněmovna – Přehledy',55),text('Volební data. Preference. Souvislosti.',True),text('Soukromý analytický projekt.',True,True)],30),column([heading('Prozkoumejte projekt','h3',True),footer_menu],45),column([heading('Soukromí','h3',True),text('<p><a href="/zasady-ochrany-osobnich-udaju/">Ochrana osobních údajů</a></p>',True),text('<p><a href="/zasady-cookies-eu/">Cookies</a></p>',True),text('Soukromý projekt. Nejde o web státní instituce.',True,True)],25)],'Společná patička',True)
for key,node in [('header',header),('footer',footer)]:parts.append({'key':key,'type':key,'name':'PS – '+('Hlavička' if key=='header' else 'Patička'),'role':'other','seo':{'robots':'noindex','sitemap':False},'template':{'version':'0.4','type':'section','title':'PS '+key,'content':[node],'page_settings':{}}})
kit={'system_colors':[{'_id':'primary','title':'Námořnická modř','color':INK},{'_id':'secondary','title':'Modrá','color':BLUE},{'_id':'text','title':'Text','color':MUTED},{'_id':'accent','title':'Červená','color':RED}], 'custom_colors':[{'_id':'pswhite','title':'Bílá','color':WHITE},{'_id':'psnavy','title':'Tmavý podklad','color':NAVY}], 'system_typography':[{'_id':'primary','title':'Nadpisy','typography_typography':'custom','typography_font_family':'Montserrat','typography_font_weight':'700'},{'_id':'secondary','title':'Doplňkové nadpisy','typography_typography':'custom','typography_font_family':'Montserrat','typography_font_weight':'600'},{'_id':'text','title':'Text','typography_typography':'custom','typography_font_family':'Open Sans','typography_font_size':sl(16),'typography_font_weight':'400','typography_line_height':sl(1.7,'em')},{'_id':'accent','title':'Tlačítka','typography_typography':'custom','typography_font_family':'Open Sans','typography_font_weight':'700'}], 'custom_typography':[]}
for id,size,mob in [('psh1',52,34),('psh2',34,27),('psh3',22,20),('pssmall',11,11),('psbutton',13,13)]:kit['custom_typography'].append({'_id':id,'title':id,'typography_typography':'custom','typography_font_family':'Montserrat' if id.startswith('psh') else 'Open Sans','typography_font_size':sl(size),'typography_font_size_mobile':sl(mob),'typography_font_weight':'700' if id!='pssmall' else '400','typography_line_height':sl(1.2 if id.startswith('psh') else 1.6,'em')})
out=copy.deepcopy(SOURCE);out.update({'title':'Poslanecká sněmovna – nativní převod 1.8','parts':parts,'styles':'','site_settings':kit,'checks':{},'shared_layout':{'header_scope':'all_pages_and_descendants','footer_scope':'all_pages_and_descendants','single_source':True,'automatic_for_new_pages':True}})
for m in out['menus']:m['items']=[x for x in m['items'] if x['page'] not in ['cookies','ochrana-osobnich-udaju']]
out['report']['verification']={'structure':True,'live_data_connected':False,'browser_desktop':False,'browser_mobile':False,'elementor_import_verified':False}
out['report']['limitations']=['Nativní převod vyžaduje reálný import a nové vizuální ověření. Původní schválení náhledu není důkaz shody nativního editoru.','Existující login, právní dokumenty a analytické pluginy se zachovávají.']
out['report']['catalog']['state']='concept';out['report']['catalog']['summary']='Nativní převod schváleného vzhledu; technicky rozpracováno, dosud nenasazeno.'
out['report']['elementor_import_verified']=False
(ROOT/'native-design-1.8.json').write_text(json.dumps(out,ensure_ascii=False,indent=2))
print('Generated',len(parts),'native parts; no external CSS or HTML layout widgets.')
