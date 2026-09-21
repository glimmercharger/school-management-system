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
page shows the `https://<random>.trycloudflare.com` link and the logins.

Starting a new run cancels any run already in progress (`concurrency:
cancel-in-progress`), so **re-running the workflow is how you get a new link**.
To end a demo early, open the run and hit *Cancel workflow* — that destroys the
runner, and the tunnel with it.

## Signing in

**The password is generated fresh for every run** — there is no fixed one to
memorise here, and an old run's password is no use against a later run. It looks
like `Demo-3f9c1a2e!`. The run tells you it in three places, so you should not
have to hunt:

- a **notice at the top of the run page**, with the link and the admin login;
- a banner in the **job log**, at the end of the "Publish the link" step;
- the full table on the run's **summary** page.

**Every account that can log in shares that one password.** That is deliberate:
Gibbon looks completely different to a parent than it does to an administrator,
and the point of the demo is to be able to see both. It is only acceptable
because the database is thrown away when the job ends.

| Account | Username | Exists when |
| --- | --- | --- |
| Administrator (created by the installer) | `admin` | always |
| Everyone in the demo school | a numeric ID, e.g. `1117` | `demo_data` is on |

With `demo_data` on you get 1,178 usable accounts:

| Role | Accounts | Example from the demo data |
| --- | --- | --- |
| Parent | 704 | `2747` — Leonard Abbott |
| Student | 414 | `2746` — Reese Abbott |
| Teacher | 47 | `1117` — Camille Ballard |
| Administrator | 9 | `192` — Buffy Ellison |
| Support Staff | 4 | — |

The run summary picks one real teacher, student and parent out of the database
and lists them with the password, so you can go straight from the summary to
signing in as each role. The examples above are what it currently picks; trust
the summary over this table.

Demo usernames are numeric staff/student IDs rather than names — `1117`, not
`camille.ballard`. To find others, sign in as `admin` and go to **User Admin →
Manage Users**, which lists everyone with their username.

With `demo_data` off, `admin` is the only account that exists and the school is
empty.

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
- **Nothing phones home.** `statsCollection`, `registerGibbonSupport` and
  `cuttingEdgeCode` are all forced off, so the instance never registers itself
  with gibbonedu.org or tries to update itself.
- **A broken demo fails the run rather than publishing a link.** Gibbon's error
  page is served with HTTP 200, so "the server answered" proves nothing; both
  readiness gates require a 200 whose body actually contains the login form,
  once against the origin and once back through the tunnel.
- **The link is public.** Anyone who has it can use the instance for as long as
  the run lasts. Treat it as a disposable sandbox, never as somewhere to put real
  data. The vhost keeps `.git` (including the ~66 copies composer leaves under
  `vendor/`), `.github`, `installer/` and dotfiles off it, and the checkout runs
  with `persist-credentials: false` so the job's token is never written to disk
  inside the document root.
