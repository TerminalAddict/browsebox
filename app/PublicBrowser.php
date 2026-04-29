<?php

declare(strict_types=1);

final class PublicBrowser
{
    public function __construct(
        private readonly FileManager $fileManager,
        private readonly PathGuard $pathGuard,
        private readonly Config $config,
    ) {
    }

    public function browse(string $relativePath): array
    {
        $relativePath = $this->pathGuard->normalizeRelativePath($relativePath);

        return [
            'current_path' => $relativePath,
            'items' => $this->fileManager->listDirectory($relativePath),
            'breadcrumbs' => View::breadcrumbs($relativePath),
        ];
    }

    public function directoryIndexFile(string $relativePath): ?string
    {
        if (!(bool) $this->config->get('allow_html_rendering', false)) {
            return null;
        }

        foreach (['index.html', 'index.htm'] as $filename) {
            $candidate = $this->pathGuard->join($relativePath, $filename);

            if ($this->fileManager->exists($candidate) && is_file($this->pathGuard->resolve($candidate, true))) {
                return $candidate;
            }
        }

        return null;
    }
}
