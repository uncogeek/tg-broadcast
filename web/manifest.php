<?php
header("Content-Type: application/manifest+json");
header("Cache-Control: public, max-age=600");
echo json_encode([
    "name"             => "اخبار زنده",
    "short_name"       => "اخبار",
    "id"               => "/news/news.php",
    "start_url"        => "/news/news.php",
    "scope"            => "/news/",
    "display"          => "standalone",
    "display_override" => ["standalone", "minimal-ui", "browser"],
    "background_color" => "#0d1117",
    "theme_color"      => "#0d1117",
    "icons"            => [
        [
            "src"     => "/news/icon-192.png",
            "sizes"   => "192x192",
            "type"    => "image/png",
            "purpose" => "any"
        ],
        [
            "src"     => "/news/icon-512.png",
            "sizes"   => "512x512",
            "type"    => "image/png",
            "purpose" => "maskable"
        ]
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
