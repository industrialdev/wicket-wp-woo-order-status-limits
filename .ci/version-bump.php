#!/usr/bin/env php
<?php

/**
 * Version bumper for Wicket WordPress plugins and themes.
 *
 * Detects the project's main file automatically:
 *   - a plugin: the root *.php whose header contains "Plugin Name:"
 *   - a theme:  style.css (header "Theme Name:")
 *
 * The current version is read from composer.json when present, otherwise from
 * the main file's "Version:" header. The new version is written to the main
 * file's header and to composer.json when that file exists.
 *
 * Usage:
 *   php .ci/version-bump.php patch      # 2.4.10 -> 2.4.11
 *   php .ci/version-bump.php minor      # 2.4.10 -> 2.5.0
 *   php .ci/version-bump.php major      # 2.4.10 -> 3.0.0
 *   php .ci/version-bump.php 2.4.11     # set an explicit version
 *   php .ci/version-bump.php            # prompt interactively
 *
 * On success the resolved version is printed to STDOUT as the last line, so CI
 * can capture it with: NEW_VERSION="$(php .ci/version-bump.php patch | tail -1)"
 */

class VersionBumper
{
    private string $currentVersion;
    private array $filesToUpdate = [];
    private ?string $mainFile = null;
    private bool $hasComposer = false;

    public function __construct()
    {
        $this->mainFile = $this->detectMainFile();

        if ($this->mainFile === null && !file_exists('composer.json')) {
            fwrite(STDERR, "Error: no composer.json and no main plugin/theme file found in current directory.\n");
            exit(1);
        }

        if (!$this->resolveCurrentVersion()) {
            exit(1);
        }

        if ($this->hasComposer) {
            $this->filesToUpdate[] = 'composer.json';
        }
        if ($this->mainFile !== null) {
            $this->filesToUpdate[] = $this->mainFile;
        }
    }

    /**
     * Plugin main file (root *.php with "Plugin Name:") or, failing that, a
     * theme's style.css (with "Theme Name:").
     */
    private function detectMainFile(): ?string
    {
        foreach (glob('*.php') ?: [] as $file) {
            $head = file_get_contents($file, false, null, 0, 4096);
            if ($head !== false && preg_match('/Plugin Name:\s*\S/i', $head)) {
                return $file;
            }
        }

        if (file_exists('style.css')) {
            $head = file_get_contents('style.css', false, null, 0, 4096);
            if ($head !== false && preg_match('/Theme Name:\s*\S/i', $head)) {
                return 'style.css';
            }
        }

        return null;
    }

    /** Current version: composer.json if it has one, else the main file header. */
    private function resolveCurrentVersion(): bool
    {
        if (file_exists('composer.json')) {
            $composerJson = json_decode((string) file_get_contents('composer.json'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                fwrite(STDERR, 'Error: Unable to parse composer.json: ' . json_last_error_msg() . "\n");
                return false;
            }
            if (isset($composerJson['version'])) {
                $this->hasComposer = true;
                $this->currentVersion = $composerJson['version'];
                return true;
            }
        }

        if ($this->mainFile !== null) {
            $version = $this->versionFromHeader($this->mainFile);
            if ($version !== null) {
                $this->currentVersion = $version;
                return true;
            }
        }

        fwrite(STDERR, "Error: could not determine current version from composer.json or the main file header.\n");
        return false;
    }

    private function versionFromHeader(string $file): ?string
    {
        $content = (string) file_get_contents($file);
        if (preg_match('/^\s*\*?\s*Version:\s*([0-9][0-9a-zA-Z.\-]*)/mi', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    private function validateNewVersion(string $newVersion): bool
    {
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $newVersion)) {
            fwrite(STDERR, "Error: Invalid version format. Please use semantic versioning (e.g., 1.2.3)\n");
            return false;
        }

        return true;
    }

    private function computeBump(string $level): ?string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $this->currentVersion, $m)) {
            fwrite(STDERR, "Error: current version '{$this->currentVersion}' is not plain X.Y.Z; cannot compute a {$level} bump.\n");
            return null;
        }

        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        switch ($level) {
            case 'major':
                return ($major + 1) . '.0.0';
            case 'minor':
                return $major . '.' . ($minor + 1) . '.0';
            case 'patch':
                return $major . '.' . $minor . '.' . ($patch + 1);
        }

        return null;
    }

    private function resolveNewVersion(?string $arg): ?string
    {
        if ($arg === null || $arg === '') {
            fwrite(STDERR, 'Enter new version (semver) or bump level [patch|minor|major]: ');
            $arg = trim((string) fgets(STDIN));
        }

        if (in_array($arg, ['patch', 'minor', 'major'], true)) {
            return $this->computeBump($arg);
        }

        return $arg;
    }

    private function updateVersionInFile(string $filePath, string $newVersion): bool
    {
        if (!file_exists($filePath)) {
            fwrite(STDERR, "Warning: File not found: {$filePath}\n");
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            fwrite(STDERR, "Error: Unable to read file: {$filePath}\n");
            return false;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $updated = false;

        switch ($extension) {
            case 'json':
                $pattern = '/"version":\s*"' . preg_quote($this->currentVersion, '/') . '"/';
                $newContent = preg_replace($pattern, '"version": "' . $newVersion . '"', $content, -1, $count);
                $updated = $count > 0;
                break;
            case 'php':
            case 'css':
                // Header version, e.g. " * Version: 1.2.3" (php docblock) or
                // "Version: 1.2.3" (css theme header).
                $versionPatternPart = '[0-9a-zA-Z\\.-]+';
                $headerPattern = '/(^\s*\*?\s*Version:\s*)' . $versionPatternPart . '/mi';
                $newContent = preg_replace($headerPattern, '${1}' . $newVersion, $content, -1, $count);
                $updated = $count > 0;

                if ($extension === 'php') {
                    // Keep any version constant in sync, e.g.
                    //   define('MYPLUGIN_VERSION', '1.2.3');
                    //   const VERSION = '1.2.3';
                    $newContent = preg_replace(
                        '/(define\(\s*[\'"][A-Z0-9_]*VERSION[\'"]\s*,\s*[\'"])' . $versionPatternPart . '([\'"]\s*\))/',
                        '${1}' . $newVersion . '${2}',
                        $newContent,
                        -1,
                        $countDefine
                    );
                    $newContent = preg_replace(
                        '/(const\s+VERSION\s*=\s*[\'"])' . $versionPatternPart . '([\'"])/',
                        '${1}' . $newVersion . '${2}',
                        $newContent,
                        -1,
                        $countConst
                    );
                    $updated = $updated || ($countDefine + $countConst) > 0;
                }
                break;
            default:
                $pattern = '/' . preg_quote($this->currentVersion, '/') . '/';
                $newContent = preg_replace($pattern, $newVersion, $content, -1, $count);
                $updated = $count > 0;
        }

        if ($newContent === null) {
            fwrite(STDERR, "Error: Pattern replacement failed in {$filePath}\n");
            return false;
        }

        if (!$updated) {
            fwrite(STDERR, "Warning: No version string found in {$filePath}\n");
            return false;
        }

        if (file_put_contents($filePath, $newContent) === false) {
            fwrite(STDERR, "Error: Unable to write to file: {$filePath}\n");
            return false;
        }

        return true;
    }

    public function run(): void
    {
        global $argv;

        fwrite(STDERR, "Current version: {$this->currentVersion}\n");

        $newVersion = $this->resolveNewVersion($argv[1] ?? null);

        if ($newVersion === null || !$this->validateNewVersion($newVersion)) {
            exit(1);
        }

        if ($newVersion === $this->currentVersion) {
            fwrite(STDERR, "Error: new version equals current version ({$newVersion}); nothing to do.\n");
            exit(1);
        }

        fwrite(STDERR, "New version: {$newVersion}\n");

        $successCount = 0;
        foreach ($this->filesToUpdate as $file) {
            if ($this->updateVersionInFile($file, $newVersion)) {
                fwrite(STDERR, "Updated version in {$file}\n");
                $successCount++;
            }
        }

        if ($successCount === 0) {
            fwrite(STDERR, "Error: No files were updated\n");
            exit(1);
        }

        if ($successCount !== count($this->filesToUpdate)) {
            fwrite(STDERR, "{$successCount} out of " . count($this->filesToUpdate) . " files were updated\n");
        }

        fwrite(STDERR, "Version bump completed: {$this->currentVersion} -> {$newVersion}\n");

        echo $newVersion . "\n";
    }
}

$bumper = new VersionBumper();
$bumper->run();
