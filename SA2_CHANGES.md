# Librowse: Web Systems 2 scenarios applied

This file lists the changes made to apply Ma'am Bautista's collected scenarios (numbers in brackets, e.g. [Q27]).
Nothing in the existing database is deleted. New tables (`ACTIVITY_LOG`, `IDEMPOTENCY_KEYS`) and new columns
(`LIBROWSE_SESSIONS.last_seen_at`, `TRANSACTIONS.reserved_until`) are created automatically on first use,
the same way the project already adds `deleted_at`.

## Already in place before these changes

These were already correct and were not changed: the price comes from the database, not the browser [Q109].
Books are reserved atomically, so two buyers can't buy the same copy [Q46, Q115]. Passwords are hashed with bcrypt [Q26].
Every endpoint checks the role on the server [Q24, Q58–60]. Customers only see their own transactions, refunds and reports [Q110].
Tokens are revoked on logout [Q3], and the Back button after logout re-checks the session [Q113]. Records are soft-deleted.

## Sessions and logout

| Change | Where | Scenario |
|---|---|---|
| **Idle timeout** (30 min of inactivity) on top of the 8-hour absolute timeout, decided by the **server** | `lib/security.php` | Q2, Q72–74 |
| "You'll be signed out in 1:59" warning with **Stay signed in** / **Sign out** (display only; the server enforces it) | `session.js` | Q73, Q78 |
| Admin auto-refresh sends `X-Librowse-Background: 1`, so it does **not** keep an idle session alive | `management.js`, `security.php` | Q72 |
| Login page explains "signed out after a period of inactivity" | `auth.js` | Q75, Q77 |
| **Log out of all devices** (customer dashboard, admin and staff sidebar) | `auth.php?action=logout_all` | Q55, Q57 |
| Changing a user's **role** or making them non-Active ends all their sessions immediately | `api/user.php` | Q56, Q67 |
| Locking an account also ends its open sessions | `api/auth.php` | Q29 |

## Login security

| Change | Scenario |
|---|---|
| **One message for every failed sign-in**: unknown user, wrong password and locked account (with a wrong password) all get *"Invalid username/email or password"*. A wrong password no longer reveals that the username exists. | Q27, Q84 |
| Only someone who types the **correct** password learns that an account is locked or inactive | Q27, Q82 |
| An unknown username takes the same time to check as a real one, so response time doesn't leak which accounts exist | Q84 |
| **Per-IP limit**: 20 failed sign-ins in 15 min from one IP → `429 Too Many Requests` + `Retry-After` | Q11, Q29 |
| Per-account lock after 4 wrong passwords is kept (admin unlock + unlock request) | Q82–83 |
| Passwords longer than 72 characters are rejected (bcrypt limit) | Q26 |

## Duplicate submissions, concurrency, transactions

| Change | Where | Scenario |
|---|---|---|
| **Idempotency keys**: every create request (buy, trade, list a book, refund, report) carries an `Idempotency-Key`. A double click, retry or lost reply returns the **first** result (`Idempotent-Replayed: true`) and creates nothing new. | `lib/idempotency.php`, `session.js` | Q34, Q61, Q63, Q65, Q116, Q118 |
| If no reply arrives, the user is told honestly that the result is unknown and that **trying again is safe** | `script.js` | Q63, Q118 |
| Transaction status changes only apply if the status is **still** what the staff member saw. Otherwise `409 "just changed by someone else"` (no lost update) | `api/transactions.php` | Q67, Q114 |
| Refund decisions use the same rule, so two staff can't both decide one refund | `api/refund_request.php` | Q114 |
| **Reservations expire**: a Pending request not accepted within 72 h is cancelled and the book goes back on the shelf. This only applies to new requests. | `api/transactions.php` | Q46 |
| Book + category links are saved in **one database transaction** (all or nothing) | `api/books_catalog.php` | Q13, Q64 |

## Never trust the browser

| Change | Scenario |
|---|---|
| `created_by_admin_id` (categories) and `admin_id` (system records) are set by the server, not taken from the request | Q36, Q109 |
| Admin-created or edited usernames and emails are validated on the server too (an HTML injection in a username is blocked) | Q21, Q23 |
| Audit records can't be edited (`PUT` → 405) | Q70 |
| The username in the top bar is escaped before display (XSS) | Q36 |

## Logging and monitoring

| Change | Scenario |
|---|---|
| New **`ACTIVITY_LOG`** table stores who, what, when (UTC), IP and browser for each entry. Logged events: sign-ins (ok/failed), lockouts, unlocks, logouts, idle sign-outs, permission denials (403), role/status changes, transaction and refund decisions, and expired reservations. | Q38, Q69–70 |
| Passwords, tokens and photos are **never** logged | Q38, Q70 |
| **Admin → Activity Log** tab: filters plus a **monitoring** panel ("12 failed sign-ins in the last hour", "IP x tried 5 accounts", locked accounts) | Q71 |
| Automatic responses: account lock, IP throttling and idle sign-out | Q71 |

## Errors, configuration, deployment

| Change | Scenario |
|---|---|
| Uncaught errors return a friendly JSON message ("Nothing was changed — please try again"). Technical details go to the server log only (`APP_DEBUG=1` shows them). | Q33, Q77, Q101 |
| Database down → `503` + `Retry-After` and "Nothing was saved" (the site itself is up) | Q117 |
| Settings moved to `lib/config.php` and environment variables: `SESSION_IDLE_MINUTES`, `SESSION_MAX_HOURS`, `LOGIN_MAX_ATTEMPTS`, `LOGIN_IP_LIMIT`, `LOGIN_IP_WINDOW_MINUTES`, `RESERVATION_HOURS` | Q49–50 |
| CORS no longer falls back to "allow every website" (`*`). Only localhost, `*.vercel.app` and the same host are allowed. | Q36 |
| Security headers (`nosniff`, `X-Frame-Options`, `Referrer-Policy`), and `no-store` on signed-in pages in `vercel.json` | Q113 |
| `setup.php` (which drops all tables) only runs from the hosting computer, never on Vercel | Q36 |
| Removed `data/security.xml`, which still held old session-token hashes | Q38 |
| Fresh database fix: `transactions.php` adds `USER_BOOKS.deleted_at` if it is missing | Q103 |

## Test results (local PHP 8.3 with a MySQL-compatible server)

- Unknown user and wrong password both returned `401` with the same message. A locked account with the correct password returned `423`.
- 9 simultaneous buy requests for one book (3 buyers): **1** succeeded and **8** got `409`.
- The same Idempotency-Key sent twice created **one** transaction. The second reply had `Idempotent-Replayed: true`.
- Staff and admin deciding one transaction at the same time: one succeeded and the other was rejected.
- A session idle for 31 min returned `401 idle_timeout`. A background request did **not** reset the timer.
- After 20 failed sign-ins from one IP, the next ones returned `429` with `Retry-After: 900`.
- A request from an unknown website origin got no CORS permission.
- The browser test showed the idle warning, an automatic sign-out with an explanation, the Activity Log tab and log out of all devices. All pages ran with no JavaScript errors.
