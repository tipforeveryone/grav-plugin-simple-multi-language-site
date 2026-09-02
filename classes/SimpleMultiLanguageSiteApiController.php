<?php

declare(strict_types=1);

namespace Grav\Plugin\SimpleMultiLanguageSite;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 backend for Simple Multi Language Site's "Multi Language" page
 * (the classic-admin "Language Converter"). Ported straight from
 * SimpleMultiLanguageSitePlugin::handleAssignLanguages() and
 * twigMissingTranslations() — same LanguageManager/TranslationLinker
 * calls, just exposed as routes instead of an onAdminTaskExecute task /
 * Twig function.
 *
 * The page-editor "Add Translation" field (admin-next/fields/
 * simple-multi-language-site-translate.js) does NOT call anything here —
 * it only needs the generic GET /pages (template filter), GET /pages/
 * {route}, GET /config/plugins/simple-multi-language-site and POST /pages,
 * all of which already exist.
 */
class SimpleMultiLanguageSiteApiController extends AbstractApiController
{
    public function assignLanguages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $this->grav['pages']->enablePages();
        $languages = $this->languages();

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

            $language = $languages->findByRoute('/' . ltrim((string) $page->route(), '/'));
            if (!$language) {
                continue;
            }

            $header->smls_language = $language['code'];
            $page->header($header);
            $page->save(false);
            $count++;
        }

        return ApiResponse::create(['assigned' => $count]);
    }

    public function missingTranslations(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $languages = $this->languages()->getLanguages();
        if (count($languages) < 2) {
            return ApiResponse::create(['rows' => []]);
        }

        $this->grav['pages']->enablePages();
        $linker = $this->linker();

        $rows = [];
        foreach ($this->grav['pages']->all() as $page) {
            if (!$page) {
                continue;
            }

            $code = trim((string) ($page->header()->smls_language ?? ''));
            if ($code === '') {
                continue;
            }

            $translations = $linker->getTranslations($page);
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

        return ApiResponse::create(['rows' => $rows]);
    }

    private function languages(): LanguageManager
    {
        return new LanguageManager($this->config);
    }

    private function linker(): TranslationLinker
    {
        return new TranslationLinker($this->languages(), $this->grav['pages']);
    }
}
