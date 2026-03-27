<?php
/**
 * Nibbly CMS — Starter Content Generator
 *
 * Called by setup.php to generate demo pages after initial installation.
 * Contains all i18n strings (en/de/es) and content generators for:
 *   - Enhanced homepage
 *   - Block types demo page
 *   - Components demo page (PHP template + JSON)
 *   - About page
 *   - News listing + detail templates + demo posts
 */

/**
 * Returns all i18n strings for demo content.
 */
function getStarterI18n($siteName) {
    return [
        'en' => [
            // Homepage
            'home_title' => 'Welcome to ' . $siteName,
            'home_intro' => '<p>Your website is ready. This is example content to help you get started — edit or replace it anytime via the <a href="admin/">admin panel</a> or directly on this page when logged in.</p>',
            'home_features_title' => 'What You Can Do',
            'home_feature_1' => 'Edit content directly on the page — just click and type.',
            'home_feature_2' => 'Add new sections: text, images, quotes, lists, and more.',
            'home_feature_3' => 'All content is stored as simple JSON files — no database needed.',
            'home_feature_4' => 'Supports multiple languages out of the box.',
            'home_quote' => 'The best CMS is the one you don\'t have to think about.',
            'home_quote_author' => 'A wise developer',
            'home_getting_started_title' => 'Getting Started',
            'home_getting_started' => '<p>Open the <a href="admin/">admin panel</a> to manage pages, upload images, and configure your site. You can also log in and click <strong>Edit Page</strong> in the toolbar to make changes directly.</p><p>Check out the other demo pages to see what\'s possible: <a href="blocks">Block Types</a> shows every content block, <a href="components">Components</a> demonstrates interactive widgets, and <a href="about">About</a> is a typical content page you can use as a starting point.</p>',
            'home_cta_title' => 'Start Building',
            'home_cta_text' => '<p>This example content can be deleted at any time — either from the dashboard or by removing sections in the inline editor. Replace it with your own content and make this site yours.</p>',
            'home_img_caption' => 'Replace this placeholder with your own image.',
            'home_card_1_title' => 'For Websites',
            'home_card_1_desc' => 'Business sites, portfolios, landing pages — any content-driven site that needs easy editing without a database.',
            'home_card_2_title' => 'For Clients',
            'home_card_2_desc' => 'Hand over a site your clients can actually maintain. Click-to-edit text, drag-and-drop images, no training needed.',
            'home_card_3_title' => 'For Developers',
            'home_card_3_desc' => 'Plain PHP templates, JSON content files, CSS custom properties. No framework, no build step, no magic.',
            'home_card_4_title' => 'For AI Workflows',
            'home_card_4_desc' => 'Tell your AI to build with Nibbly — the result is a working CMS, not a prototype that needs a backend.',

            // About page
            'about_title' => 'About',
            'about_heading' => 'About Us',
            'about_intro' => '<p>This is an example page to show how a typical content page looks in Nibbly. It uses only standard block types — heading, text, image, quote — rendered automatically from a JSON file.</p>',
            'about_mission_title' => 'Our Mission',
            'about_mission' => '<p>We believe websites should be simple to build and even simpler to maintain. No databases, no complex deployments, no vendor lock-in. Just files on a server that you own and control.</p><p>Every piece of content on this site is stored as a plain JSON file. You can open it in any text editor, back it up by copying a folder, and deploy by uploading files.</p>',
            'about_quote' => 'Simplicity is the ultimate sophistication.',
            'about_quote_author' => 'Leonardo da Vinci',
            'about_values_title' => 'What We Value',
            'about_value_1' => 'Simplicity over complexity',
            'about_value_2' => 'Ownership over dependency',
            'about_value_3' => 'Transparency over magic',
            'about_value_4' => 'Accessibility over exclusivity',
            'about_closing' => '<p>This is demo content — feel free to replace it with your own story. You can edit everything directly on the page when logged in, or modify the JSON file at <code>content/pages/{lang}_about.json</code>.</p>',

            // Block types demo
            'blocks_title' => 'Block Types',
            'blocks_heading' => 'All Block Types',
            'blocks_intro' => '<p>This page demonstrates every content block available in Nibbly. Each section uses a different <code>type</code> value in the JSON content file. You can mix and match them freely when building pages.</p>',
            'blocks_text_heading' => 'Text Block',
            'blocks_text_title' => 'Rich Text Content',
            'blocks_text_content' => '<p>The text block is the most versatile block type. It supports <strong>bold</strong>, <em>italic</em>, <a href="#">links</a>, and other HTML formatting. Use it for paragraphs, articles, or any free-form content.</p><p>Each text block can have an optional title that appears as a heading above the content. The title field is great for section headings that belong to a specific text block.</p>',
            'blocks_quote_heading' => 'Quote Block',
            'blocks_quote_text' => 'Content is like water — it takes the shape of whatever container you put it in.',
            'blocks_quote_author' => 'A content strategist',
            'blocks_image_heading' => 'Image Block',
            'blocks_image_caption' => 'Images can be full width, medium, or small. This one is full width with a caption.',
            'blocks_list_heading' => 'List Block',
            'blocks_list_title' => 'Features of the List Block',
            'blocks_list_1' => 'Bullet and numbered list styles available',
            'blocks_list_2' => 'Each item is individually editable by admins',
            'blocks_list_3' => 'Items can be added, removed, and reordered',
            'blocks_list_4' => 'Great for feature lists, steps, or checklists',
            'blocks_card_heading' => 'Card Block',
            'blocks_card_1_title' => 'First Card',
            'blocks_card_1_desc' => 'Cards automatically arrange in a responsive grid. Each card has an image, title, and description.',
            'blocks_card_2_title' => 'Second Card',
            'blocks_card_2_desc' => 'Use cards for team members, services, portfolio items, or any content that benefits from a visual grid layout.',
            'blocks_card_3_title' => 'Third Card',
            'blocks_card_3_desc' => 'All card content is editable inline. Click any text to change it, or click the image to replace it.',
            'blocks_card_4_title' => 'Fourth Card',
            'blocks_card_4_desc' => 'Cards automatically form a 2×2 or 4×1 grid depending on screen width. Add as many as you need.',
            'blocks_media_heading' => 'Media Blocks',
            'blocks_media_text' => '<p>Nibbly also supports <strong>YouTube</strong>, <strong>SoundCloud</strong>, and <strong>audio</strong> embeds. These blocks accept a video/track ID or audio file path and render the appropriate player.</p><p>Below is an example YouTube embed:</p>',
            'blocks_layout_heading' => 'Layout Blocks',
            'blocks_layout_text' => '<p>Two layout blocks help structure your page: <strong>Divider</strong> adds a horizontal line, and <strong>Spacer</strong> adds vertical space (sm, md, lg, or xl). You\'ve seen both used throughout this page.</p>',
            'blocks_closing' => '<p>All these block types can be added to any page via the admin dashboard or by editing the JSON content file directly. See the <a href="components">Components page</a> for interactive widgets like FAQ accordions, pricing tables, and more.</p>',

            // Components page
            'comp_title' => 'Components',
            'comp_heading' => 'Interactive Components',
            'comp_intro' => 'Beyond basic content blocks, Nibbly includes ready-made components for common website patterns. Each component is rendered by a PHP function and populated from JSON data.',
            'comp_features_heading' => 'Feature Grid',
            'comp_features_desc' => 'Icon-driven feature cards arranged in a responsive grid.',
            'comp_feat_1_title' => 'No Database',
            'comp_feat_1_desc' => 'Content lives in JSON files. Back up by copying a folder.',
            'comp_feat_2_title' => 'Inline Editing',
            'comp_feat_2_desc' => 'Click any text on the page to edit it. No backend forms.',
            'comp_feat_3_title' => 'Automatic Backups',
            'comp_feat_3_desc' => 'Every save creates a timestamped backup you can restore.',
            'comp_feat_4_title' => 'Multi-Language',
            'comp_feat_4_desc' => 'Built-in support for multiple languages. No plugins needed.',
            'comp_feat_5_title' => 'Zero Dependencies',
            'comp_feat_5_desc' => 'No Composer, no npm. Just PHP and a web server.',
            'comp_feat_6_title' => 'Clean URLs',
            'comp_feat_6_desc' => 'SEO-friendly paths that work on Apache and the dev server.',
            'comp_stats_heading' => 'Stats',
            'comp_stats_desc' => 'Highlight key figures at a glance.',
            'comp_stat_1_value' => '0',
            'comp_stat_1_label' => 'Dependencies',
            'comp_stat_2_value' => '< 500 KB',
            'comp_stat_2_label' => 'Install Size',
            'comp_stat_3_value' => '11+',
            'comp_stat_3_label' => 'Block Types',
            'comp_stat_4_value' => '< 5 min',
            'comp_stat_4_label' => 'Setup Time',
            'comp_testimonials_heading' => 'Testimonials',
            'comp_testimonials_desc' => 'Customer quotes displayed as cards.',
            'comp_test_1_quote' => 'We replaced our old CMS in an afternoon. No database migration, no plugin conflicts. Just upload and go.',
            'comp_test_1_author' => 'Alex Demo',
            'comp_test_1_role' => 'Web Developer',
            'comp_test_2_quote' => 'My client figured out the inline editor in five minutes. That never happened with WordPress.',
            'comp_test_2_author' => 'Jordan Example',
            'comp_test_2_role' => 'Agency Owner',
            'comp_test_3_quote' => 'Flat files mean I can version-control everything with Git. Rollback a content change? Just git revert.',
            'comp_test_3_author' => 'Sam Placeholder',
            'comp_test_3_role' => 'Full-Stack Developer',
            'comp_team_heading' => 'Team Grid',
            'comp_team_desc' => 'A people directory with names, roles, and bios.',
            'comp_team_1_name' => 'Alex Demo',
            'comp_team_1_role' => 'Developer',
            'comp_team_1_bio' => 'Builds things that work. Prefers flat files over fat databases.',
            'comp_team_2_name' => 'Jordan Example',
            'comp_team_2_role' => 'Designer',
            'comp_team_2_bio' => 'Makes pixels behave. Believes good UI is invisible UI.',
            'comp_team_3_name' => 'Sam Placeholder',
            'comp_team_3_role' => 'Documentation',
            'comp_team_3_bio' => 'Writes docs that people actually read. A rare and valuable skill.',
            'comp_team_4_name' => 'Taylor Test',
            'comp_team_4_role' => 'DevOps',
            'comp_team_4_bio' => 'Deploys with rsync and sleeps well at night. Zero downtime advocate.',
            'comp_faq_heading' => 'FAQ Accordion',
            'comp_faq_desc' => 'Collapsible question-and-answer pairs.',
            'comp_faq_1_q' => 'How do I add a new page?',
            'comp_faq_1_a' => 'Create a JSON file in content/pages/ with your page content, or use the admin dashboard to create a new page. The router will automatically serve it at a clean URL.',
            'comp_faq_2_q' => 'Can I use my own design?',
            'comp_faq_2_a' => 'Yes. Edit the CSS custom properties in css/style.css to set your colors, fonts, and spacing. For deeper customization, create a PHP template file in your language directory.',
            'comp_faq_3_q' => 'How do backups work?',
            'comp_faq_3_a' => 'Every time you save content, Nibbly creates a timestamped copy of the JSON file in the backups/ directory. You can restore any previous version from the admin dashboard.',
            'comp_faq_4_q' => 'Is there a build step?',
            'comp_faq_4_a' => 'No. Nibbly is plain PHP — edit a file and the change is live. No compilation, no bundling, no deployment pipeline.',
            'comp_timeline_heading' => 'Timeline',
            'comp_timeline_desc' => 'A vertical changelog for milestones or version history.',
            'comp_tl_1_date' => 'Today',
            'comp_tl_1_title' => 'Installation Complete',
            'comp_tl_1_desc' => 'Nibbly is installed and ready to use. Start editing content directly in the browser.',
            'comp_tl_1_version' => 'v1.0',
            'comp_tl_2_date' => 'Next',
            'comp_tl_2_title' => 'Customize Your Site',
            'comp_tl_2_desc' => 'Replace demo content with your own. Adjust colors, fonts, and layout via CSS custom properties.',
            'comp_tl_2_version' => 'Step 2',
            'comp_tl_3_date' => 'Then',
            'comp_tl_3_title' => 'Go Live',
            'comp_tl_3_desc' => 'Upload to your server and share with the world. Any PHP hosting works.',
            'comp_tl_3_version' => 'Step 3',
            'comp_closing' => '<p>All components above are rendered from JSON data using built-in PHP functions like <code>renderFeatureGrid()</code>, <code>renderFaqAccordion()</code>, <code>renderTeamGrid()</code>, and others. See the documentation for the full list.</p>',

            // News
            'news_title' => 'News',
            'news_page_title' => 'News',
            'news_intro' => 'Latest updates and announcements.',
            'news_back' => '&larr; Back to News',
            'news_post_1_title' => 'Welcome to Your New Website',
            'news_post_1_excerpt' => 'Your Nibbly CMS is installed and ready to go. Here\'s a quick overview of what you can do.',
            'news_post_1_content' => '<p>Congratulations — your website is up and running! This is an example news post to show you how the blog system works.</p><h2>What are news posts?</h2><p>News posts are stored as individual JSON files in the <code>content/news/</code> directory. Each post has a title, date, author, excerpt, optional cover image, and full HTML content.</p><h2>Managing posts</h2><p>You can create, edit, and delete posts from the <a href="admin/">admin dashboard</a>. Posts support rich text editing, image uploads, and a draft/published toggle.</p><p>This demo post can be deleted at any time. Replace it with your own content when you\'re ready.</p>',
            'news_post_1_author' => 'Nibbly CMS',
            'news_post_2_title' => 'Getting Started with Content Editing',
            'news_post_2_excerpt' => 'A quick guide to inline editing, the admin dashboard, and how content is stored.',
            'news_post_2_content' => '<p>Nibbly makes content editing simple. Here are the three main ways to work with your content:</p><h2>1. Inline Editing</h2><p>Log in and click <strong>Edit Page</strong> in the toolbar. Now you can click on any text, image, or link on the page to edit it directly. Changes are saved to JSON files automatically.</p><h2>2. Admin Dashboard</h2><p>The <a href="admin/">admin dashboard</a> gives you an overview of all pages, news posts, images, and site settings. Create new pages, manage media files, and configure your site from one place.</p><h2>3. Direct File Editing</h2><p>Since all content is stored as JSON files, you can also edit them directly in any text editor. This is useful for developers or for bulk changes. Content files live in <code>content/pages/</code> and <code>content/news/</code>.</p>',
            'news_post_2_author' => 'Nibbly CMS',

            // Nav labels
            'nav_home' => 'Home',
            'nav_about' => 'About',
            'nav_blocks' => 'Block Types',
            'nav_components' => 'Components',
            'nav_news' => 'News',
        ],

        'de' => [
            // Homepage
            'home_title' => 'Willkommen bei ' . $siteName,
            'home_intro' => '<p>Deine Website ist bereit. Das hier ist Beispiel-Inhalt zum Starten — bearbeite oder ersetze ihn jederzeit über den <a href="admin/">Admin-Bereich</a> oder direkt auf dieser Seite, wenn du eingeloggt bist.</p>',
            'home_features_title' => 'Das kannst du tun',
            'home_feature_1' => 'Inhalte direkt auf der Seite bearbeiten — einfach klicken und tippen.',
            'home_feature_2' => 'Neue Abschnitte hinzufügen: Texte, Bilder, Zitate, Listen und mehr.',
            'home_feature_3' => 'Alle Inhalte werden als einfache JSON-Dateien gespeichert — keine Datenbank nötig.',
            'home_feature_4' => 'Unterstützt mehrere Sprachen von Haus aus.',
            'home_quote' => 'Das beste CMS ist das, über das man nicht nachdenken muss.',
            'home_quote_author' => 'Ein weiser Entwickler',
            'home_getting_started_title' => 'Erste Schritte',
            'home_getting_started' => '<p>Öffne den <a href="admin/">Admin-Bereich</a>, um Seiten zu verwalten, Bilder hochzuladen und deine Website zu konfigurieren. Du kannst dich auch einloggen und in der Toolbar auf <strong>Seite bearbeiten</strong> klicken, um Änderungen direkt vorzunehmen.</p><p>Schau dir die anderen Demo-Seiten an: <a href="blocks">Block-Typen</a> zeigt jeden Content-Block, <a href="components">Komponenten</a> demonstriert interaktive Widgets, und <a href="about">Über uns</a> ist eine typische Inhaltsseite als Ausgangspunkt.</p>',
            'home_cta_title' => 'Loslegen',
            'home_cta_text' => '<p>Dieser Beispiel-Inhalt kann jederzeit gelöscht werden — entweder im Dashboard oder durch Entfernen der Abschnitte im Inline-Editor. Ersetze ihn durch deine eigenen Inhalte.</p>',
            'home_img_caption' => 'Ersetze diesen Platzhalter durch ein eigenes Bild.',
            'home_card_1_title' => 'Für Websites',
            'home_card_1_desc' => 'Firmenwebsites, Portfolios, Landing Pages — jede inhaltliche Seite, die einfache Bearbeitung ohne Datenbank braucht.',
            'home_card_2_title' => 'Für Kunden',
            'home_card_2_desc' => 'Übergib eine Website, die deine Kunden wirklich pflegen können. Texte anklicken und bearbeiten — keine Schulung nötig.',
            'home_card_3_title' => 'Für Entwickler',
            'home_card_3_desc' => 'Einfache PHP-Templates, JSON-Dateien, CSS Custom Properties. Kein Framework, kein Build-Step, keine Magie.',
            'home_card_4_title' => 'Für KI-Workflows',
            'home_card_4_desc' => 'Lass deine KI mit Nibbly bauen — das Ergebnis ist ein fertiges CMS, kein Prototyp der noch ein Backend braucht.',

            // About page
            'about_title' => 'Über uns',
            'about_heading' => 'Über uns',
            'about_intro' => '<p>Dies ist eine Beispielseite, die zeigt, wie eine typische Inhaltsseite in Nibbly aussieht. Sie verwendet nur Standard-Block-Typen — Überschrift, Text, Bild, Zitat — automatisch aus einer JSON-Datei gerendert.</p>',
            'about_mission_title' => 'Unsere Mission',
            'about_mission' => '<p>Wir glauben, dass Websites einfach zu erstellen und noch einfacher zu pflegen sein sollten. Keine Datenbanken, keine komplexen Deployments, kein Vendor-Lock-in. Nur Dateien auf einem Server, den du besitzt und kontrollierst.</p><p>Jeder Inhalt auf dieser Seite wird als einfache JSON-Datei gespeichert. Du kannst sie in jedem Texteditor öffnen, per Ordnerkopie sichern und per Datei-Upload deployen.</p>',
            'about_quote' => 'Einfachheit ist die höchste Stufe der Vollendung.',
            'about_quote_author' => 'Leonardo da Vinci',
            'about_values_title' => 'Unsere Werte',
            'about_value_1' => 'Einfachheit statt Komplexität',
            'about_value_2' => 'Kontrolle statt Abhängigkeit',
            'about_value_3' => 'Transparenz statt Magie',
            'about_value_4' => 'Zugänglichkeit statt Exklusivität',
            'about_closing' => '<p>Dies ist Demo-Inhalt — ersetze ihn gerne durch deine eigene Geschichte. Du kannst alles direkt auf der Seite bearbeiten, wenn du eingeloggt bist, oder die JSON-Datei unter <code>content/pages/{lang}_about.json</code> anpassen.</p>',

            // Block types demo
            'blocks_title' => 'Block-Typen',
            'blocks_heading' => 'Alle Block-Typen',
            'blocks_intro' => '<p>Diese Seite zeigt jeden verfügbaren Content-Block in Nibbly. Jeder Abschnitt verwendet einen anderen <code>type</code>-Wert in der JSON-Datei. Du kannst sie frei kombinieren, wenn du Seiten erstellst.</p>',
            'blocks_text_heading' => 'Text-Block',
            'blocks_text_title' => 'Rich-Text-Inhalt',
            'blocks_text_content' => '<p>Der Text-Block ist der vielseitigste Block-Typ. Er unterstützt <strong>fett</strong>, <em>kursiv</em>, <a href="#">Links</a> und andere HTML-Formatierungen. Verwende ihn für Absätze, Artikel oder beliebige Freitextinhalte.</p><p>Jeder Text-Block kann einen optionalen Titel haben, der als Überschrift über dem Inhalt erscheint. Das Titelfeld eignet sich gut für Abschnittsüberschriften.</p>',
            'blocks_quote_heading' => 'Zitat-Block',
            'blocks_quote_text' => 'Inhalt ist wie Wasser — er nimmt die Form jedes Behälters an, in den man ihn füllt.',
            'blocks_quote_author' => 'Ein Content-Stratege',
            'blocks_image_heading' => 'Bild-Block',
            'blocks_image_caption' => 'Bilder können volle Breite, mittel oder klein sein. Dieses ist in voller Breite mit Bildunterschrift.',
            'blocks_list_heading' => 'Listen-Block',
            'blocks_list_title' => 'Eigenschaften des Listen-Blocks',
            'blocks_list_1' => 'Aufzählungs- und nummerierte Listen verfügbar',
            'blocks_list_2' => 'Jeder Eintrag ist einzeln bearbeitbar',
            'blocks_list_3' => 'Einträge können hinzugefügt, entfernt und umsortiert werden',
            'blocks_list_4' => 'Ideal für Feature-Listen, Anleitungen oder Checklisten',
            'blocks_card_heading' => 'Karten-Block',
            'blocks_card_1_title' => 'Erste Karte',
            'blocks_card_1_desc' => 'Karten ordnen sich automatisch in einem responsiven Raster an. Jede Karte hat ein Bild, einen Titel und eine Beschreibung.',
            'blocks_card_2_title' => 'Zweite Karte',
            'blocks_card_2_desc' => 'Verwende Karten für Teammitglieder, Dienstleistungen, Portfolio-Einträge oder andere Inhalte, die von einem visuellen Raster profitieren.',
            'blocks_card_3_title' => 'Dritte Karte',
            'blocks_card_3_desc' => 'Alle Karteninhalte sind inline bearbeitbar. Klicke auf einen Text, um ihn zu ändern, oder auf das Bild, um es auszutauschen.',
            'blocks_card_4_title' => 'Vierte Karte',
            'blocks_card_4_desc' => 'Karten bilden je nach Bildschirmbreite ein 2×2- oder 4×1-Raster. Füge so viele hinzu wie du brauchst.',
            'blocks_media_heading' => 'Medien-Blöcke',
            'blocks_media_text' => '<p>Nibbly unterstützt auch <strong>YouTube</strong>-, <strong>SoundCloud</strong>- und <strong>Audio</strong>-Einbettungen. Diese Blöcke akzeptieren eine Video-/Track-ID oder einen Dateipfad und rendern den passenden Player.</p><p>Unten ist ein Beispiel für eine YouTube-Einbettung:</p>',
            'blocks_layout_heading' => 'Layout-Blöcke',
            'blocks_layout_text' => '<p>Zwei Layout-Blöcke helfen bei der Seitenstruktur: <strong>Trennlinie</strong> fügt eine horizontale Linie ein, und <strong>Abstand</strong> fügt vertikalen Raum hinzu (sm, md, lg oder xl). Beide wurden auf dieser Seite bereits verwendet.</p>',
            'blocks_closing' => '<p>Alle diese Block-Typen können über das Admin-Dashboard oder durch direktes Bearbeiten der JSON-Datei zu jeder Seite hinzugefügt werden. Siehe die <a href="components">Komponenten-Seite</a> für interaktive Widgets wie FAQ-Akkordeons, Preistabellen und mehr.</p>',

            // Components page
            'comp_title' => 'Komponenten',
            'comp_heading' => 'Interaktive Komponenten',
            'comp_intro' => 'Neben den grundlegenden Content-Blöcken bietet Nibbly fertige Komponenten für häufige Website-Muster. Jede Komponente wird durch eine PHP-Funktion gerendert und aus JSON-Daten befüllt.',
            'comp_features_heading' => 'Feature-Raster',
            'comp_features_desc' => 'Icon-basierte Feature-Karten in einem responsiven Raster.',
            'comp_feat_1_title' => 'Keine Datenbank',
            'comp_feat_1_desc' => 'Inhalte leben in JSON-Dateien. Backup per Ordnerkopie.',
            'comp_feat_2_title' => 'Inline-Bearbeitung',
            'comp_feat_2_desc' => 'Klicke auf einen Text, um ihn direkt zu bearbeiten. Keine Backend-Formulare.',
            'comp_feat_3_title' => 'Automatische Backups',
            'comp_feat_3_desc' => 'Jedes Speichern erstellt ein Backup mit Zeitstempel.',
            'comp_feat_4_title' => 'Mehrsprachig',
            'comp_feat_4_desc' => 'Eingebaute Unterstützung für mehrere Sprachen. Keine Plugins nötig.',
            'comp_feat_5_title' => 'Null Abhängigkeiten',
            'comp_feat_5_desc' => 'Kein Composer, kein npm. Nur PHP und ein Webserver.',
            'comp_feat_6_title' => 'Saubere URLs',
            'comp_feat_6_desc' => 'SEO-freundliche Pfade auf Apache und dem Dev-Server.',
            'comp_stats_heading' => 'Statistiken',
            'comp_stats_desc' => 'Wichtige Kennzahlen auf einen Blick.',
            'comp_stat_1_value' => '0',
            'comp_stat_1_label' => 'Abhängigkeiten',
            'comp_stat_2_value' => '< 500 KB',
            'comp_stat_2_label' => 'Installationsgröße',
            'comp_stat_3_value' => '11+',
            'comp_stat_3_label' => 'Block-Typen',
            'comp_stat_4_value' => '< 5 Min',
            'comp_stat_4_label' => 'Setup-Zeit',
            'comp_testimonials_heading' => 'Stimmen',
            'comp_testimonials_desc' => 'Kundenzitate als Karten dargestellt.',
            'comp_test_1_quote' => 'Wir haben unser altes CMS an einem Nachmittag ersetzt. Keine Datenbank-Migration, keine Plugin-Konflikte. Einfach hochladen und loslegen.',
            'comp_test_1_author' => 'Alex Demo',
            'comp_test_1_role' => 'Webentwickler',
            'comp_test_2_quote' => 'Mein Kunde hat den Inline-Editor in fünf Minuten verstanden. Das ist mit WordPress nie passiert.',
            'comp_test_2_author' => 'Jordan Beispiel',
            'comp_test_2_role' => 'Agenturinhaber',
            'comp_test_3_quote' => 'Flat Files bedeuten: alles mit Git versionieren. Änderung rückgängig machen? Einfach git revert.',
            'comp_test_3_author' => 'Sam Platzhalter',
            'comp_test_3_role' => 'Full-Stack-Entwickler',
            'comp_team_heading' => 'Team-Raster',
            'comp_team_desc' => 'Ein Personenverzeichnis mit Namen, Rollen und Beschreibungen.',
            'comp_team_1_name' => 'Alex Demo',
            'comp_team_1_role' => 'Entwicklung',
            'comp_team_1_bio' => 'Baut Dinge, die funktionieren. Bevorzugt Flat Files gegenüber fetten Datenbanken.',
            'comp_team_2_name' => 'Jordan Beispiel',
            'comp_team_2_role' => 'Design',
            'comp_team_2_bio' => 'Bringt Pixel in Ordnung. Glaubt, dass gutes UI unsichtbar ist.',
            'comp_team_3_name' => 'Sam Platzhalter',
            'comp_team_3_role' => 'Dokumentation',
            'comp_team_3_bio' => 'Schreibt Dokumentation, die Leute tatsächlich lesen. Eine seltene Gabe.',
            'comp_team_4_name' => 'Taylor Test',
            'comp_team_4_role' => 'DevOps',
            'comp_team_4_bio' => 'Deployt mit rsync und schläft gut. Null-Downtime-Verfechter.',
            'comp_faq_heading' => 'FAQ-Akkordeon',
            'comp_faq_desc' => 'Aufklappbare Frage-Antwort-Paare.',
            'comp_faq_1_q' => 'Wie füge ich eine neue Seite hinzu?',
            'comp_faq_1_a' => 'Erstelle eine JSON-Datei in content/pages/ mit deinem Seiteninhalt oder nutze das Admin-Dashboard. Der Router stellt sie automatisch unter einer sauberen URL bereit.',
            'comp_faq_2_q' => 'Kann ich mein eigenes Design verwenden?',
            'comp_faq_2_a' => 'Ja. Bearbeite die CSS Custom Properties in css/style.css für Farben, Schriften und Abstände. Für tiefere Anpassungen erstelle eine PHP-Template-Datei in deinem Sprachverzeichnis.',
            'comp_faq_3_q' => 'Wie funktionieren Backups?',
            'comp_faq_3_a' => 'Bei jedem Speichern erstellt Nibbly eine Kopie der JSON-Datei mit Zeitstempel im backups/-Verzeichnis. Du kannst jede frühere Version über das Admin-Dashboard wiederherstellen.',
            'comp_faq_4_q' => 'Gibt es einen Build-Step?',
            'comp_faq_4_a' => 'Nein. Nibbly ist reines PHP — bearbeite eine Datei und die Änderung ist sofort live. Keine Kompilierung, kein Bundling, keine Deployment-Pipeline.',
            'comp_timeline_heading' => 'Timeline',
            'comp_timeline_desc' => 'Ein vertikaler Zeitstrahl für Meilensteine oder Versionshistorie.',
            'comp_tl_1_date' => 'Heute',
            'comp_tl_1_title' => 'Installation abgeschlossen',
            'comp_tl_1_desc' => 'Nibbly ist installiert und einsatzbereit. Beginne mit der Bearbeitung direkt im Browser.',
            'comp_tl_1_version' => 'v1.0',
            'comp_tl_2_date' => 'Als Nächstes',
            'comp_tl_2_title' => 'Website anpassen',
            'comp_tl_2_desc' => 'Ersetze Demo-Inhalte durch eigene. Passe Farben, Schriften und Layout über CSS Custom Properties an.',
            'comp_tl_2_version' => 'Schritt 2',
            'comp_tl_3_date' => 'Dann',
            'comp_tl_3_title' => 'Online gehen',
            'comp_tl_3_desc' => 'Lade die Dateien auf deinen Server und teile die Seite mit der Welt. Jedes PHP-Hosting funktioniert.',
            'comp_tl_3_version' => 'Schritt 3',
            'comp_closing' => '<p>Alle Komponenten oben werden aus JSON-Daten gerendert, mit eingebauten PHP-Funktionen wie <code>renderFeatureGrid()</code>, <code>renderFaqAccordion()</code>, <code>renderTeamGrid()</code> und anderen. Siehe die Dokumentation für die vollständige Liste.</p>',

            // News
            'news_title' => 'Neuigkeiten',
            'news_page_title' => 'Neuigkeiten',
            'news_intro' => 'Aktuelle Meldungen und Ankündigungen.',
            'news_back' => '&larr; Zurück zu Neuigkeiten',
            'news_post_1_title' => 'Willkommen auf deiner neuen Website',
            'news_post_1_excerpt' => 'Dein Nibbly CMS ist installiert und einsatzbereit. Hier ein kurzer Überblick, was du tun kannst.',
            'news_post_1_content' => '<p>Herzlichen Glückwunsch — deine Website läuft! Dies ist ein Beispiel-Newsbeitrag, der zeigt, wie das Blog-System funktioniert.</p><h2>Was sind News-Beiträge?</h2><p>News-Beiträge werden als einzelne JSON-Dateien im Verzeichnis <code>content/news/</code> gespeichert. Jeder Beitrag hat einen Titel, ein Datum, einen Autor, eine Kurzfassung, ein optionales Titelbild und HTML-Inhalt.</p><h2>Beiträge verwalten</h2><p>Du kannst Beiträge im <a href="admin/">Admin-Dashboard</a> erstellen, bearbeiten und löschen. Beiträge unterstützen Rich-Text-Bearbeitung, Bild-Upload und einen Entwurf/Veröffentlicht-Schalter.</p><p>Dieser Demo-Beitrag kann jederzeit gelöscht werden. Ersetze ihn durch deine eigenen Inhalte.</p>',
            'news_post_1_author' => 'Nibbly CMS',
            'news_post_2_title' => 'Erste Schritte mit der Inhaltsbearbeitung',
            'news_post_2_excerpt' => 'Eine kurze Anleitung zu Inline-Bearbeitung, Admin-Dashboard und Inhaltsspeicherung.',
            'news_post_2_content' => '<p>Nibbly macht die Inhaltsbearbeitung einfach. Hier sind die drei wichtigsten Wege, mit deinen Inhalten zu arbeiten:</p><h2>1. Inline-Bearbeitung</h2><p>Logge dich ein und klicke auf <strong>Seite bearbeiten</strong> in der Toolbar. Jetzt kannst du jeden Text, jedes Bild oder jeden Link auf der Seite direkt bearbeiten. Änderungen werden automatisch in JSON-Dateien gespeichert.</p><h2>2. Admin-Dashboard</h2><p>Das <a href="admin/">Admin-Dashboard</a> gibt dir einen Überblick über alle Seiten, News-Beiträge, Bilder und Website-Einstellungen. Erstelle neue Seiten, verwalte Mediendateien und konfiguriere deine Website an einem Ort.</p><h2>3. Direkte Dateibearbeitung</h2><p>Da alle Inhalte als JSON-Dateien gespeichert sind, kannst du sie auch direkt in einem Texteditor bearbeiten. Das ist nützlich für Entwickler oder Massenänderungen. Inhaltsdateien liegen in <code>content/pages/</code> und <code>content/news/</code>.</p>',
            'news_post_2_author' => 'Nibbly CMS',

            // Nav labels
            'nav_home' => 'Startseite',
            'nav_about' => 'Über uns',
            'nav_blocks' => 'Block-Typen',
            'nav_components' => 'Komponenten',
            'nav_news' => 'Neuigkeiten',
        ],

        'es' => [
            // Homepage
            'home_title' => 'Bienvenido a ' . $siteName,
            'home_intro' => '<p>Tu sitio web está listo. Este es contenido de ejemplo — edítalo o reemplázalo en cualquier momento desde el <a href="admin/">panel de administración</a> o directamente en esta página cuando estés conectado.</p>',
            'home_features_title' => 'Lo que puedes hacer',
            'home_feature_1' => 'Edita el contenido directamente en la página — solo haz clic y escribe.',
            'home_feature_2' => 'Añade nuevas secciones: textos, imágenes, citas, listas y más.',
            'home_feature_3' => 'Todo el contenido se almacena como archivos JSON — no necesitas base de datos.',
            'home_feature_4' => 'Soporte multilingüe incluido de serie.',
            'home_quote' => 'El mejor CMS es aquel en el que no tienes que pensar.',
            'home_quote_author' => 'Un desarrollador sabio',
            'home_getting_started_title' => 'Primeros pasos',
            'home_getting_started' => '<p>Abre el <a href="admin/">panel de administración</a> para gestionar páginas, subir imágenes y configurar tu sitio. También puedes iniciar sesión y hacer clic en <strong>Editar página</strong> en la barra de herramientas para hacer cambios directamente.</p><p>Explora las otras páginas de demostración: <a href="blocks">Tipos de bloque</a> muestra cada bloque de contenido, <a href="components">Componentes</a> demuestra widgets interactivos, y <a href="about">Acerca de</a> es una página de contenido típica como punto de partida.</p>',
            'home_cta_title' => 'Empezar a construir',
            'home_cta_text' => '<p>Este contenido de ejemplo se puede eliminar en cualquier momento — desde el panel o eliminando secciones en el editor inline. Reemplázalo con tu propio contenido.</p>',
            'home_img_caption' => 'Reemplaza este marcador con tu propia imagen.',
            'home_card_1_title' => 'Para sitios web',
            'home_card_1_desc' => 'Sitios corporativos, portfolios, landing pages — cualquier sitio que necesite edición fácil sin base de datos.',
            'home_card_2_title' => 'Para clientes',
            'home_card_2_desc' => 'Entrega un sitio que tus clientes realmente puedan mantener. Clic para editar texto, sin necesidad de formación.',
            'home_card_3_title' => 'Para desarrolladores',
            'home_card_3_desc' => 'Templates PHP, archivos JSON, CSS Custom Properties. Sin framework, sin build step, sin magia.',
            'home_card_4_title' => 'Para flujos con IA',
            'home_card_4_desc' => 'Dile a tu IA que construya con Nibbly — el resultado es un CMS funcional, no un prototipo que necesita backend.',

            // About page
            'about_title' => 'Acerca de',
            'about_heading' => 'Acerca de nosotros',
            'about_intro' => '<p>Esta es una página de ejemplo que muestra cómo se ve una página de contenido típica en Nibbly. Usa solo tipos de bloque estándar — encabezado, texto, imagen, cita — renderizados automáticamente desde un archivo JSON.</p>',
            'about_mission_title' => 'Nuestra misión',
            'about_mission' => '<p>Creemos que los sitios web deben ser fáciles de construir y aún más fáciles de mantener. Sin bases de datos, sin despliegues complejos, sin dependencia de proveedores. Solo archivos en un servidor que tú controlas.</p><p>Cada contenido de este sitio se almacena como un archivo JSON plano. Puedes abrirlo en cualquier editor de texto, hacer backup copiando una carpeta y desplegar subiendo archivos.</p>',
            'about_quote' => 'La simplicidad es la sofisticación suprema.',
            'about_quote_author' => 'Leonardo da Vinci',
            'about_values_title' => 'Nuestros valores',
            'about_value_1' => 'Simplicidad sobre complejidad',
            'about_value_2' => 'Control sobre dependencia',
            'about_value_3' => 'Transparencia sobre magia',
            'about_value_4' => 'Accesibilidad sobre exclusividad',
            'about_closing' => '<p>Este es contenido de demostración — reemplázalo con tu propia historia. Puedes editar todo directamente en la página cuando estés conectado, o modificar el archivo JSON en <code>content/pages/{lang}_about.json</code>.</p>',

            // Block types demo
            'blocks_title' => 'Tipos de bloque',
            'blocks_heading' => 'Todos los tipos de bloque',
            'blocks_intro' => '<p>Esta página demuestra cada bloque de contenido disponible en Nibbly. Cada sección usa un valor <code>type</code> diferente en el archivo JSON. Puedes combinarlos libremente al crear páginas.</p>',
            'blocks_text_heading' => 'Bloque de texto',
            'blocks_text_title' => 'Contenido con formato',
            'blocks_text_content' => '<p>El bloque de texto es el tipo más versátil. Soporta <strong>negrita</strong>, <em>cursiva</em>, <a href="#">enlaces</a> y otros formatos HTML. Úsalo para párrafos, artículos o cualquier contenido libre.</p><p>Cada bloque de texto puede tener un título opcional que aparece como encabezado sobre el contenido.</p>',
            'blocks_quote_heading' => 'Bloque de cita',
            'blocks_quote_text' => 'El contenido es como el agua — toma la forma de cualquier recipiente en el que lo pongas.',
            'blocks_quote_author' => 'Un estratega de contenido',
            'blocks_image_heading' => 'Bloque de imagen',
            'blocks_image_caption' => 'Las imágenes pueden ser de ancho completo, medio o pequeño. Esta es de ancho completo con pie de foto.',
            'blocks_list_heading' => 'Bloque de lista',
            'blocks_list_title' => 'Características del bloque de lista',
            'blocks_list_1' => 'Estilos de lista con viñetas y numerada disponibles',
            'blocks_list_2' => 'Cada elemento es editable individualmente',
            'blocks_list_3' => 'Los elementos se pueden añadir, eliminar y reordenar',
            'blocks_list_4' => 'Ideal para listas de características, pasos o checklists',
            'blocks_card_heading' => 'Bloque de tarjeta',
            'blocks_card_1_title' => 'Primera tarjeta',
            'blocks_card_1_desc' => 'Las tarjetas se organizan automáticamente en una cuadrícula responsiva. Cada tarjeta tiene imagen, título y descripción.',
            'blocks_card_2_title' => 'Segunda tarjeta',
            'blocks_card_2_desc' => 'Usa tarjetas para miembros del equipo, servicios, portfolio u otro contenido que se beneficie de un diseño visual en cuadrícula.',
            'blocks_card_3_title' => 'Tercera tarjeta',
            'blocks_card_3_desc' => 'Todo el contenido de las tarjetas es editable inline. Haz clic en cualquier texto para cambiarlo o en la imagen para reemplazarla.',
            'blocks_card_4_title' => 'Cuarta tarjeta',
            'blocks_card_4_desc' => 'Las tarjetas forman una cuadrícula 2×2 o 4×1 según el ancho de pantalla. Añade tantas como necesites.',
            'blocks_media_heading' => 'Bloques de medios',
            'blocks_media_text' => '<p>Nibbly también soporta incrustaciones de <strong>YouTube</strong>, <strong>SoundCloud</strong> y <strong>audio</strong>. Estos bloques aceptan un ID de video/pista o una ruta de archivo y renderizan el reproductor adecuado.</p><p>Abajo hay un ejemplo de incrustación de YouTube:</p>',
            'blocks_layout_heading' => 'Bloques de diseño',
            'blocks_layout_text' => '<p>Dos bloques de diseño ayudan a estructurar tu página: <strong>Separador</strong> añade una línea horizontal, y <strong>Espaciador</strong> añade espacio vertical (sm, md, lg o xl). Ambos se han usado a lo largo de esta página.</p>',
            'blocks_closing' => '<p>Todos estos tipos de bloque se pueden añadir a cualquier página desde el dashboard o editando directamente el archivo JSON. Ve la página de <a href="components">Componentes</a> para widgets interactivos como acordeones FAQ, tablas de precios y más.</p>',

            // Components page
            'comp_title' => 'Componentes',
            'comp_heading' => 'Componentes interactivos',
            'comp_intro' => 'Además de los bloques de contenido básicos, Nibbly incluye componentes listos para usar en patrones web comunes. Cada componente se renderiza con una función PHP y se alimenta de datos JSON.',
            'comp_features_heading' => 'Cuadrícula de características',
            'comp_features_desc' => 'Tarjetas con iconos en una cuadrícula responsiva.',
            'comp_feat_1_title' => 'Sin base de datos',
            'comp_feat_1_desc' => 'El contenido vive en archivos JSON. Backup copiando una carpeta.',
            'comp_feat_2_title' => 'Edición inline',
            'comp_feat_2_desc' => 'Haz clic en cualquier texto para editarlo. Sin formularios backend.',
            'comp_feat_3_title' => 'Backups automáticos',
            'comp_feat_3_desc' => 'Cada guardado crea un backup con marca de tiempo.',
            'comp_feat_4_title' => 'Multilingüe',
            'comp_feat_4_desc' => 'Soporte integrado para múltiples idiomas. Sin plugins.',
            'comp_feat_5_title' => 'Cero dependencias',
            'comp_feat_5_desc' => 'Sin Composer, sin npm. Solo PHP y un servidor web.',
            'comp_feat_6_title' => 'URLs limpias',
            'comp_feat_6_desc' => 'Rutas amigables para SEO en Apache y el servidor de desarrollo.',
            'comp_stats_heading' => 'Estadísticas',
            'comp_stats_desc' => 'Cifras clave de un vistazo.',
            'comp_stat_1_value' => '0',
            'comp_stat_1_label' => 'Dependencias',
            'comp_stat_2_value' => '< 500 KB',
            'comp_stat_2_label' => 'Tamaño',
            'comp_stat_3_value' => '11+',
            'comp_stat_3_label' => 'Tipos de bloque',
            'comp_stat_4_value' => '< 5 min',
            'comp_stat_4_label' => 'Instalación',
            'comp_testimonials_heading' => 'Testimonios',
            'comp_testimonials_desc' => 'Citas de clientes como tarjetas.',
            'comp_test_1_quote' => 'Reemplazamos nuestro viejo CMS en una tarde. Sin migración de base de datos, sin conflictos de plugins. Solo subir y listo.',
            'comp_test_1_author' => 'Alex Demo',
            'comp_test_1_role' => 'Desarrollador web',
            'comp_test_2_quote' => 'Mi cliente entendió el editor inline en cinco minutos. Eso nunca pasó con WordPress.',
            'comp_test_2_author' => 'Jordan Ejemplo',
            'comp_test_2_role' => 'Dueño de agencia',
            'comp_test_3_quote' => 'Archivos planos significan que puedo versionar todo con Git. ¿Revertir un cambio? Solo git revert.',
            'comp_test_3_author' => 'Sam Marcador',
            'comp_test_3_role' => 'Desarrollador full-stack',
            'comp_team_heading' => 'Cuadrícula de equipo',
            'comp_team_desc' => 'Un directorio de personas con nombres, roles y biografías.',
            'comp_team_1_name' => 'Alex Demo',
            'comp_team_1_role' => 'Desarrollo',
            'comp_team_1_bio' => 'Construye cosas que funcionan. Prefiere archivos planos a bases de datos gordas.',
            'comp_team_2_name' => 'Jordan Ejemplo',
            'comp_team_2_role' => 'Diseño',
            'comp_team_2_bio' => 'Hace que los píxeles se comporten. Cree que una buena UI es invisible.',
            'comp_team_3_name' => 'Sam Marcador',
            'comp_team_3_role' => 'Documentación',
            'comp_team_3_bio' => 'Escribe documentación que la gente realmente lee. Una habilidad rara.',
            'comp_team_4_name' => 'Taylor Test',
            'comp_team_4_role' => 'DevOps',
            'comp_team_4_bio' => 'Despliega con rsync y duerme tranquilo. Defensor del cero tiempo de inactividad.',
            'comp_faq_heading' => 'Acordeón FAQ',
            'comp_faq_desc' => 'Pares de preguntas y respuestas desplegables.',
            'comp_faq_1_q' => '¿Cómo añado una nueva página?',
            'comp_faq_1_a' => 'Crea un archivo JSON en content/pages/ con tu contenido, o usa el dashboard de administración. El router lo servirá automáticamente en una URL limpia.',
            'comp_faq_2_q' => '¿Puedo usar mi propio diseño?',
            'comp_faq_2_a' => 'Sí. Edita las CSS Custom Properties en css/style.css para colores, fuentes y espaciado. Para personalización profunda, crea un archivo de template PHP en tu directorio de idioma.',
            'comp_faq_3_q' => '¿Cómo funcionan los backups?',
            'comp_faq_3_a' => 'Cada vez que guardas, Nibbly crea una copia con marca de tiempo en el directorio backups/. Puedes restaurar cualquier versión anterior desde el dashboard.',
            'comp_faq_4_q' => '¿Hay un paso de compilación?',
            'comp_faq_4_a' => 'No. Nibbly es PHP puro — edita un archivo y el cambio está en vivo. Sin compilación, sin bundling, sin pipeline de despliegue.',
            'comp_timeline_heading' => 'Línea de tiempo',
            'comp_timeline_desc' => 'Una línea de tiempo vertical para hitos o historial de versiones.',
            'comp_tl_1_date' => 'Hoy',
            'comp_tl_1_title' => 'Instalación completada',
            'comp_tl_1_desc' => 'Nibbly está instalado y listo. Comienza a editar contenido directamente en el navegador.',
            'comp_tl_1_version' => 'v1.0',
            'comp_tl_2_date' => 'Siguiente',
            'comp_tl_2_title' => 'Personaliza tu sitio',
            'comp_tl_2_desc' => 'Reemplaza el contenido demo con el tuyo. Ajusta colores, fuentes y diseño con CSS Custom Properties.',
            'comp_tl_2_version' => 'Paso 2',
            'comp_tl_3_date' => 'Después',
            'comp_tl_3_title' => 'Publicar',
            'comp_tl_3_desc' => 'Sube los archivos a tu servidor y compártelo con el mundo. Cualquier hosting PHP funciona.',
            'comp_tl_3_version' => 'Paso 3',
            'comp_closing' => '<p>Todos los componentes de arriba se renderizan desde datos JSON con funciones PHP integradas como <code>renderFeatureGrid()</code>, <code>renderFaqAccordion()</code>, <code>renderTeamGrid()</code> y otras. Consulta la documentación para la lista completa.</p>',

            // News
            'news_title' => 'Noticias',
            'news_page_title' => 'Noticias',
            'news_intro' => 'Últimas novedades y anuncios.',
            'news_back' => '&larr; Volver a Noticias',
            'news_post_1_title' => 'Bienvenido a tu nuevo sitio web',
            'news_post_1_excerpt' => 'Tu Nibbly CMS está instalado y listo. Aquí tienes un resumen de lo que puedes hacer.',
            'news_post_1_content' => '<p>¡Felicidades — tu sitio web está funcionando! Esta es una publicación de ejemplo para mostrarte cómo funciona el sistema de blog.</p><h2>¿Qué son las publicaciones?</h2><p>Las publicaciones se almacenan como archivos JSON individuales en el directorio <code>content/news/</code>. Cada publicación tiene título, fecha, autor, extracto, imagen de portada opcional y contenido HTML.</p><h2>Gestionar publicaciones</h2><p>Puedes crear, editar y eliminar publicaciones desde el <a href="admin/">panel de administración</a>. Las publicaciones soportan edición de texto enriquecido, subida de imágenes y un interruptor de borrador/publicado.</p><p>Esta publicación demo se puede eliminar en cualquier momento. Reemplázala con tu propio contenido.</p>',
            'news_post_1_author' => 'Nibbly CMS',
            'news_post_2_title' => 'Primeros pasos con la edición de contenido',
            'news_post_2_excerpt' => 'Una guía rápida sobre edición inline, el dashboard y cómo se almacena el contenido.',
            'news_post_2_content' => '<p>Nibbly hace que la edición de contenido sea simple. Aquí están las tres formas principales de trabajar con tu contenido:</p><h2>1. Edición inline</h2><p>Inicia sesión y haz clic en <strong>Editar página</strong> en la barra de herramientas. Ahora puedes hacer clic en cualquier texto, imagen o enlace para editarlo directamente. Los cambios se guardan automáticamente en archivos JSON.</p><h2>2. Panel de administración</h2><p>El <a href="admin/">panel de administración</a> te da una vista general de todas las páginas, publicaciones, imágenes y configuración del sitio.</p><h2>3. Edición directa de archivos</h2><p>Como todo el contenido se almacena en JSON, también puedes editarlo directamente en cualquier editor de texto. Los archivos de contenido están en <code>content/pages/</code> y <code>content/news/</code>.</p>',
            'news_post_2_author' => 'Nibbly CMS',

            // Nav labels
            'nav_home' => 'Inicio',
            'nav_about' => 'Acerca de',
            'nav_blocks' => 'Tipos de bloque',
            'nav_components' => 'Componentes',
            'nav_news' => 'Noticias',
        ],
    ];
}

// ============================================================
// Content generators — each returns a complete JSON-ready array
// ============================================================

/**
 * Enhanced homepage content.
 */
function getHomeContent($lang, $siteName, $t) {
    $now = date('c');
    $rid = function() { return 'section_' . bin2hex(random_bytes(4)); };

    return [
        'page' => $lang . '_home',
        'lang' => $lang,
        'title' => $siteName,
        'lastModified' => $now,
        'sections' => [
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['home_title'],
                'level' => 'h1',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['home_intro'],
            ],
            [
                'id' => $rid(),
                'type' => 'image',
                'src' => 'https://picsum.photos/1200/600?random=1',
                'alt' => $siteName,
                'caption' => $t['home_img_caption'],
            ],
            [
                'id' => $rid(),
                'type' => 'spacer',
                'size' => 'sm',
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/250?random=1',
                'title' => $t['home_card_1_title'],
                'description' => $t['home_card_1_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/250?random=2',
                'title' => $t['home_card_2_title'],
                'description' => $t['home_card_2_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/250?random=3',
                'title' => $t['home_card_3_title'],
                'description' => $t['home_card_3_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/250?random=4',
                'title' => $t['home_card_4_title'],
                'description' => $t['home_card_4_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'spacer',
                'size' => 'sm',
            ],
            [
                'id' => $rid(),
                'type' => 'list',
                'title' => $t['home_features_title'],
                'items' => [
                    ['text' => $t['home_feature_1']],
                    ['text' => $t['home_feature_2']],
                    ['text' => $t['home_feature_3']],
                    ['text' => $t['home_feature_4']],
                ],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            [
                'id' => $rid(),
                'type' => 'quote',
                'text' => $t['home_quote'],
                'attribution' => $t['home_quote_author'],
            ],
            [
                'id' => $rid(),
                'type' => 'spacer',
                'size' => 'md',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => $t['home_getting_started_title'],
                'content' => $t['home_getting_started'],
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => $t['home_cta_title'],
                'content' => $t['home_cta_text'],
            ],
        ],
    ];
}

/**
 * About page content (JSON only, served by front controller).
 */
function getAboutContent($lang, $t) {
    $now = date('c');
    $rid = function() { return 'section_' . bin2hex(random_bytes(4)); };

    return [
        'page' => $lang . '_about',
        'lang' => $lang,
        'title' => $t['about_title'],
        'description' => '',
        'lastModified' => $now,
        'sections' => [
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['about_heading'],
                'level' => 'h1',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['about_intro'],
            ],
            [
                'id' => $rid(),
                'type' => 'image',
                'src' => 'https://picsum.photos/1200/600?random=2',
                'alt' => $t['about_heading'],
                'caption' => '',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => $t['about_mission_title'],
                'content' => $t['about_mission'],
            ],
            [
                'id' => $rid(),
                'type' => 'quote',
                'text' => $t['about_quote'],
                'attribution' => $t['about_quote_author'],
            ],
            [
                'id' => $rid(),
                'type' => 'list',
                'title' => $t['about_values_title'],
                'items' => [
                    ['text' => $t['about_value_1']],
                    ['text' => $t['about_value_2']],
                    ['text' => $t['about_value_3']],
                    ['text' => $t['about_value_4']],
                ],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['about_closing'],
            ],
        ],
    ];
}

/**
 * Block types demo page content (JSON only, served by front controller).
 */
function getBlocksContent($lang, $t) {
    $now = date('c');
    $rid = function() { return 'section_' . bin2hex(random_bytes(4)); };

    return [
        'page' => $lang . '_blocks',
        'lang' => $lang,
        'title' => $t['blocks_title'],
        'description' => '',
        'lastModified' => $now,
        'sections' => [
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_heading'],
                'level' => 'h1',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['blocks_intro'],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Text block
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_text_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => $t['blocks_text_title'],
                'content' => $t['blocks_text_content'],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Quote block
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_quote_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'quote',
                'text' => $t['blocks_quote_text'],
                'attribution' => $t['blocks_quote_author'],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Image block
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_image_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'image',
                'src' => 'https://picsum.photos/1200/600?random=3',
                'alt' => 'Placeholder image',
                'caption' => $t['blocks_image_caption'],
                'width' => 'full',
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // List block
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_list_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'list',
                'title' => $t['blocks_list_title'],
                'items' => [
                    ['text' => $t['blocks_list_1']],
                    ['text' => $t['blocks_list_2']],
                    ['text' => $t['blocks_list_3']],
                    ['text' => $t['blocks_list_4']],
                ],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Card block
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_card_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/300?random=5',
                'title' => $t['blocks_card_1_title'],
                'description' => $t['blocks_card_1_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/300?random=6',
                'title' => $t['blocks_card_2_title'],
                'description' => $t['blocks_card_2_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/300?random=7',
                'title' => $t['blocks_card_3_title'],
                'description' => $t['blocks_card_3_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'card',
                'image' => 'https://picsum.photos/400/300?random=8',
                'title' => $t['blocks_card_4_title'],
                'description' => $t['blocks_card_4_desc'],
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Media blocks
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_media_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['blocks_media_text'],
            ],
            [
                'id' => $rid(),
                'type' => 'youtube',
                'videoId' => 'dQw4w9WgXcQ',
                'title' => 'YouTube Embed',
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Layout blocks
            [
                'id' => $rid(),
                'type' => 'heading',
                'text' => $t['blocks_layout_heading'],
                'level' => 'h2',
            ],
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['blocks_layout_text'],
            ],
            [
                'id' => $rid(),
                'type' => 'spacer',
                'size' => 'lg',
            ],
            [
                'id' => $rid(),
                'type' => 'divider',
            ],
            // Closing
            [
                'id' => $rid(),
                'type' => 'text',
                'title' => '',
                'content' => $t['blocks_closing'],
            ],
        ],
    ];
}

/**
 * Components page content (JSON data for the custom PHP template).
 */
function getComponentsContent($lang, $t) {
    $now = date('c');

    return [
        'page' => $lang . '_components',
        'lang' => $lang,
        'title' => $t['comp_title'],
        'description' => '',
        'lastModified' => $now,
        'title_text' => $t['comp_heading'],
        'intro' => $t['comp_intro'],
        'features' => [
            'heading' => $t['comp_features_heading'],
            'desc' => $t['comp_features_desc'],
            'items' => [
                ['icon' => 'database', 'title' => $t['comp_feat_1_title'], 'desc' => $t['comp_feat_1_desc']],
                ['icon' => 'edit', 'title' => $t['comp_feat_2_title'], 'desc' => $t['comp_feat_2_desc']],
                ['icon' => 'backup', 'title' => $t['comp_feat_3_title'], 'desc' => $t['comp_feat_3_desc']],
                ['icon' => 'globe', 'title' => $t['comp_feat_4_title'], 'desc' => $t['comp_feat_4_desc']],
                ['icon' => 'feather', 'title' => $t['comp_feat_5_title'], 'desc' => $t['comp_feat_5_desc']],
                ['icon' => 'link', 'title' => $t['comp_feat_6_title'], 'desc' => $t['comp_feat_6_desc']],
            ],
        ],
        'stats' => [
            'heading' => $t['comp_stats_heading'],
            'desc' => $t['comp_stats_desc'],
            'items' => [
                ['value' => $t['comp_stat_1_value'], 'label' => $t['comp_stat_1_label']],
                ['value' => $t['comp_stat_2_value'], 'label' => $t['comp_stat_2_label']],
                ['value' => $t['comp_stat_3_value'], 'label' => $t['comp_stat_3_label']],
                ['value' => $t['comp_stat_4_value'], 'label' => $t['comp_stat_4_label']],
            ],
        ],
        'testimonials' => [
            'heading' => $t['comp_testimonials_heading'],
            'desc' => $t['comp_testimonials_desc'],
            'items' => [
                ['quote' => $t['comp_test_1_quote'], 'author' => $t['comp_test_1_author'], 'role' => $t['comp_test_1_role']],
                ['quote' => $t['comp_test_2_quote'], 'author' => $t['comp_test_2_author'], 'role' => $t['comp_test_2_role']],
                ['quote' => $t['comp_test_3_quote'], 'author' => $t['comp_test_3_author'], 'role' => $t['comp_test_3_role']],
            ],
        ],
        'team' => [
            'heading' => $t['comp_team_heading'],
            'desc' => $t['comp_team_desc'],
            'members' => [
                [
                    'image' => ['src' => 'https://thispersondoesnotexist.com/#1', 'alt' => $t['comp_team_1_name']],
                    'name' => $t['comp_team_1_name'],
                    'role' => $t['comp_team_1_role'],
                    'bio' => $t['comp_team_1_bio'],
                ],
                [
                    'image' => ['src' => 'https://thispersondoesnotexist.com/#2', 'alt' => $t['comp_team_2_name']],
                    'name' => $t['comp_team_2_name'],
                    'role' => $t['comp_team_2_role'],
                    'bio' => $t['comp_team_2_bio'],
                ],
                [
                    'image' => ['src' => 'https://thispersondoesnotexist.com/#3', 'alt' => $t['comp_team_3_name']],
                    'name' => $t['comp_team_3_name'],
                    'role' => $t['comp_team_3_role'],
                    'bio' => $t['comp_team_3_bio'],
                ],
                [
                    'image' => ['src' => 'https://thispersondoesnotexist.com/#4', 'alt' => $t['comp_team_4_name']],
                    'name' => $t['comp_team_4_name'],
                    'role' => $t['comp_team_4_role'],
                    'bio' => $t['comp_team_4_bio'],
                ],
            ],
        ],
        'faq' => [
            'heading' => $t['comp_faq_heading'],
            'desc' => $t['comp_faq_desc'],
            'entries' => [
                ['question' => $t['comp_faq_1_q'], 'answer' => $t['comp_faq_1_a']],
                ['question' => $t['comp_faq_2_q'], 'answer' => $t['comp_faq_2_a']],
                ['question' => $t['comp_faq_3_q'], 'answer' => $t['comp_faq_3_a']],
                ['question' => $t['comp_faq_4_q'], 'answer' => $t['comp_faq_4_a']],
            ],
        ],
        'timeline' => [
            'heading' => $t['comp_timeline_heading'],
            'desc' => $t['comp_timeline_desc'],
            'entries' => [
                ['date' => $t['comp_tl_1_date'], 'version' => $t['comp_tl_1_version'], 'title' => $t['comp_tl_1_title'], 'desc' => $t['comp_tl_1_desc'], 'status' => 'released'],
                ['date' => $t['comp_tl_2_date'], 'version' => $t['comp_tl_2_version'], 'title' => $t['comp_tl_2_title'], 'desc' => $t['comp_tl_2_desc'], 'status' => 'upcoming'],
                ['date' => $t['comp_tl_3_date'], 'version' => $t['comp_tl_3_version'], 'title' => $t['comp_tl_3_title'], 'desc' => $t['comp_tl_3_desc'], 'status' => 'upcoming'],
            ],
        ],
        'closing' => $t['comp_closing'],
    ];
}

// ============================================================
// PHP template generators
// ============================================================

/**
 * Returns the PHP template string for the components page.
 */
function getComponentsTemplate($lang, $title) {
    return <<<'PHPTPL'
<?php
$pageTitle = '{TITLE}';
$pageDescription = '';
$currentLang = '{LANG}';
$currentPage = 'components';
$contentPage = '{LANG}_components';
if (!isset($basePath)) $basePath = '../';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';

$_p = $contentPage;
?>

    <main class="main-content">
        <div class="content-inner">
            <h1><?php echo editableText($_p, 'title_text', 'Components'); ?></h1>
            <p class="page-intro"><?php echo editableText($_p, 'intro', ''); ?></p>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'features.heading', 'Features'); ?></h2>
                <p><?php echo editableText($_p, 'features.desc', ''); ?></p>
                <?php echo renderFeatureGrid($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'stats.heading', 'Stats'); ?></h2>
                <p><?php echo editableText($_p, 'stats.desc', ''); ?></p>
                <?php echo renderStats($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'testimonials.heading', 'Testimonials'); ?></h2>
                <p><?php echo editableText($_p, 'testimonials.desc', ''); ?></p>
                <?php echo renderTestimonials($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'team.heading', 'Team'); ?></h2>
                <p><?php echo editableText($_p, 'team.desc', ''); ?></p>
                <?php echo renderTeamGrid($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'faq.heading', 'FAQ'); ?></h2>
                <p><?php echo editableText($_p, 'faq.desc', ''); ?></p>
                <?php echo renderFaqAccordion($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'timeline.heading', 'Timeline'); ?></h2>
                <p><?php echo editableText($_p, 'timeline.desc', ''); ?></p>
                <?php echo renderTimeline($_p); ?>
            </section>

            <section class="demo-section">
                <h2><?php echo editableText($_p, 'events.heading', 'Upcoming Events'); ?></h2>
                <?php
                $upcoming = getUpcomingEvents(4);
                if (!empty($upcoming)) {
                    echo renderEventList($upcoming, $currentLang, false, true);
                } else {
                    echo '<p style="color: var(--color-text-secondary); font-style: italic;">No upcoming events. Add events in the admin dashboard.</p>';
                }
                ?>
            </section>

            <div class="demo-closing">
                <?php echo editableHtml($_p, 'closing', ''); ?>
            </div>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
PHPTPL;
}

/**
 * Returns the PHP template string for the news listing page.
 */
function getNewsTemplate($lang, $title, $intro) {
    return <<<'PHPTPL'
<?php
$pageTitle = '{TITLE}';
$pageDescription = '{INTRO}';
$currentLang = '{LANG}';
$currentPage = 'news';
if (!isset($basePath)) $basePath = '../';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/header.php';
include $_includeBase . 'includes/content-loader.php';
?>

    <main class="main-content">
        <div class="content-inner">
            <div class="news-page">
                <div class="news-page__header">
                    <h1 class="news-page__title">{TITLE}</h1>
                    <p class="news-page__intro">{INTRO}</p>
                </div>
                <?php echo renderNewsList(0, '{LANG}'); ?>
            </div>
        </div>
    </main>

<?php include $_includeBase . 'includes/footer.php'; ?>
PHPTPL;
}

/**
 * Returns the PHP template string for the news post detail page.
 */
function getNewsPostTemplate($lang, $backLabel) {
    return <<<'PHPTPL'
<?php
$currentLang = '{LANG}';
$currentPage = 'news';
if (!isset($basePath)) $basePath = '../';

$_includeBase = dirname(__DIR__) . '/';

include $_includeBase . 'includes/content-loader.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    header('Location: ' . $basePath . '{NEWS_PATH}');
    exit;
}

$post = null;
$newsDir = $_includeBase . 'content/news/';
$_defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
if (is_dir($newsDir)) {
    foreach (glob($newsDir . '*.json') as $file) {
        $p = json_decode(file_get_contents($file), true);
        if (!is_array($p)) continue;
        if (($p['slug'] ?? '') !== $slug) continue;
        if (!empty($p['hidden'])) continue;
        $postLang = $p['lang'] ?? $_defaultLang;
        if ($postLang !== $currentLang) continue;
        $post = $p;
        break;
    }
}

if (!$post) {
    header('HTTP/1.0 404 Not Found');
    $pageTitle = 'Not Found';
    $pageDescription = '';
    include $_includeBase . 'includes/header.php';
    echo '<main class="main-content"><div class="content-inner"><h1>Not found</h1><p><a href="' . $basePath . '{NEWS_PATH}">{BACK_LABEL}</a></p></div></main>';
    include $_includeBase . 'includes/footer.php';
    exit;
}

$pageTitle = htmlspecialchars($post['title']);
$pageDescription = htmlspecialchars($post['excerpt'] ?? '');

if (session_status() === PHP_SESSION_NONE) session_start();
$_isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$newsPostJson = $_isAdmin ? json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

include $_includeBase . 'includes/header.php';

$dateObj = new DateTime($post['date']);
$formattedDate = $dateObj->format('F j, Y');
?>

    <main class="main-content">
        <div class="content-inner">
            <article class="news-post-page" data-news-post="<?php echo htmlspecialchars($post['id']); ?>">
                <a href="<?php echo $basePath; ?>{NEWS_PATH}" class="news-post-page__back">{BACK_LABEL}</a>

                <?php if (!empty($post['image'])): ?>
                <div class="news-post-page__hero">
                    <img src="<?php echo htmlspecialchars($post['image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                </div>
                <?php endif; ?>

                <header class="news-post-page__header">
                    <time class="news-post-page__date" datetime="<?php echo htmlspecialchars($post['date']); ?>"><?php echo $formattedDate; ?></time>
                    <h1 class="news-post-page__title"><?php echo htmlspecialchars($post['title']); ?></h1>
                    <?php if (!empty($post['author'])): ?>
                    <span class="news-post-page__author"><?php echo htmlspecialchars($post['author']); ?></span>
                    <?php endif; ?>
                </header>

                <div class="news-post-page__content">
                    <?php echo $post['content']; ?>
                </div>

                <footer class="news-post-page__footer">
                    <a href="<?php echo $basePath; ?>{NEWS_PATH}" class="news-post-page__back">{BACK_LABEL}</a>
                </footer>
            </article>
        </div>
    </main>

<?php if (!empty($newsPostJson)): ?>
<script>window.__cmsNewsPost = <?php echo $newsPostJson; ?>;</script>
<?php endif; ?>
<?php include $_includeBase . 'includes/footer.php'; ?>
PHPTPL;
}

/**
 * Returns demo news posts for a language.
 */
function getNewsPosts($lang, $t) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    return [
        [
            'filename' => $today . '-demo-welcome-' . $lang . '.json',
            'data' => [
                'id' => 'demo-welcome-' . $lang,
                'slug' => 'demo-welcome',
                'lang' => $lang,
                'title' => $t['news_post_1_title'],
                'date' => $today,
                'author' => $t['news_post_1_author'],
                'excerpt' => $t['news_post_1_excerpt'],
                'image' => 'https://picsum.photos/1200/600?random=9',
                'content' => $t['news_post_1_content'],
                'hidden' => false,
            ],
        ],
        [
            'filename' => $yesterday . '-demo-getting-started-' . $lang . '.json',
            'data' => [
                'id' => 'demo-getting-started-' . $lang,
                'slug' => 'demo-getting-started',
                'lang' => $lang,
                'title' => $t['news_post_2_title'],
                'date' => $yesterday,
                'author' => $t['news_post_2_author'],
                'excerpt' => $t['news_post_2_excerpt'],
                'image' => 'https://picsum.photos/1200/600?random=10',
                'content' => $t['news_post_2_content'],
                'hidden' => false,
            ],
        ],
    ];
}

/**
 * Returns nav-config.php content string with all demo pages.
 */
function getNavConfig($languages, $primaryLang, $setupLanguages) {
    $i18nNav = getStarterI18n('');

    $lines = "<?php\n";
    $lines .= "/**\n * Navigation Configuration\n * Generated by Setup Wizard. Customize as needed.\n */\n\n";
    $lines .= "\$defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : '{$primaryLang}';\n\n";

    // $SITE_LANGUAGES
    $lines .= "\$SITE_LANGUAGES = [\n";
    foreach ($languages as $lang) {
        $lines .= "    '{$lang}' => '" . addcslashes($setupLanguages[$lang], "'\\") . "',\n";
    }
    $lines .= "];\n\n";

    // Page slugs with their labels
    $pages = ['home', 'about', 'blocks', 'components', 'news'];

    // Contact page slugs per language
    $contactSlugs = [];
    foreach ($languages as $lang) {
        $contactSlugs[$lang] = $lang === 'de' ? 'kontakt' : ($lang === 'es' ? 'contacto' : 'contact');
    }

    // $PAGE_MAPPING
    $lines .= "\$PAGE_MAPPING = [\n";
    foreach ($pages as $slug) {
        $lines .= "    '{$slug}' => [\n";
        foreach ($languages as $lang) {
            if ($slug === 'home') {
                $path = ($lang === $primaryLang) ? '.' : $lang . '/';
            } else {
                $path = ($lang === $primaryLang) ? $slug : $lang . '/' . $slug;
            }
            $lines .= "        '{$lang}' => '{$path}',\n";
        }
        $lines .= "    ],\n";
    }
    // Contact page with localized slugs (+ aliases for all slug variants)
    foreach (array_unique($contactSlugs) as $variant) {
        $lines .= "    '{$variant}' => [\n";
        foreach ($languages as $lang) {
            $cSlug = $contactSlugs[$lang];
            $path = ($lang === $primaryLang) ? $cSlug : $lang . '/' . $cSlug;
            $lines .= "        '{$lang}' => '{$path}',\n";
        }
        $lines .= "    ],\n";
    }
    $lines .= "];\n\n";

    // $NAV_ITEMS
    $lines .= "\$NAV_ITEMS = [\n";
    foreach ($languages as $lang) {
        $t = $i18nNav[$lang] ?? $i18nNav['en'];
        $lines .= "    '{$lang}' => [\n";
        foreach ($pages as $slug) {
            if ($slug === 'home') {
                $href = ($lang === $primaryLang) ? '.' : $lang . '/';
            } else {
                $href = ($lang === $primaryLang) ? $slug : $lang . '/' . $slug;
            }
            $label = $t['nav_' . $slug] ?? ucfirst($slug);
            $lines .= "        ['href' => '{$href}', 'label' => '" . addcslashes($label, "'\\") . "', 'page' => '{$slug}'],\n";
        }
        $lines .= "    ],\n";
    }
    $lines .= "];\n";

    return $lines;
}

/**
 * Returns starter events for new installations.
 * Generates 4 events with dates relative to the installation date.
 */
function getStarterEvents($languages) {
    $baseDate = new DateTime();

    $events = [];

    // Event 1: ~3 weeks from now
    $d1 = (clone $baseDate)->modify('+21 days');
    $d1end = (clone $d1)->modify('+1 day');
    $events[] = [
        'id' => $d1->format('Y-m-d') . '-community-meetup',
        'date' => $d1->format('Y-m-d'),
        'time' => '18:30',
        'end-date' => $d1->format('Y-m-d'),
        'end-time' => '21:00',
        'url' => '',
        'title' => buildLangMap($languages, 'Community Meetup', 'Community-Treffen', 'Encuentro comunitario'),
        'location' => buildLangMap($languages, 'Online (Zoom)', 'Online (Zoom)', 'En línea (Zoom)'),
        'description' => buildLangMap($languages,
            'Monthly community meetup — share projects, ask questions, and connect with other users.',
            'Monatliches Community-Treffen — Projekte vorstellen, Fragen stellen und sich mit anderen Nutzern vernetzen.',
            'Encuentro mensual de la comunidad — comparte proyectos, haz preguntas y conecta con otros usuarios.'
        ),
        'admission' => buildLangMap($languages, 'Free', 'Kostenlos', 'Gratis'),
        'image' => '',
    ];

    // Event 2: ~6 weeks from now
    $d2 = (clone $baseDate)->modify('+42 days');
    $d2end = (clone $d2)->modify('+2 days');
    $events[] = [
        'id' => $d2->format('Y-m-d') . '-web-dev-conference',
        'date' => $d2->format('Y-m-d'),
        'time' => '09:00',
        'end-date' => $d2end->format('Y-m-d'),
        'end-time' => '17:00',
        'url' => 'https://example.com/webdevconf',
        'title' => buildLangMap($languages, 'Web Dev Conference', 'Web-Entwickler-Konferenz', 'Conferencia de Desarrollo Web'),
        'location' => buildLangMap($languages, 'Vienna, Austria', 'Wien, Österreich', 'Viena, Austria'),
        'description' => buildLangMap($languages,
            'A two-day conference covering modern web development, performance optimization, and CMS architecture.',
            'Zweitägige Konferenz über moderne Webentwicklung, Performance-Optimierung und CMS-Architektur.',
            'Conferencia de dos días sobre desarrollo web moderno, optimización de rendimiento y arquitectura CMS.'
        ),
        'admission' => buildLangMap($languages, 'Ticket required', 'Ticket erforderlich', 'Entrada con ticket'),
        'image' => '',
    ];

    // Event 3: ~10 weeks from now
    $d3 = (clone $baseDate)->modify('+70 days');
    $events[] = [
        'id' => $d3->format('Y-m-d') . '-open-source-summit',
        'date' => $d3->format('Y-m-d'),
        'time' => '10:00',
        'end-date' => $d3->format('Y-m-d'),
        'end-time' => '18:00',
        'url' => 'https://example.com/oss-summit',
        'title' => buildLangMap($languages, 'Open Source Summit', 'Open-Source-Gipfel', 'Cumbre Open Source'),
        'location' => buildLangMap($languages, 'Berlin, Germany', 'Berlin, Deutschland', 'Berlín, Alemania'),
        'description' => buildLangMap($languages,
            'A day of talks and workshops about open-source projects, licensing, and sustainable community building.',
            'Ein Tag voller Vorträge und Workshops über Open-Source-Projekte, Lizenzierung und nachhaltige Community-Arbeit.',
            'Un día de charlas y talleres sobre proyectos de código abierto, licencias y construcción de comunidades sostenibles.'
        ),
        'admission' => buildLangMap($languages, 'Free registration', 'Kostenlose Registrierung', 'Registro gratuito'),
        'image' => '',
    ];

    // Event 4: ~14 weeks from now
    $d4 = (clone $baseDate)->modify('+98 days');
    $d4end = (clone $d4)->modify('+1 day');
    $events[] = [
        'id' => $d4->format('Y-m-d') . '-design-systems-workshop',
        'date' => $d4->format('Y-m-d'),
        'time' => '09:00',
        'end-date' => $d4end->format('Y-m-d'),
        'end-time' => '16:00',
        'url' => '',
        'title' => buildLangMap($languages, 'Design Systems Workshop', 'Design-Systems-Workshop', 'Taller de Sistemas de Diseño'),
        'location' => buildLangMap($languages, 'Amsterdam, Netherlands', 'Amsterdam, Niederlande', 'Ámsterdam, Países Bajos'),
        'description' => buildLangMap($languages,
            'Hands-on workshop on building and maintaining design systems with CSS custom properties, component libraries, and documentation.',
            'Praxisworkshop zum Aufbau und zur Pflege von Design-Systemen mit CSS Custom Properties, Komponentenbibliotheken und Dokumentation.',
            'Taller práctico sobre la creación y mantenimiento de sistemas de diseño con propiedades CSS personalizadas, bibliotecas de componentes y documentación.'
        ),
        'admission' => buildLangMap($languages, 'Ticket required', 'Ticket erforderlich', 'Entrada con ticket'),
        'image' => '',
    ];

    return [
        'events' => $events,
        'lastModified' => date('c'),
    ];
}

/**
 * Returns demo contact form messages for new installations.
 */
function getStarterMails() {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $earlier = clone $now;
    $earlier->modify('-2 days');

    return [
        [
            'id' => 'mail_' . uniqid(),
            'timestamp' => $now->format('c'),
            'lang' => 'en',
            'name' => 'Emily Carter',
            'email' => 'emily.carter@designstudio.io',
            'phone' => '+1 415 555 0187',
            'occasion' => 'Contact inquiry',
            'date' => '',
            'message' => "Hi there!\n\nI came across your site while researching lightweight CMS options for a client project. We're a small design agency and most of our clients don't need the overhead of WordPress — they just want a clean site they can update themselves.\n\nCould you tell me more about how the inline editing works? Specifically, can clients edit text and swap images without ever touching code? That would be a huge selling point for us.\n\nAlso curious about multilingual support — one of our clients operates in three countries.\n\nLooking forward to hearing from you!\n\nBest,\nEmily",
            'status' => 'sent',
            'read' => false,
        ],
        [
            'id' => 'mail_' . uniqid(),
            'timestamp' => $earlier->format('c'),
            'lang' => 'en',
            'name' => 'Marcus Johnson',
            'email' => 'm.johnson@freelanceweb.dev',
            'phone' => '',
            'occasion' => 'Contact inquiry',
            'date' => '',
            'message' => "Hello,\n\nI'm a freelance developer and I've been looking for a flat-file CMS that I can deploy on cheap shared hosting for my clients. No database means no extra config, which is exactly what I need.\n\nQuick question: is there an easy way to back up the entire site? My clients sometimes break things and I'd love a one-click restore option.\n\nThanks for building this — the JSON-based approach is really clever.\n\nCheers,\nMarcus",
            'status' => 'sent',
            'read' => true,
        ],
    ];
}

/**
 * Helper: build a language map from up to 3 language values.
 */
function buildLangMap($languages, $en, $de = '', $es = '') {
    $map = [];
    foreach ($languages as $lang) {
        if ($lang === 'de' && $de) {
            $map[$lang] = $de;
        } elseif ($lang === 'es' && $es) {
            $map[$lang] = $es;
        } else {
            $map[$lang] = $en;
        }
    }
    return $map;
}
