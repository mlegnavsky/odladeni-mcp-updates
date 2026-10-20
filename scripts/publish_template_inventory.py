#!/usr/bin/env python3
"""Inventory ZIP kits over FTPS; publish JSON and dashboard with backup and read-back.\nTriggered for the first production inventory.\n"""
import ftplib, io, os, ssl, json, hashlib, zipfile, collections, posixpath
from pathlib import Path

ROOT="kits"
TARGET="odladeni-template-catalog-20261020"
MAX_ZIP=60*1024*1024
MAX_JSON=8*1024*1024

def collect(ftp, folder, depth=0):
    if depth>3: return []
    found=[]
    for name, facts in ftp.mlsd(folder):
        if name in (".","..",TARGET) or name.startswith("."): continue
        path=posixpath.join(folder,name)
        if facts.get("type")=="dir":
            found+=collect(ftp,path,depth+1)
        elif name.lower().endswith(".zip") and facts.get("type")=="file":
            found.append((path,int(facts.get("size","0"))))
    return found

def inspect(path, payload):
    out={"archive":posixpath.basename(path),"remote_path":path,"sha256":hashlib.sha256(payload).hexdigest(),"compatibility":"untested","widget_counts":{},"candidate_templates":0,"containers":0,"json_files":0,"warnings":[]}
    counts=collections.Counter()
    try:
        with zipfile.ZipFile(io.BytesIO(payload)) as z:
            for item in z.infolist():
                if item.is_dir() or not item.filename.lower().endswith(".json"):continue
                if item.file_size>MAX_JSON:
                    out["warnings"].append("Large JSON skipped: "+item.filename);continue
                try: obj=json.loads(z.read(item))
                except (ValueError,UnicodeError,RuntimeError):continue
                out["json_files"]+=1
                if isinstance(obj,dict) and ("content" in obj or "elements" in obj):out["candidate_templates"]+=1
                stack=[obj]
                while stack:
                    node=stack.pop()
                    if isinstance(node,list):stack.extend(node)
                    elif isinstance(node,dict):
                        if node.get("elType")=="container":out["containers"]+=1
                        if node.get("elType")=="widget" and isinstance(node.get("widgetType"),str):counts[node["widgetType"]]+=1
                        stack.extend(node.values())
    except (zipfile.BadZipFile,RuntimeError,ValueError) as exc:
        out["error"]=str(exc)
    out["widget_counts"]=dict(counts.most_common())
    return out

def read(ftp,name):
    blocks=[]
    ftp.retrbinary("RETR "+name,blocks.append)
    return b"".join(blocks)

def publish(ftp,name,payload):
    tmp=name+".incoming"
    backup=name+".previous"
    try:
        old=read(ftp,name)
    except ftplib.error_perm as e:
        if not str(e).startswith("550"):raise
        old=None
    if old==payload:
        print(name,"unchanged");return
    # Preserve prior bytes before replacing. Never delete backup automatically.
    if old is not None:
        try:
            prior=read(ftp,backup)
        except ftplib.error_perm:
            prior=None
        if prior is not None and prior!=old:
            raise RuntimeError("Backup exists with different content: "+backup)
        if prior is None:
            ftp.storbinary("STOR "+backup,io.BytesIO(old))
            if read(ftp,backup)!=old:raise RuntimeError("Backup verification failed")
    ftp.storbinary("STOR "+tmp,io.BytesIO(payload))
    if read(ftp,tmp)!=payload:raise RuntimeError("Staged file verification failed")
    if old is not None:ftp.delete(name)
    try:
        ftp.rename(tmp,name)
        if read(ftp,name)!=payload:raise RuntimeError("Post-publish verification failed")
    except Exception:
        if old is not None:
            try:
                ftp.storbinary("STOR "+name,io.BytesIO(old))
            except Exception:pass
        raise
    print("Published",name,hashlib.sha256(payload).hexdigest())

def main():
    ftp=ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=60)
    ftp.connect(os.environ["FTP_HOST"],21)
    ftp.login(os.environ["FTP_USER"],os.environ["FTP_PASSWORD"])
    ftp.prot_p()
    ftp.cwd(ROOT)
    archives=collect(ftp,".")
    print("Found ZIP archives:",len(archives))
    if not archives:raise RuntimeError("No ZIP files discovered; refusing empty catalog")
    result=[]
    for path,size in archives:
        if size>MAX_ZIP:
            result.append({"archive":posixpath.basename(path),"remote_path":path,"error":"ZIP too large for scanner","compatibility":"untested"})
            continue
        try:
            payload=read(ftp,path)
            result.append(inspect(path,payload))
        except (ftplib.all_errors,OSError,RuntimeError) as exc:
            result.append({"archive":posixpath.basename(path),"remote_path":path,"error":str(exc),"compatibility":"untested"})
    try:ftp.mkd(TARGET)
    except ftplib.error_perm:pass
    ftp.cwd(TARGET)
    catalog=json.dumps(result,ensure_ascii=False,indent=2).encode("utf-8")
    publish(ftp,"catalog.json",catalog)
    publish(ftp,"index.html",Path("dashboard/template-kits.html").read_bytes())
    ftp.quit()
    print("Catalog completed:",len(result),"ZIP entries")

if __name__=="__main__":main()
