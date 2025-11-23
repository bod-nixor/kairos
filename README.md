# Kairos Queue Management Portal

Kairos is a role-aware help-queue and room management portal for courses. It combines a PHP REST API, a lightweight JavaScript single-page UI, and a Python-based WebSocket relay to keep students, teaching assistants, managers, and administrators in sync in real time.

## Features

- **Google OAuth sign-in with domain enforcement**: Users authenticate with Google Identity and are restricted to the configured email domain via `ALLOWED_DOMAIN`. The API upserts users on first login and assigns a default role.
- **Role-based access control**: Roles (student, TA, manager, admin) flow through the PHP API and helper utilities in `src/rbac.php` to scope which courses, rooms, and queues a user can view or mutate.
- **Course, room, and queue directory**: Students can browse courses and the rooms within them, while queues are filtered based on their enrollments or staff assignments.
- **Queue participation**: Students join or leave queues atomically; TA actions move students into service and stop sessions while keeping auditability through `ta_assignments` and optional `ta_audit_log` entries.
- **WebSocket updates**: `ws_server.py` relays queue and projector events over Socket.IO so the front-end can update without polling. Server-sent change notifications can also be persisted through the `change_log` table.
- **Configurable environment**: The PHP layer reads `.env` values defined in `config/app.php`, and the WebSocket relay mirrors that behaviour to keep both services aligned.

## Architecture

- **PHP REST API (`public/api/`)**: Handles authentication, course/room/queue lookups, queue joins/leaves, TA workflows, and capability checks. Shared bootstrap and role helpers live in `config/app.php`, `public/api/bootstrap.php`, and `public/api/_helpers.php`.
- **Role utilities (`src/rbac.php`)**: Centralizes RBAC checks for course, room, and queue access, including helper lookups for student/TA/manager mappings.
- **Front-end (`public/`)**: Static HTML/CSS/JS shell that consumes the API and WebSocket events. JavaScript configuration is loaded from `public/api/config.php` and normalized in `public/js/config.js`.
- **WebSocket relay (`ws_server.py`)**: Flask-SocketIO app that accepts signed tokens, rooms clients into per-channel namespaces, and broadcasts queue/projector events emitted by the PHP API.

## Prerequisites

- PHP 8.1+ with `pdo_mysql`, `curl`, and `openssl` extensions enabled.
- MariaDB/MySQL 10.6+ with InnoDB.
- Python 3.10+ for the WebSocket relay (`pip install -r requirements.txt`).
- A Google Cloud OAuth client ID for the front-end sign-in flow.

## Configuration

Create a `.env` file in the project root (loaded by both PHP and `ws_server.py`) with values similar to:

```
APP_DEBUG=true
APP_TIMEZONE=UTC
ALLOWED_DOMAIN=example.edu
DEFAULT_ROLE_NAME=student

DB_DATABASE=kairos
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=kairos
DB_PASSWORD=secret
DB_CHARSET=utf8mb4

GOOGLE_CLIENT_ID=your-google-oauth-client-id.apps.googleusercontent.com

WS_SHARED_SECRET=replace-with-random-hex
WS_SOCKET_PATH=/websocket/socket.io
WS_PUBLIC_URL=wss://your-host.example.edu
SESSION_COOKIE_NAME=kairos_session
SESSION_COOKIE_PATH=/
```

The PHP layer also honours `DB_DSN` if you prefer a full DSN string, while the WebSocket relay reads the same `.env` for Socket.IO configuration.

## Database setup

Run the schema script to provision the database:

```
mariadb -u <user> -p < sql/initialize_schema.sql
```

The script creates core tables for roles, users, courses, rooms, queues, queue entries, TA assignments/audit records, enrollment mappings used by the RBAC helpers, a `queues_info` view for queue metadata, and supporting indexes for queue lookups.

## Running the stack locally

1. **Install PHP dependencies** (none beyond built-in extensions) and ensure the web root points to `public/`. For quick testing you can use PHP's built-in server:
   ```
   php -S 0.0.0.0:8000 -t public
   ```
2. **Start the WebSocket relay** in another terminal after installing Python dependencies:
   ```
   pip install -r requirements.txt
   WS_SHARED_SECRET=replace-with-random-hex python ws_server.py
   ```
3. **Sign in via the browser** at `http://localhost:8000/` using a Google account on the allowed domain. Create courses/rooms/queues in the database so they appear in the UI.

## Data flow highlights

- Authentication populates the `users` table and stores the session in PHP; REST endpoints guard access with `require_login()`.
- RBAC helpers derive course access from enrollment/staff mapping tables and short-circuit admin/manager privileges.
- Queue actions (`public/api/queues.php`) update `queue_entries`, emit `change_log` rows when available, and broadcast WebSocket events via `_ws_notify.php` to connected clients.
- TA actions (`public/api/ta/*.php`) move students into `ta_assignments`, mark sessions complete, and log optional audit rows for traceability.

Refer to `CHANGES_KAIROS.md` for deployment-specific notes and additional migration suggestions.
