<?php
use Kirby\Toolkit\Query;
use Kirby\Toolkit\I18n;
use Kirby\Panel\Controller\PageTree;
use Kirby\Cms\App;
use Kirby\Cms\Find;

// Vue component docs : https://getkirby.com/docs/reference/plugins/extensions/sections#vue-component
// 

// Useful for updating related code parts : Kirby/src/Panel/Controller/PageTree.php

Kirby::plugin(
    name: 'daandelange/arborescence',
    info: [
        'license' => 'MIT'
    ],
    version: '1.0.0',
    extends: [
        'sections' => [
            'arborescence' => [
                //'extends' => 'sections/pages',
                'props' => [
                    'label' => function ($label = null) {
                        return I18n::translate($label, $label); // translates lang arrays or keys
                    },
                    'rootPage' => function (?string $rootPage = null) {
                        // Set default dynamically
                        if(!$rootPage){
                            /** @var \Kirby\Cms\Page | Site */
                            $model = $this->model();
                            //return $this->model()->id()??'site';
                            if(!$model->id()) $rootPage = 'site';
                            else $rootPage = $model->id();
                        }

                        // Sanitize
                        if($rootPage!='site'){
                            // Prepend page
                            if(!str_starts_with($rootPage, 'pages/')) $rootPage = 'pages/'.$rootPage;
                        }
                        return $rootPage; // self (page) or site
                    },
                    // Rather to show the parent entry or not 
                    'showParent' => function(bool $showParent = true){
                        return $showParent;
                    }
                ],
                'computed' => [
                    'parentIcon' => function(){
                        if ($this->rootPage() === 'site') {
                            return App::instance()->site()->homePage()?->panel()->image()['icon'] ?? 'home';
                        }

                        /** @var \Kirby\Cms\Page | Site */
                        $model = $this->model();
                        return match (true) {
                            default => $model->panel()->image()['icon'] ?? null
                        };
                    },
                    'parentTitle' => function(){
                        // No root page ? --> set to current content object
                        $root = $this->rootPage();
                        $model = null;
                        if ($root === 'site') {
                            $model = App::instance()->site()->homePage();
                        } elseif(!$root){
                            /** @var \Kirby\Cms\Page | Site */
                            $model = $this->model();
                        }
                        // Custom object
                        else {
                            $model = Find::parent($root);
                        }

                        if($model){
                            return match (true) {
                                // Site : Match Kirby behaviour.
                                // Todo: rather show site title ?
                                $model instanceof Kirby\Cms\Site => I18n::translate('view.site'),
                                // Any page: show page title
                                default                               => $model->content()->title()->value() ?? I18n::translate('page')
                            };
                            
                        }

                        // Todo: dirty = error speads in UI
                        return 'Invalid rootPage setting !';
                    },
                    'parentOpenTarget' => function () {
                        $model = match (true) {
                            $this->rootPage() === 'site' => App::instance()->site()->homePage(),
                            default                      => Find::parent($this->rootPage()),
                        };

                        if (!$model || !$model->id()) {
                            return null;
                        }

                        return 'pages/' . str_replace('/', '+', $model->id());
                    },
                    'pages' => function () {
                        // The pages object is sent with the initial request.
                        // Note: Otherwise the load triggers another load, which slows down load time and feels buggy
                        $pages = (new PageTree())->children(
                            parent: $this->rootPage(), // App::instance()->request()->get('parent'),
                            moving: null
                        );

                        if ($this->rootPage() !== 'site') {
                            return $pages;
                        }

                        $homePageId = App::instance()->site()->homePageId();

                        foreach ($pages as $index => $page) {
                            if (($page['id'] ?? null) !== $homePageId) {
                                continue;
                            }

                            unset($pages[$index]);
                            return array_values($pages);
                        }

                        return $pages;
                    },
                    'activePage' => function(){
                        return; // disabled since k5 !
                    },
                    'isSite' => function(){
                        return $this->model() instanceof Kirby\Cms\Site;
                    }
                ]
            ]
        ]

    ],
);
