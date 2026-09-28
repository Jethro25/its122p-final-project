# Admin & Staff Panels — What's New

Two new pages give Administrators and Staff a working management interface,
built against the existing PHP/MySQL backend (no backend changes were
needed — every endpoint used already existed).

## New / changed files

| File | What it is |
|---|---|
| `admin.html` | Admin panel: Users, Book Categories, Book Catalog, Reports, Transactions, System Records |
| `staff.html` | Staff panel: Customers, Customer Forms, Moderation, Transactions, Refunds, Report an Issue |
| `manage.js` | Shared logic for both pages (fetches data, renders tables, saves edits) |
| `dashboard.html` | Was a broken stub; now a working redirector to the correct page for the signed-in role |
| `auth.js` | Login now redirects by role (Admin → `admin.html`, Staff → `staff.html`, Customer → `index.html`); added demo accounts for Staff/Admin |
| `script.js` | Admin/Staff visiting the customer marketplace are now redirected to their own panel instead of being bounced to the login screen |
| `style.css` | Added styles for stat cards, status badges, filter bars, and inline table editing |

## Demo logins (from the seeded sample data)

| Username | Password | Role |
|---|---|---|
| `alice_wong` | `password` | Admin |
| `priya_singh` | `password` | Staff |
| `emma_clarke` | `password` | Customer |

## What each panel covers (mapped to `ProjectDraft.md`)

**Admin** — "Administrators can manage users / reports / books & categories / records", "Monitor transactions":
- Change any user's role or status, delete accounts
- Full CRUD on book categories and the book catalog
- View and resolve every report (all categories)
- Monitor and update any transaction's status
- View system records and add manual entries

**Staff** — "Manage Customer statuses & permissions", "Approve/reject Customer forms", "Manage Customer transactions", "Review refund requests":
- Change a Customer's status and edit their `permission` JSON (cannot change role or delete — Admin-only, per spec)
- Approve/reject `Verification_Form` and `Seller_Application` reports
- Review `User_Violation` / `Listing_Dispute` / `General_Feedback` reports (moderation)
- Update transaction statuses
- Approve/reject refund requests
- "Report an issue to Administration" — see note below

## Design decisions / gaps found in the spec vs. the code

1. **`managed_by_staff_id` on `USERS` doesn't exist.** `ProjectDraft.md`'s
   role-mapping table lists `USERS (status, permissions, managed_by_staff_id)`
   for Staff's "manage customer statuses" feature, but the actual `USER`
   table (`schema.sql`) has no such column — there's no way to record which
   staff member is assigned to which customer. Status and `permission`
   changes are fully implemented; if you want the "assigned staff" tracking
   too, that needs a new nullable `managed_by_staff_id` column + migration.

2. **"Report application issues to Administration" (Staff) has no backing
   table.** There's no ticketing/issues table in the ERD. Per your
   confirmation, this reuses `REPORTS` (`submitted_by_id` = the staff
   member, `report_category = 'General_Feedback'`, `related_entity_type =
   'None'`). It shows up in the Admin panel's Reports section like any
   other report.

3. **Two pre-existing frontend bugs blocked *all* Admin/Staff access**
   (fixed as part of this work, not new features):
   - `dashboard.html` checked `localStorage.getItem("user")`, but the app
     actually stores the session under `"librowseCurrentUser"` — so it
     always saw "not logged in."
   - It also compared `role === 'admin'`/`'staff'` (lowercase), while the
     database stores `'Admin'`/`'Staff'` (capitalized) — so even a correct
     session would always fail the check and show "Access Denied."
   - Separately, `index.html` redirected any non-Customer straight to
     `login.html`, and `login.html` always redirected everyone to
     `index.html` after signing in — so an Admin or Staff account had no
     reachable page at all, in a loop. Login now redirects by role, and
     landing on the wrong page redirects you to the right one instead of
     kicking you out.

4. **No authentication/authorization on the backend.** The backend
   README already flags this ("No authentication is included"). These
   pages call the same open REST API the customer marketplace does —
   there's nothing server-side stopping a Customer from calling
   `admin.html`'s underlying endpoints directly (e.g. via curl) and
   editing any table. That's a real gap if this goes beyond a class
   project; fixing it means adding a session/token check in
   `dispatch.php` before routing, as the backend README already suggests.

5. **Refunds are Staff-only, not shown to Admin.** `ProjectDraft.md`
   assigns "review refund requests" specifically to Staff, and doesn't
   list it as an Admin capability, so the Admin panel doesn't include a
   refunds tab. Say the word if you'd rather Admin have read/oversight
   access too.
