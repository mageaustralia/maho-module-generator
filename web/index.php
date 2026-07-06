<?php

/**
 * Maho Module Generator - local web UI (v1.0-alpha)
 *
 * LOCAL TOOL ONLY. No auth, no rate limiting, no CSRF - see web/README.md.
 * Run with:  php -S localhost:8080 -t web
 *
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use MahoModuleGenerator\Generator;
use MahoModuleGenerator\Spec;
use MahoModuleGenerator\SpecException;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../vendor/autoload.php';

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── POST /generate : YAML spec in, module zip out ────────────────────
if ($path === '/generate' && $method === 'POST') {
    $yaml = (string) ($_POST['spec'] ?? file_get_contents('php://input'));
    if (trim($yaml) === '') {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo "empty spec\n";
        return;
    }
    try {
        $raw = Yaml::parse($yaml);
        if (!is_array($raw)) {
            throw new SpecException('spec must be a YAML mapping');
        }
        $spec = Spec::fromArray($raw);
        $files = (new Generator())->generate($spec);
    } catch (SpecException | \Throwable $e) {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo 'spec error: ' . $e->getMessage() . "\n";
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'mmg-zip-');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $rel => $contents) {
        $zip->addFromString($spec->moduleName() . '/' . $rel, $contents);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $spec->moduleName() . '.zip"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    return;
}

// ── POST /extract : M1 module zip in, clean-room spec YAML out ───────
if ($path === '/extract' && $method === 'POST') {
    if (!class_exists(\MahoModuleGenerator\Extractor\M1SpecExtractor::class)) {
        http_response_code(501);
        header('Content-Type: text/plain');
        echo "M1 extraction not available in this build\n";
        return;
    }
    $upload = $_FILES['m1zip'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo "upload a zip of the Magento 1 module as field 'm1zip'\n";
        return;
    }
    $workDir = sys_get_temp_dir() . '/mmg-extract-' . bin2hex(random_bytes(6));
    mkdir($workDir, 0755, true);
    try {
        $zip = new ZipArchive();
        if ($zip->open((string) $upload['tmp_name']) !== true) {
            throw new SpecException('could not open the uploaded zip');
        }
        // Guard against zip-slip: reject entries that escape the work dir.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..')) {
                throw new SpecException("unsafe zip entry: $name");
            }
        }
        $zip->extractTo($workDir);
        $zip->close();
        $yaml = (new \MahoModuleGenerator\Extractor\M1SpecExtractor())->extractToYaml($workDir);
        header('Content-Type: text/yaml');
        header('Content-Disposition: attachment; filename="extracted-spec.yaml"');
        echo $yaml;
    } catch (SpecException | \Throwable $e) {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo 'extract error: ' . $e->getMessage() . "\n";
    } finally {
        // best-effort cleanup
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($workDir);
    }
    return;
}

// ── GET / : single-page UI ────────────────────────────────────────────
if ($path !== '/') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "not found\n";
    return;
}

$exampleSpec = (string) file_get_contents(__DIR__ . '/../specs/example-testimonials.yaml');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maho Module Generator</title>
<style>
    :root { --brand: #4f46e5; --ink: #0f172a; --muted: #64748b; --rule: #e2e8f0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, sans-serif; color: var(--ink); background: #f8fafc; }
    .wrap { max-width: 860px; margin: 0 auto; padding: 40px 20px 80px; }
    h1 { font-size: 26px; margin: 0 0 4px; }
    h1 em { font-style: normal; color: var(--brand); }
    .sub { color: var(--muted); margin: 0 0 28px; font-size: 14px; }
    .card { background: #fff; border: 1px solid var(--rule); border-radius: 8px; padding: 22px; margin-bottom: 22px; }
    .card h2 { font-size: 16px; margin: 0 0 10px; }
    .card p { font-size: 13px; color: var(--muted); margin: 0 0 14px; line-height: 1.5; }
    textarea { width: 100%; min-height: 380px; font-family: ui-monospace, Menlo, Consolas, monospace;
               font-size: 12.5px; line-height: 1.5; padding: 12px; border: 1px solid var(--rule);
               border-radius: 6px; resize: vertical; }
    textarea:focus { outline: 2px solid var(--brand); outline-offset: -1px; }
    button { background: var(--brand); color: #fff; border: 0; border-radius: 6px; font-weight: 600;
             font-size: 14px; padding: 11px 22px; cursor: pointer; margin-top: 12px; }
    button:hover { filter: brightness(1.08); }
    input[type=file] { font-size: 13px; margin: 6px 0 4px; }
    .warn { background: #fef3c7; border-left: 3px solid #f59e0b; padding: 10px 14px; border-radius: 0 4px 4px 0;
            font-size: 12.5px; color: #78350f; margin-top: 26px; }
    code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Maho <em>Module Generator</em></h1>
    <p class="sub">Spec in, best-practice module out. Local tool - see the warning at the bottom.</p>

    <form class="card" method="post" action="/generate">
        <h2>Generate from a spec</h2>
        <p>Edit the YAML below (or paste your own), then Generate to download the module as a zip.
           Unknown keys are fatal by design - typos fail loudly.</p>
        <textarea name="spec" spellcheck="false"><?= htmlspecialchars($exampleSpec, ENT_QUOTES) ?></textarea>
        <button type="submit">Generate module zip</button>
    </form>

    <form class="card" method="post" action="/extract" enctype="multipart/form-data">
        <h2>Clean-room a Magento 1 module</h2>
        <p>Upload a zip of an M1 module. You get back a <strong>spec</strong> (never code) derived from its
           structure - tables, routes, emails. Review and edit the spec, then generate above.
           Only the spec crosses the boundary, so the output is a clean-room reimplementation, not a port.</p>
        <input type="file" name="m1zip" accept=".zip" required>
        <br>
        <button type="submit">Extract spec from M1 module</button>
    </form>

    <div class="warn">
        <strong>Local tool only.</strong> This server has no authentication, no rate limiting and no CSRF
        protection. Run it via <code>php -S localhost:8080 -t web</code> on your own machine.
        Do not expose it to the internet as-is.
    </div>
</div>
</body>
</html>
