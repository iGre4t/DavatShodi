from pathlib import Path
with open('api/guests.php','r',encoding='utf-8') as f:
    for i,line in enumerate(f,1):
        if 1400 <= i <= 1700:
            print(f'{i:04}: {line.rstrip()}')
