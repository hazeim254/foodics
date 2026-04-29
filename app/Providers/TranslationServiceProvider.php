<?php

namespace App\Providers;

use Illuminate\Translation\FileLoader;
use Illuminate\Translation\TranslationServiceProvider as BaseProvider;

class TranslationServiceProvider extends BaseProvider
{
    protected function registerLoader(): void
    {
        $this->app->singleton('translation.loader', function ($app) {
            $path = $app['path.lang'];

            return new class($app['files'], $path) extends FileLoader
            {
                private string $customPath;

                private array $arTranslations = [];

                public function __construct($files, $path)
                {
                    parent::__construct($files, $path);
                    $this->customPath = $path;
                }

                public function load($locale, $group, $namespace = null): array
                {
                    if (empty($this->arTranslations)) {
                        $file = $this->customPath.'/ar/messages.php';
                        if ($this->files->exists($file)) {
                            $this->arTranslations = $this->files->getRequire($file);
                        }
                    }

                    if ($locale === 'ar' && ! empty($this->arTranslations)) {
                        return $this->arTranslations;
                    }

                    return [];
                }
            };
        });
    }
}
