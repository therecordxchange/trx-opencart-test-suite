<?php

trait ControllerLoadingTrait
{
    private array $controllerBasePaths;
    private bool $debugControllerLoading = false;

    protected function initializeControllerLoader(): void
    {
        $this->controllerBasePaths = [
            APP_ROOT . 'catalog/controller/',
            APP_ROOT . 'admin/controller/',
            '/var/www/trx-enterprise-php/htdocs/catalog/controller/',
            '/var/www/trx-enterprise-php/htdocs/admin/controller/',
            DIR_APPLICATION . 'controller/',
        ];
    }

    protected function enableControllerLoadingDebug(): void
    {
        $this->debugControllerLoading = true;
    }

    private function debugLog(string $message): void
    {
        // Always log when enabled, visible in both local and CI logs
        if ($this->debugControllerLoading || getenv('CONTROLLER_LOADING_DEBUG')) {
            file_put_contents('php://stderr', "[CONTROLLER] " . $message . "\n");
        }
    }

    protected function loadControllersForCoverage(): void
    {
        $this->initializeControllerLoader();

        $explicitPath = $this->getControllerPath();

        $this->debugLog("=== Controller Loading Debug ===");
        $this->debugLog("Test class: " . get_class($this));

        if ($explicitPath) {
            $this->debugLog("Using explicit controller path: {$explicitPath}");
            $this->loadControllerFile($explicitPath, false);
        } else {
            $this->debugLog("Using conventional path resolution");
            $this->loadControllerByConvention();
        }

        $this->debugLog("=== End Controller Loading Debug ===");
    }

    protected function getControllerPath(): ?string
    {
        return null; // Override in test classes for non-standard paths
    }

    private function loadControllerByConvention(): void
    {
        $testClass = get_class($this);
        if (!preg_match('/Controller(.+)Test$/', $testClass, $matches)) {
            $this->debugLog("Could not extract controller name from test class: {$testClass}");
            return;
        }

        $originalClassName = $matches[1];
        $this->debugLog("Extracted original controller name: {$originalClassName}");

        $snake = $this->camelToSnake($originalClassName);
        $this->debugLog("Converted to snake_case: {$snake}");

        $attempts = $this->generateConventionalPaths($originalClassName);

        $this->debugLog("Generated " . count($attempts) . " path attempts:");
        foreach ($attempts as $i => $path) {
            $this->debugLog("  Attempt " . ($i + 1) . ": {$path}");
        }

        foreach ($attempts as $path) {
            if ($this->tryLoadControllerFile($path)) {
                $this->debugLog("SUCCESS: Loaded controller from path: {$path}");
                return;
            }
        }

        $this->debugLog("FAILURE: Could not find controller file for {$originalClassName}");
        $this->debugLog("All attempted paths failed: " . implode(', ', $attempts));
    }

    private function generateConventionalPaths(string $className): array
    {
        $snake = $this->camelToSnake($className);
        $parts = explode('_', $snake);
        $attempts = [];

        $this->debugLog("Snake case parts: [" . implode(', ', $parts) . "]");

        // Pattern 1: First part as dir, rest as filename (studio/workbench_voicecopy.php)
        if (count($parts) >= 2) {
            $dir = $parts[0];
            $file = implode('_', array_slice($parts, 1));
            $path = $dir . '/' . $file . '.php';
            $attempts[] = $path;
            $this->debugLog("Pattern 1 (dir/file): {$dir} + {$file} = {$path}");
        }

        // Pattern 2: Each part as directory except last (studio/workbench/voicecopy.php)
        if (count($parts) > 2) {
            $dirs = array_slice($parts, 0, -1);
            $file = end($parts);
            $path = implode('/', $dirs) . '/' . $file . '.php';
            $attempts[] = $path;
            $this->debugLog("Pattern 2 (nested dirs): [" . implode('/', $dirs) . "] + {$file} = {$path}");
        }

        // Pattern 3: All parts as filename (studio_workbench_voicecopy.php)
        $path = implode('_', $parts) . '.php';
        $attempts[] = $path;
        $this->debugLog("Pattern 3 (flat file): " . implode('_', $parts) . " = {$path}");

        $uniqueAttempts = array_unique($attempts);
        $this->debugLog("Unique attempts: " . count($uniqueAttempts) . " (removed " . (count($attempts) - count($uniqueAttempts)) . " duplicates)");

        return $uniqueAttempts;
    }

    private function tryLoadControllerFile(string $path): bool
    {
        $this->debugLog("Trying to load controller file: {$path}");

        foreach ($this->controllerBasePaths as $basePath) {
            $fullPath = $basePath . $path;
            $this->debugLog("  Checking: {$fullPath}");

            if (file_exists($fullPath)) {
                $this->debugLog("  FOUND: File exists at {$fullPath}");
                require_once $fullPath;
                return true;
            } else {
                $this->debugLog("  NOT FOUND: {$fullPath}");
            }
        }

        return false;
    }

    private function camelToSnake(string $input): string
    {
        $result = strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($input)));
        $this->debugLog("camelToSnake: '{$input}' -> '{$result}'");
        return $result;
    }

    protected function loadControllerFile(string $controllerPath, bool $convertPath = true): void
    {
        $this->debugLog("loadControllerFile called with: path='{$controllerPath}', convertPath=" . ($convertPath ? 'true' : 'false'));

        if ($convertPath) {
            $originalPath = $controllerPath;
            $controllerPath = $this->convertClassNameToPath($controllerPath);
            $this->debugLog("Path converted from '{$originalPath}' to '{$controllerPath}'");
        }

        $controllerPath = preg_replace('/\.php$/', '', $controllerPath) . '.php';
        $this->debugLog("Final path after .php normalization: '{$controllerPath}'");

        foreach ($this->controllerBasePaths as $basePath) {
            $fullPath = $basePath . $controllerPath;
            $this->debugLog("Trying loadControllerFile at: {$fullPath}");

            if (file_exists($fullPath)) {
                $this->debugLog("SUCCESS: loadControllerFile loaded from {$fullPath}");
                require_once $fullPath;
                return;
            } else {
                $this->debugLog("MISS: loadControllerFile not found at {$fullPath}");
            }
        }

        $this->debugLog("FAILURE: loadControllerFile could not find: {$controllerPath}");
    }
}
