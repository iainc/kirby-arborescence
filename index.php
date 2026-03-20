<?php

use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Panel\Controller\PageTree;
use Kirby\Toolkit\I18n;

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

if (!function_exists('arborescenceSearchIndexCacheKey')) {
    function arborescenceSearchIndexCacheKey(string $rootPage, array $branchSorts = []): string
    {
        $app = App::instance();
        $language = $app->language()?->code() ?? 'default';
        $user = $app->user()?->id() ?? 'guest';
        $flags = function_exists('ia_feature_flags_active_flags') === true
            ? implode(',', ia_feature_flags_active_flags())
            : '';

        return 'arborescence.search-index.' . md5(json_encode([
            'branchSorts' => $branchSorts,
            'flags' => $flags,
            'language' => $language,
            'root' => $rootPage,
            'user' => $user,
        ]));
    }
}

if (!function_exists('arborescenceSearchablePath')) {
    function arborescenceSearchablePath(string $id): string
    {
        return $id;
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

if (!function_exists('arborescenceTreePayload')) {
    function arborescenceTreePayload(
        string $rootPage,
        bool $showParent = true,
        bool $showPaths = true,
        array $branchSorts = []
    ): array
    {
        $model = arborescenceRootModel($rootPage);

        return [
            'branchSorts' => $branchSorts,
            'headline' => null,
            'isSite' => $model instanceof Site,
            'label' => null,
            'pages' => arborescenceTopLevelEntries($rootPage, $branchSorts),
            'parentIcon' => arborescenceParentIcon($rootPage, $model),
            'parentOpenTarget' => arborescenceParentOpenTarget($rootPage, $model),
            'parentTitle' => arborescenceParentTitle($rootPage, $model),
            'rootPage' => $rootPage,
            'showParent' => $showParent,
            'showPaths' => $showPaths,
        ];
    }
}

if (!function_exists('arborescenceSearchIndexRecord')) {
    function arborescenceSearchIndexRecord(Page $page, string|null $parentId): array
    {
        $uuid = $page->uuid()?->toString();

        return [
            'icon' => $page->panel()->image()['icon'] ?? null,
            'id' => $page->id(),
            'label' => arborescencePanelTitle($page),
            'parentId' => $parentId,
            'path' => arborescenceSearchablePath($page->id()),
            'slug' => $page->slug(),
            'status' => $page->status(),
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
        $cacheKey = arborescenceSearchIndexCacheKey($rootPage, $branchSorts);
        $cached = $cache->get($cacheKey);

        if (is_array($cached) === true) {
            return $cached;
        }

        $root = arborescenceRootModel($rootPage);
        $records = [];

        if ($root === null) {
            $cache->set($cacheKey, $records);
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

        $cache->set($cacheKey, $records);
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
            $data['label'] = arborescencePanelTitle($entry);
            $data['path'] = arborescenceSearchablePath($entry->id());
            $data['status'] = $entry->status();
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
                    'pages' => function () {
                        return arborescenceTopLevelEntries($this->rootPage(), $this->branchSorts());
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
                        $showParent = $this->requestQuery('showParent') !== '0';
                        $showPaths = $this->requestQuery('showPaths') !== '0';
                        $branchSorts = arborescenceNormalizeBranchSorts(
                            $this->requestQuery('branchSorts')
                        );

                        return arborescenceTreePayload($rootPage, $showParent, $showPaths, $branchSorts);
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
                            'searchIndex' => arborescenceSearchIndex($rootPage, $branchSorts),
                        ];
                    },
                ],
            ],
        ],
    ],
);
