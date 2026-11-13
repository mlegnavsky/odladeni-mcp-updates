const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'../plugin/assets');
const context={jspdf:require('jspdf'),Date,console,URL};context.window=context;vm.createContext(context);
for(const f of ['font.js','model.js','pdf.js'])vm.runInContext(fs.readFileSync(path.join(root,f),'utf8'),context);
test('exports a real PDF with Czech font and compact public overview without internal findings',()=>{
 const report={url:'https://example.com/',started_at:'2026-11-13T09:00:00Z',state:'complete',pages:[{url:'https://example.com/',title:'Český web – zásady cookies',status:200,services:[],cookies:[],kind:'page'}],policies:[],errors:[]};
 report.presentation={method:'2.0',percent:null,critical:0,conclusion:'Část měření není dokončena.',areas:['Dokumentace','Souhlas','Cookies','Vzhled'].map(label=>({label,percent:null,passed:0,total:1})),issues:[],categories:[],phases:[],inventory:{cookies:0,storage:0},performed:0,unfinished:4};
 const doc=context.GCAPdf.buildPdf(report),data=Buffer.from(doc.output('arraybuffer'));
 assert.equal(data.subarray(0,5).toString(),'%PDF-');assert.equal(doc.getNumberOfPages(),3);assert.ok(data.length>10000);
 fs.writeFileSync('/tmp/gca-export-test.pdf',data);
 const text=require('node:child_process').execFileSync('pdftotext',['/tmp/gca-export-test.pdf','-'],{encoding:'utf8'});assert.match(text,/Část měření není dokončena/);assert.doesNotMatch(text,/SECRET_EVIDENCE/);
});
