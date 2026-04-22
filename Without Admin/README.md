# Maghreb Events — Static Frontend

This is the fully static version of the Maghreb Events website. All data that was previously loaded from the API (`webapi.jamie-datson.com`) is now **hardcoded directly in each HTML file**. There is no backend dependency — the site can be served from any static host (GitHub Pages, Netlify, Cloudflare Pages, an Apache/Nginx server, etc.).

---

## How to Update Content

Every page that displays dynamic data contains a clearly labelled JavaScript block near the bottom of the file. You only need to edit the data arrays/objects inside those blocks — no programming knowledge required beyond basic copy-paste.

---

### 1. Homepage Event Countdown — `index.html`

Find this block near the bottom of `index.html`:

```js
// ── Hardcoded Events ──────────────────────────────────────────────────────
const UPCOMING_EVENT = {
  title: "New Year's in Morocco",
  start_time: "2026-12-31T22:00:00Z"   // ISO 8601 UTC date
};
```

**To change the event:**
- Update `title` to the new event name.
- Update `start_time` to the event start date/time in UTC using the format `YYYY-MM-DDTHH:MM:SSZ`.

> The typewriter cycling phrases (English, Arabic, French, Spanish) are in the `phrases` array just below — update those too if the event name changes.

---

### 2. Partners — `partners/index.html`

Find this block:

```js
// ── Hardcoded Partners Data ────────────────────────────────────────────────
const PARTNERS = [
  {
    name: "Royal Air Maroc Virtual",
    type: "Virtual Airline",
    status: "verified",          // "verified" | "pending" | "suspended"
    description: "...",
    website: "https://...",      // leave "" for no link
    logo_url: ""                 // leave "" to show default icon
  },
  // ...
];
```

**To add a partner:** copy one object `{ ... }`, paste it inside the array, and fill in the fields.  
**To remove a partner:** delete the corresponding `{ ... },` block.  
**To update a partner:** edit the field values in place.

---

### 3. Airlines — `airlines/index.html`

Find this block:

```js
// ── Hardcoded Airlines Data ────────────────────────────────────────────────
const AIRLINES = [
  {
    name: "Royal Air Maroc Virtual",
    icao: "RAM",
    callsign: "MAROCAIR",
    status: "active",            // "active" | "observer" | "suspended"
    description: "...",
    pilot_count: 320,
    logo_url: ""
  },
  // ...
];
```

Also update the static stats section below the list if the numbers change:

```html
<p class="font-headline text-4xl font-light" id="stat-pilots">1 200</p>
...
<p class="font-headline text-4xl font-light" id="stat-vas">6</p>
```

---

### 4. Hitsquad Roster — `hitsquad/index.html`

Find this block:

```js
// ── Hardcoded Hitsquad Roster ──────────────────────────────────────────────
const ROSTER = [
  { name: "Ahmed Benali",    cid: "1412345", photo_url: "" },
  // ...
];
```

**To add a member:** append `{ name: "...", cid: "...", photo_url: "" }` inside the array.  
**To remove a member:** delete their line.  
`photo_url` accepts any image URL. Leave it `""` to use the default avatar.

---

### 5. Planning Team — `planning/index.html`

Find this block:

```js
// ── Hardcoded Planning Team ────────────────────────────────────────────────
const TEAM = {
  team_leads:     [ { name: "...", role: "...", cid: "...", bio: "...", photo_url: "" }, ... ],
  communications: [ ... ],
  routes:         [ ... ],
  technology:     [ ... ]
};
```

Members go into whichever section fits their role. Each member object supports:

| Field       | Required | Notes                                           |
|-------------|----------|-------------------------------------------------|
| `name`      | ✅        | Display name                                    |
| `role`      | ✅        | Job title shown as a badge                      |
| `cid`       | ❌        | VATSIM CID — omit or set `""` to hide           |
| `bio`       | ❌        | Short description — omit or set `""` to hide    |
| `photo_url` | ❌        | Avatar image URL — leave `""` for default avatar|

If a section has no members it is automatically hidden.

---

### 6. Pilot Briefings — `briefing/index.html`

Find this block:

```js
// ── Hardcoded Pilot Briefings ──────────────────────────────────────────────
const BRIEFINGS = [
  {
    title: "New Year's in Morocco — Pilot Briefing",
    url: "",           // ← Paste the direct PDF URL here
    created_at: "2026-04-01T00:00:00Z"
  }
];
```

- Set `url` to a **direct link to a publicly accessible PDF** (e.g. Google Drive direct-download, Dropbox, or a CDN URL).
- Add older briefings after the first entry — the first item is always shown by default; if there are multiple, a dropdown picker appears automatically.
- Leave the array empty (`const BRIEFINGS = [];`) to display the "no briefing published" message.

> **Google Drive tip:** to get a direct PDF link from Google Drive, replace `/view` with `/preview` in the share URL, e.g.:  
> `https://drive.google.com/file/d/FILE_ID/preview`

---

## Shared Navigation & Theme — `assets/site.js`

The navigation links, theme toggle and shared header are all controlled by `assets/site.js`. This file is **copied** into each subdirectory's `assets/` folder.

**If you add or remove a page from the nav**, update the `links` array in `assets/site.js` and re-copy the file to all subdirectories:

```js
ME.nav = {
  links: [
    { href: '/',         label: 'Home',          key: 'home' },
    { href: '/partners', label: 'Partners',       key: 'partners' },
    // ... add/remove entries here
  ],
  // ...
};
```

Then run:
```bash
for dir in admin airlines briefing callback giveaway hitsquad login partners planning; do
  cp assets/site.js $dir/assets/site.js
done
```

---

## Deployment

Because this is a fully static site, deployment is straightforward:

| Host                | Instructions                                                  |
|---------------------|---------------------------------------------------------------|
| **GitHub Pages**    | Push the `frontend-static/` folder to a `gh-pages` branch    |
| **Netlify**         | Drag and drop the `frontend-static/` folder into the dashboard|
| **Cloudflare Pages**| Connect your repo; set build output directory to `frontend-static` |
| **Apache / Nginx**  | Copy the folder contents to your document root                |

The `.htaccess` file is already included for Apache clean URL routing.

---

## What Was Removed

| Feature              | Status in static version                                      |
|----------------------|---------------------------------------------------------------|
| Backend API calls    | ❌ Removed — all data is hardcoded                            |
| VATSIM OAuth login   | ❌ Removed — login/admin pages kept but auth is no-op         |
| Admin panel          | ❌ Non-functional without backend                             |
| Live data syncing    | ❌ Data must be updated manually in the HTML files            |

