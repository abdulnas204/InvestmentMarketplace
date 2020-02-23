<?php

namespace Controllers;

use Core\{Controller, Database, View};
use Dto\ErrorRoute;
use Helpers\{
    Output,
    Data\Currency,
};
use Libraries\{
    Screens,
};
use Models\Collection\{
    Languages,
    ProjectChatMessages,
    MVProjectLangs,
    Payments,
    Projects,
    MVProjectFilterAvailableLangs,
    MVProjectSearchs,
    Users,
};
use Models\Table\{
    Language,
    Project,
    ProjectChatMessage,
    ProjectLang,
    Redirect,
};
use Models\MView\MVProjectLang;
use Models\Constant\{
    ProjectStatus,
    Views,
};
use Requests\Investment\{AddRequest,
    ChangeStatusRequest,
    ChatMessagesRequest,
    CheckSiteRequest,
    DetailsRequest,
    RedirectRequest,
    SetChatMessageRequest,
    ShowRequest};
use Requests\Telegram\SendPhotoRequest;
use Traits\AuthTrait;
use Views\Investment\{Added, Details, DetailsMeta, ProjectFilter, Registration, Show, NoShow};

class Investment extends Controller {
    use AuthTrait;
    private CONST
        LIMIT = 20,
        GUEST_USER_ID = 2;

    public function registration(): Output {
        $params = [
            'payments'                  => new Payments(),
            'mainProjectLanguages'      => new Languages('pos is not null', 'pos asc'),
            'secondaryProjectLanguages' => new Languages('pos is null'),
            'currency'                  => Currency::getCurrency(),
            'authModel'                 => CurrentUser(),
        ];

        Output()->addView(Registration::class, $params);
        return Output()->addFunction('ProjectRegistration');
    }

    public function show(ShowRequest $request): Output {
        $MVProjectFilterAvailableLangs = new MVProjectFilterAvailableLangs(['status_id' => $request->status]);

        if (!$MVProjectFilterAvailableLangs->count()) {
            // без фильтра
            return $this->noShow([Views::PROJECT_FILTER => '']);
        }
        $languages = new Languages(['id' => $MVProjectFilterAvailableLangs->getValuesByKey()]);
        /** @var Language $pageLanguage текущий язык*/
        $pageLanguage = $languages->getByKeyAndValue('shortname', $request->lang);

        $projectFilter = (new View(ProjectFilter::class, [
            'request'                       => $request,
            'url'                           => Router()->getRoute()->generateUrl(),
            'languages'                     => $languages,
            'MVProjectFilterAvailableLangs' => $MVProjectFilterAvailableLangs,
            'pageLanguage'                  => $pageLanguage ?? new Language(['flag' => 'xx']), // фэйк
        ]));

        if (!$pageLanguage) {
            return $this->noShow([Views::PROJECT_FILTER => $projectFilter]);
        }

        // ID найденных проектов
        $projectSearchs = new MVProjectSearchs([
            'lang_id' => $pageLanguage->id,
            'status_id' => $request->status,
        ], min(self::LIMIT, $MVProjectFilterAvailableLangs->{$pageLanguage->id}->cnt));

        if (!$projectSearchs->count()) {
            return $this->noShow([Views::PROJECT_FILTER => $projectFilter]);
        }

        $projectIds     = $projectSearchs->getValuesByKey();
        $projects       = new Projects(['id' => $projectIds]);
        if (!$projects->count()) {
            return $this->noShow([Views::PROJECT_FILTER => $projectFilter]);
        }
        $MVProjectLangs = new MVProjectLangs(['id' => $projectIds]);
        $payments       = new Payments(['id' => $projects->getUniqueValuesByKey('id_payments')]);

        $pageParams = [
            'projects'            => $projects,
            'MVProjectLangs'      => $MVProjectLangs,
            'pageLanguage'        => $pageLanguage,
            'payments'            => $payments,
            'languages'           => $languages,
            'isAdmin'             => CurrentUser()->isAdmin(),
            Views::PROJECT_FILTER => $projectFilter,
        ];

        Output()->addFunctions([
            'setStorage' => ['lang' => $pageLanguage->id, 'chat' => []],
            'initChat',
            'panelScrollerInit',
            'imgClickInit',
            'loadRealThumbs',
            'checkChats',
        ], Output::DOCUMENT);

        return Output()->addView(Show::class, $pageParams);
    }

    public function details(DetailsRequest $request): Output {
        $project = (new Project())->getRowFromDbAndFill(['url' => $request->site]);
        if (!$project->id) {
            Output()->addHeader(Output::E404);
            return Router()->route(new ErrorRoute(Translate()->error, Translate()->noProject));
        }

        $language = (new Language())->getRowFromDbAndFill(['shortname' => $request->lang]);
        if (!$language->id) {
            Error()->add('lang', Translate()->noLanguage, true);
        }

        $projectLang = (new ProjectLang())->getRowFromDbAndFill([
            'project_id' => $project->id,
            'lang_id' => $language->id,
        ]);
        if (!$projectLang->id) {
            Error()->add('lang', Translate()->noLanguage, true);
        }
        $MVProjectLang  = (new MVProjectLang())->getById($project->id);
        $payments       = new Payments(['id' => $project->id_payments]);
        $languages      = new Languages(['id' => $MVProjectLang->lang_id]);

        $pageParams = [
            'project'     => $project,
            'projectLang' => $projectLang,
            'payments'    => $payments,
            'languages'   => $languages,
            'language'    => $language,
        ];

        if (Output()->isLayoutEnabled()) {
            Output()->addAdditionalLayoutView(Views::META, DetailsMeta::class, $pageParams);
        }

        return Output()
            ->addFunctions([
                'setStorage' => ['lang' => $language->id, 'chat' => []],
                'initChat',
                'panelScrollerInit',
                'imgClickInit',
                'checkChats',
            ], Output::DOCUMENT)
            ->addView(Details::class, $pageParams);
    }

    private function noShow(array $pageParams): Output {
        return Output()->addView(NoShow::class, $pageParams);
    }

    public function add(AddRequest $request, CheckSiteRequest $checkSiteRequest): Output {
        Db()->startTransaction();
        Error()->exitIfExists();

        $url = $this->getWebsiteUrl($checkSiteRequest);

        if (count(array_unique([
            count($request->plan_percents),
            count($request->plan_period),
            count($request->plan_period_type),
            count($request->plan_start_deposit),
            count($request->plan_currency_type)
            ])) !== 1)
        {
            // Кол-во элементов отличается
            Error()->add('plans', Translate()->error . ': ' . Translate()->plans, true);
        }

        // Сохраняем проект
        $project            = new Project($request->toArray());
        $project->admin     = CurrentUser()->getId() ?? self::GUEST_USER_ID;
        $project->url       = $url;
        $project->ref_url   = $checkSiteRequest->website;
        $project->status_id = ProjectStatus::NOT_PUBLISHED;
        $project->save();

        Screens::saveScreenShot($url, $project->id);

        // Сохраняем описания
        foreach ($request->description as $langId => $description) {
            $projectLang              = new ProjectLang();
            $projectLang->project_id  = $project->id;
            $projectLang->lang_id     = $langId;
            $projectLang->description = str_replace("\n", '</br>', $description);
            $projectLang->save();
            unset($projectLang);
        }

        self::refreshMViews();

        $message = new SendPhotoRequest([
            'chat_id' => \Config::TELEGRAM_MY_ID,
            'caption' => sprintf('New project is added *%s* (%s)', $project->name, $project->url),
            'photo'   => Screens::getOriginalJpgScreen($project->id),
        ]);
        App()->telegram()->sendPhoto($message);

        return Output()
            ->addView(Added::class)
            ->addAlertSuccess(Translate()->success, Translate()->projectIsAdded);
    }

    public function changeStatus(ChangeStatusRequest $request): Output {
        static::adminAccess();

        $project = (new Project())->getById($request->project);
        $project->status_id = $request->status;
        $project->save();

        self::refreshMViews();
        Database::getInstance()->setTable('mv_sitemapxml')->refresh();
        return \Controllers\Users::reloadPage();
    }

    private static function refreshMViews(): void {
        // Обновляем вьюшки @TODO перенести в rabbit
        MVProjectFilterAvailableLangs::refresh();
        MVProjectLangs::refresh();
        MVProjectSearchs::refresh();
    }

    private function getWebsiteUrl(CheckSiteRequest $request): string {
        $url = self::getParsedUrl(str_replace('www.', '', strtolower($request->website)));

        if ((Project::getDb()->selectRow(['url' => $url]))) {
            Error()->add('website', Translate()->siteExists, true);
        }

        return $url;
    }

    public function checkWebsite(CheckSiteRequest $request, bool $getUrl = false): Output {
        $this->getWebsiteUrl($request);
        return Output()->addFieldSuccess('website', Translate()->siteIsFree);
    }

    private static function getParsedUrl(string $url): string {
        $urlParsed = parse_url($url);

        if (isset($urlParsed['scheme'], $urlParsed['host'])) {
            if (count(explode('.', $url)) < 2) {
                Error()->add('website', Translate()->wrongUrl, true);
            }
            return $urlParsed['host'];
        }
        elseif (isset($urlParsed['host'])) {
            return $urlParsed['host'];
        }
        elseif (isset($urlParsed['path'])) {
            return self::getParsedUrl('https://' . $urlParsed['path']);
        }

        return $url;
    }

    public function sendMessage(SetChatMessageRequest $request): Output {
        (new ProjectChatMessage([
            'user_id'       => CurrentUser()->getId(),
            'project_id'    => $request->project,
            'lang_id'       => $request->lang,
            'message'       => $request->message,
            'session_id'    => CurrentUser()->session_id,
        ]))->save();

        return Output()->addFunction('checkChats');
    }

    public function getChatMessages(ChatMessagesRequest $request): Output {
        if ($request->messages) {
            $messages = new ProjectChatMessages($request);

            if ($messages->count()) {
                $userIds = $messages->getUniqueValuesByKey('user_id');
                if (!empty($userIds)) {
                    $users = new Users(['id' => $messages->getUniqueValuesByKey('user_id')], ['id', 'login', 'name', 'status_id']);
                    Output()->addFunction('setNewChatMessages', ['users' => $users->toArray()]);
                }
                Output()->addFunction('setNewChatMessages', ['messages' => $messages->toArray()]);
            }
        }

        return Output()->addFunction('sleepAndCheckChats');
    }

    public function redirect(RedirectRequest $request): Output {
        Output()->addHeader(Output::E404);
        Error()->exitIfExists();

        (new Redirect([
            'user_id' => CurrentUser()->getId(),
            'project_id' => $request->project,
            'session_id' => CurrentUser()->session_id,
        ]))->save();

        $project = (new Project())->getById($request->project);

        return Output()->addRedirectHeader($project->ref_url);
    }
}
