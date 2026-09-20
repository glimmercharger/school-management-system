# Live demo

`live-demo.yml` stands up a throwaway, publicly reachable Gibbon instance from a
GitHub Actions run and prints you a link. Nothing is deployed, nothing persists:
the database, the generated `config.php` and the admin account all live and die
with the job.

## Running it

Actions tab → **Live demo** → **Run workflow**. Two inputs:

| Input | Default | Meaning |
| --- | --- | --- |
| `duration_minutes` | `60` | How long to hold the link open. Clamped to 1–300; anything non-numeric falls back to 60. |
| `demo_data` | `true` | Load `gibbon_demo.sql` — a whole demo school (≈1,200 people, classes, timetables). Untick for an empty install. |

Takes roughly 4–6 minutes to come up. When it is ready, the run's **summary**
page shows the `https://<random>.trycloudflare.com` link and a table of logins —
an administrator plus one real teacher, student and parent from the demo data,
all sharing a password generated fresh for that run.

Starting a new run cancels any run already in progress (`concurrency:
cancel-in-progress`), so **re-running the workflow is how you get a new link**.
To end a demo early, open the run and hit *Cancel workflow*; the keep-alive step
traps that and shuts the tunnel down.

## How it works

```
MySQL service container
  ↓
apt: apache2 + mod_php 8.3 + extensions      composer install --no-dev
  ↓
cloudflared quick tunnel  ──▶ captures https://xxx.trycloudflare.com
  ↓
.github/scripts/install-demo.php  ──▶ installs Gibbon with that URL baked in
  ↓
Apache starts, readiness poll, link published, sleep
```

The one part worth understanding is **why the tunnel starts before the app is
installed**. Gibbon keeps its public origin in the `gibbonSetting` row
`absoluteURL`; `SessionFactory::populateSettings` loads it into the session and
every Twig template renders links and asset `src`s as `{{ absoluteURL }}/…`. So
the public hostname has to be known *before* the install writes that setting —
otherwise every stylesheet, script and link on the site points at `localhost` and
the page arrives unstyled and unnavigable. A quick tunnel happily returns 502s
until the origin answers, and the link is only published once the site is
actually serving, so starting it first costs nothing.

`install-demo.php` drives the same `Gibbon\Install\Installer` API that the
three-step web wizard at `installer/install.php` uses — it is not a
hand-rolled pile of SQL. Its header comments map each part back to the wizard
step it replaces.

## Notes

- **The demo data is stuck in one academic year.** `gibbon.sql` marks 2025-2026
  as `Current`. Gibbon picks the year by status rather than by date, so
  everything works, but once today falls outside that range, views scoped to
  *today* (timetable, attendance, daily dashboard widgets) come up empty. The run
  summary says so explicitly when it applies.
- **Every account that can log in shares one password.** That is the point — you
  can look around as a parent or a student, not just as an admin — and it is only
  acceptable because the database is destroyed with the job.
- **Nothing phones home.** `statsCollection`, `registerGibbonSupport` and
  `cuttingEdgeCode` are all forced off, so the instance never registers itself
  with gibbonedu.org or tries to update itself.
- **The link is public.** Anyone who has it can use the instance for as long as
  the run lasts. Treat it as a disposable sandbox, never as somewhere to put real
  data.
