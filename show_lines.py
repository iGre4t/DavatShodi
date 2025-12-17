from pathlib import Path
lines = Path('app.js').read_text(encoding='utf-8').splitlines()
start = 3950
end = 4100
for i in range(start, end):
    print(f'{i+1}: {lines[i]}')
