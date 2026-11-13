const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'../plugin/assets');
const context={jspdf:require('jspdf'),Date,console};context.window=context;vm.createContext(context);
for(const f of ['font.js','model.js','pdf.js'])vm.runInContext(fs.readFileSync(path.join(root,f),'utf8'),context);
test('exports a real PDF with Czech font and complete unverified findings',()=>{
 const report={url:'https://example.com/',started_at:'2026-11-13T09:00:00Z',state:'complete',pages:[{url:'https://example.com/',title:'Český web – zásady cookies',status:200,services:[],cookies:[],kind:'page'}],policies:[],errors:[]};
 const doc=context.GCAPdf.buildPdf(report),data=Buffer.from(doc.output('arraybuffer'));
 assert.equal(data.subarray(0,5).toString(),'%PDF-');assert.ok(doc.getNumberOfPages()>1);assert.ok(data.length>20000);
 fs.writeFileSync('/tmp/gca-export-test.pdf',data);
});
