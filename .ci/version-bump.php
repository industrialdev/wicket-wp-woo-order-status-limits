#!/usr/bin/env php
<?php

class VersionBumper
{
    private string $currentVersion;
    private array $filesToUpdate = [
        'composer.json',
        'wicket-wp-woo-order-status-limits.php',
    ];

    public function __construct()
    {
        if (!$this->getCurrentVersion()) {
            exit(1);
        }
    }

    private function getCurrentVersion(): bool
    {
        if (!file_exists('composer.json')) {
            echo "Error: composer.json not found in current directory.\n";
            return false;
        }

        $composerJson = json_decode(file_get_contents('composer.json'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error: Unable to parse composer.json: " . json_last_error_msg() . "\n";
            return false;
        }

        if (!isset($composerJson['version'])) {
            echo "Error: No version field found in composer.json\n";
            return false;
        }

        $this->currentVersion = $composerJson['version'];
        return true;
    }

    private function validateNewVersion(string $newVersion): bool
    {
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $newVersion)) {
            echo "Error: Invalid version format. Please use semantic versioning (e.g., 1.2.3)\n";
            return false;
        }

        return true;
    }

    private function updateVersionInFile(string $filePath, string $newVersion): bool
    {
        if (!file_exists($filePath)) {
            echo "Warning: File not found: {$filePath}\n";
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            echo "Error: Unable to read file: {$filePath}\n";
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
                $newContent = $content;
                $versionPatternPart = '[0-9a-zA-Z\\.-]+';

                $docblockPattern = '/(^\s*\*\s*Version:\s*)' . $versionPatternPart . '/m';
                $tempContent = preg_replace($docblockPattern, '${1}' . $newVersion, $content, -1, $count1);

                if ($count1 > 0) {
                    $newContent = $tempContent;
                    $updated = true;
                } else {
                    $plainHeaderPattern = '/(Version:\s*)' . $versionPatternPart . '/i';
                    $tempContent = preg_replace($plainHeaderPattern, '${1}' . $newVersion, $content, -1, $count2);
                    if ($count2 > 0) {
                        $newContent = $tempContent;
                        $updated = true;
                    } else {
                        $quotedCurrentVersion = preg_quote($this->currentVersion, '/');
                        $directPattern = '/' . $quotedCurrentVersion . '/';
                        $tempContent = preg_replace($directPattern, $newVersion, $content, -1, $count3);
                        if ($count3 > 0) {
                            $newContent = $tempContent;
                            $updated = true;
                        }
                    }
                }
                break;
            default:
                $pattern = '/' . preg_quote($this->currentVersion, '/') . '/';
                $newContent = preg_replace($pattern, $newVersion, $content, -1, $count);
                $updated = $count > 0;
        }

        if ($newContent === null) {
            echo "Error: Pattern replacement failed in {$filePath}\n";
            return false;
        }

        if (!$updated) {
            echo "Warning: No version string found in {$filePath}\n";
            return false;
        }

        if (file_put_contents($filePath, $newContent) === false) {
            echo "Error: Unable to write to file: {$filePath}\n";
            return false;
        }

        return true;
    }

    public function run(): void
    {
        echo "Current version: {$this->currentVersion}\n";

        // Accept version as CLI arg or prompt interactively.
        global $argv;
        if (!empty($argv[1])) {
            $newVersion = trim($argv[1]);
            echo "New version: {$newVersion}\n";
        } else {
            echo "Enter new version (semver): ";
            $newVersion = trim(fgets(STDIN));
        }

        if (!$this->validateNewVersion($newVersion)) {
            exit(1);
        }

        $successCount = 0;
        foreach ($this->filesToUpdate as $file) {
            if ($this->updateVersionInFile($file, $newVersion)) {
                echo "Updated version in {$file}\n";
                $successCount++;
            }
        }

        if ($successCount === 0) {
            echo "Error: No files were updated\n";
            exit(1);
        }

        if ($successCount !== count($this->filesToUpdate)) {
            echo "{$successCount} out of " . count($this->filesToUpdate) . " files were updated\n";
        }

        echo "Version bump completed: {$this->currentVersion} → {$newVersion}\n";
    }
}

$bumper = new VersionBumper();
$bumper->run();
