from pathlib import Path
text = Path('draw.php').read_text(encoding='utf-8')
print(text[text.index('<script type="module"'):text.index('</script>')+9])
