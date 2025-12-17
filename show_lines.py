from pathlib import Path
import sys
sys.stdout.reconfigure(encoding='utf-8')
lines = Path('invitepanel.php').read_text(encoding='utf-8').splitlines()
for i in range(540, 620):
    print(f'{i+1}: {lines[i]}')
