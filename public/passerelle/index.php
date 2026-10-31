<?php
/**
 * Centrale élèves — page publique de la spécification passerelle.atelier (rendu simple du Markdown).
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

function md_en_ligne(string $t): string
{
    $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $codes = [];
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $t) ?? $t;
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t) ?? $t;
    $t = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $t) ?? $t;
    return preg_replace_callback("/\x01(\d+)\x01/", fn($m) => $codes[(int) $m[1]], $t) ?? $t;
}

function md_en_html(string $md): string
{
    $html = '';
    $lignes = preg_split('/\R/', $md) ?: [];
    for ($i = 0, $n = count($lignes); $i < $n; $i++) {
        $l = $lignes[$i];
        if (str_starts_with($l, '```')) {
            $bloc = [];
            while (++$i < $n && !str_starts_with($lignes[$i], '```')) {
                $bloc[] = $lignes[$i];
            }
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $bloc), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
        } elseif (preg_match('/^(#{1,4})\s+(.*)$/', $l, $m)) {
            $niv = strlen($m[1]);
            $html .= "<h$niv>" . md_en_ligne($m[2]) . "</h$niv>\n";
        } elseif (str_starts_with($l, '|')) {
            $rangs = [];
            for (; $i < $n && str_starts_with($lignes[$i], '|'); $i++) {
                $rangs[] = $lignes[$i];
            }
            $i--;
            $html .= '<div class="defile"><table>';
            foreach ($rangs as $k => $r) {
                if (preg_match('/^\|[\s:|-]+\|$/', $r)) {
                    continue;
                }
                $cellules = array_map('trim', explode('|', trim($r, " |")));
                $b = $k === 0 ? 'th' : 'td';
                $html .= '<tr>' . implode('', array_map(fn($c) => "<$b>" . md_en_ligne($c) . "</$b>", $cellules)) . '</tr>';
            }
            $html .= "</table></div>\n";
        } elseif (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $l)) {
            $ordonnee = (bool) preg_match('/^\s*\d+\./', $l);
            $html .= $ordonnee ? '<ol>' : '<ul>';
            for (; $i < $n && preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $lignes[$i], $m); $i++) {
                $html .= '<li' . ($m[1] !== '' ? ' class="sous-item"' : '') . '>' . md_en_ligne($m[3]) . '</li>';
            }
            $i--;
            $html .= $ordonnee ? "</ol>\n" : "</ul>\n";
        } elseif (str_starts_with($l, '>')) {
            $bloc = [];
            for (; $i < $n && str_starts_with($lignes[$i], '>'); $i++) {
                $bloc[] = ltrim(substr($lignes[$i], 1));
            }
            $i--;
            $html .= '<blockquote>' . md_en_html(implode("\n", $bloc)) . "</blockquote>\n";
        } elseif (trim($l) === '---') {
            $html .= "<hr>\n";
        } elseif (trim($l) !== '') {
            $para = [$l];
            while ($i + 1 < $n && trim($lignes[$i + 1]) !== '' && !preg_match('/^(#|\||```|>|\s*([-*]|\d+\.)\s)/', $lignes[$i + 1])) {
                $para[] = $lignes[++$i];
            }
            $html .= '<p>' . md_en_ligne(implode(' ', $para)) . "</p>\n";
        }
    }
    return $html;
}

$md = (string) file_get_contents(__DIR__ . '/SPECIFICATION.md');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Passerelle des ateliers — Centrale élèves</title>
<style>
    :root { color-scheme: light dark; --fond: #f6f7fb; --carte: #fff; --texte: #1d2433; --doux: #5b6577; --code: #eef1f7; --lien: #2f6fdb; }
    @media (prefers-color-scheme: dark) { :root { --fond: #141821; --carte: #1c2230; --texte: #e6e9f0; --doux: #9aa4b6; --code: #262d3d; --lien: #7aa7ff; } }
    body { margin: 0; background: var(--fond); color: var(--texte); font: 16px/1.55 system-ui, sans-serif; }
    main { max-width: 860px; margin: 0 auto; padding: 24px 16px 60px; }
    article { background: var(--carte); border-radius: 12px; padding: 8px 28px 24px; }
    a { color: var(--lien); }
    code { background: var(--code); padding: 1px 5px; border-radius: 4px; font-size: .9em; }
    pre { background: var(--code); padding: 12px; border-radius: 8px; overflow-x: auto; }
    pre code { padding: 0; background: none; }
    blockquote { margin: 0; border-left: 4px solid var(--lien); padding: 2px 14px; color: var(--doux); }
    .defile { overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; font-size: .93rem; }
    th, td { border-bottom: 1px solid var(--code); padding: 6px 8px; text-align: left; vertical-align: top; }
    li.sous-item { margin-left: 22px; }
    .fichiers { color: var(--doux); font-size: .95rem; }
    @media (max-width: 600px) { article { padding: 4px 14px 18px; } }
</style>
</head>
<body>
<main>
    <p class="fichiers">Centrale élèves · <a href="SPECIFICATION.md">SPECIFICATION.md</a> ·
        <a href="passerelle-atelier.schema.json">passerelle-atelier.schema.json</a> · <a href="modele-centrale.json">modele-centrale.json</a></p>
    <article><?= md_en_html($md) ?></article>
</main>
</body>
</html>
