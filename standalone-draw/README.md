# Standalone draw module

Copy this entire folder into a PHP project and open `draw.php`.

## Requirements

- PHP 7.4 or newer
- Write permission for `runtime/` and `data/`
- PHP served through a web server; opening the files directly will not run them

## Pages

- `draw.php`: randomly chooses an eligible participant and stores confirmed winners
- `prizes.php`: randomly chooses each prize once and fills one of 12 cards

The shared header and numeric keypad shortcuts navigate between both pages.

## Connect participants from another system

Edit `loadStandaloneParticipants()` in `config.php`. Return an array containing:

```php
[
    [
        'id' => 123,
        'code' => '4821',
        'full_name' => 'Example User',
    ],
]
```

The included implementation reads `data/participants.json`. It can be replaced
with a database query, API request, framework service, or another data source.

`id`, `code`, and `full_name` are required. The code is displayed as four digits.
Optional fields are documented in `config.php`.

## Prizes and state

Edit `data/prizes.csv` to define prizes. Keep the `id,name` header and use unique
numeric IDs.

- Confirmed winners: `runtime/standalone/winners of standalone.csv`
- Prize state: `data/prize_draw_state_standalone.json`

The reset keyboard shortcut on either page is `Numpad 8 + Numpad 9`.
Use `Numpad 1` for prizes and `Numpad 2` for participants.
