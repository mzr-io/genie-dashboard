<?php

namespace Tests\Architecture\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static checks over PHP source trees. Every check returns violation strings that name the file.
 * Fixture files use the `.php.stub` extension so Composer, Pint and PHPStan ignore them.
 */
final class Scanner
{
    /** @var array{edges: array<string, list<string>>, kernel: string, tables: array<string, list<string>>, global_tables: list<string>, json_decode_banned: list<string>} */
    private array $rules;

    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? require __DIR__.'/../dependencies.php';
    }

    /**
     * Forbidden module edges, non-Contracts access and kernel-to-module calls under `$appRoot` (an `app` directory).
     *
     * @return list<string>
     */
    public function boundaryViolations(string $appRoot): array
    {
        $violations = [];

        foreach ($this->owned($appRoot) as [$file, $owner, $code]) {
            $code = str_replace('\\\\', '\\', $code);

            preg_match_all('/\buse\s+App\\\\Modules\\\\[\w\\\\]*\{/', $code, $groups, PREG_OFFSET_CAPTURE);
            foreach ($groups[0] as [, $offset]) {
                $violations[] = "{$file}:{$this->lineAt($code, $offset)} group imports of App\\Modules are not allowed; one import per class";
            }

            preg_match_all('/App\\\\Modules\\\\(\w+)((?:\\\\\w+)*)/', $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $match) {
                $target = $match[1][0];
                $namespace = ltrim(str_replace('\\', '/', $match[2][0]), '/');
                $line = $this->lineAt($code, $match[0][1]);

                if ($owner === $this->rules['kernel']) {
                    $violations[] = "{$file}:{$line} kernel calls module {$target} (the kernel calls no module)";
                } elseif ($owner !== $target) {
                    if (! in_array($target, $this->rules['edges'][$owner] ?? [], true)) {
                        $violations[] = "{$file}:{$line} forbidden edge {$owner} -> {$target}";
                    } elseif ($namespace !== 'Contracts' && ! str_starts_with($namespace, 'Contracts/')) {
                        $violations[] = "{$file}:{$line} edge {$owner} -> {$target} reaches past Contracts ({$match[0][0]})";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Queries naming a table owned by someone else.
     *
     * @return list<string>
     */
    public function tableViolations(string $appRoot): array
    {
        $owners = [];
        foreach ($this->rules['tables'] as $owner => $tables) {
            foreach ($tables as $table) {
                $owners[$table] = $owner;
            }
        }

        $patterns = [
            '/(?:DB::table|->table|->from|->join|->leftJoin|->rightJoin|->crossJoin|->joinSub)\(\s*[\'"]([\w.]+?)(?:\s+as\s+\w+)?[\'"]/i',
            '/DB::(?:select|statement|insert|update|delete|unprepared|raw|selectOne)\(\s*[\'"][^\'"]*?\b(?:from|join|into|update)\s+"?(\w+)"?/is',
        ];

        $violations = [];

        foreach ($this->owned($appRoot) as [$file, $owner, $code]) {
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $table = strtolower(substr(strrchr('.'.$match[1][0], '.'), 1));
                    $tableOwner = $owners[$table] ?? null;

                    if ($tableOwner !== null && $tableOwner !== $owner) {
                        $line = $this->lineAt($code, $match[0][1]);
                        $violations[] = "{$file}:{$line} {$owner} queries table {$table} owned by {$tableOwner}";
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * `json_decode` calls inside the banned modules.
     *
     * @return list<string>
     */
    public function jsonDecodeViolations(string $appRoot): array
    {
        $violations = [];

        foreach ($this->owned($appRoot) as [$file, $owner]) {
            if (! in_array($owner, $this->rules['json_decode_banned'], true)) {
                continue;
            }

            foreach ($this->tokens($file) as [$id, $text, $line]) {
                if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($text, '\\') === 'json_decode') {
                    $violations[] = "{$file}:{$line} json_decode in {$owner} (use the lossless JSON parser)";
                }
            }
        }

        return $violations;
    }

    /**
     * Any file under `$appRoot` (not only modules and the kernel) that sets the Workspace context,
     * other than WorkspaceTransaction: `set_config` calls and the `app.workspace_id` setting.
     *
     * @return list<string>
     */
    public function workspaceContextViolations(string $appRoot): array
    {
        $violations = [];

        foreach ($this->files($appRoot) as $file) {
            $relative = substr($file, strlen(rtrim($appRoot, '/')) + 1);

            if (in_array($relative, ['Platform/Tenancy/WorkspaceTransaction.php', 'Platform/Tenancy/WorkspaceTransaction.php.stub'], true)) {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($file));

            if (preg_match_all('/set_config\b|app\.workspace_id/i', $code, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $violations[] = "{$file}:{$this->lineAt($code, $offset)} sets the Workspace context with {$text}; only WorkspaceTransaction may";
                }
            }
        }

        return $violations;
    }

    /**
     * Any file under `$appRoot` that makes an outbound request around the guard: curl, the `Http` facade, Guzzle, raw sockets,
     * or `file_get_contents` / `fopen` on an http(s) URL. Only NativeCurlClient may use curl.
     *
     * @return list<string>
     */
    public function egressViolations(string $appRoot): array
    {
        $violations = [];
        $pattern = '/\bcurl_(?:init|setopt|setopt_array|multi_init)\s*\(|\bHttp::|\bGuzzleHttp\\\\Client\b|\bfsockopen\s*\(|\bpfsockopen\s*\(|\bstream_socket_client\s*\(|\b(?:file_get_contents|fopen)\s*\(\s*[\'"]https?:\/\//i';

        foreach ($this->files($appRoot) as $file) {
            $relative = substr($file, strlen(rtrim($appRoot, '/')) + 1);

            // HealthChecker only probes this container's own listening port on 127.0.0.1: no Data Source, no outside host.
            if (in_array($relative, ['Modules/Connector/Infrastructure/NativeCurlClient.php', 'Modules/Connector/Infrastructure/NativeCurlClient.php.stub', 'Support/Health/HealthChecker.php'], true)) {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($file));
            $code = str_replace('\\\\', '\\', $code);

            if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $violations[] = "{$file}:{$this->lineAt($code, $offset)} makes an outbound request with {$text}; use EgressTransport";
                }
            }
        }

        return $violations;
    }

    /**
     * Migrations creating a non-global table without `workspace_id`.
     *
     * @return list<string>
     */
    public function migrationViolations(string $migrationsDir): array
    {
        $violations = [];

        foreach ($this->files($migrationsDir) as $file) {
            $code = $this->stripComments((string) file_get_contents($file));

            $segments = [];
            preg_match_all('/Schema::create\(\s*[\'"](\w+)[\'"]/', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$table, $offset]) {
                $segments[] = [$table, $offset];
            }
            preg_match_all('/create\s+table\s+(?:if\s+not\s+exists\s+)?"?(\w+)"?/i', $code, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$table, $offset]) {
                $segments[] = [$table, $offset];
            }

            usort($segments, fn (array $a, array $b): int => $a[1] <=> $b[1]);

            foreach ($segments as $i => [$table, $offset]) {
                $end = $segments[$i + 1][1] ?? strlen($code);
                $body = substr($code, $offset, $end - $offset);

                // A partition takes its columns, `workspace_id` included, from its partitioned parent.
                if (preg_match('/\A\w+"?\s+partition\s+of\b/i', $body) === 1) {
                    continue;
                }

                if (! in_array(strtolower($table), $this->rules['global_tables'], true) && ! str_contains($body, 'workspace_id')) {
                    $violations[] = "{$file} creates table {$table} without workspace_id";
                }
            }
        }

        return $violations;
    }

    /**
     * A migration that creates a partitioned table or a partition must also switch on row-level security: a partition queried
     * directly is checked by its own policy, not its parent's, so `ENABLE` and `FORCE ROW LEVEL SECURITY` and a policy
     * have to appear in the same migration (Story 2.5).
     *
     * @return list<string>
     */
    public function partitionViolations(string $migrationsDir): array
    {
        $violations = [];

        foreach ($this->files($migrationsDir) as $file) {
            $code = $this->stripComments((string) file_get_contents($file));

            if (preg_match('/\bpartition\s+(?:by|of)\b/i', $code) !== 1) {
                continue;
            }

            foreach (['enable row level security', 'force row level security', 'create policy'] as $needed) {
                if (stripos($code, $needed) === false) {
                    $violations[] = "{$file} creates a partitioned table or a partition without `{$needed}`";
                }
            }
        }

        return $violations;
    }

    /**
     * Every PHP file under `$appRoot/Modules/<Module>` and `$appRoot/<kernel>`, with comments removed.
     *
     * @return iterable<array{0: string, 1: string, 2: string}> file, owner, code
     */
    private function owned(string $appRoot): iterable
    {
        $appRoot = rtrim($appRoot, '/');

        foreach ($this->files($appRoot) as $file) {
            $relative = substr($file, strlen($appRoot) + 1);

            if (preg_match('#^Modules/(\w+)/#', $relative, $m)) {
                $owner = $m[1];
            } elseif (str_starts_with($relative, $this->rules['kernel'].'/')) {
                $owner = $this->rules['kernel'];
            } else {
                continue;
            }

            yield [$file, $owner, $this->stripComments((string) file_get_contents($file))];
        }
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $info) {
            if ($info->isFile() && preg_match('/\.php(\.stub)?$/', $info->getFilename())) {
                $files[] = $info->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<array{0: int, 1: string, 2: int}> */
    private function tokens(string $file): array
    {
        $out = [];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out[] = [$token[0], $token[1], $token[2]];
            }
        }

        return $out;
    }

    /** Removes comments but keeps their newlines so line numbers stay correct. */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    private function lineAt(string $code, int $offset): int
    {
        return substr_count($code, "\n", 0, $offset) + 1;
    }
}
