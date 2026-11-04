<?php
namespace Agent;

/**
 * Avatars are composed, not stored: a body (any object) wearing a face (eyes, brows, mouth and the expression parts the
 * animations drive). `resources/avatars/` holds both as data, and render() stitches one SVG per request.
 *
 * An avatar id is a shipped character's slug ("arthur", the body and face it was drawn with) or "body.face" for any mix.
 * Every face follows the same part vocabulary (see docs/personas.md), so every animation works on every combination.
 */
class Avatar
{
    const ID = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)?$/D';
    private static $manifest;
    private static $fragments = [];

    private static function manifest()
    {
        if (self::$manifest === null) {
            self::$manifest = json_decode(file_get_contents(Runtime::root() . '/resources/avatars/avatars.json'), true);
        }
        return self::$manifest;
    }

    /** @return array{body:string,face:string}|null */
    public static function resolve($id)
    {
        if (!is_string($id) || !preg_match(self::ID, $id)) return null;
        $m = self::manifest();
        if (isset($m['presets'][$id])) return ['body' => $m['presets'][$id]['body'], 'face' => $m['presets'][$id]['face']];
        $parts = explode('.', $id) + [1 => 'terminal'];
        return isset($m['bodies'][$parts[0]], $m['faces'][$parts[1]]) ? ['body' => $parts[0], 'face' => $parts[1]] : null;
    }

    public static function valid($id)
    {
        return self::resolve($id) !== null;
    }

    /** The shortest id for a body and face: a shipped character's slug when the pair is exactly one of them. */
    public static function id($body, $face)
    {
        $m = self::manifest();
        return isset($m['presets'][$body]) && $m['presets'][$body]['body'] === $body && $m['presets'][$body]['face'] === $face ? $body : $body . '.' . $face;
    }

    /** Shipped characters first (in manifest order), then the library, alphabetically. @return list<array{id:string,label:string,source:string,own:bool}> */
    public static function bodies()
    {
        $m = self::manifest();
        $own = $library = [];
        foreach ($m['bodies'] as $id => $body) {
            $row = ['id' => $id, 'label' => ucfirst($body['label']), 'source' => $body['source'], 'own' => isset($m['presets'][$id])];
            if ($row['own']) $own[] = $row; else $library[] = $row;
        }
        usort($library, function ($a, $b) { return strcasecmp($a['label'], $b['label']); });
        return array_merge($own, $library);
    }

    /** @return list<array{id:string,label:string}> */
    public static function faces()
    {
        $faces = [];
        foreach (self::manifest()['faces'] as $id => $face) $faces[] = ['id' => $id, 'label' => $face['label']];
        return $faces;
    }

    /** Every id that may be chosen for a shipped character. @return list<string> */
    public static function presets()
    {
        return array_keys(self::manifest()['presets']);
    }

    private static function fragment($kind, $slug)
    {
        if (!isset(self::$fragments[$kind][$slug])) {
            $parts = explode("<!--over-->\n", file_get_contents(Runtime::root() . '/resources/avatars/' . $kind . '/' . $slug . '.svg'), 2);
            self::$fragments[$kind][$slug] = [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : ''];
        }
        return self::$fragments[$kind][$slug];
    }

    private static function luminance($hex)
    {
        $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }, sscanf(ltrim($hex, '#'), '%02x%02x%02x'));
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function contrast($a, $b)
    {
        $x = self::luminance($a);
        $y = self::luminance($b);
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function number($value)
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    public static function render($id)
    {
        if (!self::resolve($id)) $id = 'terminal';
        $pair = self::resolve($id);
        $m = self::manifest();
        $body = $m['bodies'][$pair['body']];
        $face = $m['faces'][$pair['face']];
        [$bodyUnder, $bodyOver] = self::fragment('bodies', $pair['body']);
        [$faceUnder, $faceOver] = self::fragment('faces', $pair['face']);

        // A face keeps its own colours on the body it was drawn for; on any other body its ink and eyes adapt to what is behind them.
        $ink = $face['ink'];
        $eyeline = '';
        if ($pair['body'] !== $pair['face']) {
            if (self::contrast($ink, $body['lid']) < 3) $ink = self::luminance($body['lid']) > 0.35 ? '#2a2723' : '#f4efe4';
            if (!empty($face['eye']) && self::contrast($face['eye'], $body['lid']) < 1.6) {
                $eyeline = ' stroke="' . $ink . '" stroke-opacity=".5" stroke-width="1.6"';
            }
        }
        $dress = function ($svg) use ($ink, $body, $eyeline) {
            $svg = str_replace(['{{ink}}', '{{lid}}'], [$ink, $body['lid']], $svg);
            return $eyeline === '' ? $svg : preg_replace('/(<ellipse cx="(?:87|151)" cy="80" rx="13" ry="17" fill="#[0-9a-fA-F]{6}")/', '$1' . $eyeline, $svg);
        };
        $scale = max(0.5, min(1.2, $body['face']['w'] / $face['w']));
        $place = 'translate(' . self::number($body['face']['x'] - $face['center'][0] * $scale) . ' ' . self::number($body['face']['y'] - $face['center'][1] * $scale) . ') scale(' . self::number($scale) . ')';

        $label = isset($m['presets'][$id]) ? $m['presets'][$id]['label'] : ucfirst($body['label']) . ' with ' . $face['label'] . '\'s face';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 220" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
            . "\n " . '<ellipse cx="120" cy="203" rx="' . self::number($body['shadow']) . '" ry="8" fill="#473e2a" opacity=".09"/>'
            . "\n " . '<g class="avatar-body">' . $bodyUnder
            . "\n " . '<g transform="' . $place . '">' . $dress($faceUnder) . '</g>'
            . ($bodyOver === '' ? '' : "\n " . $bodyOver)
            . "\n " . '<g transform="' . $place . '">' . $dress($faceOver) . '</g>'
            . "\n </g>\n</svg>";
    }

    /** Credits for every library body, for the credits page and docs. @return list<array{label:string,source:string,license:string}> */
    public static function credits()
    {
        $credits = [];
        foreach (self::manifest()['bodies'] as $body) $credits[$body['source'] . '|' . $body['license']] = ['source' => $body['source'], 'license' => $body['license']];
        return array_values($credits);
    }
}
