const test=require('node:test'),assert=require('node:assert/strict');
const {confinedStatus}=require('../plugin/runtime/sandbox.cjs');
test('content process requires active seccomp, no-new-privileges and an unprivileged UID',()=>{
 const status='Uid:\t1001\t1001\t1001\t1001\nNoNewPrivs:\t1\nSeccomp:\t2\nCapEff:\t0000000000000000\nSeccomp_filters:\t3\n';
 assert.equal(confinedStatus(status,1001,2),true);
 assert.equal(confinedStatus(status.replace('Seccomp:\t2','Seccomp:\t0'),1001),false);
 assert.equal(confinedStatus(status.replaceAll('1001','0'),0),false);
 assert.equal(confinedStatus(status.replace('NoNewPrivs:\t1','NoNewPrivs:\t0'),1001),false);
});

test('inherited hosting filters alone do not prove browser sandbox',()=>{assert.equal(confinedStatus('Uid: 1001 1001 1001 1001\nNoNewPrivs: 1\nSeccomp: 2\nCapEff: 0000\nSeccomp_filters: 2\n',1001,2),false);});
test('only web content tabs are subject to the content-process sandbox check',()=>{
 const {webContentCommand}=require('../plugin/runtime/sandbox.cjs');
 assert.equal(webContentCommand(['firefox','-contentproc','-parentBuildID','1234','tab',''].join('\0')),true);
 assert.equal(webContentCommand(['firefox','-contentproc','-parentBuildID','1234','socket',''].join('\0')),false);
});
