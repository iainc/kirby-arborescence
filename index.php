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
    function arborescenceSearchIndexCacheKey(string $rootPage): string
    {
        $app = App::instance();
        $language = $app->language()?->code() ?? 'default';
        $user = $app->user()?->id() ?? 'guest';
        $flags = function_exists('ia_feature_flags_active_flags') === true
            ? implode(',', ia_feature_flags_active_flags())
            : '';

        return 'arborescence.search-index.' . md5(json_encode([
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

if (!function_exists('arborescenceVisibleChildren')) {
    function arborescenceVisibleChildren(Site|Page $parent, bool $skipHomePage = false): array
    {
        $children = $parent
            ->childrenAndDrafts()
            ->filterBy('isListable', true)
            ->values();

        if ($skipHomePage !== true) {
            return $children;
        }

        $homePageId = App::instance()->site()->homePageId();

        return array_values(array_filter(
            $children,
            fn ($child) => $child instanceof Page && $child->id() !== $homePageId
        ));
    }
}

if (!function_exists('arborescenceTopLevelEntries')) {
    function arborescenceTopLevelEntries(string $rootPage): array
    {
        $root = arborescenceRootModel($rootPage);
        if ($root === null) {
            return [];
        }

        $tree = new ArborescencePageTree();

        return array_map(
            fn (Page $page) => $tree->entry($page),
            arborescenceVisibleChildren($root, $rootPage === 'site')
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
    function arborescenceTreePayload(string $rootPage, bool $showParent = true): array
    {
        $model = arborescenceRootModel($rootPage);

        return [
            'headline' => null,
            'isSite' => $model instanceof Site,
            'label' => null,
            'pages' => arborescenceTopLevelEntries($rootPage),
            'parentIcon' => arborescenceParentIcon($rootPage, $model),
            'parentOpenTarget' => arborescenceParentOpenTarget($rootPage, $model),
            'parentTitle' => arborescenceParentTitle($rootPage, $model),
            'rootPage' => $rootPage,
            'showParent' => $showParent,
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
            'uuid' => $uuid,
            'value' => $uuid ?? $page->id(),
        ];
    }
}

if (!function_exists('arborescenceCollectSearchIndex')) {
    function arborescenceCollectSearchIndex(
        Page $page,
        string|null $parentId,
        array &$records
    ): void {
        $records[] = arborescenceSearchIndexRecord(
            page: $page,
            parentId: $parentId
        );

        foreach (arborescenceVisibleChildren($page) as $child) {
            arborescenceCollectSearchIndex(
                page: $child,
                parentId: $page->id(),
                records: $records
            );
        }
    }
}

if (!function_exists('arborescenceSearchIndex')) {
    function arborescenceSearchIndex(string $rootPage): array
    {
        $cache = App::instance()->cache('pages');
        $cacheKey = arborescenceSearchIndexCacheKey($rootPage);
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

        foreach (arborescenceVisibleChildren($root, $rootPage === 'site') as $child) {
            arborescenceCollectSearchIndex(
                page: $child,
                parentId: null,
                records: $records
            );
        }

        $cache->set($cacheKey, $records);
        return $records;
    }
}

class ArborescencePageTree extends PageTree
{
    public function entry(Site|Page $entry, Page|null $moving = null): array
    {
        $data = parent::entry($entry, $moving);

        if ($entry instanceof Page) {
            $data['label'] = arborescencePanelTitle($entry);
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
                        return arborescenceTopLevelEntries($this->rootPage());
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

                        return arborescenceTreePayload($rootPage, $showParent);
                    },
                ],
                [
                    'pattern' => 'arborescence/search-index',
                    'method' => 'GET',
                    'action' => function () {
                        $rootPage = trim((string)$this->requestQuery('root'));

                        return [
                            'searchIndex' => arborescenceSearchIndex($rootPage),
                        ];
                    },
                ],
            ],
        ],
    ],
);
