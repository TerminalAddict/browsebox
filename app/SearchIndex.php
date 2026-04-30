<?php

declare(strict_types=1);

final class SearchIndex
{
    private const VERSION = 1;
    private const MAX_TEXT_BYTES = 262144;
    private const MAX_RESULTS = 50;

    private string $indexFile;
    private string $cacheDirectory;
    private string $storageRoot;
    private array $searchableTextExtensions;

    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $pathGuard,
        private readonly FileManager $fileManager,
    ) {
        $dataRoot = rtrim($this->config->requireString('data_root'), '/');
        $this->indexFile = $dataRoot . '/search-index.json';
        $this->cacheDirectory = $dataRoot . '/search-cache';
        $this->storageRoot = $this->pathGuard->storageRoot();
        $this->searchableTextExtensions = array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            (array) $this->config->get('searchable_text_extensions', ['txt', 'md', 'html', 'htm', 'csv', 'log', 'json', 'xml', 'pdf'])
        );
    }

    public function ensureBuilt(): void
    {
        if (is_file($this->indexFile)) {
            return;
        }

        $this->rebuild();
    }

    public function rebuild(): void
    {
        $this->ensureCacheDirectory();
        $index = $this->emptyIndex();
        $activeCacheKeys = [];

        foreach ($this->scanRelativePath('') as $document) {
            $this->addDocument($index, $document);

            if (is_string($document['content_cache'] ?? null) && $document['content_cache'] !== '') {
                $activeCacheKeys[] = basename((string) $document['content_cache']);
            }
        }

        $index['built_at'] = date(DATE_ATOM);
        $this->saveIndex($index);
        $this->cleanupUnusedCacheFiles($activeCacheKeys);
    }

    public function indexPath(string $relativePath): void
    {
        $this->ensureBuilt();
        $index = $this->loadIndex();
        $this->removePathFromIndex($index, $relativePath);
        $activeCacheKeys = $this->activeCacheKeys($index);

        if ($this->fileManager->exists($relativePath)) {
            foreach ($this->scanRelativePath($relativePath) as $document) {
                $this->addDocument($index, $document);

                if (is_string($document['content_cache'] ?? null) && $document['content_cache'] !== '') {
                    $activeCacheKeys[] = basename((string) $document['content_cache']);
                }
            }
        }

        $index['built_at'] = date(DATE_ATOM);
        $this->saveIndex($index);
        $this->cleanupUnusedCacheFiles($activeCacheKeys);
    }

    public function movePath(string $oldRelativePath, string $newRelativePath): void
    {
        $this->ensureBuilt();
        $index = $this->loadIndex();
        $this->removePathFromIndex($index, $oldRelativePath);
        $activeCacheKeys = $this->activeCacheKeys($index);

        if ($this->fileManager->exists($newRelativePath)) {
            foreach ($this->scanRelativePath($newRelativePath) as $document) {
                $this->addDocument($index, $document);

                if (is_string($document['content_cache'] ?? null) && $document['content_cache'] !== '') {
                    $activeCacheKeys[] = basename((string) $document['content_cache']);
                }
            }
        }

        $index['built_at'] = date(DATE_ATOM);
        $this->saveIndex($index);
        $this->cleanupUnusedCacheFiles($activeCacheKeys);
    }

    public function removePath(string $relativePath): void
    {
        $this->ensureBuilt();
        $index = $this->loadIndex();
        $this->removePathFromIndex($index, $relativePath);
        $index['built_at'] = date(DATE_ATOM);
        $this->saveIndex($index);
        $this->cleanupUnusedCacheFiles($this->activeCacheKeys($index));
    }

    public function search(string $query, int $limit = self::MAX_RESULTS): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'query' => '',
                'results' => [],
                'count' => 0,
                'built_at' => null,
            ];
        }

        $this->ensureBuilt();
        $index = $this->loadIndex();
        $documents = is_array($index['documents'] ?? null) ? $index['documents'] : [];
        $normalizedQuery = $this->normalizeSearchText($query);
        $queryTerms = $this->tokenize($normalizedQuery);
        $queryNgrams = $this->ngrams($normalizedQuery);
        $candidateScores = [];

        foreach ($queryTerms as $term) {
            foreach ((array) ($index['filename_terms'][$term] ?? []) as $path) {
                $candidateScores[$path] = ($candidateScores[$path] ?? 0) + 18;
            }

            foreach ((array) ($index['content_terms'][$term] ?? []) as $path) {
                $candidateScores[$path] = ($candidateScores[$path] ?? 0) + 8;
            }
        }

        foreach ($queryNgrams as $ngram) {
            foreach ((array) ($index['filename_ngrams'][$ngram] ?? []) as $path) {
                $candidateScores[$path] = ($candidateScores[$path] ?? 0) + 2;
            }
        }

        if ($candidateScores === [] || strlen($normalizedQuery) < 3) {
            foreach ($documents as $path => $document) {
                $basename = $this->normalizeSearchText((string) ($document['name'] ?? ''));
                $fullPath = $this->normalizeSearchText((string) $path);

                if ($normalizedQuery !== '' && (str_contains($basename, $normalizedQuery) || str_contains($fullPath, $normalizedQuery))) {
                    $candidateScores[$path] = ($candidateScores[$path] ?? 0) + 12;
                }
            }
        }

        $results = [];

        foreach ($candidateScores as $path => $seedScore) {
            $document = $documents[$path] ?? null;

            if (!is_array($document)) {
                continue;
            }

            $score = $seedScore + $this->scoreDocument($document, $normalizedQuery, $queryTerms, $queryNgrams);

            if ($score <= 0) {
                continue;
            }

            $snippet = $this->snippetForDocument($document, $queryTerms, $normalizedQuery);

            $results[] = [
                'score' => $score,
                'path' => (string) ($document['path'] ?? $path),
                'name' => (string) ($document['name'] ?? basename($path)),
                'type' => (string) ($document['type'] ?? 'file'),
                'icon' => (string) ($document['icon'] ?? 'file'),
                'size' => is_int($document['size'] ?? null) ? $document['size'] : null,
                'modified' => is_int($document['modified'] ?? null) ? $document['modified'] : null,
                'has_entrypoint' => (bool) ($document['has_entrypoint'] ?? false),
                'matched_content' => $snippet !== null,
                'snippet' => $snippet,
            ];
        }

        usort($results, static function (array $left, array $right): int {
            $scoreComparison = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $typeComparison = strcmp((string) ($left['type'] ?? ''), (string) ($right['type'] ?? ''));

            if ($typeComparison !== 0) {
                return $typeComparison;
            }

            return strcasecmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? ''));
        });

        return [
            'query' => $query,
            'results' => array_slice($results, 0, max(1, $limit)),
            'count' => count($results),
            'built_at' => is_string($index['built_at'] ?? null) ? $index['built_at'] : null,
        ];
    }

    public function status(): array
    {
        if (!is_file($this->indexFile)) {
            return [
                'exists' => false,
                'built_at' => null,
                'document_count' => 0,
                'pdf_text_extractor' => $this->pdftotextAvailable() ? 'pdftotext' : 'fallback',
            ];
        }

        $index = $this->loadIndex();

        return [
            'exists' => true,
            'built_at' => is_string($index['built_at'] ?? null) ? $index['built_at'] : null,
            'document_count' => count((array) ($index['documents'] ?? [])),
            'pdf_text_extractor' => $this->pdftotextAvailable() ? 'pdftotext' : 'fallback',
        ];
    }

    private function emptyIndex(): array
    {
        return [
            'version' => self::VERSION,
            'built_at' => null,
            'documents' => [],
            'filename_terms' => [],
            'content_terms' => [],
            'filename_ngrams' => [],
        ];
    }

    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0775, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Unable to create search cache directory.');
        }
    }

    private function loadIndex(): array
    {
        if (!is_file($this->indexFile)) {
            return $this->emptyIndex();
        }

        $raw = file_get_contents($this->indexFile);

        if ($raw === false || $raw === '') {
            return $this->emptyIndex();
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Search index is invalid.');
        }

        return array_replace_recursive($this->emptyIndex(), $decoded);
    }

    private function saveIndex(array $index): void
    {
        $directory = dirname($this->indexFile);
        $tempFile = tempnam($directory, 'browsebox-search-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary search index.');
        }

        $payload = json_encode($index, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to encode search index.');
        }

        if (file_put_contents($tempFile, $payload, LOCK_EX) === false || !rename($tempFile, $this->indexFile)) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to write search index.');
        }
    }

    private function removePathFromIndex(array &$index, string $relativePath): void
    {
        $prefix = $relativePath === '' ? '' : $relativePath . '/';
        $documentPaths = array_keys((array) ($index['documents'] ?? []));

        foreach ($documentPaths as $path) {
            if ($relativePath !== '' && $path !== $relativePath && !str_starts_with($path, $prefix)) {
                continue;
            }

            $document = $index['documents'][$path] ?? null;

            if (!is_array($document)) {
                unset($index['documents'][$path]);
                continue;
            }

            foreach ((array) ($document['filename_terms'] ?? []) as $term) {
                $this->removePosting($index['filename_terms'], (string) $term, $path);
            }

            foreach ((array) ($document['content_terms'] ?? []) as $term) {
                $this->removePosting($index['content_terms'], (string) $term, $path);
            }

            foreach ((array) ($document['filename_ngrams'] ?? []) as $ngram) {
                $this->removePosting($index['filename_ngrams'], (string) $ngram, $path);
            }

            unset($index['documents'][$path]);
        }
    }

    private function removePosting(array &$map, string $token, string $path): void
    {
        if (!isset($map[$token]) || !is_array($map[$token])) {
            return;
        }

        $map[$token] = array_values(array_filter(
            $map[$token],
            static fn (mixed $value): bool => (string) $value !== $path
        ));

        if ($map[$token] === []) {
            unset($map[$token]);
        }
    }

    private function addDocument(array &$index, array $document): void
    {
        $path = (string) ($document['path'] ?? '');

        if ($path === '') {
            return;
        }

        $index['documents'][$path] = $document;

        foreach ((array) ($document['filename_terms'] ?? []) as $term) {
            $index['filename_terms'][$term] ??= [];
            $index['filename_terms'][$term][] = $path;
            $index['filename_terms'][$term] = array_values(array_unique($index['filename_terms'][$term]));
        }

        foreach ((array) ($document['content_terms'] ?? []) as $term) {
            $index['content_terms'][$term] ??= [];
            $index['content_terms'][$term][] = $path;
            $index['content_terms'][$term] = array_values(array_unique($index['content_terms'][$term]));
        }

        foreach ((array) ($document['filename_ngrams'] ?? []) as $ngram) {
            $index['filename_ngrams'][$ngram] ??= [];
            $index['filename_ngrams'][$ngram][] = $path;
            $index['filename_ngrams'][$ngram] = array_values(array_unique($index['filename_ngrams'][$ngram]));
        }
    }

    private function scanRelativePath(string $relativePath): array
    {
        $fullPath = $this->pathGuard->resolve($relativePath, true);

        if (is_file($fullPath)) {
            return [$this->buildDocument($relativePath, $fullPath, false)];
        }

        $documents = [];
        $directoryIterator = new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS);
        $filteredIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $current): bool {
                return !str_starts_with($current->getFilename(), '.');
            }
        );
        $iterator = new RecursiveIteratorIterator(
            $filteredIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        $normalizedBase = $relativePath;

        if ($normalizedBase !== '') {
            $documents[] = $this->buildDocument($normalizedBase, $fullPath, true);
        }

        foreach ($iterator as $item) {
            $name = $item->getFilename();

            if (str_starts_with($name, '.')) {
                continue;
            }

            $itemPath = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($itemPath, strlen($this->storageRoot)), '/');

            if ($relative === '') {
                continue;
            }

            $documents[] = $this->buildDocument($relative, $itemPath, $item->isDir());
        }

        return $documents;
    }

    private function buildDocument(string $relativePath, string $fullPath, bool $isDirectory): array
    {
        $name = basename($relativePath);
        $filenameTerms = $this->tokenize($this->normalizeSearchText($name));
        $filenameNgrams = $this->ngrams($this->normalizeSearchText($name . ' ' . $relativePath));
        $contentTerms = [];
        $contentCache = null;

        if (!$isDirectory) {
            $extractedText = $this->extractSearchableContent($fullPath);

            if ($extractedText !== null && $extractedText !== '') {
                $contentTerms = $this->tokenize($this->normalizeSearchText($extractedText));
                $contentCache = $this->cacheTextContent($relativePath, $fullPath, $extractedText);
            }
        }

        return [
            'path' => $relativePath,
            'name' => $name,
            'type' => $isDirectory ? 'dir' : 'file',
            'icon' => $isDirectory ? 'folder' : $this->iconForFile($name),
            'size' => $isDirectory ? null : (filesize($fullPath) ?: null),
            'modified' => filemtime($fullPath) ?: null,
            'has_entrypoint' => $isDirectory ? $this->directoryHasEntrypoint($fullPath) : false,
            'filename_terms' => $filenameTerms,
            'filename_ngrams' => $filenameNgrams,
            'content_terms' => $contentTerms,
            'content_cache' => $contentCache,
        ];
    }

    private function extractSearchableContent(string $fullPath): ?string
    {
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->searchableTextExtensions, true)) {
            return null;
        }

        if ($extension === 'pdf') {
            return $this->extractPdfText($fullPath);
        }

        $raw = @file_get_contents($fullPath, false, null, 0, self::MAX_TEXT_BYTES);

        if ($raw === false || $raw === '') {
            return null;
        }

        if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        }

        if (in_array($extension, ['html', 'htm'], true)) {
            $raw = strip_tags($raw);
        }

        return trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    }

    private function extractPdfText(string $fullPath): ?string
    {
        $text = $this->extractPdfTextWithPdftotext($fullPath);

        if ($text === null || $text === '') {
            $text = $this->extractPdfTextFallback($fullPath);
        }

        return $text === '' ? null : $text;
    }

    private function extractPdfTextWithPdftotext(string $fullPath): ?string
    {
        if (!$this->pdftotextAvailable()) {
            return null;
        }

        if (!function_exists('proc_open')) {
            return null;
        }

        $command = ['pdftotext', '-layout', '-enc', 'UTF-8', $fullPath, '-'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return $stderr === '' ? null : null;
        }

        return trim(preg_replace('/\s+/u', ' ', $stdout) ?? $stdout);
    }

    private function pdftotextAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!function_exists('proc_open')) {
            $available = false;
            return $available;
        }

        $process = @proc_open(['pdftotext', '-v'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            $available = false;
            return $available;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $available = true;

        return $available;
    }

    private function extractPdfTextFallback(string $fullPath): ?string
    {
        $raw = @file_get_contents($fullPath, false, null, 0, self::MAX_TEXT_BYTES);

        if ($raw === false || $raw === '') {
            return null;
        }

        preg_match_all('/\(([^()]|\\\\.){4,}\)/', $raw, $matches);

        if (($matches[0] ?? []) === []) {
            return null;
        }

        $parts = [];

        foreach ($matches[0] as $match) {
            $value = substr((string) $match, 1, -1);
            $value = preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $value) ?? $value;
            $value = preg_replace('/\\\\[0-7]{1,3}/', ' ', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            $value = trim($value);

            if ($value !== '' && preg_match('/[A-Za-z]{3,}/', $value)) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(' ', array_slice($parts, 0, 2000));
    }

    private function cacheTextContent(string $relativePath, string $fullPath, string $content): string
    {
        $this->ensureCacheDirectory();
        $cacheKey = sha1($relativePath . '|' . (string) (filemtime($fullPath) ?: 0) . '|' . (string) (filesize($fullPath) ?: 0)) . '.txt';
        $cacheFile = $this->cacheDirectory . '/' . $cacheKey;

        if (!is_file($cacheFile)) {
            file_put_contents($cacheFile, $content, LOCK_EX);
        }

        return $cacheFile;
    }

    private function cleanupUnusedCacheFiles(array $activeCacheKeys): void
    {
        if (!is_dir($this->cacheDirectory)) {
            return;
        }

        $active = array_fill_keys($activeCacheKeys, true);
        $entries = scandir($this->cacheDirectory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            if (!isset($active[$entry])) {
                @unlink($this->cacheDirectory . '/' . $entry);
            }
        }
    }

    private function activeCacheKeys(array $index): array
    {
        $keys = [];

        foreach ((array) ($index['documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $contentCache = (string) ($document['content_cache'] ?? '');

            if ($contentCache !== '') {
                $keys[] = basename($contentCache);
            }
        }

        return array_values(array_unique($keys));
    }

    private function scoreDocument(array $document, string $normalizedQuery, array $queryTerms, array $queryNgrams): int
    {
        $name = $this->normalizeSearchText((string) ($document['name'] ?? ''));
        $path = $this->normalizeSearchText((string) ($document['path'] ?? ''));
        $score = 0;

        if ($normalizedQuery !== '') {
            if ($name === $normalizedQuery) {
                $score += 120;
            }

            if (str_contains($name, $normalizedQuery)) {
                $score += 80;
            }

            if (str_contains($path, $normalizedQuery)) {
                $score += 36;
            }
        }

        $filenameTerms = array_fill_keys((array) ($document['filename_terms'] ?? []), true);
        $contentTerms = array_fill_keys((array) ($document['content_terms'] ?? []), true);

        foreach ($queryTerms as $term) {
            if (isset($filenameTerms[$term])) {
                $score += 18;
            }

            if (isset($contentTerms[$term])) {
                $score += 9;
            }
        }

        $nameNgrams = $this->ngrams($name);

        if ($queryNgrams !== [] && $nameNgrams !== []) {
            $intersection = count(array_intersect($queryNgrams, $nameNgrams));
            $union = count(array_unique(array_merge($queryNgrams, $nameNgrams)));

            if ($union > 0) {
                $score += (int) round(($intersection / $union) * 30);
            }
        }

        if ($normalizedQuery !== '' && strlen($normalizedQuery) <= 64 && $name !== '') {
            $distance = levenshtein(substr($normalizedQuery, 0, 255), substr($name, 0, 255));
            $maxLength = max(strlen($normalizedQuery), strlen($name));

            if ($maxLength > 0) {
                $similarity = 1 - ($distance / $maxLength);

                if ($similarity > 0.35) {
                    $score += (int) round($similarity * 24);
                }
            }
        }

        if (($document['type'] ?? '') === 'dir') {
            $score += 4;
        }

        return $score;
    }

    private function snippetForDocument(array $document, array $queryTerms, string $normalizedQuery): ?string
    {
        $cacheFile = (string) ($document['content_cache'] ?? '');

        if ($cacheFile === '' || !is_file($cacheFile)) {
            return null;
        }

        $content = file_get_contents($cacheFile);

        if ($content === false || $content === '') {
            return null;
        }

        $haystack = $this->normalizeSearchText($content);
        $needle = $normalizedQuery;
        $position = $needle === '' ? false : strpos($haystack, $needle);

        if ($position === false) {
            foreach ($queryTerms as $term) {
                $position = strpos($haystack, $term);

                if ($position !== false) {
                    $needle = $term;
                    break;
                }
            }
        }

        if ($position === false) {
            return null;
        }

        $start = max(0, $position - 80);
        $snippet = function_exists('mb_substr')
            ? mb_substr($content, $start, 220)
            : substr($content, $start, 220);
        $snippet = trim(preg_replace('/\s+/u', ' ', $snippet) ?? $snippet);

        if ($start > 0) {
            $snippet = '…' . $snippet;
        }

        $contentLength = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);

        if ($contentLength > $start + 220) {
            $snippet .= '…';
        }

        return $snippet;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function tokenize(string $value): array
    {
        if ($value === '') {
            return [];
        }

        preg_match_all('/[a-z0-9]{2,}/', $value, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function ngrams(string $value, int $size = 3): array
    {
        $value = str_replace(' ', '', $value);

        if ($value === '') {
            return [];
        }

        if (strlen($value) <= $size) {
            return [$value];
        }

        $grams = [];
        $length = strlen($value) - $size + 1;

        for ($index = 0; $index < $length; $index++) {
            $grams[] = substr($value, $index, $size);
        }

        return array_values(array_unique($grams));
    }

    private function iconForFile(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'zip', 'tar', 'gz', 'tgz', '7z', 'rar' => 'archive',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => 'image',
            'html', 'htm' => 'html',
            'pdf' => 'pdf',
            'mp3', 'wav', 'ogg', 'flac' => 'audio',
            'mp4', 'webm', 'mov', 'mkv' => 'video',
            default => 'file',
        };
    }

    private function directoryHasEntrypoint(string $directoryPath): bool
    {
        foreach (['index.html', 'index.htm', 'index.php'] as $candidate) {
            if (is_file($directoryPath . '/' . $candidate)) {
                return true;
            }
        }

        return false;
    }
}
