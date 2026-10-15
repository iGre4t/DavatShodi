# Stage draw connection recovery

The general and period draw pages share `draw-connection.js`. It checks the guest draw and competition-prize state every five seconds, uses a seven-second request timeout, and retries state reads every 1.5 seconds after a failure. Browser online events trigger an immediate check.

During an outage, draw, confirmation, card selection, cancellation, reset, and their keyboard shortcuts are blocked. Existing numbers, cards and winner lists remain visible. A small status at the bottom indicates connection/recovery; raw network errors do not appear on the stage.

Controls unlock only after both draw states have been read successfully. Mutations are never automatically replayed: if a roll or confirmation reached the server but its response was lost, the pending selection or confirmed result is recovered from server state. Guest pending selections are returned only to their original operator and only while unexpired and still eligible. Genuine session/access problems remain blocked with a small status message.

Deployment requires updating `api/lib/egm-period-draws.php`, and copying these files into the template and each existing EGM directory:

- `period_draw.php` (apply the existing instance path transformations)
- `competition-prizes.js`
- `draw-connection.js` (new file)

`general_draw.php` already includes `period_draw.php`. No database migration or WebSocket service is required for draw recovery. Physical internet connectivity must return; the page then reconnects automatically without a reload. This change has been tested locally; upload it to the event host before using the stage page.
