import pathlib, sys
path=pathlib.Path('prizes.php')
lines=path.read_text(encoding='utf-8').splitlines()
