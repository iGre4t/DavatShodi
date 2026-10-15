# Live period game monitoring

The period Games page includes a horizontally scrollable ranking table with sticky column headers, individual level totals and round scores, referee names, team status, room occupancy and elapsed room timers. Normal games share one ranking; gender-separated games rank male and female teams separately. Unfinished teams and partial rounds count, ties share the same rank, and filtering never renumbers ranks. Completed level totals take precedence over their round data, matching the ranking export rule. Administrators can correct recorded scores and add/remove team members. Membership corrections are audited, check for concurrent edits, and preserve scores and room progress. Referee editing rules are unchanged.

## WebSocket service

Run Node.js 22+ on the server and install this service's dependencies:

```sh
cd services/egm-games-live
npm ci
EGM_UPSTREAM_URL=https://your-site.example EGM_PUBLIC_ORIGIN=https://your-site.example PORT=8091 npm start
```

Run it under your process supervisor. `EGM_UPSTREAM_URL` points to your PHP application's origin (an internal HTTP origin is also supported); `EGM_PUBLIC_ORIGIN` is the exact browser origin, including a non-default port when applicable. The service binds only to localhost.

Proxy paths ending in `/egm-games-live` from the public site to the service, retaining their query string, Cookie and Origin headers. Example nginx location in the site's HTTPS server block:

```nginx
location ~ /egm-games-live$ {
    proxy_pass http://127.0.0.1:8091;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header Origin $http_origin;
    proxy_set_header Cookie $http_cookie;
    proxy_read_timeout 60s;
}
```

For Apache use an equivalent WebSocket reverse proxy rule. The browser uses the same origin and the instance's existing panel session cookie. The service authorizes the period snapshot before upgrading and rechecks access on every read. Endpoints are restricted to EGM games.php paths; client cookies are sent only to the configured PHP origin. Clients cannot send mutations over this connection.

The service checks the database through PHP every second and sends updated snapshots only when the revision changes; heartbeats maintain connectivity and synchronize room clocks. This is WebSocket delivery with a one-second database observation interval. Without the service/proxy, the page falls back to authenticated HTTP refreshes every five seconds and shows its connection state. Timers tick locally each second in either mode. Hidden pages stop their socket; reconnection is automatic.

Deploy the modified shared PHP libraries, assets/egm-games.js, and the period pane template. No database schema migration is required. The WebSocket service and reverse proxy must be running for the connection indicator to report live mode.

Score details include members and identifiers, each recorded round, room, submission time and submitter. The save control remains visible while scrolling and only submits changed scores with concurrency checks. Incoming snapshots preserve score/member drafts, search focus and table scroll position. Without the WebSocket service, automatic HTTP refresh every five seconds remains available.
