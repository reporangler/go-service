<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use RepoRangler\Services\MetadataClient;
use RepoRangler\Services\StorageClient;

class GoController extends BaseController
{
    /**
     * Encode a Go module path: uppercase letters become '!' + lowercase.
     */
    private function encodeModulePath(string $path): string
    {
        return preg_replace_callback('/[A-Z]/', fn($m) => '!' . strtolower($m[0]), $path);
    }

    /**
     * Decode a Go module path: '!' + lowercase becomes uppercase.
     */
    private function decodeModulePath(string $path): string
    {
        return preg_replace_callback('/!([a-z])/', fn($m) => strtoupper($m[1]), $path);
    }

    /**
     * GET /{module}/@v/list
     * Returns plain text list of versions, one per line.
     */
    public function listVersions(string $module)
    {
        $module = $this->decodeModulePath($module);
        $metadata = app(MetadataClient::class);
        $repoType = config('app.repo_type');

        $result = $metadata->getPackagesByName($repoType, $module);

        $versions = [];
        foreach (($result['data'] ?? []) as $pkg) {
            $versions[] = $pkg['version'];
        }

        return new Response(implode("\n", $versions), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * GET /{module}/@v/{version}.info
     * Returns JSON with Version and Time.
     */
    public function versionInfo(string $module, string $version)
    {
        $module = $this->decodeModulePath($module);
        $metadata = app(MetadataClient::class);
        $repoType = config('app.repo_type');

        $result = $metadata->getPackagesByName($repoType, $module);

        foreach (($result['data'] ?? []) as $pkg) {
            if ($pkg['version'] === $version) {
                $definition = $pkg['definition'] ?? [];
                return new JsonResponse([
                    'Version' => $version,
                    'Time' => $definition['time'] ?? $pkg['created_at'] ?? now()->toIso8601String(),
                ]);
            }
        }

        return new JsonResponse(['error' => 'not found'], 404);
    }

    /**
     * GET /{module}/@v/{version}.mod
     * Returns the go.mod file content.
     */
    public function goMod(string $module, string $version)
    {
        $module = $this->decodeModulePath($module);
        $metadata = app(MetadataClient::class);
        $storage = app(StorageClient::class);
        $repoType = config('app.repo_type');

        $result = $metadata->getPackagesByName($repoType, $module);

        foreach (($result['data'] ?? []) as $pkg) {
            if ($pkg['version'] === $version) {
                $definition = $pkg['definition'] ?? [];
                $gomodKey = $definition['gomod_key'] ?? null;

                if ($gomodKey) {
                    $content = $storage->download($gomodKey);
                    if ($content !== null) {
                        return new Response($content, 200, [
                            'Content-Type' => 'text/plain',
                        ]);
                    }
                }

                // Fallback: return a minimal go.mod
                return new Response("module {$module}\n", 200, [
                    'Content-Type' => 'text/plain',
                ]);
            }
        }

        return new JsonResponse(['error' => 'not found'], 404);
    }

    /**
     * GET /{module}/@v/{version}.zip
     * Returns the module zip file.
     */
    public function moduleZip(string $module, string $version)
    {
        $module = $this->decodeModulePath($module);
        $metadata = app(MetadataClient::class);
        $storage = app(StorageClient::class);
        $repoType = config('app.repo_type');

        $result = $metadata->getPackagesByName($repoType, $module);

        foreach (($result['data'] ?? []) as $pkg) {
            if ($pkg['version'] === $version) {
                $definition = $pkg['definition'] ?? [];
                $zipKey = $definition['zip_key'] ?? $pkg['storage_key'] ?? null;

                if ($zipKey) {
                    $content = $storage->download($zipKey);
                    if ($content !== null) {
                        return new Response($content, 200, [
                            'Content-Type' => 'application/zip',
                            'Content-Length' => strlen($content),
                        ]);
                    }
                }

                return new JsonResponse(['error' => 'zip not found'], 404);
            }
        }

        return new JsonResponse(['error' => 'not found'], 404);
    }

    /**
     * GET /{module}/@latest
     * Returns the latest version info.
     */
    public function latest(string $module)
    {
        $module = $this->decodeModulePath($module);
        $metadata = app(MetadataClient::class);
        $repoType = config('app.repo_type');

        $result = $metadata->getPackagesByName($repoType, $module);

        $latest = null;
        foreach (($result['data'] ?? []) as $pkg) {
            if ($latest === null || version_compare(ltrim($pkg['version'], 'v'), ltrim($latest['version'], 'v'), '>')) {
                $latest = $pkg;
            }
        }

        if ($latest) {
            $definition = $latest['definition'] ?? [];
            return new JsonResponse([
                'Version' => $latest['version'],
                'Time' => $definition['time'] ?? $latest['created_at'] ?? now()->toIso8601String(),
            ]);
        }

        return new JsonResponse(['error' => 'not found'], 404);
    }

    /**
     * POST /upload
     * Custom upload endpoint for publishing Go modules.
     * Accepts multipart form: module, version, gomod (file or string), zip (file).
     */
    public function upload(Request $request)
    {
        $user = $request->user();

        if (!$user || $user->is_public_user) {
            return new JsonResponse(['error' => 'unauthorized', 'message' => 'Authentication required'], 401);
        }

        $request->validate([
            'module' => 'required|string',
            'version' => 'required|string|starts_with:v',
            'zip' => 'required|file',
        ]);

        $module = $request->input('module');
        $version = $request->input('version');

        // Determine package group from module path
        $parts = explode('/', $module);
        $packageGroup = $request->header('x-package-group', $parts[0] ?? 'public');

        $encodedModule = $this->encodeModulePath($module);
        $moduleBase = basename($module);

        $metadata = app(MetadataClient::class);
        $storage = app(StorageClient::class);
        $repoType = config('app.repo_type');

        // Upload zip
        $zipContent = file_get_contents($request->file('zip')->getRealPath());
        $zipKey = "go/{$packageGroup}/{$encodedModule}/{$version}/{$moduleBase}-{$version}.zip";
        $storage->upload($zipKey, $zipContent);

        // Upload go.mod
        $gomodKey = "go/{$packageGroup}/{$encodedModule}/{$version}/go.mod";
        if ($request->hasFile('gomod')) {
            $gomodContent = file_get_contents($request->file('gomod')->getRealPath());
        } elseif ($request->has('gomod')) {
            $gomodContent = $request->input('gomod');
        } else {
            $gomodContent = "module {$module}\n";
        }
        $storage->upload($gomodKey, $gomodContent);

        // Store metadata
        $time = now()->toIso8601String();
        $definition = [
            'module' => $module,
            'version' => $version,
            'time' => $time,
            'gomod_key' => $gomodKey,
            'zip_key' => $zipKey,
        ];

        $metadata->addPackage($repoType, $packageGroup, $module, $version, $definition, $zipKey, 'zip');

        return new JsonResponse([
            'ok' => true,
            'module' => $module,
            'version' => $version,
            'time' => $time,
        ]);
    }
}
