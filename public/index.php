<?php
declare(strict_types=1);
require __DIR__ . '/../lib/commun.php';
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Centrale élèves</title>
<style>
:root { --fond: #f6f5f2; --texte: #1d1f24; --doux: #62666f; --accent: #1f5f8b; color-scheme: light dark; }
@media (prefers-color-scheme: dark) { :root { --fond: #16181c; --texte: #e8e6e1; --doux: #a2a5ad; --accent: #6aa9d6; } }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--fond); color: var(--texte);
       font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 16px; }
main { max-width: 440px; text-align: center; }
h1 { margin: 0 0 8px; }
p { color: var(--doux); }
a { color: var(--accent); }
</style>
</head>
<body>
<main>
    <h1>Centrale élèves</h1>
    <p>Service de connexion des applications de technologie. Il n'y a rien à faire ici :
        utilisez les applications données par votre professeur.</p>
    <p><a href="admin/">Espace enseignant</a> · <a href="apps/exemple/">Application d'exemple</a></p>
</main>
</body>
</html>
