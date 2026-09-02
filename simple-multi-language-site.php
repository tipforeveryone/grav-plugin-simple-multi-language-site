<?php

namespace Grav\Plugin;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Plugin\SimpleMultiLanguageSite\CountryFlags;
use Grav\Plugin\SimpleMultiLanguageSite\LanguageManager;
use Grav\Plugin\SimpleMultiLanguageSite\SimpleMultiLanguageSiteApiController;
use Grav\Plugin\SimpleMultiLanguageSite\TranslationLinker;
use RocketTheme\Toolbox\Event\Event;

/**
 * Simple Multi Language Site (SMLS)
 *
 * Đa ngôn ngữ dựa trên field frontmatter (header.smls_language + header.smls_translations
 * map code=>route), không dùng cơ chế multi-language native của Grav. Site vẫn
 * giữ nguyên cấu trúc thư mục theo ngôn ngữ (vd user/pages/vi, user/pages/en);
 * mỗi ngôn ngữ + root_path tương ứng được khai báo tự do trong config plugin
 * (không hardcode vi/en/fr). Không có khái niệm "Neutral" — mọi trang phải
 * gán ngôn ngữ, trang cũ thiếu field coi như thuộc default_language.
 *
 * Field cố tình có tiền tố "smls_" — KHÔNG đặt tên trơn "language"/"translations",
 * xem ghi chú trong onBlueprintCreated() bên dưới.
 */
class SimpleMultiLanguageSitePlugin extends Plugin
{
    private ?LanguageManager $languageManager = null;

    private ?TranslationLinker $translationLinker = null;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiRegisterRoutes'  => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'    => ['onApiSidebarItems', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        self::registerAutoload();

        if (!$this->config->get('plugins.simple-multi-language-site.enabled', true)) {
            return;
        }

        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
            // These three also fire for Admin2/API requests — the api
            // plugin's PagesController fires the same onAdmin* events for
            // backward compat (see CLAUDE.md), and the api plugin's own
            // blueprint resolution fires onBlueprintCreated too — so they
            // must NOT be gated behind isAdmin() below, or the "Ngôn ngữ /
            // Bản dịch" fields and the after-save sync silently stop
            // working the moment a page is edited through Admin2 instead
            // of admin-classic.
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
            'onAdminCreatePageFrontmatter' => ['onAdminCreatePageFrontmatter', 0],
        ]);

        if (!$this->isAdmin()) {
            return;
        }

        $this->enable([
            'onAdminMenu' => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /** Admin2 counterpart to onAdminMenu() below. */
    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->config->get('plugins.simple-multi-language-site.enabled', true)) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'simple-multi-language-site',
            'plugin'    => 'simple-multi-language-site',
            'label'     => 'Multi Language',
            'icon'      => 'fa-language',
            'route'     => '/plugin/simple-multi-language-site',
            'priority'  => 80,
            'authorize' => ['api.super'],
        ];
        $event['items'] = $items;
    }

    /** Admin2 counterpart to the smlsassignlanguages onAdminTaskExecute handler below. */
    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->config->get('plugins.simple-multi-language-site.enabled', true)) {
            return;
        }

        $routes = $event['routes'];
        $controller = SimpleMultiLanguageSiteApiController::class;
        $routes->post('/simple-multi-language-site/assign-languages', [$controller, 'assignLanguages']);
        $routes->get('/simple-multi-language-site/missing-translations', [$controller, 'missingTranslations']);
    }

    /**
     * Nút "Add Translation" submit task=continue với data[header][smls_language]
     * + data[header][smls_translations][...] (xem field
     * simple-multi-language-site-translate.html.twig) — nhưng
     * Admin::getPage() (admin/classes/plugin/Admin.php, method dựng trang mới
     * từ dữ liệu session của taskContinue) CHỈ tự lấy đúng key "title" từ data
     * submit vào header trang mới, mọi key khác trong data[header][...] bị bỏ
     * qua hoàn toàn — nên trang B tạo ra vẫn rơi về ngôn ngữ mặc định thay vì
     * ngôn ngữ bản dịch đã chọn. Core CHỪA SẴN event này đúng cho việc này
     * (bắn kèm 'header' => &$header theo tham chiếu) — gộp thêm data[header]
     * vào đây để hoàn tất phần core bỏ sót.
     */
    public function onAdminCreatePageFrontmatter(Event $event): void
    {
        $data = (array) ($event['data'] ?? []);
        $extraHeader = (array) ($data['header'] ?? []);
        if (empty($extraHeader)) {
            return;
        }

        $header = (array) ($event['header'] ?? []);
        $event['header'] = $header + $extraHeader;
    }

    /**
     * Field "multilang_templates" (type: checkboxes, từ plugin "form" vendored)
     * render mỗi option là 1 cặp <input>+<label style="display:inline"> rời rạc
     * trong <div class="checkboxes">, xuống dòng theo flow tự nhiên của trình
     * duyệt (lộn xộn khi có nhiều option). Không sửa trực tiếp template của
     * plugin "form" (vendored) — chỉ inject 1 đoạn CSS nhỏ, scope theo class
     * "smls-multilang-grid" (khai báo ở wrapper_classes của field trong
     * blueprints.yaml), xếp lại thành lưới đều. Input checkbox vốn đã bị ẩn
     * (display:none, css lõi của Admin) nên chỉ các <label> mới thực sự tham
     * gia layout — làm grid item luôn được, không cần bọc thêm.
     */
    public function onOutputGenerated(): void
    {
        $output = $this->grav->output;
        if (strpos($output, '</head>') === false) {
            return;
        }

        $style = '<style>'
            . '.checkboxes.smls-multilang-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.5rem 1rem;}'
            . '.checkboxes.smls-multilang-grid label{margin:0;}'
            . '</style>';

        $this->grav->output = str_replace('</head>', $style . "\n</head>", $output);
    }

    private static bool $autoloadRegistered = false;

    /**
     * Đăng ký autoload cho namespace của plugin. Gọi tĩnh ngay khi file này
     * được require (xem cuối file) — KHÔNG chỉ gọi trong onPluginsInitialized(),
     * vì các ngữ cảnh CLI như `bin/gpm index`/`bin/gpm install` load blueprint
     * của mọi plugin (kể cả field data-options@ gọi thẳng getFlagOptions())
     * mà không khởi động vòng đời event của từng plugin trước — lúc đó
     * CountryFlags chưa có cách nào được autoload nếu chỉ đăng ký trong
     * onPluginsInitialized().
     */
    public static function registerAutoload(): void
    {
        if (self::$autoloadRegistered) {
            return;
        }
        self::$autoloadRegistered = true;

        spl_autoload_register(function (string $class): void {
            $prefix = 'Grav\\Plugin\\SimpleMultiLanguageSite\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $file = __DIR__ . '/classes/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    private function languages(): LanguageManager
    {
        return $this->languageManager ??= new LanguageManager($this->config);
    }

    private function linker(): TranslationLinker
    {
        return $this->translationLinker ??= new TranslationLinker($this->languages(), $this->grav['pages']);
    }

    /** Callback tĩnh dùng bởi field select (data-options@) để nạp options = danh sách ngôn ngữ đã cấu hình. */
    public static function getLanguageOptions(): array
    {
        return LanguageManager::getLanguageOptions();
    }

    /** Callback tĩnh dùng bởi field select "Cờ" (data-options@) — danh sách cờ quốc gia để tìm theo tên. */
    public static function getFlagOptions(): array
    {
        return CountryFlags::getOptions();
    }

    /** Callback tĩnh dùng bởi field checkboxes "Template áp dụng Multi-Language" (data-options@). */
    public static function getTemplateOptions(): array
    {
        // Pages::types() đã trả về đúng dạng slug => Nhãn hiển thị (VD
        // 'article' => 'Article') — DÙNG TRỰC TIẾP, không array_combine lại
        // theo nhãn (từng làm value/key của checkboxes lệch case so với
        // $event['type'] thực tế ở onBlueprintCreated(), khiến field
        // Ngôn ngữ/Bản dịch không bao giờ được thêm dù đã tick đúng ô).
        return Pages::types();
    }

    // ---------------------------------------------------------------------
    // Twig: functions dùng cả ở frontend (theme) lẫn admin (field template)
    // ---------------------------------------------------------------------

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();
        $twig->addFunction(new \Twig\TwigFunction('smls_languages', [$this, 'twigLanguages']));
        $twig->addFunction(new \Twig\TwigFunction('smls_default_language', [$this, 'twigDefaultLanguage']));
        $twig->addFunction(new \Twig\TwigFunction('smls_current_language', [$this, 'twigCurrentLanguage']));
        $twig->addFunction(new \Twig\TwigFunction('smls_switch_route', [$this, 'twigSwitchRoute']));
        $twig->addFunction(new \Twig\TwigFunction('smls_root_path', [$this, 'twigRootPath']));
        $twig->addFunction(new \Twig\TwigFunction('smls_translate_target_parent', [$this, 'twigTranslateTargetParent']));
        $twig->addFunction(new \Twig\TwigFunction('smls_missing_translations', [$this, 'twigMissingTranslations']));
        $twig->addFunction(new \Twig\TwigFunction('smls_pages_by_template', [$this, 'twigPagesByTemplate']));
        $twig->addFunction(new \Twig\TwigFunction('smls_switcher_display', [$this, 'twigSwitcherDisplay']));
    }

    /** Kiểu hiển thị khối chuyển ngôn ngữ ở theme: 'text' | 'flag' | 'flag_text'. */
    public function twigSwitcherDisplay(): string
    {
        $value = (string) $this->config->get('plugins.simple-multi-language-site.switcher_display', 'text');

        return in_array($value, ['text', 'flag', 'flag_text'], true) ? $value : 'text';
    }

    /**
     * Danh sách trang cùng template với $page (loại trừ chính nó) — dùng để
     * giới hạn picker "Bản dịch" chỉ trong các trang cùng loại (vd trang
     * "article" chỉ chọn được trang "article" khác làm bản dịch, không lẫn
     * nav/footer/member...).
     *
     * @return array<int, array{route: string, title: string}>
     */
    public function twigPagesByTemplate(?PageInterface $page): array
    {
        if (!$page) {
            return [];
        }

        $template = $page->template();
        $selfRoute = '/' . ltrim((string) $page->route(), '/');

        $results = [];
        foreach ($this->grav['pages']->all() as $candidate) {
            if (!$candidate || $candidate->template() !== $template) {
                continue;
            }

            $route = '/' . ltrim((string) $candidate->route(), '/');
            if ($route === $selfRoute) {
                continue;
            }

            $results[] = [
                'route' => $route,
                'title' => $candidate->title(),
            ];
        }

        usort($results, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $results;
    }

    /** @return array<int, array{code: string, label: string, root_path: string, flag: string}> */
    public function twigLanguages(): array
    {
        return $this->languages()->getLanguages();
    }

    public function twigDefaultLanguage(): string
    {
        return $this->languages()->getDefaultLanguage();
    }

    public function twigCurrentLanguage(?PageInterface $page): string
    {
        if (!$page) {
            return $this->languages()->getDefaultLanguage();
        }

        return $this->linker()->getPageLanguage($page);
    }

    public function twigSwitchRoute(?PageInterface $page, string $targetCode): ?string
    {
        if (!$page) {
            return null;
        }

        return $this->linker()->getSwitchRoute($page, $targetCode);
    }

    public function twigRootPath(string $code): ?string
    {
        $language = $this->languages()->findByCode($code);

        return $language['root_path'] ?? null;
    }

    /**
     * Đường dẫn thư mục cha đề xuất để tạo trang dịch mới ở ngôn ngữ đích:
     * giữ nguyên phần path tương đối (trừ slug của chính trang hiện tại),
     * chỉ đổi root_path nguồn sang root_path đích.
     */
    public function twigTranslateTargetParent(?PageInterface $page, string $targetCode): ?string
    {
        if (!$page) {
            return null;
        }

        $sourceCode = $this->linker()->getPageLanguage($page);
        $sourceLang = $this->languages()->findByCode($sourceCode);
        $targetLang = $this->languages()->findByCode($targetCode);
        if (!$sourceLang || !$targetLang) {
            return null;
        }

        $route = '/' . ltrim((string) $page->route(), '/');
        $root = $sourceLang['root_path'];

        if ($route === $root) {
            $relative = '';
        } elseif (strpos($route, $root . '/') === 0) {
            $relative = substr($route, strlen($root));
        } else {
            $relative = $route;
        }

        $segments = trim($relative, '/') === '' ? [] : explode('/', trim($relative, '/'));
        array_pop($segments);

        $parent = $targetLang['root_path'];
        if ($segments) {
            $parent .= '/' . implode('/', $segments);
        }

        return $parent;
    }

    /**
     * Danh sách trang đã gán ngôn ngữ nhưng còn thiếu counterpart cho ít
     * nhất 1 ngôn ngữ khác đã cấu hình — dùng cho báo cáo Language Converter.
     */
    public function twigMissingTranslations(): array
    {
        $languages = $this->languages()->getLanguages();
        if (count($languages) < 2) {
            return [];
        }

        $rows = [];
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page) {
                continue;
            }

            $code = trim((string) ($page->header()->smls_language ?? ''));
            if ($code === '') {
                continue;
            }

            $translations = $this->linker()->getTranslations($page);
            foreach ($languages as $language) {
                if ($language['code'] === $code || isset($translations[$language['code']])) {
                    continue;
                }

                $rows[] = [
                    'title' => $page->title(),
                    'route' => '/' . ltrim((string) $page->route(), '/'),
                    'language' => $code,
                    'missing_code' => $language['code'],
                    'missing_label' => $language['label'],
                ];
            }
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Admin: tab "Multi Language" trên mọi loại trang
    // ---------------------------------------------------------------------

    public function onBlueprintCreated(Event $event): void
    {
        $languages = $this->languages()->getLanguages();
        if (count($languages) < 2) {
            return;
        }

        // "use: keys" (blueprints.yaml) lưu field này dạng map {template => true/false},
        // giống hệt cách core lưu header.process ({markdown: true, twig: false}) —
        // KHÔNG phải mảng phẳng các template được chọn.
        $allowedTemplates = (array) $this->config->get('plugins.simple-multi-language-site.multilang_templates', []);
        $templateName = (string) ($event['type'] ?? '');
        if ($templateName === '' || empty($allowedTemplates[$templateName])) {
            return;
        }

        $options = [];
        foreach ($languages as $language) {
            $options[$language['code']] = $language['label'];
        }

        // Lưu ý: field KHÔNG được đặt tên "language" — Grav core (Page::init(),
        // system/src/Grav/Common/Page/Page.php) tự đọc header.language vào
        // $page->language() bất kể có bật multi-language native hay không, và
        // Admin dùng giá trị đó để build link edit trong page-list
        // (AdminTwigExtension::getPageUrl() -> Admin::getAdminRoute()), luôn
        // chèn "/<language>" NGAY TRƯỚC route admin — vd "/vi/admin/pages/..."
        // — dù system.languages.supported đang tắt. Namespace field để tránh
        // đụng độ với key lõi này (xem README).
        $fields = [];
        $fields['header.smls_language'] = [
            'type' => 'select',
            'label' => 'Ngôn ngữ',
            'default' => $this->languages()->getDefaultLanguage(),
            'options' => $options,
            'attributes' => ['data-smls-language-select' => 'true'],
            'validate' => ['required' => true],
        ];

        foreach ($languages as $language) {
            $code = $language['code'];
            // Field tự viết (không dùng type: pages gốc) — picker chỉ liệt kê
            // trang CÙNG TEMPLATE với trang đang edit (xem twigPagesByTemplate),
            // và gộp luôn nút Add Translation vào chung 1 hàng label/textbox.
            // outerclasses cho phép JS ẩn/hiện theo ngôn ngữ đang chọn ở
            // header.smls_language — chỉ hiện các ngôn ngữ KHÁC ngôn ngữ hiện tại.
            $fields['header.smls_translations.' . $code] = [
                'type' => 'simple-multi-language-site-translate',
                'label' => 'Bản dịch: ' . $language['label'],
                'target_code' => $code,
                'target_label' => $language['label'],
                'outerclasses' => 'smls-lang-row smls-lang-row-' . $code,
            ];
        }

        /** @var \Grav\Common\Data\Blueprint $blueprint */
        $blueprint = $event['blueprint'];

        // Chèn ngay dưới field "content" (khung edit chính) của tab Content có
        // sẵn, thay vì tạo tab riêng — nếu template không có field "content"
        // (vd trang không dùng markdown editor), rơi về thêm cuối danh sách.
        $existing = (array) $blueprint->get('form/fields/tabs/fields/content/fields', [], '/');
        $merged = [];
        $inserted = false;
        foreach ($existing as $key => $value) {
            $merged[$key] = $value;
            if ($key === 'content') {
                $merged += $fields;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $merged += $fields;
        }

        $blueprint->set('form/fields/tabs/fields/content/fields', $merged, '/');

        if ($blueprint->get('form/fields/tabs/fields/content/type', null, '/') === null) {
            $blueprint->set('form/fields/tabs/fields/content/type', 'tab', '/');
            $blueprint->set('form/fields/tabs/fields/content/title', 'Content', '/');
        }
    }

    // ---------------------------------------------------------------------
    // Admin: đồng bộ 2 chiều khi lưu trang
    // ---------------------------------------------------------------------

    public function onAdminAfterSave(Event $event): void
    {
        $page = $event['page'] ?? $event['object'] ?? null;
        if (!$page instanceof PageInterface) {
            return;
        }

        $this->linker()->syncBack($page);
    }

    // ---------------------------------------------------------------------
    // Admin: trang "Language Converter"
    // ---------------------------------------------------------------------

    public function onAdminMenu(): void
    {
        if (!$this->canManage()) {
            return;
        }

        $this->grav['twig']->plugins_hooked_nav['Multi Language'] = [
            'route' => 'simple-multi-language-site',
            'icon' => 'fa-language',
            'authorize' => ['admin.super'],
            'priority' => 80,
        ];
    }

    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    public function onAdminTaskExecute(Event $event): void
    {
        $controller = $event['controller'];
        $task = $controller->task ?? '';

        if ($task === 'smlsassignlanguages') {
            $event->stopPropagation();
            $this->handleAssignLanguages();
        }
    }

    /**
     * Language Converter — Phần 1: bulk gán header.smls_language cho mọi trang
     * chưa có field này, dựa trên root_path đã cấu hình cho từng ngôn ngữ.
     * Xác định 100% bằng path prefix, không đoán mò nên chạy tự động toàn bộ.
     */
    private function handleAssignLanguages(): void
    {
        if (!$this->canManage()) {
            $this->grav['admin']->setMessage('Not authorized.', 'error');

            return;
        }

        $count = 0;
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page) {
                continue;
            }

            $header = $page->header();
            $current = trim((string) ($header->smls_language ?? ''));
            if ($current !== '') {
                continue;
            }

            $language = $this->languages()->findByRoute('/' . ltrim((string) $page->route(), '/'));
            if (!$language) {
                continue;
            }

            $header->smls_language = $language['code'];
            $page->header($header);
            $page->save(false);
            $count++;
        }

        $this->grav['admin']->setMessage("Đã gán ngôn ngữ cho {$count} trang.", 'info');
    }

    private function canManage(): bool
    {
        $user = $this->grav['user'] ?? null;
        if (!$user || !$user->authenticated) {
            return false;
        }

        return $user->authorize('admin.super') === true;
    }
}

// Đăng ký autoload ngay khi file này được require (không đợi onPluginsInitialized) —
// xem docblock của registerAutoload() ở trên để biết lý do.
SimpleMultiLanguageSitePlugin::registerAutoload();
