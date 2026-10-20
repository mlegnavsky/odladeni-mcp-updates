#!/usr/bin/env python3
"""Transactional FTPS deployment engine. Disabled for production by default.

Only a fixed test path is permitted until production allowlists and healthchecks
are independently reviewed. Requires explicit expected remote SHA256.
"""
import ftplib
import hashlib
import io
import os
import ssl
import sys
import uuid

TEST_DIR = "kits"
TEST_FILE = "odladeni-managed-deploy-probe.txt"
TEST_PAYLOAD = b"ODLADENI GUARDED DEPLOY V2\n"
MAX_BYTES = 1024 * 1024

def sha(data):
    return hashlib.sha256(data).hexdigest()

def read(ftp, name):
    out = []
    ftp.retrbinary("RETR " + name, out.append)
    data = b"".join(out)
    if len(data) > MAX_BYTES:
        raise RuntimeError("Remote file exceeds size limit")
    return data

def names(ftp):
    return {p.rsplit("/", 1)[-1] for p in ftp.nlst()}

def store(ftp, name, payload):
    ftp.storbinary("STOR " + name, io.BytesIO(payload))

def run():
    mode = os.getenv("DEPLOY_MODE", "verify")
    if mode not in ("verify", "deploy"):
        sys.exit("Invalid mode")
    host = os.environ["WEB_FTP_HOST"].strip()
    user = os.environ["WEB_FTP_USER"]
    password = os.environ["WEB_FTP_PASSWORD"]
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=20)
    try:
        ftp.connect(host, 21)
        ftp.auth()
        ftp.login(user, password)
        ftp.prot_p()
        ftp.cwd(TEST_DIR)
        current_names = names(ftp)
        exists = TEST_FILE in current_names
        before = read(ftp, TEST_FILE) if exists else None
        expected = os.getenv("EXPECTED_REMOTE_SHA256", "ABSENT").strip()
        actual = sha(before) if before is not None else "ABSENT"
        print("Remote checksum:", actual)
        print("Proposed checksum:", sha(TEST_PAYLOAD))
        if mode == "verify":
            print("READ ONLY: no changes made")
            return
        if expected != actual:
            sys.exit("REFUSED: expected remote SHA256 does not match")
        if before == TEST_PAYLOAD:
            print("Already deployed; no changes")
            return
        token = uuid.uuid4().hex
        stage = ".odladeni-stage-" + token + ".txt"
        backup = ".odladeni-backup-" + token + ".txt"
        stage_created = False
        backup_created = False
        installed = False
        try:
            stage_created = True
            store(ftp, stage, TEST_PAYLOAD)
            if sha(read(ftp, stage)) != sha(TEST_PAYLOAD):
                raise RuntimeError("Stage checksum mismatch")
            if before is not None:
                backup_created = True
                store(ftp, backup, before)
                if sha(read(ftp, backup)) != actual:
                    raise RuntimeError("Backup checksum mismatch")
                if sha(read(ftp, TEST_FILE)) != actual:
                    raise RuntimeError("Remote changed during deployment")
                ftp.delete(TEST_FILE)
            ftp.rename(stage, TEST_FILE)
            stage_created = False
            installed = True
            if sha(read(ftp, TEST_FILE)) != sha(TEST_PAYLOAD):
                raise RuntimeError("Post-deployment checksum mismatch")
            print("DEPLOY VERIFIED:", sha(TEST_PAYLOAD))
            # Backup remains server-side for recovery and audit.
            if backup_created:
                print("BACKUP RETAINED:", backup, actual)
        except Exception:
            print("DEPLOY FAILED: attempting rollback")
            try:
                # A failed STOR/rename can leave a partial target. Restore exact prior state.\n                if installed or (before is not None and (TEST_FILE not in names(ftp) or sha(read(ftp, TEST_FILE)) != actual)) or (before is None and TEST_FILE in names(ftp)):
                    if TEST_FILE in names(ftp):
                        ftp.delete(TEST_FILE)
                    if backup_created and backup in names(ftp):
                        ftp.rename(backup, TEST_FILE)
                        backup_created = False
                    elif before is not None:
                        store(ftp, TEST_FILE, before)
                    if before is not None and sha(read(ftp, TEST_FILE)) != actual:
                        raise RuntimeError("Rollback checksum mismatch")
                    if before is None:
                        print("ROLLBACK: new file removed")
                    else:
                        print("ROLLBACK VERIFIED:", actual)
            except Exception as e:
                print("CRITICAL: rollback failed:", repr(e))
                raise
            finally:
                if stage_created:
                    try:
                        ftp.delete(stage)
                    except Exception:
                        print("WARNING: staged file cleanup failed")
            raise
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

if __name__ == "__main__":
    run()
