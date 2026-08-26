<?php

namespace Grav\Plugin\SimpleMultiLanguageSite;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;

/**
 * Đọc/ghi field header.smls_translations (map code => route) và đồng bộ 2
 * chiều khi 1 trang được lưu. Không có khái niệm "Neutral" — mọi trang phải
 * có header.smls_language, trang cũ chưa gán coi như thuộc default_language.
 *
 * Field KHÔNG đặt tên "language"/"translations" trơn — xem ghi chú trong
 * simple-multi-language-site.php::onBlueprintCreated() về việc Grav core
 * tự đọc header.language vào $page->language() và dùng nó để build URL
 * Admin, gây link sai dù không bật multi-language native.
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
     * Ưu tiên field header.smls_language; nếu trang cũ chưa gán, suy luận theo
     * root_path (path prefix) trước khi rơi về default_language — cùng logic
     * xác định 100% dùng ở Language Converter, để trang cũ vẫn hiển thị đúng
     * ngôn ngữ ngay cả khi chưa ai bấm "Chạy gán ngôn ngữ hàng loạt".
     */
    public function getPageLanguage(PageInterface $page): string
    {
        $code = trim((string) ($page->header()->smls_language ?? ''));
        if ($code !== '') {
            return $code;
        }

        $byRoute = $this->languages->findByRoute('/' . ltrim((string) $page->route(), '/'));
        if ($byRoute) {
            return $byRoute['code'];
        }

        return $this->languages->getDefaultLanguage();
    }

    /**
     * @return array<string, string> map code => route
     *
     * Chỉ trả về entry mà trang đích THẬT SỰ còn tồn tại — route khai báo
     * có thể là 1 link mồ côi (trang đích đã bị xoá/đổi tên mà không dọn lại
     * field này), nếu không lọc thì "Add Translation" bị ẩn sai, bộ chuyển
     * ngôn ngữ ở frontend trỏ tới trang 404, và báo cáo Language Converter
     * coi nhầm là đã đủ bản dịch.
     */
    public function getTranslations(PageInterface $page): array
    {
        $header = $page->header();
        $translations = (array) ($header->smls_translations ?? []);

        $map = [];
        foreach ($translations as $code => $route) {
            $route = trim((string) $route);
            if ($route !== '' && $this->pages->find($route)) {
                $map[(string) $code] = $route;
            }
        }

        // Tương thích ngược: trước khi có plugin này, site tự chế dùng field
        // "translation" (số ít, 1 route duy nhất — xem header.html.twig cũ).
        // Nhiều trang đã có sẵn field này; đọc thêm cho tới khi được migrate
        // sang "translations" map qua lần lưu tiếp theo trong Admin.
        $legacyRoute = trim((string) ($header->translation ?? ''));
        if ($legacyRoute !== '' && $this->pages->find($legacyRoute)) {
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
     * header.smls_translations, mở trang đích và đảm bảo nó cũng trỏ ngược lại
     * route của trang vừa lưu (bỏ qua nếu route đích không tồn tại hoặc đã
     * đúng sẵn, tránh ghi/save thừa) — sau đó dọn luôn các link ngược đã lỗi
     * thời: nếu trang khác đang trỏ về trang này ở 1 ngôn ngữ mà trang này
     * KHÔNG còn khai báo lại (vd người dùng vừa xoá entry đó và lưu), gỡ
     * entry đó khỏi trang kia luôn thay vì để lại link mồ côi.
     */
    public function syncBack(PageInterface $page): void
    {
        $sourceRoute = '/' . ltrim((string) $page->route(), '/');

        if (isset(self::$syncing[$sourceRoute])) {
            return;
        }

        $sourceCode = $this->getPageLanguage($page);
        $translations = $this->getTranslations($page);

        self::$syncing[$sourceRoute] = true;

        try {
            if ($sourceCode !== '') {
                foreach ($translations as $targetCode => $targetRoute) {
                    $this->linkBack($sourceRoute, $sourceCode, $targetRoute);
                }
            }
            $this->pruneStaleBackLinks($sourceRoute, $translations);
        } finally {
            unset(self::$syncing[$sourceRoute]);
        }
    }

    /**
     * Quét toàn bộ trang tìm những trang đang có 1 entry smls_translations
     * trỏ về $sourceRoute nhưng không còn được $sourceRoute xác nhận lại
     * (tức là bị xoá khỏi map hiện tại của trang nguồn) — gỡ entry mồ côi đó.
     *
     * @param array<string, string> $currentTranslations map hiện tại (sau khi lưu) của trang nguồn, code => route
     */
    private function pruneStaleBackLinks(string $sourceRoute, array $currentTranslations): void
    {
        foreach ($this->pages->all() as $candidate) {
            if (!$candidate) {
                continue;
            }

            $candidateRoute = '/' . ltrim((string) $candidate->route(), '/');
            if ($candidateRoute === $sourceRoute || isset(self::$syncing[$candidateRoute])) {
                continue;
            }

            $header = $candidate->header();
            $raw = (array) ($header->smls_translations ?? []);

            $staleCodes = [];
            foreach ($raw as $code => $route) {
                if ('/' . ltrim((string) $route, '/') !== $sourceRoute) {
                    continue;
                }

                $candidateCode = $this->getPageLanguage($candidate);
                if (($currentTranslations[$candidateCode] ?? null) !== $candidateRoute) {
                    $staleCodes[] = $code;
                }
            }

            if (!$staleCodes) {
                continue;
            }

            foreach ($staleCodes as $code) {
                unset($raw[$code]);
            }
            $header->smls_translations = $raw;
            $candidate->header($header);

            self::$syncing[$candidateRoute] = true;
            try {
                $candidate->save(false);
            } finally {
                unset(self::$syncing[$candidateRoute]);
            }
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
        $translations = (array) ($header->smls_translations ?? []);
        $translations[$sourceCode] = $sourceRoute;
        $header->smls_translations = $translations;
        $targetPage->header($header);

        self::$syncing[$targetRoute] = true;
        try {
            $targetPage->save(false);
        } finally {
            unset(self::$syncing[$targetRoute]);
        }
    }
}
