# Preserved legacy application

## Preservation record

The original application was tagged before relocation as
`2moons-legacy-baseline`, an annotated tag at revision
`b45cbc11d0a99e25058ab3ff1a63076cb7b93a72` (`Current Status`). It was created
from a clean tracked worktree. The tag identifies the original tracked revision;
it does not include ignored runtime output such as the existing
`legacy/includes/error.log`.

Before moving the application, a file hash/mode/symlink inventory was captured at
`/tmp/opencode/2moons-legacy-before.jsonl`; after relocation it was compared with
the corresponding `legacy/` paths. The legacy application files and directory
tree were moved intact. The original `.gitignore` was copied to `legacy/.gitignore`
and the original `README.md` was moved to `legacy/README.md`. `LICENSE` remains
unchanged at the repository root and was copied unchanged to `legacy/LICENSE`.
Runtime and ignored files included in the move are not represented by the Git tag.

## Isolation and limitations

The complete original application is under `legacy/`, including its original
entry points (`index.php`, `game.php`, `admin.php`, `install/index.php`, and
`cronjob.php`), source, assets, installer, SQL dump, Composer metadata, and
Docker Compose configuration. It is a read-only reference by convention; do not
import it into the new application or serve it as a second site.

DDEV's document root is `public/`; requests are dispatched through the new
Symfony application. `/` reports `foundation-ready` and `game:
not-implemented`; `/health` reports HTTP application liveness only. Neither is a
gameplay route. Legacy files and configuration paths are outside this document
root; `/legacy/index.php`, `/legacy/includes/config.php`, and other tested legacy
URLs return 404. Do not change the document root to the repository root or
`legacy/` to run the old application. See [FOUNDATION.md](FOUNDATION.md) for
current infrastructure status.

No safe, supported legacy runtime or base URL is claimed. The legacy working
directory would be `legacy/`, but relocation can affect assumptions in old
include/config paths. The old runtime has not been executed after relocation and
must not be exposed publicly. PHP 8.5 compatibility is unproven; the previous
source references removed PHP functions, and the old application must not be
made runnable by silently downgrading PHP. No legacy database was initialized.
`legacy/install/install.sql` and `legacy/install/` are preserved reference
materials only. The new foundation has no game schema/tables or initialized
universe; its local MariaDB service is infrastructure, not an imported legacy
game database.

The original credits remain in `legacy/README.md`, and the original MIT license
is retained as `LICENSE` and `legacy/LICENSE`. Other included artwork, bundled
libraries, and their individual notices need a separate license audit before
reuse; preservation is not a claim that every asset has the same license.
