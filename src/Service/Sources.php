<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Reads the sources of a chronicle and turns them into plain text for DeepSeek:
 * RSS/Atom feeds (recent items only), web pages (main text) and weather (Open-Meteo).
 */
final class Sources
{
    public const int MAX_DOWNLOAD = 3_000_000;
    public const int MAX_CHARS_PER_SOURCE = 12_000;
    public const int MAX_ITEMS = 25;

    private const string USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/131.0 Safari/537.36 AzuraCast-AnimateursVirtuels';

    public function __construct(
        private readonly Weather $weather
    ) {
    }

    /**
     * Reads every source. A source that fails is reported, not fatal.
     *
     * @return array<int, array{label: string, url: string, ok: bool, kind: string, text: string, error: string, items: int}>
     */
    public function readAll(array $chronicle, DateTimeImmutable $now): array
    {
        $results = [];
        foreach ($chronicle['sources'] as $source) {
            $label = $source['label'] !== '' ? $source['label']
                : ($source['type'] === 'weather' ? 'Météo ' . $source['location'] : (string)parse_url($source['url'], PHP_URL_HOST));
            $result = ['label' => $label, 'url' => $source['url'], 'ok' => false, 'kind' => $source['type'], 'text' => '', 'error' => '', 'items' => 0];
            try {
                if ($source['type'] === 'weather') {
                    $result['text'] = $this->weather->report($source);
                    $result['kind'] = 'weather';
                } else {
                    $page = $this->read($source['url'], (int)$chronicle['freshness_hours'], $now);
                    $result['text'] = $page['text'];
                    $result['kind'] = $page['kind'];
                    $result['items'] = $page['items'];
                    if ($page['kind'] === 'rss' && $page['items'] === 0) {
                        throw new RuntimeException('aucun article récent dans ce flux (fraîcheur : '
                            . (int)$chronicle['freshness_hours'] . ' h)');
                    }
                }
                $result['ok'] = trim($result['text']) !== '';
                if (!$result['ok']) {
                    $result['error'] = 'aucun texte lisible';
                }
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
            }
            $results[] = $result;
        }

        return $results;
    }

    /** @return array{kind: string, text: string, items: int} */
    public function read(string $url, int $freshnessHours, DateTimeImmutable $now): array
    {
        $response = Http::request(
            'GET',
            $url,
            [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/html;q=0.9, */*;q=0.5',
                'Accept-Language' => 'fr-BE,fr;q=0.9,en;q=0.5',
            ],
            null,
            25,
            true,
            self::MAX_DOWNLOAD
        );
        if ($response['status'] >= 400 || $response['status'] === 0) {
            throw new RuntimeException('le site répond HTTP ' . $response['status']
                . ($response['status'] === 403 ? ' (accès refusé aux robots)' : ''));
        }

        return self::extract($response['body'], (string)($response['headers']['content-type'] ?? ''), $freshnessHours, $now);
    }

    /**
     * Pure function (tested).
     *
     * @return array{kind: string, text: string, items: int}
     */
    public static function extract(string $body, string $contentType, int $freshnessHours, DateTimeImmutable $now): array
    {
        $body = self::toUtf8($body, $contentType);
        $start = ltrim(substr($body, 0, 500));
        $isFeed = str_contains($contentType, 'xml') && !str_contains($contentType, 'html')
            || preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<(rss|feed|rdf:RDF)\b/is', $start);

        if ($isFeed) {
            $items = self::feedItems($body, $freshnessHours, $now);
            $lines = [];
            foreach ($items as $item) {
                $line = '- ' . ($item['date'] !== null ? '[' . $item['date']->format('d/m H:i') . '] ' : '') . $item['title'];
                if ($item['summary'] !== '' && $item['summary'] !== $item['title']) {
                    $line .= ' : ' . $item['summary'];
                }
                $lines[] = $line;
            }

            return ['kind' => 'rss', 'text' => self::cut(implode("\n", $lines)), 'items' => count($items)];
        }

        return ['kind' => 'html', 'text' => self::cut(self::htmlText($body)), 'items' => 0];
    }

    /**
     * @return array<int, array{title: string, summary: string, date: ?DateTimeImmutable}>
     */
    public static function feedItems(string $xml, int $freshnessHours, DateTimeImmutable $now): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$feed instanceof SimpleXMLElement) {
            throw new RuntimeException('flux RSS/Atom illisible');
        }

        $nodes = [];
        if (isset($feed->channel->item)) {
            foreach ($feed->channel->item as $item) {
                $nodes[] = $item;
            }
        }
        foreach ($feed->item ?? [] as $item) { // RSS 1.0 (RDF)
            $nodes[] = $item;
        }
        foreach ($feed->entry ?? [] as $entry) { // Atom
            $nodes[] = $entry;
        }

        $items = [];
        foreach ($nodes as $node) {
            $title = self::clean((string)$node->title);
            $summary = (string)($node->description ?? '');
            if ($summary === '') {
                $summary = (string)($node->summary ?? '') ?: (string)($node->content ?? '');
            }
            $content = $node->children('http://purl.org/rss/1.0/modules/content/');
            if ($summary === '' && isset($content->encoded)) {
                $summary = (string)$content->encoded;
            }
            $summary = mb_substr(self::clean(strip_tags(html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 700);

            $dateText = (string)($node->pubDate ?? '') ?: (string)($node->published ?? '') ?: (string)($node->updated ?? '');
            if ($dateText === '') {
                $dc = $node->children('http://purl.org/dc/elements/1.1/');
                $dateText = isset($dc->date) ? (string)$dc->date : '';
            }
            $date = null;
            if ($dateText !== '') {
                try {
                    $date = (new DateTimeImmutable(trim($dateText)))->setTimezone($now->getTimezone());
                } catch (Throwable) {
                    $date = null;
                }
            }
            if ($date !== null && $freshnessHours > 0 && $now->getTimestamp() - $date->getTimestamp() > $freshnessHours * 3600) {
                continue;
            }
            if ($title === '' && $summary === '') {
                continue;
            }
            $items[] = ['title' => $title, 'summary' => $summary, 'date' => $date];
        }

        usort($items, static fn(array $a, array $b): int => ($b['date']?->getTimestamp() ?? 0) <=> ($a['date']?->getTimestamp() ?? 0));

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /** Main text of a web page: articles or main content, without menus, scripts… */
    public static function htmlText(string $html): string
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);

        $title = self::clean((string)($xpath->query('//title')->item(0)?->textContent ?? ''));
        $description = '';
        $meta = $xpath->query('//meta[@name="description" or @property="og:description"]/@content')->item(0);
        if ($meta !== null) {
            $description = self::clean((string)$meta->nodeValue);
        }

        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//header|//footer|//aside|//form|//iframe'
            . '|//button|//template|//*[@aria-hidden="true"]|//*[@role="navigation"]|//*[@hidden]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Line breaks around blocks, so that the text keeps its structure.
        foreach ($xpath->query('//p|//li|//h1|//h2|//h3|//h4|//h5|//h6|//div|//section|//article|//tr|//dt|//dd|//blockquote|//br')
            ?: [] as $node) {
            $node->parentNode?->insertBefore($dom->createTextNode("\n"), $node);
            $node->parentNode?->insertBefore($dom->createTextNode("\n"), $node->nextSibling);
        }

        $roots = [];
        foreach (['//article', '//main', '//*[@role="main"]', '//body'] as $query) {
            $found = $xpath->query($query);
            if ($found !== false && $found->length > 0) {
                foreach ($found as $node) {
                    if ($node instanceof DOMElement) {
                        $roots[] = $node;
                    }
                }
                break;
            }
        }

        $lines = [];
        $seen = [];
        foreach ($roots as $root) {
            foreach (preg_split('/\n+/', $root->textContent) ?: [] as $line) {
                $line = self::clean($line);
                if (mb_strlen($line) < 3 || isset($seen[$line])) {
                    continue;
                }
                $seen[$line] = true;
                $lines[] = $line;
            }
        }

        $head = array_filter([$title, $description]);

        return trim(implode("\n", array_merge($head !== [] ? [implode(' — ', $head), ''] : [], $lines)));
    }

    public static function toUtf8(string $body, string $contentType): string
    {
        $charset = '';
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4000), $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<\?xml[^>]+encoding=["\']([\w-]+)/i', substr($body, 0, 200), $m)) {
            $charset = $m[1];
        }
        $charset = strtoupper($charset);
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted)) {
                $body = $converted;
                // The XML parser would otherwise read the declared encoding again.
                $body = (string)preg_replace('/(<\?xml[^>]+encoding=["\'])[\w-]+/i', '$1UTF-8', $body, 1);
            }
        }

        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }

    private static function clean(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHARS_PER_SOURCE) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_CHARS_PER_SOURCE);
        $end = mb_strrpos($cut, "\n");

        return $end !== false && $end > self::MAX_CHARS_PER_SOURCE / 2 ? mb_substr($cut, 0, $end) : $cut;
    }
}
