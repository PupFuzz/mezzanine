<?php

namespace App\Coord;

use App\Fold\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One coordination hook BINDING — `docs/design/FLEET-STATE.md § 6.4`'s `coord_hooks` row and its
 * unrevoked secrets — and the operator acts that write it (COORD-STORE.md § 3.1–3.2, card#12239).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE BINDING'S `scope` IS SET AT ISSUE AND NEVER READ FROM A PAYLOAD. `install` names one install
 * and one repository; `fleet` names one repository and NO install, and every object it derives carries
 * `install_id: null` (D1 § 18.6). The payload's `repository.full_name` is a claim held against
 * `repository` for equality (D1 § 18.8 step 6) and never routed on. ⛔ `repository` IS STORED LOWERCASED
 * and step 6 compares case-insensitively, because GitHub's names are: an operator who issued `Org/Repo`
 * while GitHub reports `org/repo` is the same repository, and `uq_binding`'s byte-exact key then keeps one
 * binding per repository across casings.
 *
 * ⛔ ONE UNREVOKED BINDING PER REPOSITORY, AT ANY SCOPE (r4 MINOR-2), and the store's key is what says
 * so (`uq_binding`): two bindings on one repository would be two messages on the server for one post
 * and one bird on the client decided by arrival order. A REVOKED binding keeps its row — its digests
 * reference it — and does not block issuing the same repository again.
 *
 * ⛔ AT MOST TWO UNREVOKED SECRETS PER BINDING (r1 F16; AT-D2-29). A second is a rotation with overlap;
 * a third is refused by name, because verification tries every unrevoked secret of the binding and the
 * step-3 budget was priced at two HMACs, not a ring.
 */
final class HookBinding
{
    /** `mzh_` + 22 base64url characters (16 random bytes): a public selector, never a secret. */
    public const HOOK_ID_PATTERN = 'mzh_[A-Za-z0-9_-]{22}';

    /** `<owner>/<repo>`, bounded by § 6.4's `VARCHAR(140)`. */
    public const REPOSITORY_PATTERN = '[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}';

    public const MAX_SECRETS = 2;

    /**
     * @param  list<string>  $secrets  the unrevoked secrets, decrypted, oldest first
     */
    public function __construct(
        public readonly int $id,
        public readonly string $hookId,
        public readonly string $scope,
        public readonly ?string $installId,
        public readonly string $repository,
        public readonly bool $sharedIdentity,
        #[\SensitiveParameter] public readonly array $secrets,
    ) {}

    /**
     * The UNREVOKED binding `{hook}` names, with its unrevoked secrets — or null. A revoked binding has
     * no secret and fails D1 § 18.8 step 3 exactly as an unknown `{hook}` does.
     */
    public static function find(string $hookId): ?self
    {
        if (preg_match('/^'.self::HOOK_ID_PATTERN.'$/D', $hookId) !== 1) {
            return null;
        }

        $row = DB::table('coord_hooks')
            ->leftJoin('installs', 'installs.id', '=', 'coord_hooks.install_ref')
            ->where('coord_hooks.hook_id', $hookId)
            ->whereNull('coord_hooks.revoked_at')
            ->first(['coord_hooks.*', 'installs.install_id']);

        if ($row === null) {
            return null;
        }

        $secrets = DB::table('coord_hook_secrets')
            ->where('hook_ref', $row->id)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->pluck('secret_ciphertext')
            ->map(fn ($c) => HookSecret::decrypt((string) $c))
            ->all();

        return new self(
            id: (int) $row->id,
            hookId: (string) $row->hook_id,
            scope: (string) $row->scope,
            installId: $row->install_id === null ? null : (string) $row->install_id,
            repository: (string) $row->repository,
            sharedIdentity: (bool) $row->shared_identity,
            secrets: $secrets,
        );
    }

    /**
     * Issue a NEW binding and its first secret, in one transaction. Returns `[hook_id, secret]`; the
     * caller writes the secret to a 0600 file and prints only the URL.
     *
     * @return array{string, string}
     */
    public static function issue(string $repository, string $scope, ?string $installId, bool $sharedIdentity, string $by): array
    {
        if (preg_match('~^'.self::REPOSITORY_PATTERN.'$~D', $repository) !== 1) {
            throw new BindingRefused(sprintf('repository %s is not `<owner>/<repo>`', json_encode($repository)));
        }

        $repository = strtolower($repository);

        if ($scope === 'fleet' && $installId !== null) {
            throw new BindingRefused('--scope=fleet names no install: a fleet-scope binding carries `install_id: null` (D1 § 18.6)');
        }

        if ($scope === 'install' && $installId === null) {
            throw new BindingRefused('--scope=install needs --install=<install_id>: the binding names exactly one install');
        }

        if (! in_array($scope, ['install', 'fleet'], true)) {
            throw new BindingRefused(sprintf('--scope must be `install` or `fleet`, not %s', json_encode($scope)));
        }

        $hookId = 'mzh_'.rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $secret = HookSecret::mint();
        $now = Clock::sql(now());

        DB::transaction(function () use ($repository, $scope, $installId, $sharedIdentity, $by, $hookId, $secret, $now) {
            $installRef = null;

            if ($installId !== null) {
                $installRef = DB::table('installs')->where('install_id', $installId)->value('id');

                if ($installRef === null) {
                    throw new BindingRefused(sprintf('no install %s exists here — issue a seat token for it first', json_encode($installId)));
                }
            }

            // ⛔ THE KEY IS THE ONLY AUTHORITY (`uq_binding`), with no read ahead of it: a pre-check
            // would decide the refusal on its own and hide a key that had stopped saying it.
            try {
                $ref = DB::table('coord_hooks')->insertGetId([
                    'hook_id' => $hookId,
                    'scope' => $scope,
                    'install_ref' => $installRef,
                    'repository' => $repository,
                    'shared_identity' => $sharedIdentity,
                    'created_at' => $now,
                    'created_by' => mb_substr($by, 0, 64),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                throw new BindingRefused(sprintf(
                    'repository %s already has an unrevoked binding — one repository carries one binding at any scope; revoke it first',
                    $repository,
                ));
            }

            self::insertSecret((int) $ref, $secret, $now);
        });

        return [$hookId, $secret];
    }

    /** `--hook`: a second secret on an existing binding — rotation with overlap. Refuses a third. */
    public static function addSecret(string $hookId): string
    {
        $secret = HookSecret::mint();
        $now = Clock::sql(now());

        DB::transaction(function () use ($hookId, $secret, $now) {
            $ref = DB::table('coord_hooks')->where('hook_id', $hookId)->whereNull('revoked_at')->lockForUpdate()->value('id');

            if ($ref === null) {
                throw new BindingRefused(sprintf('no unrevoked binding %s', json_encode($hookId)));
            }

            $held = DB::table('coord_hook_secrets')->where('hook_ref', $ref)->whereNull('revoked_at')->count();

            if ($held >= self::MAX_SECRETS) {
                throw new BindingRefused(sprintf(
                    'binding %s already holds %d unrevoked secrets — the most a rotation overlap holds; revoke one with --secret=<prefix> first',
                    $hookId,
                    $held,
                ));
            }

            self::insertSecret((int) $ref, $secret, $now);
        });

        return $secret;
    }

    /**
     * Revoke one secret (by its prefix) or, with no prefix, the whole binding and every secret it holds.
     *
     * @return int the number of rows revoked
     */
    public static function revoke(string $hookId, ?string $secretPrefix): int
    {
        $now = Clock::sql(now());

        return DB::transaction(function () use ($hookId, $secretPrefix, $now) {
            $ref = DB::table('coord_hooks')->where('hook_id', $hookId)->whereNull('revoked_at')->lockForUpdate()->value('id');

            if ($ref === null) {
                throw new BindingRefused(sprintf('no unrevoked binding %s', json_encode($hookId)));
            }

            $secrets = DB::table('coord_hook_secrets')->where('hook_ref', $ref)->whereNull('revoked_at');

            if ($secretPrefix !== null) {
                $n = (clone $secrets)->where('prefix', $secretPrefix)->update(['revoked_at' => $now]);

                if ($n === 0) {
                    throw new BindingRefused(sprintf('binding %s holds no unrevoked secret with prefix %s', $hookId, json_encode($secretPrefix)));
                }

                return $n;
            }

            $n = $secrets->update(['revoked_at' => $now]);

            return $n + DB::table('coord_hooks')->where('id', $ref)->update(['revoked_at' => $now]);
        });
    }

    private static function insertSecret(int $hookRef, #[\SensitiveParameter] string $secret, string $now): void
    {
        DB::table('coord_hook_secrets')->insert([
            'hook_ref' => $hookRef,
            'secret_ciphertext' => HookSecret::encrypt($secret),
            'prefix' => HookSecret::prefix($secret),
            'created_at' => $now,
        ]);
    }
}
