# Maghreb Events — Backend API

PHP 8.3 · SQLite · JWT Auth · Zero dependencies

---

## Quick Start

```bash
# 1. Drop the folder on your server (Apache or Nginx)
# 2. Point the web root to /maghreb-api
# 3. Start the built-in dev server (local only)
php -S localhost:8080 index.php

# Default superadmin credentials (change immediately)
# Email:    admin@maghrebevents.com
# Password: admin1234
# CID:      1000004
```

---

## Project Structure

```
maghreb-api/
├── index.php              # Entry point — CORS, routing, error handling
├── .htaccess              # Apache rewrite rules
├── nginx.conf             # Nginx config template
├── config/
│   ├── database.php       # SQLite PDO singleton + auto-migration + seed
│   ├── auth.php           # JWT generation & verification, role guards
│   ├── router.php         # Lightweight regex router
│   └── helpers.php        # json_response, body(), paginate(), audit()
├── routes/
│   ├── auth.php           # Login, logout, /me, password change
│   ├── events.php         # Full events CRUD + status patch
│   ├── partners.php       # Full partners CRUD + status patch
│   ├── airlines.php       # Full airlines CRUD + status patch
│   ├── admins.php         # Admin management + audit log (superadmin)
│   └── dashboard.php      # Aggregated stats for the management terminal
└── data/
    └── maghreb.db         # Auto-created SQLite database (gitignore this)
```

---

## Authentication

All protected routes require a `Bearer` token in the `Authorization` header.

```
Authorization: Bearer <jwt_token>
```

Tokens expire after **8 hours**. There are two roles:
- `admin` — can read/write events, partners, airlines
- `superadmin` — additionally manages admins, deletes records, views audit log

---

## API Reference

### Base URL
```
http://localhost:8080/api
```

---

### Auth

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/auth/login` | — | Login and receive JWT |
| GET | `/auth/me` | ✓ | Get current admin profile |
| POST | `/auth/logout` | ✓ | Logout (audit log entry) |
| PUT | `/auth/password` | ✓ | Change own password |

**POST /auth/login**
```json
// Request
{ "email": "admin@maghrebevents.com", "password": "admin1234" }

// Response 200
{
  "token": "eyJ...",
  "admin": { "id": 1, "cid": "1000004", "name": "Admin User", "email": "...", "role": "superadmin" }
}
```

---

### Dashboard

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/dashboard` | ✓ | Aggregated stats + recent activity |

```json
// Response 200
{
  "events":   { "total": 2, "upcoming": 2, "active": 0, "completed": 0 },
  "partners": { "total": 2, "verified": 2, "pending": 0 },
  "airlines": { "total": 5, "total_pilots": 1140, "active": 4, "observer": 1 },
  "recent_events": [...],
  "recent_log":    [...]
}
```

---

### Events

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/events` | — | List events (paginated) |
| GET | `/events/:id` | — | Get single event |
| POST | `/events` | ✓ | Create event |
| PUT | `/events/:id` | ✓ | Update event |
| PATCH | `/events/:id/status` | ✓ | Update status only |
| DELETE | `/events/:id` | superadmin | Delete event |

**Query params:** `?page=1&per=20&status=upcoming`

**Valid statuses:** `upcoming` · `active` · `completed` · `cancelled`

**POST /events body:**
```json
{
  "title":       "New Year's in Morocco",
  "description": "Celebrate the new year...",
  "start_time":  "2025-01-01 06:00:00",
  "end_time":    "2025-01-01 10:00:00",
  "status":      "upcoming",
  "banner_url":  "https://..."
}
```

---

### Partners

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/partners` | — | List partners |
| GET | `/partners/:id` | — | Get single partner |
| POST | `/partners` | ✓ | Create partner |
| PUT | `/partners/:id` | ✓ | Update partner |
| PATCH | `/partners/:id/status` | ✓ | Update status only |
| DELETE | `/partners/:id` | superadmin | Delete partner |

**Query params:** `?status=verified&type=Regional+Division`

**Valid statuses:** `pending` · `verified` · `suspended` · `inactive`

---

### Airlines

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/airlines` | — | List airlines (with stats) |
| GET | `/airlines/:id` | — | Get single airline |
| POST | `/airlines` | ✓ | Register airline |
| PUT | `/airlines/:id` | ✓ | Update airline |
| PATCH | `/airlines/:id/status` | ✓ | Update status only |
| DELETE | `/airlines/:id` | superadmin | Remove airline |

**Query params:** `?status=active&search=royal`

**Valid statuses:** `active` · `observer` · `suspended` · `inactive`

**POST /airlines body:**
```json
{
  "name":        "Royal Air Maroc Virtual",
  "icao":        "RAM-V",
  "callsign":    "MAROCAIR",
  "status":      "active",
  "description": "Flag carrier of Morocco virtual sky.",
  "pilot_count": 340
}
```

---

### Admins *(superadmin only)*

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/admins` | superadmin | List all admins |
| POST | `/admins` | superadmin | Create new admin |
| DELETE | `/admins/:id` | superadmin | Delete admin |
| GET | `/audit-log` | superadmin | View audit trail |

---

### Health

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/health` | — | Service health check |

---

## Pagination

All list endpoints return:
```json
{
  "data": [...],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 47,
    "total_pages": 3
  }
}
```

---

## Error Responses

```json
{ "error": "Unauthorized — invalid or expired token" }   // 401
{ "error": "Forbidden — insufficient privileges" }        // 403
{ "error": "Event not found" }                            // 404
{ "error": "Missing required fields: title, start_time" } // 422
{ "error": "ICAO code 'RAM-V' already exists" }          // 409
{ "error": "Internal server error", "message": "..." }    // 500
```

---

## Production Checklist

- [ ] Change `$secret` in `config/auth.php` to a long random string
- [ ] Change the default admin password immediately after first login
- [ ] Move `data/maghreb.db` outside the web root and update the path in `config/database.php`
- [ ] Add `data/` to `.gitignore`
- [ ] Set `display_errors = Off` in `php.ini`
- [ ] Remove `"message"` from the 500 error handler in `index.php`
- [ ] Enable HTTPS and restrict `Access-Control-Allow-Origin` to your domain

---

## Frontend Integration Example

```javascript
// Login
const res = await fetch('http://localhost:8080/api/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'admin@maghrebevents.com', password: 'admin1234' })
});
const { token } = await res.json();

// Authenticated request
const dashboard = await fetch('http://localhost:8080/api/dashboard', {
  headers: { 'Authorization': `Bearer ${token}` }
}).then(r => r.json());

// Update event times (Management Terminal)
await fetch(`http://localhost:8080/api/events/1`, {
  method: 'PUT',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    start_time: '2025-11-15 06:00:00',
    end_time:   '2025-11-15 10:00:00'
  })
});
```
