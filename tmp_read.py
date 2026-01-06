from pathlib import Path
path = Path('guests_head.txt')
data = path.read_bytes()
pattern = b'function buildSmsWorkbook(rows)'
start = data.index(pattern)
start_line = data.index(b'return {', start)
print(data[start_line:start_line+400])
