from pathlib import Path
lines = Path('draw.php').read_text(encoding='utf-8').splitlines()
for i in range(510, 560):
    if i < len(lines):
        print(f"{i+1}: {lines[i].encode('ascii','replace').decode('ascii')}")
