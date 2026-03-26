<?php

use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Panel\Controller\PageTree;
use Kirby\Toolkit\I18n;

if (!defined('ARBORESCENCE_SEARCH_INDEX_FORMAT_VERSION')) {
    define('ARBORESCENCE_SEARCH_INDEX_FORMAT_VERSION', 3);
}

if (!function_exists('arborescencePanelTitle')) {
    function arborescencePanelTitle(Page $page): string
    {
        if (function_exists('ia_feature_flags_panel_title') === true) {
            return ia_feature_flags_panel_title($page);
        }

        $title = $page->title()->toString();

        if (is_string($title) === true && $title !== '') {
            return $title;
        }

        return I18n::translate('page');
    }
}

if (!function_exists('arborescenceParentModel')) {
    function arborescenceParentModel(string $rootPage, Site|Page $model): Site|Page|null
    {
        if ($rootPage === 'site') {
            return App::instance()->site()->homePage();
        }

        if ($rootPage === '') {
            return $model;
        }

        return Find::parent($rootPage);
    }
}

if (!function_exists('arborescenceRootModel')) {
    function arborescenceRootModel(?string $rootPage): Site|Page|null
    {
        if ($rootPage === null || $rootPage === '') {
            return null;
        }

        if ($rootPage === 'site') {
            return App::instance()->site();
        }

        return Find::parent($rootPage);
    }
}

if (!function_exists('arborescenceSearchIndexScope')) {
    function arborescenceSearchIndexScope(string $rootPage, array $branchSorts = []): string
    {
        $app = App::instance();
        $language = $app->language()?->code() ?? 'default';
        $user = $app->user()?->id() ?? 'guest';
        $flags = function_exists('ia_feature_flags_active_flags') === true
            ? ia_feature_flags_active_flags()
            : [];

        if (is_array($flags) !== true) {
            $flags = [];
        }

        $normalizedBranchSorts = $branchSorts;
        ksort($normalizedBranchSorts);

        $normalizedFlags = array_values(
            array_filter(
                array_map(fn ($flag) => is_string($flag) ? trim($flag) : '', $flags),
                fn (string $flag) => $flag !== ''
            )
        );
        sort($normalizedFlags);

        return md5(json_encode([
            'branchSorts' => $normalizedBranchSorts,
            'flags' => $normalizedFlags,
            'formatVersion' => ARBORESCENCE_SEARCH_INDEX_FORMAT_VERSION,
            'language' => $language,
            'root' => $rootPage,
            'user' => $user,
        ]));
    }
}

if (!function_exists('arborescenceContentGitDirectory')) {
    function arborescenceContentGitDirectory(string $repoRoot): string|null
    {
        $gitPath = rtrim($repoRoot, '/') . '/.git';

        if (is_dir($gitPath) === true) {
            return $gitPath;
        }

        if (is_file($gitPath) !== true) {
            return null;
        }

        $gitFile = trim((string)@file_get_contents($gitPath));
        if (str_starts_with($gitFile, 'gitdir: ') !== true) {
            return null;
        }

        $relativeGitPath = trim(substr($gitFile, strlen('gitdir: ')));
        if ($relativeGitPath === '') {
            return null;
        }

        $resolvedGitPath = str_starts_with($relativeGitPath, '/') === true
            ? $relativeGitPath
            : dirname($gitPath) . '/' . $relativeGitPath;

        return is_dir($resolvedGitPath) === true ? $resolvedGitPath : null;
    }
}

if (!function_exists('arborescencePackedRefRevision')) {
    function arborescencePackedRefRevision(string $gitDirectory, string $reference): string|null
    {
        $packedRefsPath = $gitDirectory . '/packed-refs';
        if (is_readable($packedRefsPath) !== true) {
            return null;
        }

        $packedRefs = @file($packedRefsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($packedRefs) !== true) {
            return null;
        }

        foreach ($packedRefs as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            [$hash, $name] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);

            if ($name === $reference && is_string($hash) === true && preg_match('/^[a-f0-9]{40}$/i', $hash) === 1) {
                return strtolower($hash);
            }
        }

        return null;
    }
}

if (!function_exists('arborescenceSearchIndexRevision')) {
    function arborescenceSearchIndexRevision(): string|null
    {
        static $revision = false;

        if ($revision !== false) {
            return $revision;
        }

        $revision = null;
        $repoRoot = trim((string)option('thathoff.git-content.path', App::instance()->root('content')));
        if ($repoRoot === '') {
            return null;
        }

        $gitDirectory = arborescenceContentGitDirectory($repoRoot);
        if ($gitDirectory === null) {
            return null;
        }

        $head = trim((string)@file_get_contents($gitDirectory . '/HEAD'));
        if ($head === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9]{40}$/i', $head) === 1) {
            $revision = strtolower($head);
            return $revision;
        }

        if (str_starts_with($head, 'ref: ') !== true) {
            return null;
        }

        $reference = trim(substr($head, strlen('ref: ')));
        if ($reference === '') {
            return null;
        }

        $referencePath = $gitDirectory . '/' . $reference;
        $resolvedReference = trim((string)@file_get_contents($referencePath));

        if (preg_match('/^[a-f0-9]{40}$/i', $resolvedReference) === 1) {
            $revision = strtolower($resolvedReference);
            return $revision;
        }

        $revision = arborescencePackedRefRevision($gitDirectory, $reference);
        return $revision;
    }
}

if (!function_exists('arborescenceSearchIndexCacheKey')) {
    function arborescenceSearchIndexCacheKey(string $rootPage, array $branchSorts = []): string
    {
        $revision = arborescenceSearchIndexRevision() ?? 'unknown';

        return 'arborescence.search-index.' .
            arborescenceSearchIndexScope($rootPage, $branchSorts) .
            '.' .
            $revision;
    }
}

if (!function_exists('arborescenceSearchablePath')) {
    function arborescenceSearchablePath(string $id): string
    {
        return $id;
    }
}

if (!function_exists('arborescenceCanChangeSort')) {
    function arborescenceCanChangeSort(Page $page): bool
    {
        if ($page->permissions()->can('sort') !== true) {
            return false;
        }

        return $page->parentModel()->children()->listed()->not($page)->count() > 0;
    }
}

if (!function_exists('arborescencePageMenuData')) {
    function arborescencePageMenuData(Page $page): array
    {
        return [
            'canChangeSlug' => $page->permissions()->can('changeSlug'),
            'canChangeSort' => arborescenceCanChangeSort($page),
            'canChangeStatus' => $page->permissions()->can('changeStatus'),
            'canChangeTitle' => $page->permissions()->can('changeTitle'),
            'canCreate' => $page->permissions()->can('create'),
            'canDelete' => $page->permissions()->can('delete'),
            'canDuplicate' => $page->permissions()->can('duplicate'),
            'label' => arborescencePanelTitle($page),
            'openUrl' => $page->previewUrl(),
            'panelUrl' => $page->panel()->url(true),
            'path' => arborescenceSearchablePath($page->id()),
            'status' => $page->status(),
        ];
    }
}

if (!function_exists('arborescenceNormalizeBranchId')) {
    function arborescenceNormalizeBranchId(string $branch): string
    {
        $branch = trim($branch);
        $branch = trim($branch, '/');

        if (str_starts_with($branch, 'pages/') === true) {
            $branch = substr($branch, strlen('pages/'));
        }

        return str_replace('+', '/', $branch);
    }
}

if (!function_exists('arborescenceNormalizeBranchSorts')) {
    function arborescenceNormalizeBranchSorts(array|string|null $branchSorts): array
    {
        if (is_string($branchSorts) === true) {
            $decoded = json_decode($branchSorts, true);

            if (is_array($decoded) === true) {
                $branchSorts = $decoded;
            } else {
                return [];
            }
        }

        if (is_array($branchSorts) !== true) {
            return [];
        }

        $normalized = [];

        foreach ($branchSorts as $branch => $sortBy) {
            if (is_string($branch) !== true && is_numeric($branch) !== true) {
                continue;
            }

            if (is_string($sortBy) !== true && is_numeric($sortBy) !== true) {
                continue;
            }

            $normalizedBranch = arborescenceNormalizeBranchId((string)$branch);
            $normalizedSortBy = trim((string)$sortBy);

            if ($normalizedBranch === '' || $normalizedBranch === 'site' || $normalizedSortBy === '') {
                continue;
            }

            $normalized[$normalizedBranch] = $normalizedSortBy;
        }

        ksort($normalized);
        return $normalized;
    }
}

if (!function_exists('arborescenceBranchSort')) {
    function arborescenceBranchSort(Site|Page $parent, array $branchSorts = []): string|null
    {
        if ($parent instanceof Page !== true) {
            return null;
        }

        return $branchSorts[$parent->id()] ?? null;
    }
}

if (!function_exists('arborescenceVisibleChildren')) {
    function arborescenceVisibleChildren(
        Site|Page $parent,
        bool $skipHomePage = false,
        array $branchSorts = []
    ): array
    {
        $children = $parent
            ->childrenAndDrafts()
            ->filterBy('isListable', true);

        if ($skipHomePage === true) {
            $homePageId = App::instance()->site()->homePageId();

            $children = $children->filter(
                fn ($child) => $child instanceof Page && $child->id() !== $homePageId
            );
        }

        if ($sortBy = arborescenceBranchSort($parent, $branchSorts)) {
            $children = $children->sort(...$children::sortArgs($sortBy));
        }

        return $children->values();
    }
}

if (!function_exists('arborescenceTopLevelEntries')) {
    function arborescenceTopLevelEntries(string $rootPage, array $branchSorts = []): array
    {
        $root = arborescenceRootModel($rootPage);
        if ($root === null) {
            return [];
        }

        $tree = new ArborescencePageTree($branchSorts);

        return array_map(
            fn (Page $page) => $tree->entry($page),
            arborescenceVisibleChildren($root, $rootPage === 'site', $branchSorts)
        );
    }
}

if (!function_exists('arborescenceParentIcon')) {
    function arborescenceParentIcon(string $rootPage, Site|Page|null $model = null): string|null
    {
        $model ??= arborescenceRootModel($rootPage);
        $parent = $model ? arborescenceParentModel($rootPage, $model) : null;

        if ($rootPage === 'site') {
            return $parent?->panel()->image()['icon'] ?? 'home';
        }

        if ($parent instanceof Page) {
            return $parent->panel()->image()['icon'] ?? null;
        }

        return 'home';
    }
}

if (!function_exists('arborescenceParentTitle')) {
    function arborescenceParentTitle(string $rootPage, Site|Page|null $model = null): string
    {
        $model ??= arborescenceRootModel($rootPage);
        $parent = $model ? arborescenceParentModel($rootPage, $model) : null;

        if ($parent instanceof Site) {
            return I18n::translate('view.site');
        }

        if ($parent instanceof Page) {
            return arborescencePanelTitle($parent);
        }

        return 'Invalid rootPage setting !';
    }
}

if (!function_exists('arborescenceParentOpenTarget')) {
    function arborescenceParentOpenTarget(string $rootPage, Site|Page|null $model = null): string|null
    {
        $model ??= arborescenceRootModel($rootPage);
        $parent = $model ? arborescenceParentModel($rootPage, $model) : null;

        if (!$parent || !$parent->id()) {
            return null;
        }

        return 'pages/' . str_replace('/', '+', $parent->id());
    }
}

if (!function_exists('arborescenceParentOpenUrl')) {
    function arborescenceParentOpenUrl(string $rootPage, Site|Page|null $model = null): string|null
    {
        if ($rootPage === 'site') {
            return App::instance()->site()->url();
        }

        $model ??= arborescenceRootModel($rootPage);
        $parent = $model ? arborescenceParentModel($rootPage, $model) : null;

        if ($parent instanceof Page) {
            return $parent->previewUrl() ?? $parent->url();
        }

        return null;
    }
}

if (!function_exists('arborescenceTreePayload')) {
    function arborescenceTreePayload(
        string $rootPage,
        bool $showParent = true,
        bool $showPaths = true,
        array $branchSorts = [],
        bool $includeSearchIndex = false
    ): array
    {
        $model = arborescenceRootModel($rootPage);

        $payload = [
            'branchSorts' => $branchSorts,
            'headline' => null,
            'isSite' => $model instanceof Site,
            'label' => null,
            'pages' => arborescenceTopLevelEntries($rootPage, $branchSorts),
            'parentIcon' => arborescenceParentIcon($rootPage, $model),
            'parentOpenTarget' => arborescenceParentOpenTarget($rootPage, $model),
            'parentOpenUrl' => arborescenceParentOpenUrl($rootPage, $model),
            'parentTitle' => arborescenceParentTitle($rootPage, $model),
            'searchIndexRevision' => arborescenceSearchIndexRevision(),
            'searchIndexScope' => arborescenceSearchIndexScope($rootPage, $branchSorts),
            'rootPage' => $rootPage,
            'showParent' => $showParent,
            'showPaths' => $showPaths,
        ];

        if ($includeSearchIndex === true) {
            $payload['searchIndex'] = arborescenceSearchIndex($rootPage, $branchSorts);
        }

        return $payload;
    }
}

if (!function_exists('arborescenceSearchIndexRecord')) {
    function arborescenceSearchIndexRecord(Page $page, string|null $parentId): array
    {
        $uuid = $page->uuid()?->toString();

        return [
            ...arborescencePageMenuData($page),
            'icon' => $page->panel()->image()['icon'] ?? null,
            'id' => $page->id(),
            'parentId' => $parentId,
            'slug' => $page->slug(),
            'uuid' => $uuid,
            'value' => $uuid ?? $page->id(),
        ];
    }
}

if (!function_exists('arborescenceCollectSearchIndex')) {
    function arborescenceCollectSearchIndex(
        Page $page,
        string|null $parentId,
        array &$records,
        array $branchSorts = []
    ): void {
        $records[] = arborescenceSearchIndexRecord(
            page: $page,
            parentId: $parentId
        );

        foreach (arborescenceVisibleChildren($page, false, $branchSorts) as $child) {
            arborescenceCollectSearchIndex(
                page: $child,
                parentId: $page->id(),
                records: $records,
                branchSorts: $branchSorts
            );
        }
    }
}

if (!function_exists('arborescenceSearchIndex')) {
    function arborescenceSearchIndex(string $rootPage, array $branchSorts = []): array
    {
        $cache = App::instance()->cache('pages');
        $cacheKey = arborescenceSearchIndexRevision() !== null
            ? arborescenceSearchIndexCacheKey($rootPage, $branchSorts)
            : null;

        $cached = $cacheKey !== null ? $cache->get($cacheKey) : null;

        if (is_array($cached) === true) {
            return $cached;
        }

        $root = arborescenceRootModel($rootPage);
        $records = [];

        if ($root === null) {
            if ($cacheKey !== null) {
                $cache->set($cacheKey, $records);
            }
            return $records;
        }

        foreach (arborescenceVisibleChildren($root, $rootPage === 'site', $branchSorts) as $child) {
            arborescenceCollectSearchIndex(
                page: $child,
                parentId: null,
                records: $records,
                branchSorts: $branchSorts
            );
        }

        if ($cacheKey !== null) {
            $cache->set($cacheKey, $records);
        }
        return $records;
    }
}

class ArborescencePageTree extends PageTree
{
    public function __construct(
        protected array $branchSorts = []
    ) {
        parent::__construct();
    }

    public function children(
        string|null $parent = null,
        string|null $moving = null
    ): array {
        if ($moving !== null) {
            $moving = Find::parent($moving);
        }

        if ($parent === null) {
            return [
                $this->entry($this->site, $moving)
            ];
        }

        $parentModel = Find::parent($parent);

        return array_map(
            fn (Page $page) => $this->entry($page, $moving),
            arborescenceVisibleChildren($parentModel, false, $this->branchSorts)
        );
    }

    public function entry(Site|Page $entry, Page|null $moving = null): array
    {
        $data = parent::entry($entry, $moving);

        if ($entry instanceof Page) {
            $data = [
                ...$data,
                ...arborescencePageMenuData($entry),
            ];
        }

        return $data;
    }
}

Kirby::plugin(
    name: 'daandelange/arborescence',
    info: [
        'license' => 'MIT',
    ],
    version: '1.0.0',
    extends: [
        'sections' => [
            'arborescence' => [
                'props' => [
                    'label' => function ($label = null) {
                        return I18n::translate($label, $label);
                    },
                    'rootPage' => function (?string $rootPage = null) {
                        if (!$rootPage) {
                            $model = $this->model();
                            if (!$model->id()) {
                                $rootPage = 'site';
                            } else {
                                $rootPage = $model->id();
                            }
                        }

                        if ($rootPage !== 'site' && str_starts_with($rootPage, 'pages/') !== true) {
                            $rootPage = 'pages/' . $rootPage;
                        }

                        return $rootPage;
                    },
                    'showParent' => function (bool $showParent = true) {
                        return $showParent;
                    },
                    'showPaths' => function (bool $showPaths = true) {
                        return $showPaths;
                    },
                    'branchSorts' => function (array|string|null $branchSorts = null) {
                        return arborescenceNormalizeBranchSorts($branchSorts);
                    },
                ],
                'computed' => [
                    'parentIcon' => function () {
                        $model = arborescenceParentModel($this->rootPage(), $this->model());

                        if ($this->rootPage() === 'site') {
                            return $model?->panel()->image()['icon'] ?? 'home';
                        }

                        if ($model instanceof Page) {
                            return $model->panel()->image()['icon'] ?? null;
                        }

                        return 'home';
                    },
                    'parentTitle' => function () {
                        $model = arborescenceParentModel($this->rootPage(), $this->model());

                        if ($model instanceof Site) {
                            return I18n::translate('view.site');
                        }

                        if ($model instanceof Page) {
                            return arborescencePanelTitle($model);
                        }

                        return 'Invalid rootPage setting !';
                    },
                    'parentOpenTarget' => function () {
                        $model = arborescenceParentModel($this->rootPage(), $this->model());

                        if (!$model || !$model->id()) {
                            return null;
                        }

                        return 'pages/' . str_replace('/', '+', $model->id());
                    },
                    'parentOpenUrl' => function () {
                        return arborescenceParentOpenUrl($this->rootPage(), $this->model());
                    },
                    'pages' => function () {
                        return arborescenceTopLevelEntries($this->rootPage(), $this->branchSorts());
                    },
                    'searchIndexRevision' => function () {
                        return arborescenceSearchIndexRevision();
                    },
                    'searchIndexScope' => function () {
                        return arborescenceSearchIndexScope($this->rootPage(), $this->branchSorts());
                    },
                    'activePage' => function () {
                        return;
                    },
                    'isSite' => function () {
                        return $this->model() instanceof Site;
                    },
                ],
            ],
        ],
        'api' => [
            'routes' => [
                [
                    'pattern' => 'arborescence/tree',
                    'method' => 'GET',
                    'action' => function () {
                        $rootPage = trim((string)$this->requestQuery('root'));
                        $includeSearchIndex = $this->requestQuery('includeSearchIndex') === '1';
                        $showParent = $this->requestQuery('showParent') !== '0';
                        $showPaths = $this->requestQuery('showPaths') !== '0';
                        $branchSorts = arborescenceNormalizeBranchSorts(
                            $this->requestQuery('branchSorts')
                        );

                        return arborescenceTreePayload(
                            $rootPage,
                            $showParent,
                            $showPaths,
                            $branchSorts,
                            $includeSearchIndex
                        );
                    },
                ],
                [
                    'pattern' => 'arborescence/children',
                    'method' => 'GET',
                    'action' => function () {
                        $branchSorts = arborescenceNormalizeBranchSorts(
                            $this->requestQuery('branchSorts')
                        );

                        return (new ArborescencePageTree($branchSorts))->children(
                            parent: $this->requestQuery('parent'),
                            moving: $this->requestQuery('move'),
                        );
                    },
                ],
                [
                    'pattern' => 'arborescence/search-index',
                    'method' => 'GET',
                    'action' => function () {
                        $rootPage = trim((string)$this->requestQuery('root'));
                        $branchSorts = arborescenceNormalizeBranchSorts(
                            $this->requestQuery('branchSorts')
                        );

                        return [
                            'searchIndexRevision' => arborescenceSearchIndexRevision(),
                            'searchIndexScope' => arborescenceSearchIndexScope($rootPage, $branchSorts),
                            'searchIndex' => arborescenceSearchIndex($rootPage, $branchSorts),
                        ];
                    },
                ],
            ],
        ],
    ],
);
