from pathlib import Path
lines = Path('draw.php').read_text(encoding='utf-8').splitlines()
for i in range(420,760):
    if i < len(lines):
        print(f"{i+1:4}: {lines[i].encode('ascii','replace').decode('ascii')}")
