# ManaLeague

A **Magic: The Gathering** platform for players and playgroups. ManaLeague lets you run tournaments, challenge friends, climb the Ranked ladder and keep your decks and collection in one place, built mobile-first.

**Live:** [manaleague.com.br](https://manaleague.com.br) (the interface is in Brazilian Portuguese)

The project started as an academic **SSDLC** (Secure Software Development Life Cycle) project, so security is part of the whole cycle, from requirements to deploy.

## Features

| Area | What you can do |
|---|---|
| **Tournaments** | round-robin leagues, Swiss, single or double elimination and Commander pods; finals and third-place match; standard format rules or custom house rules; repeat a tournament with the same settings |
| **Event day** | sign-up by invite link or QR code, player check-in on the event day, round timer and removal of no-shows |
| **Live** | players submit scores from their phones, with confirmation or dispute; public tournament page (no account needed) and TV mode |
| **Challenges** | 1v1 matches between friends, casual or ranked, with history, rivalries and your rival on your profile; a score the opponent does not confirm or dispute within 7 days is confirmed automatically |
| **Ranked** | seasons with ELO/PDL, rank on the profile and moderation ([rules](docs/ranked.md)) |
| **Life counter** | life counter for tournament matches, challenges and Ranked |
| **Decks** | deck building with Scryfall search, import and export (Arena format and ManaBox CSV), foils, banned-card tag, Pauper legality check (cards never printed at common are tagged and blocked from official-rules tournaments), opening-hand simulator, version history and deck comparison |
| **Collection** | ManaBox CSV import, search only your own cards and see what is missing to build a deck |
| **Profile** | personal address (`/p/your-handle`), photo or emoji, showcase, stats, recent form and achievements (some of them secret) |
| **Community** | friends, dashboard, feed, notifications and clubs |
| **Patch notes** | an in-app tab with what changed in each version, published from GitHub Releases |

## Stack

- **Back end:** Laravel 12 and PHP 8.4, with business rules in `app/Services/`.
- **Front end:** Blade, plain CSS and JavaScript, with no build step. The design system lives in `public/css/ui.css`.
- **Database:** PostgreSQL 17. Tests run on in-memory SQLite.
- **Infrastructure:**
  - Docker Compose, with the same runtime locally and in production;
  - Caddy (TLS) and a WAF: Nginx + ModSecurity with the OWASP CRS;
  - AWS: EC2, S3 for profile photos and SES for e-mail;
  - Cloudflare at the edge.
- **Integrations:** the [Scryfall API](https://scryfall.com/docs/api) for card data, images and ban lists.
- **Delivery:** GitHub Actions, SonarQube Cloud and Terraform.

```text
Internet → Cloudflare → Caddy (TLS) → Nginx + ModSecurity/OWASP CRS → PHP-FPM/Laravel → PostgreSQL
```

Details and past decisions are in [docs/architecture.md](docs/architecture.md).

## Running locally

You need Docker with Compose.

```bash
cp .env.example .env
# in .env, fill in:
#   APP_KEY=base64:<output of: openssl rand -base64 32>
#   DB_PASSWORD=<output of: openssl rand -hex 24>
docker compose up -d --build --wait --wait-timeout 300
```

- The app runs at [http://localhost:8080](http://localhost:8080). Migrations run automatically on startup.
- After changing code, run the same `docker compose up` again to update it.

Demo data (it **never** runs in production):

```bash
docker compose exec app php artisan db:seed --class=DemoSeeder
```

- Organizer login: `demo@manaleague.test`, password `manaleague123`.
- Friends with the same password: Ajani, Chandra, Jace and Liliana.

## Tests and quality

```bash
php artisan test                           # PHPUnit suite (feature and unit)
php artisan test --filter TestName         # a single file or method
node --test tests/js/deck-simulator.test.cjs
composer lint                              # Pint in check mode
```

Every behavior a player can notice has a test. See [docs/testing.md](docs/testing.md).

## CI/CD

The pipeline in `.github/workflows/pipeline.yml` runs on every pull request to `main` and every push to `main` (deploy only happens on push):

1. **Automated tests:** the full suite on PostgreSQL.
2. **Dependency and filesystem scan:** Composer Audit and Trivy.
3. **SonarQube Cloud:** a required Quality Gate.
4. **WAF:**
   - starts the full stack;
   - checks that legitimate traffic passes and that classic attacks (SQLi, XSS, LFI, RCE...) are blocked.
5. **Deploy to EC2:**
   - SSH is opened only during the deploy, only for the runner's IP;
   - releases are immutable and switched by symlink;
   - patch notes are synced.

A second workflow, `.github/workflows/daily-tasks.yml`, runs the production routines every day at 06:00 (Brasília) and on demand (**Actions → Daily tasks → Run workflow**): it refreshes the ban list of each format and the Pauper legality of every deck card from Scryfall, and confirms challenge scores left unanswered for 7 days. The deploy also runs the card checks. The run fails, and GitHub notifies the team, when a task fails (for example, Scryfall does not answer).

Publishing, editing or deleting a GitHub Release only syncs the patch notes. It does not test or redeploy code. Each published release becomes a card in the Patch notes tab and a notification for every account ([docs/patch-notes.md](docs/patch-notes.md)).

## Project structure

| Folder | Contents |
|---|---|
| `app/Services/` | business rules (subfolders `Community/`, `Decks/`, `Ranked/`); controllers stay thin |
| `routes/` | one file per module: `competition`, `live`, `deck-tools`, `community`, `challenges`, `ranked`, `life` |
| `resources/views/` | Blade views, components (`<x-avatar>`, `<x-icon>`, `<x-ui.*>`) and partials |
| `public/css/`, `public/js/` | design system (`ui.css`), per-module CSS and page scripts |
| `database/migrations/` | database schema |
| `tests/` | PHPUnit tests (`Feature/`, `Unit/`) and JS tests |
| `docker/`, `deploy/` | entrypoint, Caddy and WAF configuration |
| `terraform/` | EC2, S3, SES and Cloudflare |
| `docs/` | architecture, development rules and security |

## Documentation

The docs are written in Portuguese.

- **Architecture and modules:** [architecture.md](docs/architecture.md)
- **Development rules:** [UI](docs/ui-rules.md) · [back end](docs/backend-rules.md) · [testing](docs/testing.md)
- **Ranked:** [ELO/PDL and rules](docs/ranked.md)
- **Security:** [SSDLC](docs/ssdlc.md) · [requirements](docs/requirements.md) · [threat model](docs/threat-model.md)
- **Releases:** [patch notes](docs/patch-notes.md)

## Contributing

- **Branches:** new work goes on its own branch (`feat/...`, `fix/...`, `docs/...`) and reaches `main` through a pull request.
- **Commits:** in English, following [Conventional Commits](https://www.conventionalcommits.org/) (`feat(profile): ...`), one commit per topic.
- **Before opening a PR:**
  - the full test suite passes;
  - UI changes are checked on desktop (1280px) and mobile (390px), in dark and light themes;
  - this README is updated when the change adds or alters something it describes.
- **Working with Claude Code:** project rules are in [CLAUDE.md](CLAUDE.md), and skills in `.claude/skills/`.

## Team

| Name | Role | GitHub |
|---|---|---|
| Vinicius Vitório Rodrigues | Owner · Engineer | [@viniciusVitorio](https://github.com/viniciusVitorio) |
| Vinícius Salvador | Contributor · Engineer | [@viniciusslvdor](https://github.com/viniciusslvdor) |

## License

Code released under the [MIT](LICENSE) license © 2026 Vinicius Vitório Rodrigues. Composer dependencies use permissive licenses (MIT, BSD, Apache-2.0); `nette/*` is dual-licensed and used under BSD-3-Clause.

**Magic: The Gathering** and card names, symbols and images are property of Wizards of the Coast. ManaLeague is unofficial Fan Content permitted under the [Fan Content Policy](https://company.wizards.com/en/legal/fancontentpolicy). Not approved/endorsed by Wizards. Portions of the materials used are property of Wizards of the Coast. © Wizards of the Coast LLC.

Card data and images come from the [Scryfall API](https://scryfall.com/docs/api), following its guidelines (no charge for access to the data, images without cropping the artist credit). ManaLeague is not affiliated with Scryfall.
