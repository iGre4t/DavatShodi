from pathlib import Path
path = Path('panel.php')
text = path.read_text(encoding='utf-8')
needle = 'data-pane= database'
idx = text.index(needle)
close = text.index('</button>', idx)
lb = text.find('\n', close)
if lb == -1:
    raise SystemExit('newline after database pane not found')
insert_pos = lb + 1
insert = '              <button type=button class=sub-item data-pane=printer-settings>\r\n                Printer Setting\r\n              </button>\r\n'
text = text[:insert_pos] + insert + text[insert_pos:]
path.write_text(text, encoding='utf-8')
