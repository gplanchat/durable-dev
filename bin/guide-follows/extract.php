<?php

declare(strict_types=1);

// Writes the getting-started guide's blocks into a Symfony skeleton, verbatim.
//
// A `php` fence that declares an `App\` class or interface lands at its PSR-4 path. A `yaml` fence
// lands in `config/packages/X.yaml` when it sits under a heading naming that file; the first fence
// replaces what the recipe wrote, the next ones are appended. A fence that opens with a `#` comment
// is an aside (the `.env` line, the "no longer needed" tag), not the file's content.
//
// Usage: php extract.php <guide.md> <app-dir>

[, $guide, $app] = $argv;

$file = null;
$fence = null;
$body = [];
$written = [];
$headed = [];

foreach (file($guide, \FILE_IGNORE_NEW_LINES) as $line) {
    if (null !== $fence) {
        if ('```' !== rtrim($line)) {
            $body[] = $line;
            continue;
        }
        $code = implode("\n", $body) . "\n";
        $target = match (true) {
            'php' === $fence && preg_match('/^namespace (App(?:\\\\\w+)*);/m', $code, $ns)
                && preg_match('/^(?:final |abstract )*(?:class|interface) (\w+)/m', $code, $name)
                => 'src/' . str_replace('\\', '/', substr($ns[1], 4)) . '/' . $name[1] . '.php',
            'yaml' === $fence && null !== $file && !str_starts_with($body[0] ?? '', '#') => $file,
            default => null,
        };
        if (null !== $target) {
            @mkdir(\dirname("$app/$target"), 0o777, true);
            file_put_contents("$app/$target", isset($written[$target]) ? "\n" . $code : $code, isset($written[$target]) ? \FILE_APPEND : 0);
            $written[$target] = ($written[$target] ?? 0) + 1;
        }
        $fence = null;
        continue;
    }
    if (preg_match('/^```(\w+)/', $line, $m)) {
        [$fence, $body] = [$m[1], []];
    } elseif (str_starts_with($line, '#') || '---' === $line) {
        $file = preg_match('/^#+ `(config\/packages\/[\w.-]+\.yaml)`/', $line, $m) ? $m[1] : null;
        if (null !== $file) {
            $headed[] = $file;
        }
    }
}

foreach ($written as $target => $count) {
    echo "  $target ($count block" . ($count > 1 ? 's' : '') . ")\n";
}

// A heading that names a file and yields nothing means the rule above no longer reads the guide:
// the job would then test the recipe's file instead of the guide's, and pass for the wrong reason.
$missing = array_diff($headed, array_keys($written));
if ([] !== $missing || [] === $written) {
    fwrite(\STDERR, 'No block extracted for: ' . implode(', ', $missing ?: ['anything']) . "\n");
    exit(1);
}
