import os
import shutil

brain_dir = r"C:\Users\baron\.gemini\antigravity\brain\e88b8a99-d907-45a6-a0ba-32a4e2dca149"
artifacts_dir = os.path.join(brain_dir, "artifacts")

if not os.path.exists(artifacts_dir):
    os.makedirs(artifacts_dir)

files_to_copy = [
    "index_page_1779028576643.png",
    "mass_tools_top_1779028596401.png",
    "dorks_generated_1779028637354.png",
    "mass_tools_bottom_1779028669010.png",
    "verify_outreach_lamp_ui_1779028568158.webp"
]

for file in files_to_copy:
    src = os.path.join(brain_dir, file)
    dst = os.path.join(artifacts_dir, file)
    if os.path.exists(src):
        shutil.copy2(src, dst)
        print(f"[OK] Copied {file} -> artifacts")
    else:
        print(f"[FAIL] {file} not found in brain directory")
