<?php

namespace Tests\Architecture\Support;

use PhpToken;

/**
 * Shapers are pure (AR-28, AR-40): no database, HTTP, filesystem, clock or randomness, and no `json_decode`. The check reads the code tokens
 * of every `block-types/{key}/v{N}/Shaper.php` (a `.php.stub` in fixtures), so comments and strings never trigger it.
 */
final class ShaperScanner
{
    private const FUNCTIONS = [
        'json_decode', 'file_get_contents', 'file_put_contents', 'fopen', 'fread', 'fwrite', 'file', 'readfile', 'unlink', 'mkdir', 'scandir', 'glob',
        'is_file', 'is_dir', 'file_exists', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'shell_exec', 'exec', 'system', 'passthru',
        'proc_open', 'popen', 'time', 'microtime', 'hrtime', 'date', 'gmdate', 'mktime', 'strtotime', 'rand', 'mt_rand', 'random_int', 'random_bytes',
        'uniqid', 'sleep', 'usleep', 'getenv', 'env', 'config', 'now', 'today', 'app', 'resolve', 'cache', 'session', 'request', 'logger', 'info',
    ];

    private const CLASSES = [
        'DB', 'Http', 'Storage', 'File', 'Cache', 'Redis', 'Date', 'Carbon', 'CarbonImmutable', 'DateTime', 'DateTimeImmutable', 'PDO', 'SplFileObject',
        'Str', 'Process', 'Log', 'Config', 'Queue', 'Event',
    ];

    private const NAMESPACES = ['Illuminate', 'Carbon', 'GuzzleHttp', 'Symfony'];

    /** @return list<string> `file:line message` for each violation */
    public function violations(string $root): array
    {
        $files = array_merge(
            glob(rtrim($root, '/').'/block-types/*/v*/Shaper.php') ?: [],
            glob(rtrim($root, '/').'/block-types/*/v*/Shaper.php.stub') ?: [],
        );
        sort($files);

        $found = [];

        foreach ($files as $file) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($file)),
                fn (PhpToken $t): bool => ! $t->isIgnorable(),
            ));

            foreach ($tokens as $i => $token) {
                $name = ltrim($token->text, '\\');
                $next = $tokens[$i + 1]->text ?? '';
                $prev = $tokens[$i - 1]->text ?? '';
                $reason = null;

                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $next === '(' && ! in_array($prev, ['->', '?->', '::', 'function'], true) && in_array(strtolower($name), self::FUNCTIONS, true)) {
                    $reason = "calls {$name}()";
                } elseif ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                    $reason = "uses {$token->text} (file access)";
                } elseif ($token->is(T_EVAL)) {
                    $reason = 'uses eval';
                } elseif ($token->is(T_VARIABLE) && $next === '(' && ! in_array($prev, ['->', '?->', '::'], true)) {
                    $reason = "calls the variable function {$token->text}()";
                } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ($next === '::' || $prev === 'new') && in_array(basename(str_replace('\\', '/', $name)), self::CLASSES, true)) {
                    $reason = "uses {$name}";
                } elseif ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && in_array(explode('\\', $name)[0], self::NAMESPACES, true)) {
                    $reason = "references {$name}";
                }

                if ($reason !== null) {
                    $found[] = "{$file}:{$token->line} Shaper {$reason}: Shapers are pure (no I/O, no clock, no json_decode)";
                }
            }
        }

        return $found;
    }
}
