<?php declare(strict_types=1);

namespace Goose\Modules\Extractors;

use Goose\Article;
use Goose\Utils\Helper;
use Goose\Traits\ArticleMutatorTrait;
use Goose\Modules\{AbstractModule, ModuleInterface};
use DOMWrap\{Document, NodeList};

/**
 * Content Extractor
 *
 * @package Goose\Modules\Extractors
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */
class MetaExtractor extends AbstractModule implements ModuleInterface {
    use ArticleMutatorTrait;

    /** @var string[] */
    protected static $SPLITTER_CHARS = [
        '|', '-', '»', ':',
    ];

    /** @inheritdoc */
    public function run(Article $article): self {
        $this->article($article);

        $article->setOpenGraph($this->getOpenGraph());
        $article->setTitle($this->getTitle());
        $article->setMetaDescription($this->getMetaDescription());
        $article->setMetaKeywords($this->getMetaKeywords());
        $article->setCanonicalLink($this->getCanonicalLink());
        $article->setLanguage($this->getMetaLanguage() ?: $this->config()->get('language'));

        $this->config()->set('language', $article->getLanguage());

        return $this;
    }

    /**
     * Retrieve all OpenGraph meta data
     *
     * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
     * 
     * @return string[]
     */
    private function getOpenGraph(): array {
        $results = array();

        $nodes = $this->article()->getDoc()->find('meta[property^="og:"]');

        foreach ($nodes as $node) {
            $property = explode(':', $node->attr('property'));
            array_shift($property);
            $results[implode(':', $property)] = $node->attr('content');
        }

        // Additionally retrieve type values based on provided og:type (http://ogp.me/#types)
        if (isset($results['type'])) {
            $nodes = $this->article()->getDoc()->find('meta[property^="' . $results['type'] .':"]');

            foreach ($nodes as $node) {
                $property = explode(':', $node->attr('property'));
                array_shift($property);
                $results[implode(':', $property)] = $node->attr('content');
            }
        }

        return $results;
    }

    /**
     * Clean title text
     *
     * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
     * 
     * @param string $title
     *
     * @return string
     */
    private function cleanTitle(string $title): string {
        $openGraph = $this->article()->getOpenGraph();

        // Check if we have the site name in OpenGraph data and it does not match the title
        if (isset($openGraph['site_name']) && $openGraph['site_name'] != $title) {
            $title = str_replace($openGraph['site_name'], '', $title);
        }

        // Try to remove the domain from URL
        if ($this->article()->getDomain()) {
            $title = str_ireplace($this->article()->getDomain(), '', $title);
        }

        // Split the title in words
        // TechCrunch | my wonderfull article
        // my wonderfull article | TechCrunch
        $titleWords = preg_split('@[\s]+@', trim($title));

        // Check for an empty title
        if (empty($titleWords)) {
            return '';
        }

        // Check if last letter is in self::$SPLITTER_CHARS
        // if so remove it
        if (in_array($titleWords[count($titleWords) - 1], self::$SPLITTER_CHARS)) {
            array_pop($titleWords);
        }

        // Check if first letter is in self::$SPLITTER_CHARS
        // if so remove it
        if (isset($titleWords[0]) && in_array($titleWords[0], self::$SPLITTER_CHARS)) {
            array_shift($titleWords);
        }

        // Rebuild the title
        return trim(implode(' ', $titleWords));
    }

    /**
     * Get article title
     *
     * Ported from python-goose https://github.com/grangier/python-goose/ by Xavier Grangier
     * 
     * @return string
     */
    private function getTitle(): string {
        $openGraph = $this->article()->getOpenGraph();

        // Rely on OpenGraph in case we have the data
        if (isset($openGraph['title'])) {
            return $this->cleanTitle($openGraph['title']);
        }

        $nodes = $this->getNodesByLowercasePropertyValue($this->article()->getDoc(), 'meta', 'name', 'headline');
        if ($nodes->count()) {
            return $this->cleanTitle($nodes->first()->attr('content'));
        }

        $nodes = $this->article()->getDoc()->find('html > head > title');
        if ($nodes->count()) {
            return $this->cleanTitle(Helper::textNormalise($nodes->first()->text()));
        }

        return '';
    }

    /**
     * @param Document $doc
     * @param string $tag
     * @param string $property
     * @param string $value
     *
     * @return NodeList
     */
    private function getNodesByLowercasePropertyValue(Document $doc, string $tag, string $property, string $value): NodeList {
        return $doc->findXPath("descendant::".$tag."[translate(@".$property.", 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='".$value."']");
    }

    /**
     * @param Document $doc
     * @param string $property
     * @param string $value
     * @param string $attr
     *
     * @return string
     */
    private function getMetaContent(Document $doc, string $property, string $value, string $attr = 'content'): string {
        $nodes = $this->getNodesByLowercasePropertyValue($doc, 'meta', $property, $value);

        if (!$nodes->count()) {
            return '';
        }

        $content = $nodes->first()->attr($attr);

        if (!is_string($content)) {
            return '';
        }

        return trim($content);
    }

    /**
     * If the article has meta language set in the source, use that
     *
     * @return string
     */
    private function getMetaLanguage(): string {
        $lang = '';

        $el = $this->article()->getDoc()->find('html[lang]');

        if ($el->count()) {
            $lang = $el->first()->attr('lang');
        }

        if (empty($lang)) {
            $selectors = [
                'html > head > meta[http-equiv=content-language]',
                'html > head > meta[name=lang]',
            ];

            foreach ($selectors as $selector) {
                $el = $this->article()->getDoc()->find($selector);

                if ($el->count()) {
                    $lang = $el->first()->attr('content');
                    break;
                }
            }
        }

        if (preg_match('@^[A-Za-z]{2}$@', $lang)) {
            return strtolower($lang);
        }

        return '';
    }

    /**
     * If the article has meta description set in the source, use that
     *
     * @return string
     */
    private function getMetaDescription(): string {
        $desc = $this->getMetaContent($this->article()->getDoc(), 'name', 'description');

        if (empty($desc)) {
            $desc = $this->getMetaContent($this->article()->getDoc(), 'property', 'og:description');
        }

        if (empty($desc)) {
            $desc = $this->getMetaContent($this->article()->getDoc(), 'name', 'twitter:description');
        }

        return trim($desc);
    }

    /**
     * If the article has meta keywords set in the source, use that
     *
     * @return string
     */
    private function getMetaKeywords(): string {
        return $this->getMetaContent($this->article()->getDoc(), 'name', 'keywords');
    }

    /**
     * If the article has meta canonical link set in the url
     *
     * @return string|null
     */
    private function getCanonicalLink(): ?string {
        $nodes = $this->getNodesByLowercasePropertyValue($this->article()->getDoc(), 'link', 'rel', 'canonical');

        if ($nodes->count()) {
            return trim($nodes->first()->attr('href'));
        }

        $nodes = $this->getNodesByLowercasePropertyValue($this->article()->getDoc(), 'meta', 'property', 'og:url');

        if ($nodes->count()) {
            return trim($nodes->first()->attr('content'));
        }

        $nodes = $this->getNodesByLowercasePropertyValue($this->article()->getDoc(), 'meta', 'name', 'twitter:url');

        if ($nodes->count()) {
            return trim($nodes->first()->attr('content'));
        }

        return $this->article()->getFinalUrl();
    }
}
