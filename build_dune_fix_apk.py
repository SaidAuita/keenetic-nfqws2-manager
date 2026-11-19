import os
import sys
import glob
import shutil
import zipfile
import subprocess

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
TOOLS_DIR = os.path.join(BASE_DIR, 'scratch', 'android_tools')
APP_DIR = os.path.join(BASE_DIR, 'dune_fix_app')
BUILD_DIR = os.path.join(APP_DIR, 'build')

JAVA = os.path.join(TOOLS_DIR, 'jdk', 'jdk-17.0.10+7', 'bin', 'java.exe')
JAVAC = os.path.join(TOOLS_DIR, 'jdk', 'jdk-17.0.10+7', 'bin', 'javac.exe')
AAPT2 = os.path.join(TOOLS_DIR, 'aapt2.exe')
R8 = os.path.join(TOOLS_DIR, 'r8.jar')
ANDROID_JAR = os.path.join(TOOLS_DIR, 'android.jar')
SIGNER = os.path.join(TOOLS_DIR, 'uber-apk-signer.jar')

def run(cmd, desc):
    print(f"[*] {desc}...")
    res = subprocess.run(cmd, shell=True, capture_output=True)
    out = res.stdout.decode('cp1251', errors='replace') if res.stdout else ''
    err = res.stderr.decode('cp1251', errors='replace') if res.stderr else ''
    if res.returncode != 0:
        print(f"[!] ERROR in {desc}:")
        print("STDOUT:", out)
        print("STDERR:", err)
        sys.exit(1)
    if out.strip():
        print("   ", out.strip()[:200])

def main():
    print("=" * 60)
    print(" 🔨 BUILDING DUNE YOUTUBE FIX APK (dune-fix.apk)")
    print("=" * 60)

    # 1. Prepare build directories
    gen_dir = os.path.join(BUILD_DIR, 'gen')
    classes_dir = os.path.join(BUILD_DIR, 'classes')
    dex_dir = os.path.join(BUILD_DIR, 'dex')
    res_zip = os.path.join(BUILD_DIR, 'res.zip')
    unaligned_apk = os.path.join(BUILD_DIR, 'unaligned.apk')
    final_apk = os.path.join(BUILD_DIR, 'dune-fix.apk')

    for d in [gen_dir, classes_dir, dex_dir]:
        shutil.rmtree(d, ignore_errors=True)
        os.makedirs(d, exist_ok=True)

    manifest = os.path.join(APP_DIR, 'src', 'main', 'AndroidManifest.xml')
    res_dir = os.path.join(APP_DIR, 'src', 'main', 'res')
    java_src_dir = os.path.join(APP_DIR, 'src', 'main', 'java')

    # 2. AAPT2 Compile resources
    run(f'"{AAPT2}" compile --dir "{res_dir}" -o "{res_zip}"', "Compiling Android resources")

    # 3. AAPT2 Link resources
    run(f'"{AAPT2}" link -I "{ANDROID_JAR}" --manifest "{manifest}" -o "{unaligned_apk}" --java "{gen_dir}" "{res_zip}" --auto-add-overlay', "Linking resources & generating R.java")

    # 4. JAVAC Compile Java sources
    java_files = glob.glob(os.path.join(java_src_dir, '**', '*.java'), recursive=True)
    gen_java_files = glob.glob(os.path.join(gen_dir, '**', '*.java'), recursive=True)
    all_sources = java_files + gen_java_files
    
    sources_txt = os.path.join(BUILD_DIR, 'sources.txt')
    with open(sources_txt, 'w', encoding='utf-8') as f:
        for s in all_sources:
            f.write(s.replace('\\', '/') + '\n')

    run(f'"{JAVAC}" -encoding UTF-8 -source 1.8 -target 1.8 -cp "{ANDROID_JAR};{gen_dir}" -d "{classes_dir}" "@{sources_txt}"', "Compiling Java sources to bytecode")

    # 5. D8 Compile .class to classes.dex
    class_files = glob.glob(os.path.join(classes_dir, '**', '*.class'), recursive=True)
    classes_txt = os.path.join(BUILD_DIR, 'classes.txt')
    with open(classes_txt, 'w', encoding='utf-8') as f:
        for c in class_files:
            f.write(c.replace('\\', '/') + '\n')

    class_args = " ".join([f'"{c}"' for c in class_files])
    run(f'"{JAVA}" -cp "{R8}" com.android.tools.r8.D8 --output "{dex_dir}" --lib "{ANDROID_JAR}" {class_args}', "Compiling classes to DEX")

    # 6. Add classes.dex into unaligned.apk
    print("[*] Packaging classes.dex into APK...")
    classes_dex = os.path.join(dex_dir, 'classes.dex')
    with zipfile.ZipFile(unaligned_apk, 'a', zipfile.ZIP_DEFLATED) as apk_zip:
        apk_zip.write(classes_dex, 'classes.dex')

    # 7. Sign and zipalign APK
    run(f'"{JAVA}" -jar "{SIGNER}" -a "{unaligned_apk}" -o "{BUILD_DIR}" --allowResign', "Signing & zip-aligning APK")

    # Find the signed apk
    signed_candidates = glob.glob(os.path.join(BUILD_DIR, '*aligned*debugSigned*.apk'))
    if not signed_candidates:
        signed_candidates = glob.glob(os.path.join(BUILD_DIR, '*signed*.apk'))

    if signed_candidates:
        shutil.copy2(signed_candidates[0], final_apk)
        root_apk = os.path.join(BASE_DIR, 'dune-fix.apk')
        shutil.copy2(final_apk, root_apk)
        print(f"\n[+] SUCCESS! Final signed APK created:")
        print(f"    Build APK: {final_apk}")
        print(f"    Root APK:  {root_apk}")
        print(f"    Size:      {os.path.getsize(final_apk):,} bytes")
    else:
        print("[!] Warning: Signed APK candidate not found in build directory.")

if __name__ == '__main__':
    main()
