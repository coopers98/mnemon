<?php

namespace App\Services;

use App\Models\WikiPage;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer
{
    protected MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Render markdown content to HTML, processing wikilinks.
     */
    public function render(string $markdown): string
    {
        $processed = $this->processWikilinks($markdown);

        return (string) $this->converter->convert($processed);
    }

    /**
     * Process [[wikilink]] and [[wikilink|Display Text]] syntax.
     *
     * Replaces wikilinks with HTML anchor tags before markdown conversion.
     * Valid pages get class="wiki-link", broken links get class="wiki-link-broken".
     */
    protected function processWikilinks(string $content): string
    {
        // Cache existing page names for this render pass
        $existingPages = WikiPage::pluck('name')->toArray();

        return preg_replace_callback('/\[\[([^\]]+)\]\]/', function (array $matches) use ($existingPages) {
            $inner = $matches[1];

            // Check for alias syntax: [[page-name|Display Text]]
            if (str_contains($inner, '|')) {
                [$pageName, $displayText] = explode('|', $inner, 2);
                $pageName = trim($pageName);
                $displayText = trim($displayText);
            } else {
                $pageName = trim($inner);
                $displayText = $this->titleFromName($pageName);
            }

            $exists = in_array($pageName, $existingPages, true);
            $class = $exists ? 'wiki-link' : 'wiki-link-broken';
            $url = '/wiki/'.urlencode($pageName);

            return '<a href="'.$url.'" class="'.$class.'">'.e($displayText).'</a>';
        }, $content) ?? $content;
    }

    /**
     * Convert a page name to a display title.
     * Strips type prefix (before colon), replaces hyphens/underscores with spaces, title-cases.
     */
    protected function titleFromName(string $name): string
    {
        // Strip prefix before colon (e.g., "project:atlas-abs" → "atlas-abs")
        $base = str_contains($name, ':') ? substr($name, strpos($name, ':') + 1) : $name;

        return str($base)
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }
}
