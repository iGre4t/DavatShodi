from pathlib import Path
with open('guests.php','r',encoding='utf-8') as f:
    for i,line in enumerate(f,1):
        if 600 <= i <= 980:
            print(f'{i:04}: {line.rstrip()}')
