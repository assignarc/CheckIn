#!/usr/bin/env python3
import os
import sys
import json
import argparse
from ftplib import FTP, FTP_TLS

# Default exclusion lists (patterns to match against paths)
EXCLUDE_DIRS = {
    ".git",
    "tests",
    "node_modules",
    ".github",
    ".agents",
    ".gemini",
    ".tempmediaStorage",
    "var/cache",
    "var/log",
    "var/tmp",
    "var/share",
    "var/archives",
    "vendor",
}

EXCLUDE_FILES = {
    ".env",
    ".env.local",
    ".env.dev.local",
    ".env.test.local",
    "deploy/config.json",
    "deploy/config.json.dist",
    "deploy/ftp_deploy.py",
    "phpunit.xml",
    "phpunit.dist.xml",
    "infection.json",
}

def load_config():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    config_path = os.path.join(script_dir, "config.json")
    
    if not os.path.exists(config_path):
        print(f"[-] Configuration file not found at {config_path}")
        print("    Please copy 'config.json.dist' to 'config.json' and fill in your FTP credentials.")
        sys.exit(1)
        
    try:
        with open(config_path, "r") as f:
            return json.load(f)
    except Exception as e:
        print(f"[-] Error reading config.json: {e}")
        sys.exit(1)

def should_exclude(rel_path):
    # Normalize path separators
    normalized = rel_path.replace(os.sep, "/")
    
    # Check directory exclusions
    for d in EXCLUDE_DIRS:
        if normalized == d or normalized.startswith(d + "/"):
            return True
            
    # Check file exclusions
    for f in EXCLUDE_FILES:
        if normalized == f or normalized.endswith("/" + f):
            return True
            
    # Ignore sqlite DB files in var
    if normalized.startswith("var/") and normalized.endswith(".db"):
        return True
        
    return False

def get_upload_list(base_dir):
    upload_files = []
    upload_dirs = set()
    
    for root, dirs, files in os.walk(base_dir):
        # Calculate relative path
        rel_root = os.path.relpath(root, base_dir)
        if rel_root == ".":
            rel_root = ""
            
        # Check if root is excluded
        if rel_root and should_exclude(rel_root):
            continue
            
        for d in list(dirs):
            dir_rel = os.path.join(rel_root, d) if rel_root else d
            if should_exclude(dir_rel):
                dirs.remove(d) # Don't recurse into this directory
            else:
                upload_dirs.add(dir_rel.replace(os.sep, "/"))
                
        for f in files:
            file_rel = os.path.join(rel_root, f) if rel_root else f
            if not should_exclude(file_rel):
                upload_files.append(file_rel.replace(os.sep, "/"))
                
    return sorted(list(upload_dirs)), sorted(upload_files)

def connect_ftp(config):
    print(f"[+] Connecting to {config['host']}:{config.get('port', 21)}...")
    
    if config.get("use_ssl", True):
        ftp = FTP_TLS()
    else:
        ftp = FTP()
        
    ftp.connect(config["host"], config.get("port", 21), timeout=30)
    ftp.login(config["username"], config["password"])
    
    if config.get("use_ssl", True):
        ftp.prot_p() # Secure data connection
        print("[+] Secure SSL connection established.")
    else:
        print("[+] Plain FTP connection established.")
        
    # Change to target directory
    target_dir = config.get("remote_dir", "/")
    print(f"[+] Navigating to remote root: {target_dir}")
    try:
        ftp.cwd(target_dir)
    except Exception as e:
        print(f"[-] Error: Target directory '{target_dir}' does not exist or access is denied: {e}")
        ftp.quit()
        sys.exit(1)
        
    return ftp

def ensure_remote_dir(ftp, base_remote, rel_dir):
    parts = rel_dir.split("/")
    current = base_remote
    for part in parts:
        if not part:
            continue
        current = f"{current}/{part}".replace("//", "/")
        try:
            ftp.cwd(current)
        except Exception:
            print(f"[MKDIR] Creating remote directory: {current}")
            try:
                ftp.mkd(current)
            except Exception as e:
                print(f"[-] Warning: Failed to create {current}: {e}")
                
    # Return to remote root
    ftp.cwd(base_remote)

def main():
    parser = argparse.ArgumentParser(description="TimeFlow Production FTP Deployment Script")
    parser.add_argument("--dry-run", action="store_true", help="Print directories and files to upload without performing changes")
    args = parser.parse_args()
    
    config = load_config()
    
    # The workspace root is the parent directory of this script's folder
    workspace_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    os.chdir(workspace_root)
    
    print("[+] Scanning project workspace for files to deploy...")
    dirs_to_upload, files_to_upload = get_upload_list(workspace_root)
    
    print(f"[+] Scan completed: found {len(dirs_to_upload)} directories and {len(files_to_upload)} files to sync.")
    
    if args.dry_run:
        print("\n=== DRY RUN MODE: No actions will be taken ===")
        print("\nDirectories to create:")
        for d in dirs_to_upload:
            print(f"  [DIR]  {d}")
        print("\nFiles to upload:")
        for f in files_to_upload:
            print(f"  [FILE] {f}")
        print("\n=== Dry run completed ===")
        return
        
    # Real upload
    ftp = connect_ftp(config)
    remote_root = ftp.pwd()
    
    try:
        # Create directories
        for d in dirs_to_upload:
            ensure_remote_dir(ftp, remote_root, d)
            
        # Upload files
        success_count = 0
        fail_count = 0
        
        for idx, f in enumerate(files_to_upload, start=1):
            local_path = os.path.join(workspace_root, f.replace("/", os.sep))
            remote_path = f
            
            print(f"[{idx}/{len(files_to_upload)}] Uploading: {remote_path}...", end="", flush=True)
            
            uploaded = False
            for attempt in range(1, 4):
                try:
                    with open(local_path, "rb") as fp:
                        ftp.storbinary(f"STOR {remote_path}", fp)
                    print(" OK" if attempt == 1 else f" OK (attempt {attempt})")
                    success_count += 1
                    uploaded = True
                    break
                except Exception as e:
                    if attempt < 3:
                        try:
                            ftp.close()
                        except Exception:
                            pass
                        try:
                            ftp = connect_ftp(config)
                            remote_root = ftp.pwd()
                        except Exception:
                            pass
                    else:
                        print(f" FAILED ({e})")
                        fail_count += 1
                
        print("\n=== Deployment Completed ===")
        print(f"Successful uploads: {success_count}")
        print(f"Failed uploads: {fail_count}")
        
    finally:
        try:
            ftp.quit()
            print("[+] Connection closed cleanly.")
        except Exception:
            pass

if __name__ == "__main__":
    main()
