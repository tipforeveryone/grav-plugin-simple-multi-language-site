<?php

namespace Grav\Plugin\SimpleMultiLanguageSite;

use Grav\Common\Config\Config;

/**
 * Đọc cấu hình plugin (danh sách ngôn ngữ tự khai báo + ngôn ngữ mặc định).
 * Không có ngôn ngữ nào bị hardcode — mọi thứ đến từ plugins.simple-multi-language-site.*
 */
class LanguageManager
{
    /** @var array<int, array{code: string, label: string, root_path: string, flag: string}> */
    private array $languages;

    private string $defaultLanguage;

    public function __construct(Config $config)
    {
        $raw = (array) $config->get('plugins.simple-multi-language-site.languages', []);

        $languages = [];
        foreach ($raw as $entry) {
            $code = trim((string) ($entry['code'] ?? ''));
            $rootPath = trim((string) ($entry['root_path'] ?? ''));
            if ($code === '' || $rootPath === '') {
                continue;
            }

            $languages[] = [
                'code' => $code,
                'label' => trim((string) ($entry['label'] ?? $code)),
                'root_path' => '/' . trim($rootPath, '/'),
                'flag' => trim((string) ($entry['flag'] ?? '')),
            ];
        }

        $this->languages = $languages;
        $this->defaultLanguage = trim((string) $config->get('plugins.simple-multi-language-site.default_language', ''));
    }

    /** @return array<int, array{code: string, label: string, root_path: string, flag: string}> */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getDefaultLanguage(): string
    {
        if ($this->defaultLanguage !== '' && $this->findByCode($this->defaultLanguage)) {
            return $this->defaultLanguage;
        }

        return $this->languages[0]['code'] ?? '';
    }

    /** @return array{code: string, label: string, root_path: string, flag: string}|null */
    public function findByCode(string $code): ?array
    {
        foreach ($this->languages as $language) {
            if ($language['code'] === $code) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Tìm ngôn ngữ mà root_path là tiền tố của route đã cho.
     * Nếu nhiều root_path cùng khớp (hiếm, do cấu hình chồng lấn), ưu tiên root_path dài nhất.
     *
     * @return array{code: string, label: string, root_path: string, flag: string}|null
     */
    public function findByRoute(string $route): ?array
    {
        $route = '/' . ltrim($route, '/');

        $best = null;
        foreach ($this->languages as $language) {
            $root = $language['root_path'];
            if ($route === $root || strpos($route, $root . '/') === 0) {
                if ($best === null || strlen($root) > strlen($best['root_path'])) {
                    $best = $language;
                }
            }
        }

        return $best;
    }

    /** Options cho field select, dùng ở cả blueprint plugin settings lẫn blueprint từng trang. */
    public static function getLanguageOptions(): array
    {
        $config = \Grav\Common\Grav::instance()['config'];
        $manager = new self($config);

        $options = [];
        foreach ($manager->getLanguages() as $language) {
            $options[$language['code']] = $language['label'];
        }

        return $options;
    }
}
