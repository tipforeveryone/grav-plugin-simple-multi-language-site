<?php

namespace Grav\Plugin\SimpleMultiLanguageSite;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;

/**
 * Đọc/ghi field header.translations (map code => route) và đồng bộ 2 chiều
 * khi 1 trang được lưu. Không có khái niệm "Neutral" — mọi trang phải có
 * header.language, trang cũ chưa gán coi như thuộc default_language.
 */
class TranslationLinker
{
    /** Chặn đệ quy: route đang trong quá trình tự-sync ngược, bỏ qua nếu bị gọi lại. */
    private static array $syncing = [];

    private LanguageManager $languages;

    private Pages $pages;

    public function __construct(LanguageManager $languages, Pages $pages)
    {
        $this->languages = $languages;
        $this->pages = $pages;
    }

    /**
     * Ưu tiên field header.language; nếu trang cũ chưa gán, suy luận theo
     * root_path (path prefix) trước khi rơi về default_language — cùng logic
     * xác định 100% dùng ở Language Converter, để trang cũ vẫn hiển thị đúng
     * ngôn ngữ ngay cả khi chưa ai bấm "Chạy gán ngôn ngữ hàng loạt".
     */
    public function getPageLanguage(PageInterface $page): string
    {
        $code = trim((string) ($page->header()->language ?? ''));
        if ($code !== '') {
            return $code;
        }

        $byRoute = $this->languages->findByRoute('/' . ltrim((string) $page->route(), '/'));
        if ($byRoute) {
            return $byRoute['code'];
        }

        return $this->languages->getDefaultLanguage();
    }

    /** @return array<string, string> map code => route */
    public function getTranslations(PageInterface $page): array
    {
        $header = $page->header();
        $translations = (array) ($header->translations ?? []);

        $map = [];
        foreach ($translations as $code => $route) {
            $route = trim((string) $route);
            if ($route !== '') {
                $map[(string) $code] = $route;
            }
        }

        // Tương thích ngược: trước khi có plugin này, site tự chế dùng field
        // "translation" (số ít, 1 route duy nhất — xem header.html.twig cũ).
        // Nhiều trang đã có sẵn field này; đọc thêm cho tới khi được migrate
        // sang "translations" map qua lần lưu tiếp theo trong Admin.
        $legacyRoute = trim((string) ($header->translation ?? ''));
        if ($legacyRoute !== '') {
            $legacyLanguage = $this->languages->findByRoute($legacyRoute);
            if ($legacyLanguage && !isset($map[$legacyLanguage['code']])) {
                $map[$legacyLanguage['code']] = $legacyRoute;
            }
        }

        return $map;
    }

    public function getSwitchRoute(PageInterface $page, string $targetCode): ?string
    {
        return $this->getTranslations($page)[$targetCode] ?? null;
    }

    /**
     * Sau khi 1 trang được lưu trong Admin: với mỗi counterpart khai báo trong
     * header.translations, mở trang đích và đảm bảo nó cũng trỏ ngược lại
     * route của trang vừa lưu. Bỏ qua nếu route đích không tồn tại hoặc đã
     * đúng sẵn (tránh ghi/save thừa).
     */
    public function syncBack(PageInterface $page): void
    {
        $sourceRoute = '/' . ltrim((string) $page->route(), '/');

        if (isset(self::$syncing[$sourceRoute])) {
            return;
        }

        $sourceCode = $this->getPageLanguage($page);
        $translations = $this->getTranslations($page);
        if ($sourceCode === '' || !$translations) {
            return;
        }

        self::$syncing[$sourceRoute] = true;

        try {
            foreach ($translations as $targetCode => $targetRoute) {
                $this->linkBack($sourceRoute, $sourceCode, $targetRoute);
            }
        } finally {
            unset(self::$syncing[$sourceRoute]);
        }
    }

    private function linkBack(string $sourceRoute, string $sourceCode, string $targetRoute): void
    {
        $targetRoute = '/' . ltrim($targetRoute, '/');
        if (isset(self::$syncing[$targetRoute])) {
            return;
        }

        $targetPage = $this->pages->find($targetRoute);
        if (!$targetPage) {
            return;
        }

        $existing = $this->getTranslations($targetPage);
        if (($existing[$sourceCode] ?? null) === $sourceRoute) {
            return;
        }

        $header = $targetPage->header();
        $translations = (array) ($header->translations ?? []);
        $translations[$sourceCode] = $sourceRoute;
        $header->translations = $translations;
        $targetPage->header($header);

        self::$syncing[$targetRoute] = true;
        try {
            $targetPage->save(false);
        } finally {
            unset(self::$syncing[$targetRoute]);
        }
    }
}
