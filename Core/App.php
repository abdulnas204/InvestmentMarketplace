<?php

namespace Core;

use Exceptions\ErrorException;
use Helpers\Errors;
use Helpers\Locale;
use Helpers\Output;
use Libraries\Telegram;
use Models\Collection\SiteLanguages;
use Models\CurrentUser;
use Services\FacebookService;
use Traits\Instance;
use Views\Errors\ErrorDefault;

class App
{
    use Instance;

    /**
     * @return $this
     * @throws \ReflectionException
     */
    public function start(): self {
        if (IS_AJAX) {
            $this->output()->disableLayout();
        }

        try {
            $this->router()->go();
            Db()->endTransaction();
        } catch (ErrorException $e) {
            if (!$this->output()->isContentLoaded() && $this->output()->isLayoutEnabled()) {
                $this->output()->addView(ErrorDefault::class, [
                    'description' => $e->getMessage(),
                    'title' => $e->getKey(),
                    'code' => $e->getCode(),
                ]);
            }
            Db()->rollBackTransaction();
        } finally {
            echo $this->output()
                ->headers()
                ->result();
        }

        return $this;
    }

    public function db(): Database {
        return Database::getInstance();
    }

    public function router(): Router {
        return Router::getInstance();
    }

    public function output(): Output {
        return Output::getInstance();
    }

    public function error(): Errors {
        return Errors::getInstance();
    }

    public function locale(): Locale {
        return Locale::getInstance();
    }

    public function currentUser(): CurrentUser {
        return CurrentUser::getInstance();
    }

    public function siteLanguages(): SiteLanguages {
        return SiteLanguages::getInstance();
    }

    public function auth(): Auth {
        return Auth::getInstance();
    }

    public function telegram(): Telegram {
        return Telegram::getInstance();
    }

    public function facebook(): FacebookService {
        return FacebookService::getInstance();
    }
}
