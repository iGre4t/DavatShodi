# Iran Map

Create Task Club → Custom TaskClub → Iran Map.

This is a complete coded template based on Standard Task Club. Its participant
task screen adds a `نقشه ایران` button. The side slide shows the configured event
logo above an inline SVG of Iran and a `برگشت` button using the same
`tc-bottom-cta` / `tc-bottom-cta-btn` styles as the other bottom actions.
The existing rewards roadmap remains available.

Successful mission completions (server-recorded score above zero) open the map.
One random dot per mission is stored in the instance database under a per-user
`iran_map_<sha256(work ID)>` data key. A database advisory lock serializes updates,
so retries and concurrent requests cannot duplicate or relocate dots. Geometry
sampling keeps dots inside the SVG outline. Zero-score and incomplete missions
do not earn dots; previous positive completions are reconciled on first sync.

The reveal waits for slide animation completion, a visible browser tab, an
on-screen map and a short viewing pause. The server records the reveal only
after the dot is visible. Interrupted reveals retry on later visits. Existing
dots render at their saved positions. The client checks for delayed awards every
10 seconds while idle and after closing a mission; it does not interrupt an
active mission or reward dialog. The map endpoint uses the existing session and
CSRF validation and reads scores from the server rather than accepting scores
or coordinates from the browser.

Checks: `php tests/iran_map_dots_test.php` and
`php tests/task_club_templates_test.php`. The browser harness in
`outputs/iran-map-qa` covers layout, navigation and delayed dot reveal.

The slide supports keyboard focus containment, Escape to return, focus restoration
and reduced motion. No map API or external request is needed by participants.

Source: `mini apps/taskclub-templates/iran-map`. Source templates are blocked
from direct HTTP access by the parent `.htaccess`; generated instances are served
from their normal mission directories. All runtime data is initialized separately
by the creator.

The SVG outline is derived from Natural Earth 1:50m Admin 0 Countries (public domain):
https://www.naturalearthdata.com/downloads/50m-cultural-vectors/50m-admin-0-countries-2/
GeoJSON input:
https://github.com/nvkelso/natural-earth-vector/blob/master/geojson/ne_50m_admin_0_countries.geojson
Longitude is scaled by cos(32°) and latitude inverted, then fitted to the viewBox.
