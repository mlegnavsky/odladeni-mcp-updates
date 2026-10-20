#!/usr/bin/env python3
"""Guarded FTPS deployment of a single explicitly allowlisted test file.
Never touches production PHP or WordPress files.
"""
import ftplib
import hashlib
import io
import os
import ssl
import sys

TARGET_DIR = "kits"
TARGET_FILE = "odladeni-managed-deploy-probe.txt"
EXPECTED = b"ODLADENI MANAGED DEPLOY PROBE V1\n"

def digest(b):
    return hashlib.sha256(b).hexdigest()

def main():
    host = os.environ["WEB_FTP_HOST"].strip()
    user = os.environ["WEB_FTP_USER"]
    password = os.environ["WEB_FTP_PASSWORD"]
    mode = os.environ.get("DEPLOY_MODE", "verify")
    if mode not in ("verify", "deploy"):
        sys.exit("Invalid mode")
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=20)
    try:
        ftp.connect(host, 21)
        ftp.auth()
        ftp.login(user, password)
        ftp.prot_p()
        ftp.cwd(TARGET_DIR)
        files = {p.rsplit("/", 1)[-1] for p in ftp.nlst()}
        exists = TARGET_FILE in files
        old = None
        if exists:
            chunks = []
            ftp.retrbinary("RETR " + TARGET_FILE, chunks.append)
            old = b"".join(chunks)
        print("Target:", TARGET_DIR + "/" + TARGET_FILE)
        print("Existing SHA256:", digest(old) if old is not None else "absent")
        print("Proposed SHA256:", digest(EXPECTED))
        if mode == "verify":
            print("READ ONLY: no changes performed")
            return
        # A previously existing file must never be overwritten in this first deployment.
        if exists:
            if old == EXPECTED:
                print("Already deployed; no change")
                return
            sys.exit("Refusing to overwrite existing file")
        created = False
        try:
            # STOR can leave a partial file on failure: attempt cleanup only of this unique path.
            created = True
            ftp.storbinary("STOR " + TARGET_FILE, io.BytesIO(EXPECTED))
            chunks = []
            ftp.retrbinary("RETR " + TARGET_FILE, chunks.append)
            if b"".join(chunks) != EXPECTED:
                raise RuntimeError("Post-deploy checksum mismatch")
            print("DEPLOY PASS:", digest(EXPECTED))
        except Exception:
            if created:
                try:
                    ftp.delete(TARGET_FILE)
                    print("ROLLBACK: newly created test file removed")
                except Exception as cleanup_error:
                    print("ROLLBACK FAILED:", type(cleanup_error).__name__)
            raise
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

if __name__ == "__main__":
    main()
