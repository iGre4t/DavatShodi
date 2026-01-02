from pathlib import Path
lines = Path('api/data.php').read_text(encoding='utf-8').splitlines()
start = 830
end = 960
for i in range(start, min(end, len(lines))):
    print(f'{i+1}: {lines[i]}')
