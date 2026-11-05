<?php
namespace Agent;

/**
 * A small, deterministic force-directed layout that renders memory-graph edges as one inline SVG, so the graph needs no
 * script and looks the same on every load. Nodes link back to the Memory search for their label.
 */
class GraphLayout
{
    /**
     * @param list<array{subject:string,relation:string,object:string}> $edges
     * @return array{nodes:list<array>,edges:list<array>} nodes carry label, x, y, r and degree; edges index into nodes
     */
    public static function layout(array $edges, $width = 760, $height = 440, $maxNodes = 60)
    {
        $index = [];
        $nodes = [];
        foreach ($edges as $edge) {
            foreach ([$edge['subject'], $edge['object']] as $label) {
                $key = mb_strtolower(trim($label));
                if (!isset($index[$key])) { $index[$key] = count($nodes); $nodes[] = ['label' => $label, 'degree' => 0]; }
            }
        }
        $links = [];
        $seen = [];
        foreach ($edges as $edge) {
            $a = $index[mb_strtolower(trim($edge['subject']))];
            $b = $index[mb_strtolower(trim($edge['object']))];
            // Several memories can state the same relation; draw it once.
            $key = $a . '>' . $b . '>' . mb_strtolower(trim($edge['relation']));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $nodes[$a]['degree']++;
            if ($a !== $b) $nodes[$b]['degree']++;
            $links[] = ['source' => $a, 'target' => $b, 'relation' => $edge['relation'], 'claim_id' => isset($edge['claim_id']) ? (int)$edge['claim_id'] : null];
        }
        // Keep the best-connected nodes when there are too many to read.
        if (count($nodes) > $maxNodes) {
            $order = array_keys($nodes);
            usort($order, function ($x, $y) use ($nodes) { return [$nodes[$y]['degree'], $x] <=> [$nodes[$x]['degree'], $y]; });
            $keep = array_flip(array_slice($order, 0, $maxNodes));
            $map = [];
            $kept = [];
            foreach ($nodes as $i => $node) if (isset($keep[$i])) { $map[$i] = count($kept); $kept[] = $node; }
            $links = array_values(array_filter(array_map(function ($l) use ($map) {
                return isset($map[$l['source']], $map[$l['target']]) ? ['source' => $map[$l['source']], 'target' => $map[$l['target']]] + $l : null;
            }, $links)));
            $nodes = $kept;
        }
        $n = count($nodes);
        if ($n === 0) return ['nodes' => [], 'edges' => []];

        // Deterministic start on a circle, ordered by label, then Fruchterman-Reingold with cooling.
        $margin = 56;
        $cx = $width / 2;
        $cy = $height / 2;
        foreach ($nodes as $i => &$node) {
            $angle = 2 * M_PI * (crc32(mb_strtolower($node['label'])) % 3600) / 3600;
            $radius = min($width, $height) * 0.3 + ($i % 3) * 14;
            $node['x'] = $cx + cos($angle) * $radius;
            $node['y'] = $cy + sin($angle) * $radius;
        }
        unset($node);
        $k = 0.85 * sqrt(($width - 2 * $margin) * ($height - 2 * $margin) / max(1, $n));
        $temperature = $width / 8;
        for ($step = 0; $step < 160; $step++) {
            $dx = array_fill(0, $n, 0.0);
            $dy = array_fill(0, $n, 0.0);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $vx = $nodes[$i]['x'] - $nodes[$j]['x'];
                    $vy = $nodes[$i]['y'] - $nodes[$j]['y'];
                    $d = max(0.01, sqrt($vx * $vx + $vy * $vy));
                    $f = $k * $k / $d;
                    $dx[$i] += $vx / $d * $f; $dy[$i] += $vy / $d * $f;
                    $dx[$j] -= $vx / $d * $f; $dy[$j] -= $vy / $d * $f;
                }
            }
            foreach ($links as $l) {
                if ($l['source'] === $l['target']) continue;
                $vx = $nodes[$l['source']]['x'] - $nodes[$l['target']]['x'];
                $vy = $nodes[$l['source']]['y'] - $nodes[$l['target']]['y'];
                $d = max(0.01, sqrt($vx * $vx + $vy * $vy));
                $f = $d * $d / $k;
                $dx[$l['source']] -= $vx / $d * $f; $dy[$l['source']] -= $vy / $d * $f;
                $dx[$l['target']] += $vx / $d * $f; $dy[$l['target']] += $vy / $d * $f;
            }
            for ($i = 0; $i < $n; $i++) {
                $dx[$i] -= ($nodes[$i]['x'] - $cx) * 0.05; // a little gravity keeps islands on the canvas
                $dy[$i] -= ($nodes[$i]['y'] - $cy) * 0.05;
                $len = max(0.01, sqrt($dx[$i] * $dx[$i] + $dy[$i] * $dy[$i]));
                $move = min($len, $temperature);
                $nodes[$i]['x'] = max($margin, min($width - $margin, $nodes[$i]['x'] + $dx[$i] / $len * $move));
                $nodes[$i]['y'] = max($margin / 1.5, min($height - $margin / 1.5, $nodes[$i]['y'] + $dy[$i] / $len * $move));
            }
            $temperature *= 0.96;
        }
        $maxDegree = max(1, max(array_column($nodes, 'degree')));
        foreach ($nodes as &$node) $node['r'] = round(6 + 9 * sqrt($node['degree'] / $maxDegree), 1);
        unset($node);
        return ['nodes' => $nodes, 'edges' => $links];
    }

    /** The graph as inline SVG; `$link` turns a node label into the URL its click goes to. */
    public static function svg(array $edges, callable $link, $selected = '', $width = 760, $height = 440)
    {
        $graph = self::layout($edges, $width, $height);
        $h = function ($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); };
        $nodes = $graph['nodes'];
        $dense = count($graph['edges']) > 28 ? ' dense' : '';
        $svg = '<svg class="graph' . $dense . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Memory graph: ' . count($nodes) . ' things, ' . count($graph['edges']) . ' relations">'
            . '<defs><marker id="graph-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 10 5 0 10z"/></marker></defs>';
        foreach ($graph['edges'] as $edge) {
            $a = $nodes[$edge['source']];
            $b = $nodes[$edge['target']];
            if ($edge['source'] === $edge['target']) continue;
            $vx = $b['x'] - $a['x'];
            $vy = $b['y'] - $a['y'];
            $d = max(0.01, sqrt($vx * $vx + $vy * $vy));
            $x1 = $a['x'] + $vx / $d * $a['r'];
            $y1 = $a['y'] + $vy / $d * $a['r'];
            $x2 = $b['x'] - $vx / $d * ($b['r'] + 3);
            $y2 = $b['y'] - $vy / $d * ($b['r'] + 3);
            $svg .= '<g class="graph-edge"><title>' . $h($a['label'] . ' ' . $edge['relation'] . ' ' . $b['label']) . '</title>'
                . '<line class="graph-hit" x1="' . round($x1, 1) . '" y1="' . round($y1, 1) . '" x2="' . round($x2, 1) . '" y2="' . round($y2, 1) . '"/>'
                . '<line class="graph-line" x1="' . round($x1, 1) . '" y1="' . round($y1, 1) . '" x2="' . round($x2, 1) . '" y2="' . round($y2, 1) . '" marker-end="url(#graph-arrow)"/>'
                . '<text class="graph-relation" x="' . round(($x1 + $x2) / 2, 1) . '" y="' . round(($y1 + $y2) / 2 - 3, 1) . '">' . $h(mb_strimwidth($edge['relation'], 0, 24, '…')) . '</text></g>';
        }
        foreach ($nodes as $node) {
            $isSelected = $selected !== '' && mb_strtolower(trim($selected)) === mb_strtolower(trim($node['label']));
            $svg .= '<a class="graph-node' . ($isSelected ? ' selected' : '') . '" href="' . $h($link($node['label'])) . '"><title>' . $h($node['label']) . ' (' . $node['degree'] . ')</title>'
                . '<circle cx="' . round($node['x'], 1) . '" cy="' . round($node['y'], 1) . '" r="' . $node['r'] . '"/>'
                . '<text x="' . round($node['x'], 1) . '" y="' . round($node['y'] + $node['r'] + 12, 1) . '">' . $h(mb_strimwidth($node['label'], 0, 22, '…')) . '</text></a>';
        }
        return $svg . '</svg>';
    }
}
